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
require_once '../../includes/page-cache.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

$raw = trim((string) ($_POST['ideas'] ?? ''));
if ($raw === '') { echo json_encode(['success' => false, 'message' => 'Chưa nhập ý tưởng nào.']); exit; }

$lines = preg_split('/\r\n|\r|\n/', $raw);
$author = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Admin');

function fetch_youtube_title(string $url): ?string
{
    $api = 'https://www.youtube.com/oembed?url=' . rawurlencode($url) . '&format=json';
    $json = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false]);
        $json = curl_exec($ch);
        curl_close($ch);
    }
    if (!$json) $json = @file_get_contents($api);
    if (!$json) return null;
    $d = json_decode($json, true);
    return is_array($d) && !empty($d['title']) ? (string) $d['title'] : null;
}

$created = 0; $skipped = 0; $items = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;

    $title = $line;
    $content = '';
    $thumb = '';
    $ytId = function_exists('blog_youtube_id') ? blog_youtube_id($line) : null;
    if ($ytId) {
        $ytTitle = fetch_youtube_title($line);
        $title = $ytTitle ?: ('Video YouTube ' . $ytId);
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
        $pdo->prepare("INSERT INTO posts (title, slug, summary, content, status, schema_type, thumbnail, author_name, created_at, updated_at)
                       VALUES (?, ?, '', ?, 0, 'BlogPosting', ?, ?, NOW(), NOW())")
            ->execute([$title, $slug, $content, $thumb, $author]);
        $newId = (int) $pdo->lastInsertId();
        $created++;
        $items[] = ['id' => $newId, 'title' => $title, 'edit_url' => 'edit.php?id=' . $newId];
    } catch (Throwable $e) {
        $skipped++;
    }
}

if ($created > 0 && class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
if ($created > 0 && function_exists('log_activity')) log_activity('import', 'post', null, "Nhập $created bài nháp từ ý tưởng/YouTube");

echo json_encode(['success' => true, 'created' => $created, 'skipped' => $skipped, 'items' => $items], JSON_UNESCAPED_UNICODE);
