<?php
/**
 * includes/blog.php — Helper dùng chung cho blog (bài viết, category, tag, TOC).
 * Tái dùng get_image_url, postUrl, categoryUrl, tagUrl.
 */

/**
 * Lấy danh sách category của 1 bài (kèm primary lên đầu).
 */
function blog_post_categories(PDO $pdo, int $postId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.slug FROM post_categories pc
             JOIN categories c ON c.id = pc.category_id
             WHERE pc.post_id = ? ORDER BY c.name ASC"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Lấy category chính của bài (primary_category_id, fallback category đầu tiên).
 */
function blog_primary_category(PDO $pdo, array $post): ?array
{
    $pid = (int) ($post['primary_category_id'] ?? 0);
    try {
        if ($pid > 0) {
            $s = $pdo->prepare("SELECT id, name, slug FROM categories WHERE id = ? LIMIT 1");
            $s->execute([$pid]);
            $row = $s->fetch();
            if ($row) return $row;
        }
        $cats = blog_post_categories($pdo, (int) $post['id']);
        return $cats[0] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Lấy tag của 1 bài.
 */
function blog_post_tags(PDO $pdo, int $postId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.name, t.slug FROM post_tags pt
             JOIN tags t ON t.id = pt.tag_id
             WHERE pt.post_id = ? ORDER BY t.name ASC"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Bài liên quan: cùng category chính, loại trừ bài hiện tại.
 */
function blog_related_posts(PDO $pdo, array $post, int $limit = 3): array
{
    $cat = blog_primary_category($pdo, $post);
    if (!$cat) return [];
    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT p.id, p.title, p.slug, p.thumbnail, p.created_at
             FROM posts p JOIN post_categories pc ON pc.post_id = p.id
             WHERE pc.category_id = ? AND p.id <> ? AND p.status = 1 AND p.deleted_at IS NULL
             ORDER BY p.created_at DESC LIMIT " . (int) $limit
        );
        $stmt->execute([(int) $cat['id'], (int) $post['id']]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Sinh mục lục (TOC) từ heading h2/h3 trong HTML; gắn id cho heading.
 * Trả về [tocHtml, contentHtmlWithAnchors].
 */
function blog_build_toc(string $html): array
{
    if (trim($html) === '' || !preg_match_all('/<h([23])\b[^>]*>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER)) {
        return ['', $html];
    }
    $items = [];
    $used = [];
    foreach ($m as $mt) {
        $level = (int) $mt[1];
        $text = trim(html_entity_decode(strip_tags($mt[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') continue;
        $base = function_exists('create_slug') ? create_slug($text) : preg_replace('/[^a-z0-9]+/', '-', strtolower($text));
        if ($base === '') $base = 'muc';
        $id = $base;
        $i = 2;
        while (isset($used[$id])) { $id = $base . '-' . $i; $i++; }
        $used[$id] = true;

        // Gắn id vào heading đầu tiên khớp (chưa có id)
        $orig = $mt[0];
        if (stripos($orig, ' id=') === false) {
            $new = preg_replace('/<h' . $level . '\b/i', '<h' . $level . ' id="' . $id . '"', $orig, 1);
            $pos = strpos($html, $orig);
            if ($pos !== false) {
                $html = substr_replace($html, $new, $pos, strlen($orig));
            }
        }
        $items[] = ['level' => $level, 'text' => $text, 'id' => $id];
    }
    if (count($items) < 3) {
        return ['', $html]; // bài ngắn -> không cần TOC
    }
    $toc = '<nav class="blog-toc" aria-label="Mục lục"><div class="blog-toc__title"><i class="bi bi-list-ul"></i> Mục lục</div><ul>';
    foreach ($items as $it) {
        $cls = $it['level'] === 3 ? ' class="toc-sub"' : '';
        $toc .= '<li' . $cls . '><a href="#' . $it['id'] . '">' . htmlspecialchars($it['text'], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    $toc .= '</ul></nav>';
    return [$toc, $html];
}

/**
 * Bọc bảng trong .table-responsive (giữ layout mobile).
 */
function blog_wrap_tables(string $html): string
{
    if ($html === '') return $html;
    $html = preg_replace('/<table\b([^>]*)>/i', '<div class="table-responsive"><table$1>', $html);
    $html = preg_replace('/<\/table>/i', '</table></div>', $html);
    return $html;
}

/**
 * Trích video ID từ URL YouTube (youtu.be, watch?v=, embed, shorts). Null nếu không phải.
 */
function blog_normalize_wp_content(string $html): string
{
    if ($html === '') return $html;

    $html = preg_replace_callback('~\[caption\b([^\]]*)\](.*?)\[/caption\]~is', function ($m) {
        $attrs = (string) $m[1];
        $inner = (string) $m[2];

        if (!preg_match('~<img\b[^>]*>~i', $inner, $imgMatch)) {
            return trim(strip_tags($inner));
        }

        $img = $imgMatch[0];
        $captionHtml = trim(str_replace($img, '', $inner));
        $captionText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($captionHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $class = 'wp-caption';
        if (preg_match('/\balign="([^"]+)"/i', $attrs, $align)) {
            $class .= ' ' . preg_replace('/[^a-z0-9_-]/i', '', $align[1]);
        }

        $style = '';
        if (preg_match('/\bwidth="(\d+)"/i', $attrs, $width)) {
            $style = ' style="max-width:' . (int) $width[1] . 'px"';
        }

        $figure = '<figure class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' . $style . '>' . $img;
        if ($captionText !== '') {
            $figure .= '<figcaption>' . htmlspecialchars($captionText, ENT_QUOTES, 'UTF-8') . '</figcaption>';
        }
        return $figure . '</figure>';
    }, $html);

    return preg_replace('~\[/?caption[^\]]*\]~i', '', $html);
}

function blog_clean_wp_text(string $text): string
{
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~\[caption\b[^\]]*\].*?\[/caption\]~is', ' ', $text);
    $text = preg_replace('~\[caption\b.*$~is', ' ', $text);
    $text = preg_replace('~\[[a-zA-Z_][a-zA-Z0-9_-]*(?:\s+[^\]]*)?\]|\[/[a-zA-Z_][a-zA-Z0-9_-]*\]~', ' ', $text);
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
}

function blog_youtube_id(string $url): ?string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $patterns = [
        '~youtu\.be/([A-Za-z0-9_-]{11})~i',
        '~youtube\.com/watch\?(?:[^"\s]*&)?v=([A-Za-z0-9_-]{11})~i',
        '~youtube\.com/embed/([A-Za-z0-9_-]{11})~i',
        '~youtube\.com/shorts/([A-Za-z0-9_-]{11})~i',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $url, $m)) return $m[1];
    }
    return null;
}

/**
 * HTML iframe nhúng YouTube responsive (16:9, lazy, no-cookie domain).
 */
function blog_youtube_iframe(string $id): string
{
    $id = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    return '<div class="video-embed"><iframe src="https://www.youtube-nocookie.com/embed/' . $id . '"'
        . ' title="YouTube video" loading="lazy" frameborder="0"'
        . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"'
        . ' allowfullscreen></iframe></div>';
}

/**
 * Nhúng / chuẩn hoá video YouTube trong nội dung bài (giống oEmbed WordPress):
 *  - <iframe> YouTube thô (từ WordPress, height cố định) -> bọc khung responsive.
 *  - URL trần trọn trong 1 thẻ <p> -> thay bằng iframe.
 *  - URL trần đứng riêng 1 dòng -> thay bằng iframe.
 * KHÔNG đụng URL nằm trong <a href> hay xen giữa văn bản, KHÔNG bọc lại iframe đã trong .video-embed.
 */
/**
 * Hạ cấp mọi thẻ <h1> trong NỘI DUNG bài/trang xuống <h2>.
 * Tránh trùng H1 với tiêu đề trang (mỗi trang chỉ nên có đúng 1 H1 — chuẩn SEO).
 */
function blog_demote_headings(string $html): string
{
    if ($html === '' || stripos($html, '<h1') === false) return $html;
    return preg_replace('~<(/?)h1(\b[^>]*)>~i', '<$1h2$2>', $html);
}

/**
 * Trích YouTube video id ĐẦU TIÊN trong nội dung HTML (dùng cho VideoObject schema).
 */
function blog_extract_youtube_id(string $html): ?string
{
    if ($html === '' || stripos($html, 'youtu') === false) return null;
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:[^"\s]*&)?v=|embed/|shorts/))([A-Za-z0-9_-]{11})~i', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m)) {
        return $m[1];
    }
    return null;
}

function blog_embed_youtube(string $html): string
{
    if ($html === '' || stripos($html, 'youtu') === false) return $html;

    // 0) <iframe> YouTube thô -> rút id, dựng lại trong khung .video-embed responsive.
    if (stripos($html, '<iframe') !== false) {
        $html = preg_replace_callback(
            '~<iframe\b[^>]*\bsrc=("|\')([^"\']*youtu[^"\']*)\1[^>]*>\s*</iframe>~i',
            function ($m) {
                $id = blog_youtube_id($m[2]);
                return $id ? blog_youtube_iframe($id) : $m[0];
            },
            $html
        );
        // Dọn khung .video-embed lồng nhau nếu iframe vốn đã được bọc sẵn.
        $html = preg_replace(
            '~<div class="video-embed">\s*(<div class="video-embed">.*?</div>)\s*</div>~is',
            '$1',
            $html
        );
    }

    // 1) <p>  https://youtu.be/xxxx  </p>  (chỉ chứa đúng 1 URL)
    $html = preg_replace_callback(
        '~<p[^>]*>\s*((?:https?:)?//[^\s<]*youtu[^\s<]*)\s*</p>~i',
        function ($m) {
            $id = blog_youtube_id($m[1]);
            return $id ? blog_youtube_iframe($id) : $m[0];
        },
        $html
    );

    // 2) URL trần đứng riêng 1 dòng (không nằm trong thuộc tính/thẻ a).
    $html = preg_replace_callback(
        '~(^|\n|<br\s*/?>)\s*((?:https?:)?//[^\s<]*youtu[^\s<]*)\s*(?=\n|<br\s*/?>|$)~i',
        function ($m) {
            $id = blog_youtube_id($m[2]);
            return $id ? $m[1] . blog_youtube_iframe($id) : $m[0];
        },
        $html
    );

    return $html;
}

/**
 * URL ảnh đại diện bài (fallback placeholder).
 */
function blog_thumb(array $post): string
{
    return get_image_url($post['thumbnail'] ?? '', 'news');
}

/**
 * Render 1 card bài viết (dùng chung ở trang chủ, category, tag, liên quan).
 */
function blog_card(array $p): string
{
    $url = postUrl($p['slug']);
    $thumb = blog_thumb($p);
    $alt = $p['thumbnail_alt'] ?? $p['title'];
    ob_start(); ?>
    <article class="card h-100 border-0 shadow-sm blog-card">
        <a href="<?php echo $url; ?>" class="blog-card__thumb" aria-label="<?php echo e($p['title']); ?>">
            <img src="<?php echo e($thumb); ?>" alt="<?php echo e($alt); ?>" loading="lazy" decoding="async">
        </a>
        <div class="card-body d-flex flex-column">
            <h3 class="blog-card__title"><a href="<?php echo $url; ?>"><?php echo e($p['title']); ?></a></h3>
            <?php if (!empty($p['summary'])): ?>
                <p class="blog-card__excerpt"><?php echo e(mb_substr(strip_tags($p['summary']), 0, 110, 'UTF-8')); ?>…</p>
            <?php endif; ?>
            <div class="blog-card__meta mt-auto">
                <i class="bi bi-clock"></i>
                <time datetime="<?php echo date('c', strtotime($p['created_at'])); ?>"><?php echo date('d/m/Y', strtotime($p['created_at'])); ?></time>
            </div>
        </div>
    </article>
    <?php return ob_get_clean();
}

/**
 * Đồng bộ category cho bài (mảng id). Trả về primary (id đầu tiên) hoặc null.
 */
function blog_sync_post_categories(PDO $pdo, int $postId, array $catIds): ?int
{
    $catIds = array_values(array_unique(array_filter(array_map('intval', $catIds), fn($v) => $v > 0)));
    $pdo->prepare("DELETE FROM post_categories WHERE post_id = ?")->execute([$postId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (?, ?)");
    foreach ($catIds as $cid) {
        $ins->execute([$postId, $cid]);
    }
    return $catIds[0] ?? null;
}

/**
 * Đồng bộ tag cho bài từ chuỗi CSV tên tag (tự tạo tag mới nếu chưa có).
 */
function blog_sync_post_tags(PDO $pdo, int $postId, string $tagsCsv): void
{
    $names = array_filter(array_map('trim', explode(',', $tagsCsv)), fn($v) => $v !== '');
    $pdo->prepare("DELETE FROM post_tags WHERE post_id = ?")->execute([$postId]);
    if (empty($names)) return;
    $ins = $pdo->prepare("INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)");
    foreach ($names as $name) {
        $slug = create_slug($name);
        if ($slug === '') continue;
        $sel = $pdo->prepare("SELECT id FROM tags WHERE slug = ? LIMIT 1");
        $sel->execute([$slug]);
        $tid = $sel->fetchColumn();
        if (!$tid) {
            $pdo->prepare("INSERT INTO tags (name, slug, created_at) VALUES (?, ?, NOW())")->execute([$name, $slug]);
            $tid = (int) $pdo->lastInsertId();
        }
        $ins->execute([$postId, (int) $tid]);
    }
}

/**
 * Tạo bản ghi slug_redirects 301 khi đổi slug bài viết.
 */
function blog_record_slug_redirect(PDO $pdo, string $oldSlug, string $newSlug, int $postId): void
{
    $oldSlug = trim($oldSlug, '/');
    $newSlug = trim($newSlug, '/');
    if ($oldSlug === '' || $oldSlug === $newSlug) return;
    try {
        $pdo->prepare("INSERT INTO slug_redirects (old_path, new_path, entity_type, entity_id, created_at)
                       VALUES (?, ?, 'post', ?, NOW())")
            ->execute(['/' . $oldSlug . '/', '/' . $newSlug . '/', $postId]);
    } catch (Throwable $e) { /* bảng/cột có thể khác — bỏ qua an toàn */ }
}

/**
 * Upload file tài liệu (pdf/doc/docx/xls/xlsx/ppt/pptx/zip).
 * Trả về [path nội bộ, tên gốc] hoặc [null, null] nếu lỗi.
 */
function blog_upload_document(array $file): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return [null, null];
    }
    $allowed = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip'  => 'application/zip',
    ];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return [null, null];
    }
    $dir = ROOT_PATH . 'assets/uploads/documents/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $origName = preg_replace('/[^\p{L}\p{N}\.\-_ ]+/u', '', (string) $file['name']);
    $safe = 'doc_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $safe)) {
        return [null, null];
    }
    return ['assets/uploads/documents/' . $safe, $origName ?: $safe];
}

function blog_ensure_post_ratings_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `post_ratings` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `post_id` INT UNSIGNED NOT NULL,
            `rating` TINYINT UNSIGNED NOT NULL,
            `reviewer_name` VARCHAR(120) NOT NULL DEFAULT '',
            `comment` TEXT NULL,
            `identity_hash` CHAR(64) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `user_agent_hash` CHAR(64) NOT NULL DEFAULT '',
            `status` ENUM('approved','pending','spam') NOT NULL DEFAULT 'approved',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_post_identity` (`post_id`, `identity_hash`),
            KEY `idx_post_status` (`post_id`, `status`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `post_rating_legacy` (
            `post_id` INT UNSIGNED NOT NULL,
            `rating_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `rating_sum` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `rating_avg` DECIMAL(4,2) NOT NULL DEFAULT 0,
            `source` VARCHAR(40) NOT NULL DEFAULT 'kk-star-ratings',
            `source_ref` VARCHAR(80) NOT NULL DEFAULT '',
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`post_id`),
            KEY `idx_source_ref` (`source`, `source_ref`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
    }
}

function blog_rating_identity_hash(): string
{
    $ip = function_exists('get_client_ip_address')
        ? get_client_ip_address()
        : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $salt = defined('SECURITY_KEY') ? SECURITY_KEY : (defined('DB_NAME') ? DB_NAME : 'blog');
    return hash('sha256', $ip . '|' . $ua . '|' . $salt);
}

function blog_post_rating_summary(PDO $pdo, int $postId): array
{
    $summary = [
        'count' => 0,
        'average' => 0.0,
        'average_text' => '0.0',
        'percent' => 0,
    ];
    if ($postId <= 0) {
        return $summary;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(l.rating_count, 0) + COALESCE(r.rating_count, 0) AS rating_count,
                COALESCE(l.rating_sum, 0) + COALESCE(r.rating_sum, 0) AS rating_sum
             FROM (SELECT ? AS post_id) p
             LEFT JOIN post_rating_legacy l ON l.post_id = p.post_id
             LEFT JOIN (
                SELECT post_id, COUNT(*) AS rating_count, SUM(rating) AS rating_sum
                FROM post_ratings
                WHERE post_id = ? AND status = 'approved'
                GROUP BY post_id
             ) r ON r.post_id = p.post_id"
        );
        $stmt->execute([$postId, $postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $count = (int) ($row['rating_count'] ?? 0);
        $sum = (float) ($row['rating_sum'] ?? 0);
        $avg = $count > 0 ? ($sum / $count) : 0.0;
        $summary['count'] = $count;
        $summary['average'] = $avg;
        $summary['average_text'] = number_format($avg, 1, '.', '');
        $summary['percent'] = (int) round(max(0, min(5, $avg)) / 5 * 100);
    } catch (Throwable $e) {
    }

    return $summary;
}

/**
 * Card bài viết dạng DANH SÁCH (ảnh trái, nội dung phải) — dùng cho layout list.
 */
function blog_card_list(array $p): string
{
    $url = postUrl($p['slug']);
    $thumb = blog_thumb($p);
    $alt = $p['thumbnail_alt'] ?? $p['title'];
    ob_start(); ?>
    <article class="blog-card-list">
        <a href="<?php echo $url; ?>" class="blog-card-list__thumb">
            <img src="<?php echo e($thumb); ?>" alt="<?php echo e($alt); ?>" loading="lazy" decoding="async">
        </a>
        <div class="blog-card-list__body">
            <h3 class="blog-card-list__title"><a href="<?php echo $url; ?>"><?php echo e($p['title']); ?></a></h3>
            <?php if (!empty($p['summary'])): ?>
                <p class="blog-card-list__excerpt"><?php echo e(mb_substr(strip_tags($p['summary']), 0, 140, 'UTF-8')); ?>…</p>
            <?php endif; ?>
            <div class="blog-card__meta"><i class="bi bi-clock"></i>
                <time datetime="<?php echo date('c', strtotime($p['created_at'])); ?>"><?php echo date('d/m/Y', strtotime($p['created_at'])); ?></time></div>
        </div>
    </article>
    <?php return ob_get_clean();
}

/**
 * Card bài viết dạng PHỦ ẢNH (ảnh nền + chữ overlay) — dùng cho layout overlay/magazine.
 */
function blog_card_overlay(array $p, bool $large = false): string
{
    $url = postUrl($p['slug']);
    $thumb = blog_thumb($p);
    $alt = $p['thumbnail_alt'] ?? $p['title'];
    $cls = $large ? ' blog-card-overlay--lg' : '';
    ob_start(); ?>
    <a href="<?php echo $url; ?>" class="blog-card-overlay<?php echo $cls; ?>" aria-label="<?php echo e($p['title']); ?>">
        <img src="<?php echo e($thumb); ?>" alt="<?php echo e($alt); ?>" loading="lazy" decoding="async">
        <span class="blog-card-overlay__grad"></span>
        <span class="blog-card-overlay__body">
            <span class="blog-card-overlay__title"><?php echo e($p['title']); ?></span>
            <span class="blog-card-overlay__meta"><i class="bi bi-clock"></i> <?php echo date('d/m/Y', strtotime($p['created_at'])); ?></span>
        </span>
    </a>
    <?php return ob_get_clean();
}

/**
 * Trả về chuỗi style padding/margin cho 1 block trang chủ.
 * Đọc $GLOBALS['block_spacing'] (mảng pt/pb/mt/mb px) do index.php set theo từng block.
 */
function block_spacing_style(int $defaultPt = 48, int $defaultPb = 48): string
{
    $s = $GLOBALS['block_spacing'] ?? [];
    $num = function ($v, $def) {
        return ($v === '' || $v === null || !is_numeric($v)) ? (int) $def : max(0, min(300, (int) $v));
    };
    $pt = $num($s['pt'] ?? null, $defaultPt);
    $pb = $num($s['pb'] ?? null, $defaultPb);
    $mt = $num($s['mt'] ?? null, 0);
    $mb = $num($s['mb'] ?? null, 0);
    return "padding-top:{$pt}px;padding-bottom:{$pb}px;margin-top:{$mt}px;margin-bottom:{$mb}px;";
}


if (!function_exists('blog_import_remote_image')) {
    /**
     * Tải 1 ảnh từ URL về thư viện media, trả về đường dẫn tương đối (hoặc null nếu lỗi).
     */
    function blog_import_remote_image(PDO $pdo, string $url, string $altName = ''): ?string
    {
        $url = trim($url);
        if ($url === '' || stripos($url, 'http') !== 0) return null;
        $data = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false]);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 300) $data = null;
        }
        if ($data === null) $data = @file_get_contents($url);
        if (!$data) return null;
        $info = @getimagesizefromstring($data);
        if ($info === false || empty($info[0])) return null;
        $mime = $info['mime'] ?? 'image/jpeg';
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $ext = $extMap[$mime] ?? 'jpg';
        $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . '/' : dirname(__DIR__) . '/';
        $ym = date('Y/m');
        $dir = $root . 'assets/uploads/media/' . $ym . '/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $base = function_exists('create_slug') ? create_slug($altName ?: 'thumb') : 'thumb';
        if ($base === '') $base = 'thumb';
        $stored = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . substr($base, 0, 40) . '.' . $ext;
        $abs = $dir . $stored;
        if (@file_put_contents($abs, $data) === false) return null;
        $rel = 'assets/uploads/media/' . $ym . '/' . $stored;
        if (function_exists('register_media_file')) @register_media_file($pdo, $abs, $rel, ($altName ?: $stored));
        return $rel;
    }
}

if (!function_exists('blog_import_youtube_thumb')) {
    /**
     * Tải ảnh đại diện từ thumbnail YouTube (ưu tiên maxres, fallback hq). Trả về path tương đối hoặc null.
     */
    function blog_import_youtube_thumb(PDO $pdo, string $videoId, string $altName = ''): ?string
    {
        if ($videoId === '') return null;
        foreach (['maxresdefault', 'sddefault', 'hqdefault'] as $q) {
            $rel = blog_import_remote_image($pdo, "https://i.ytimg.com/vi/{$videoId}/{$q}.jpg", $altName);
            if ($rel) return $rel;
        }
        return null;
    }
}
