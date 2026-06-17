<?php
/**
 * admin/ajax/post-import.php — Nhập ý tưởng / link YouTube thành bài viết NHÁP.
 * POST: ideas (mỗi dòng 1 ý tưởng hoặc 1 link YouTube), csrf_token
 * - Link YouTube: tự lấy tiêu đề (oEmbed) + nhúng video làm nội dung gốc.
 * - Dòng thường: dùng làm tiêu đề bài nháp.
 * Trả JSON: { success, created, items:[{id,title,edit_url}], skipped }
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/blog.php';
require_once '../../includes/llm.php';
require_once '../../includes/page-cache.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

$raw = trim((string) ($_POST['ideas'] ?? ''));
if ($raw === '') { echo json_encode(['success' => false, 'message' => 'Chưa nhập ý tưởng nào.']); exit; }

$lines = preg_split('/\r\n|\r|\n/', $raw);
$author = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin');

function import_fetch_url(string $url): ?string
{
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    }
    if (!$body) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: Mozilla/5.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
    }
    return is_string($body) && $body !== '' ? $body : null;
}

function import_clean_meta_text(string $text, int $limit = 0): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($limit > 0 && mb_strlen($text, 'UTF-8') > $limit) {
        $text = rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…';
    }
    return $text;
}

function import_meta_content(string $html, string $attr, string $name): string
{
    $name = preg_quote($name, '~');
    if (preg_match('~<meta\b(?=[^>]*\b' . $attr . '=["\']' . $name . '["\'])(?=[^>]*\bcontent=["\']([^"\']*)["\'])[^>]*>~i', $html, $m)) {
        return import_clean_meta_text($m[1]);
    }
    return '';
}

function fetch_youtube_meta(string $url): array
{
    $api = 'https://www.youtube.com/oembed?url=' . rawurlencode($url) . '&format=json';
    $json = import_fetch_url($api);
    $data = ['title' => '', 'description' => '', 'keywords' => ''];
    $d = $json ? json_decode($json, true) : null;
    if (is_array($d) && !empty($d['title'])) {
        $data['title'] = import_clean_meta_text((string) $d['title'], 255);
    }

    $html = import_fetch_url($url);
    if ($html) {
        $data['description'] = import_meta_content($html, 'name', 'description')
            ?: import_meta_content($html, 'property', 'og:description');
        $data['keywords'] = import_meta_content($html, 'name', 'keywords');
        if (preg_match('~ytInitialPlayerResponse\s*=\s*({.+?});~s', $html, $m)) {
            $player = json_decode($m[1], true);
            $details = is_array($player) ? ($player['videoDetails'] ?? []) : [];
            if (is_array($details)) {
                if ($data['description'] === '' && !empty($details['shortDescription'])) {
                    $data['description'] = import_clean_meta_text((string) $details['shortDescription']);
                }
                if ($data['keywords'] === '' && !empty($details['keywords']) && is_array($details['keywords'])) {
                    $data['keywords'] = import_clean_meta_text(implode(', ', $details['keywords']));
                }
                if ($data['title'] === '' && !empty($details['title'])) {
                    $data['title'] = import_clean_meta_text((string) $details['title'], 255);
                }
            }
        }
        if ($data['title'] === '') {
            $data['title'] = import_meta_content($html, 'property', 'og:title')
                ?: import_meta_content($html, 'name', 'title');
        }
    }

    return $data;
}

function import_first_keyword(string $keywords, string $fallbackTitle): string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $keywords)), fn($v) => $v !== ''));
    $focus = $parts[0] ?? $fallbackTitle;
    return import_clean_meta_text($focus, 120);
}

function import_write_youtube_ai(PDO $pdo, int $postId, string $title, string $summary, string $keywords, string $youtubeId): array
{
    if ($youtubeId === '' || !function_exists('ai_rewrite_blog_post')) {
        return ['ok' => false, 'message' => 'skip'];
    }

    $seed = trim($summary);
    if ($keywords !== '') {
        $seed .= "\n\nTu khoa tham khao: " . $keywords;
    }
    $seed = trim($seed);

    $article = ai_rewrite_blog_post($title, $seed, $youtubeId);
    if (empty($article['ok'])) {
        return ['ok' => false, 'message' => (string) ($article['error'] ?? 'ai_error')];
    }

    $newContent = (string) ($article['content'] ?? '');
    $newSummary = trim((string) ($article['description'] ?? ''));
    if ($newSummary === '') {
        $newSummary = $summary;
    }

    $seo = function_exists('ai_generate_seo')
        ? ai_generate_seo($title, $newSummary, strip_tags($newContent))
        : ['ok' => false];
    $seoOk = !empty($seo['ok']);
    if ($seoOk) {
        $kw = is_array($seo['meta_keywords'] ?? null)
            ? implode(', ', $seo['meta_keywords'])
            : (string) ($seo['meta_keywords'] ?? '');
        $pdo->prepare("UPDATE posts SET content=?, summary=?, meta_title=?, meta_description=?, meta_keywords=?, focus_keyword=?, updated_at=NOW() WHERE id=?")
            ->execute([$newContent, $newSummary, $seo['meta_title'], $seo['meta_description'], $kw, $seo['focus_keyword'], $postId]);
        if ($kw !== '' && function_exists('blog_sync_post_tags')) {
            blog_sync_post_tags($pdo, $postId, $kw);
        }
        return ['ok' => true, 'message' => 'content_seo'];
    }

    $pdo->prepare("UPDATE posts SET content=?, summary=?, updated_at=NOW() WHERE id=?")
        ->execute([$newContent, $newSummary, $postId]);
    return ['ok' => true, 'message' => 'content_only'];
}

$created = 0; $skipped = 0; $items = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    $title = $line;
    $content = '';
    $thumb = '';
    $summary = '';
    $metaDescription = '';
    $metaKeywords = '';
    $focusKeyword = '';
    $ytId = function_exists('blog_youtube_id') ? blog_youtube_id($line) : null;
    if ($ytId) {
        $ytMeta = fetch_youtube_meta($line);
        $ytTitle = $ytMeta['title'] ?? '';
        $title = $ytTitle ?: ('Video YouTube ' . $ytId);
        $summary = import_clean_meta_text((string) ($ytMeta['description'] ?? ''), 600);
        $metaDescription = import_clean_meta_text((string) ($ytMeta['description'] ?? ''), 160);
        $metaKeywords = import_clean_meta_text((string) ($ytMeta['keywords'] ?? ''), 500);
        $focusKeyword = import_first_keyword($metaKeywords, $title);
        if (function_exists('blog_youtube_iframe')) $content = blog_youtube_iframe($ytId);
        // Ảnh đại diện = thumbnail video (giống plugin WordPress)
        $thumb = blog_import_youtube_thumb($pdo, $ytId, $title) ?: '';
    }
    $title = mb_substr(trim($title), 0, 255, 'UTF-8');
    if ($title === '') { $skipped++; continue; }

    $slug = create_slug($title);
    if ($slug === '') $slug = 'bai-viet-' . substr(uniqid(), -6);
    $c = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE slug = ?");
    $c->execute([$slug]);
    if ((int) $c->fetchColumn() > 0) $slug .= '-' . substr(uniqid(), -4);

    try {
        $pdo->prepare("INSERT INTO posts (title, slug, summary, content, status, schema_type, thumbnail, author_name, meta_description, meta_keywords, focus_keyword, created_at, updated_at)
                       VALUES (?, ?, ?, ?, 0, 'BlogPosting', ?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$title, $slug, $summary, $content, $thumb, $author, $metaDescription, $metaKeywords, $focusKeyword]);
        $newId = (int) $pdo->lastInsertId();
        if ($metaKeywords !== '' && function_exists('blog_sync_post_tags')) {
            blog_sync_post_tags($pdo, $newId, $metaKeywords);
        }
        $aiStatus = '';
        if ($ytId) {
            $ai = import_write_youtube_ai($pdo, $newId, $title, $summary, $metaKeywords, $ytId);
            $aiStatus = !empty($ai['ok']) ? (string) $ai['message'] : ('error:' . (string) ($ai['message'] ?? 'ai_error'));
        }
        $created++;
        $items[] = ['id' => $newId, 'title' => $title, 'edit_url' => 'edit.php?id=' . $newId, 'ai' => $aiStatus];
    } catch (Throwable $e) {
        $skipped++;
    }
}

if ($created > 0 && class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
if ($created > 0 && function_exists('log_activity')) log_activity('import', 'post', null, "Nhập $created bài nháp từ ý tưởng/YouTube");

echo json_encode(['success' => true, 'created' => $created, 'skipped' => $skipped, 'items' => $items], JSON_UNESCAPED_UNICODE);
