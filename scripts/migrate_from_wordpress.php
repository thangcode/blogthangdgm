<?php
/**
 * migrate_from_wordpress.php
 * Di chuyển dữ liệu blog từ WordPress (thangdgm_db) sang blogthangdgm.
 * Idempotent: UPSERT theo slug, copy ảnh bỏ qua nếu đã có.
 *
 * Chạy: php scripts/migrate_from_wordpress.php
 *
 * Nguồn ảnh WP: F:\Xamp\htdocs\thangdgm\wp-content\uploads
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../config/database.php'; // $pdo (đích)
$pdo->exec("SET NAMES utf8mb4");

// ---- Cấu hình nguồn ----
const WP_DB   = 'thangdgm_db';
const WP_HOST = 'localhost';
const WP_USER = 'root';
const WP_PASS = '';
const WP_UPLOADS = 'F:\\Xamp\\htdocs\\thangdgm\\wp-content\\uploads';
const WP_DOMAINS = ['thang-dgm.com', 'www.thang-dgm.com', 'thangdgm.test', 'www.thangdgm.test'];

$DEST_ROOT = dirname(__DIR__);
$WP_UPLOADS_WEB = $DEST_ROOT . '/assets/uploads/wp';
$DOC_DIR = $DEST_ROOT . '/assets/uploads/documents';

$src = new PDO('mysql:host=' . WP_HOST . ';dbname=' . WP_DB . ';charset=utf8mb4', WP_USER, WP_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stats = ['cat' => 0, 'tag' => 0, 'post' => 0, 'img' => 0, 'img_miss' => 0, 'doc' => 0, 'short' => 0, 'err' => 0];
function logline($m) { echo $m . PHP_EOL; }

// ---------- Helpers ----------
function wp_meta(PDO $src, int $postId, string $key)
{
    $s = $src->prepare("SELECT meta_value FROM wp_postmeta WHERE post_id=? AND meta_key=? LIMIT 1");
    $s->execute([$postId, $key]);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

/** Copy 1 file từ wp-content/uploads (relpath kiểu '2025/05/x.jpg') sang đích, trả web path root-relative hoặc null. */
function copy_upload(string $relpath, string $destBase, string $webBase, array &$stats)
{
    $relpath = ltrim(str_replace('\\', '/', $relpath), '/');
    if ($relpath === '') return null;
    $srcFile = WP_UPLOADS . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relpath);
    if (!is_file($srcFile)) {
        $stats['img_miss']++;
        return null;
    }
    $destFile = $destBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relpath);
    $destDir = dirname($destFile);
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    if (!is_file($destFile)) {
        if (@copy($srcFile, $destFile)) {
            $stats['img']++;
        } else {
            $stats['img_miss']++;
            return null;
        }
    }
    return $webBase . '/' . $relpath; // ví dụ /assets/uploads/wp/2025/05/x.jpg
}

/** Rewrite mọi URL ảnh WP trong HTML -> nội bộ; thêm loading=lazy cho <img>. */
function rewrite_content(string $html, string $destBase, string $webBase, array &$stats): string
{
    if ($html === '') return $html;
    $domains = implode('|', array_map('preg_quote', WP_DOMAINS));
    // Bắt URL trỏ wp-content/uploads (có/không scheme, có/không domain)
    $pattern = '#(?:https?:)?//(?:' . $domains . ')/wp-content/uploads/([^"\'\s)]+)#i';
    $html = preg_replace_callback($pattern, function ($m) use ($destBase, $webBase, &$stats) {
        $rel = $m[1];
        $web = copy_upload($rel, $destBase, $webBase, $stats);
        return $web ?? $m[0]; // không copy được -> giữ nguyên (sẽ log miss)
    }, $html);

    // URL dạng /wp-content/uploads/... (không domain)
    $html = preg_replace_callback('#(?<![:/])/wp-content/uploads/([^"\'\s)]+)#i', function ($m) use ($destBase, $webBase, &$stats) {
        $rel = $m[1];
        $web = copy_upload($rel, $destBase, $webBase, $stats);
        return $web ?? $m[0];
    }, $html);

    // Thêm loading="lazy" cho <img> chưa có
    $html = preg_replace_callback('#<img\b(?![^>]*\bloading=)([^>]*)>#i', function ($m) {
        return '<img loading="lazy" decoding="async"' . $m[1] . '>';
    }, $html);

    // Làm sạch comment Elementor/WP cơ bản
    $html = preg_replace('/<!--\s*\/?wp:[^>]*?-->/s', '', $html);
    return $html;
}

function make_summary(?string $excerpt, string $content): string
{
    $excerpt = trim((string) $excerpt);
    if ($excerpt !== '') return mb_substr(strip_tags($excerpt), 0, 300, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($content)));
    return mb_substr($text, 0, 280, 'UTF-8');
}

function clean_rankmath_value(?string $v): string
{
    $v = trim((string) $v);
    if ($v === '' || strpos($v, '%') !== false) return ''; // bỏ template var %title% %sep%
    return $v;
}

// ---------- 1) Categories ----------
logline("=== MIGRATE CATEGORIES ===");
$catMap = []; // wp term_id -> new id
$catParentWp = []; // new id -> wp parent term_id
$rows = $src->query("SELECT t.term_id, t.name, t.slug, tt.description, tt.count, tt.parent
    FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id
    WHERE tt.taxonomy='category'");
foreach ($rows as $r) {
    if ((int) $r['count'] <= 0) continue; // bỏ category rỗng (gồm uncategorized, Viết Content...)
    try {
        $name = html_entity_decode($r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, status, created_at)
            VALUES (?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description)");
        // categories.slug có thể không unique -> kiểm tra trước
        $chk = $pdo->prepare("SELECT id FROM categories WHERE slug=? LIMIT 1");
        $chk->execute([$r['slug']]);
        $existing = $chk->fetchColumn();
        if ($existing) {
            $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?")
                ->execute([$name, $r['description'], $existing]);
            $catMap[(int) $r['term_id']] = (int) $existing;
        } else {
            $pdo->prepare("INSERT INTO categories (name, slug, description, status, created_at) VALUES (?, ?, ?, 1, NOW())")
                ->execute([$name, $r['slug'], $r['description']]);
            $catMap[(int) $r['term_id']] = (int) $pdo->lastInsertId();
            $stats['cat']++;
        }
        $catParentWp[$catMap[(int) $r['term_id']]] = (int) $r['parent'];
    } catch (Throwable $e) {
        $stats['err']++; logline("[ERR] cat {$r['slug']}: " . $e->getMessage());
    }
}
// Pass 2: thiết lập quan hệ cha-con (giữ đúng cấu trúc phân cấp như WordPress)
foreach ($catParentWp as $newId => $wpParent) {
    $parentNewId = ($wpParent > 0 && isset($catMap[$wpParent])) ? $catMap[$wpParent] : null;
    try {
        $pdo->prepare("UPDATE categories SET parent_id=? WHERE id=?")->execute([$parentNewId, $newId]);
    } catch (Throwable $e) {
        $stats['err']++; logline("[ERR] cat parent #{$newId}: " . $e->getMessage());
    }
}
logline("Categories mới: {$stats['cat']} (tổng map: " . count($catMap) . ")");

// ---------- 2) Tags ----------
logline("=== MIGRATE TAGS ===");
$tagMap = [];
$rows = $src->query("SELECT t.term_id, t.name, t.slug, tt.description
    FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id
    WHERE tt.taxonomy='post_tag'");
foreach ($rows as $r) {
    try {
        $name = html_entity_decode($r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $chk = $pdo->prepare("SELECT id FROM tags WHERE slug=? LIMIT 1");
        $chk->execute([$r['slug']]);
        $existing = $chk->fetchColumn();
        if ($existing) {
            $tagMap[(int) $r['term_id']] = (int) $existing;
        } else {
            $pdo->prepare("INSERT INTO tags (name, slug, description, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$name, $r['slug'], $r['description']]);
            $tagMap[(int) $r['term_id']] = (int) $pdo->lastInsertId();
            $stats['tag']++;
        }
    } catch (Throwable $e) {
        $stats['err']++; logline("[ERR] tag {$r['slug']}: " . $e->getMessage());
    }
}
logline("Tags mới: {$stats['tag']} (tổng map: " . count($tagMap) . ")");

// ---------- 3) Posts ----------
logline("=== MIGRATE POSTS ===");
$posts = $src->query("SELECT ID, post_title, post_name, post_content, post_excerpt, post_author, post_date, post_modified
    FROM wp_posts WHERE post_type='post' AND post_status='publish' ORDER BY post_date ASC");

$authorCache = [];
foreach ($posts as $p) {
    try {
        $wpId = (int) $p['ID'];
        $slug = $p['post_name'];
        if ($slug === '') continue;
        $title = html_entity_decode($p['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Author
        $aid = (int) $p['post_author'];
        if (!isset($authorCache[$aid])) {
            $s = $src->prepare("SELECT display_name FROM wp_users WHERE ID=?");
            $s->execute([$aid]);
            $authorCache[$aid] = $s->fetchColumn() ?: 'Admin';
        }
        $author = $authorCache[$aid];

        // Content + rewrite ảnh
        $content = rewrite_content((string) $p['post_content'], $GLOBALS['WP_UPLOADS_WEB'], '/assets/uploads/wp', $stats);
        $summary = make_summary($p['post_excerpt'], (string) $p['post_content']);

        // SEO
        $metaTitle = clean_rankmath_value(wp_meta($src, $wpId, 'rank_math_title')) ?: $title;
        $metaDesc  = clean_rankmath_value(wp_meta($src, $wpId, 'rank_math_description')) ?: $summary;
        $focus     = (string) (wp_meta($src, $wpId, 'rank_math_focus_keyword') ?? '');
        $focus     = trim(explode(',', $focus)[0]);
        $rich      = (string) (wp_meta($src, $wpId, 'rank_math_rich_snippet') ?? '');
        $schemaType = $rich === 'article' ? 'Article' : 'BlogPosting';

        // Views
        $views = (int) (wp_meta($src, $wpId, 'penci_post_views_count') ?? 0);

        // Thumbnail
        $thumb = null; $thumbAlt = $title;
        $thumbId = (int) (wp_meta($src, $wpId, '_thumbnail_id') ?? 0);
        if ($thumbId > 0) {
            $af = wp_meta($src, $thumbId, '_wp_attached_file');
            if ($af) {
                $thumb = copy_upload($af, $GLOBALS['WP_UPLOADS_WEB'], '/assets/uploads/wp', $stats);
                $thumb = $thumb ? ltrim($thumb, '/') : null; // lưu dạng assets/... cho get_image_url
            }
            $alt = wp_meta($src, $thumbId, '_wp_attachment_image_alt');
            if ($alt) $thumbAlt = $alt;
        }

        // Document (ACF file_document -> attachment id)
        $docPath = null; $docName = null;
        $docId = (int) (wp_meta($src, $wpId, 'file_document') ?? 0);
        if ($docId > 0) {
            $af = wp_meta($src, $docId, '_wp_attached_file');
            if ($af) {
                $copied = copy_upload($af, $DOC_DIR, '/assets/uploads/documents', $stats);
                if ($copied) {
                    $docPath = ltrim($copied, '/');
                    $docName = basename($af);
                    $stats['doc']++;
                }
            }
        }

        // Primary category
        $primaryCat = null;
        $pc = (int) (wp_meta($src, $wpId, 'rank_math_primary_category') ?? 0);
        if ($pc > 0 && isset($catMap[$pc])) $primaryCat = $catMap[$pc];

        // UPSERT post theo slug
        $chk = $pdo->prepare("SELECT id FROM posts WHERE slug=? LIMIT 1");
        $chk->execute([$slug]);
        $postId = $chk->fetchColumn();

        $fields = [
            'title' => $title, 'summary' => $summary, 'content' => $content,
            'meta_title' => mb_substr($metaTitle, 0, 255, 'UTF-8'),
            'meta_description' => $metaDesc, 'focus_keyword' => $focus,
            'schema_type' => $schemaType, 'thumbnail' => $thumb, 'thumbnail_alt' => $thumbAlt,
            'author_name' => $author, 'views' => $views,
            'document_path' => $docPath, 'document_name' => $docName,
            'primary_category_id' => $primaryCat,
            'status' => 1,
            'created_at' => $p['post_date'], 'updated_at' => $p['post_modified'],
        ];

        if ($postId) {
            $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE posts SET $set WHERE id=?");
            $stmt->execute([...array_values($fields), $postId]);
        } else {
            $cols = '`' . implode('`,`', array_keys($fields)) . '`,`slug`';
            $ph = implode(',', array_fill(0, count($fields) + 1, '?'));
            $stmt = $pdo->prepare("INSERT INTO posts ($cols) VALUES ($ph)");
            $stmt->execute([...array_values($fields), $slug]);
            $postId = (int) $pdo->lastInsertId();
            $stats['post']++;
        }
        $postId = (int) $postId;

        // Quan hệ category/tag
        $rel = $src->prepare("SELECT tt.taxonomy, tt.term_id FROM wp_term_relationships tr
            JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
            WHERE tr.object_id=? AND tt.taxonomy IN ('category','post_tag')");
        $rel->execute([$wpId]);
        $pdo->prepare("DELETE FROM post_categories WHERE post_id=?")->execute([$postId]);
        $pdo->prepare("DELETE FROM post_tags WHERE post_id=?")->execute([$postId]);
        $firstCat = null;
        foreach ($rel as $rr) {
            $tid = (int) $rr['term_id'];
            if ($rr['taxonomy'] === 'category' && isset($catMap[$tid])) {
                $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)")
                    ->execute([$postId, $catMap[$tid]]);
                if ($firstCat === null) $firstCat = $catMap[$tid];
            } elseif ($rr['taxonomy'] === 'post_tag' && isset($tagMap[$tid])) {
                $pdo->prepare("INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)")
                    ->execute([$postId, $tagMap[$tid]]);
            }
        }
        // primary_category fallback
        if ($primaryCat === null && $firstCat !== null) {
            $pdo->prepare("UPDATE posts SET primary_category_id=? WHERE id=?")->execute([$firstCat, $postId]);
        }
    } catch (Throwable $e) {
        $stats['err']++; logline("[ERR] post {$p['post_name']}: " . $e->getMessage());
    }
}
logline("Posts mới: {$stats['post']}");

// ---------- 3b) Pages (trang tĩnh) ----------
logline("=== MIGRATE PAGES ===");
$stats['page'] = 0;
// Bỏ qua: trang WooCommerce/tiện ích, trang chủ WP trùng, và trang đã có file tĩnh (gioi-thieu/lien-he).
$skipPageSlugs = ['cart','checkout','shop','my-account','password-protected-form',
    'thang-digital-marketin-home-page','gioi-thieu','lien-he'];
try {
    $pages = $src->query("SELECT ID, post_title, post_name, post_content, post_excerpt, post_modified
        FROM wp_posts WHERE post_type='page' AND post_status='publish' ORDER BY post_date ASC");
    foreach ($pages as $pg) {
        $slug = $pg['post_name'];
        if ($slug === '' || in_array($slug, $skipPageSlugs, true)) continue;
        try {
            $title = html_entity_decode($pg['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $content = rewrite_content((string) $pg['post_content'], $GLOBALS['WP_UPLOADS_WEB'], '/assets/uploads/wp', $stats);
            $summary = make_summary($pg['post_excerpt'], (string) $pg['post_content']);
            $metaTitle = clean_rankmath_value(wp_meta($src, (int) $pg['ID'], 'rank_math_title')) ?: $title;
            $metaDesc  = clean_rankmath_value(wp_meta($src, (int) $pg['ID'], 'rank_math_description')) ?: $summary;

            $chk = $pdo->prepare("SELECT id FROM pages WHERE slug = ? LIMIT 1");
            $chk->execute([$slug]);
            $pid = $chk->fetchColumn();
            if ($pid) {
                $pdo->prepare("UPDATE pages SET title=?, summary=?, content=?, meta_title=?, meta_description=?, status=1, updated_at=? WHERE id=?")
                    ->execute([$title, $summary, $content, mb_substr($metaTitle,0,255,'UTF-8'), $metaDesc, $pg['post_modified'], $pid]);
            } else {
                $pdo->prepare("INSERT INTO pages (title, slug, summary, content, meta_title, meta_description, status, created_at, updated_at) VALUES (?,?,?,?,?,?,1,NOW(),?)")
                    ->execute([$title, $slug, $summary, $content, mb_substr($metaTitle,0,255,'UTF-8'), $metaDesc, $pg['post_modified']]);
                $stats['page']++;
            }
        } catch (Throwable $e) {
            $stats['err']++; logline("[ERR] page {$pg['post_name']}: " . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    logline("[ERR] pages query: " . $e->getMessage());
}
logline("Pages mới: {$stats['page']}");


// ---------- 4) Short links (BetterLinks) ----------
logline("=== MIGRATE SHORT LINKS ===");
try {
    $bl = $src->query("SELECT link_title, link_slug, short_url, target_url, redirect_type, track_me FROM wp_betterlinks");
    foreach ($bl as $l) {
        // Slug ưu tiên short_url (slug rút gọn người dùng tự đặt); fallback link_slug nếu trống.
        $raw = trim((string) ($l['short_url'] !== '' ? $l['short_url'] : $l['link_slug']));
        $raw = ltrim($raw, '/');
        // Bỏ prefix kiểu "go/" của BetterLinks: router chỉ phân giải slug 1 đoạn.
        if (strpos($raw, '/') !== false) {
            $raw = substr($raw, strrpos($raw, '/') + 1);
        }
        $slug = strtolower($raw);
        if ($slug === '') continue;
        $rt = (int) $l['redirect_type']; if (!in_array($rt, [301,302,307,308])) $rt = 307;
        $track = ($l['track_me'] === '1' || $l['track_me'] === 1) ? 1 : 1;
        $chk = $pdo->prepare("SELECT id FROM short_links WHERE slug=? LIMIT 1");
        $chk->execute([$slug]);
        $ex = $chk->fetchColumn();
        $title = html_entity_decode((string) $l['link_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($ex) {
            $pdo->prepare("UPDATE short_links SET title=?, target_url=?, redirect_type=?, is_tracking_enabled=?, status=1 WHERE id=?")
                ->execute([$title, $l['target_url'], $rt, $track, $ex]);
        } else {
            $pdo->prepare("INSERT INTO short_links (title, slug, target_url, redirect_type, is_tracking_enabled, status, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())")
                ->execute([$title, $slug, $l['target_url'], $rt, $track]);
            $stats['short']++;
        }
    }
} catch (Throwable $e) {
    logline("[ERR] short links: " . $e->getMessage());
}
logline("Short links mới: {$stats['short']}");

// ---------- Báo cáo ----------
logline("\n=== BÁO CÁO ===");
foreach ($stats as $k => $v) logline(str_pad($k, 10) . ": $v");
logline("=== HOÀN TẤT ===");
