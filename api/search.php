<?php
/**
 * api/search.php — Tìm kiếm bài viết (AJAX, trả JSON).
 * GET q : từ khóa. Trả tối đa 8 gợi ý + tổng số kết quả.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/url-helper.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$q = trim((string) ($_GET['q'] ?? ''));
if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) < 2) {
    echo json_encode(['success' => true, 'items' => [], 'total' => 0, 'q' => $q], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $like = '%' . $q . '%';
    $st = $pdo->prepare("SELECT id, title, slug, summary, thumbnail FROM posts
                         WHERE status = 1 AND deleted_at IS NULL AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)
                         ORDER BY (title LIKE ?) DESC, created_at DESC LIMIT 8");
    $st->execute([$like, $like, $like, $like]);
    $rows = $st->fetchAll();

    $cs = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE status = 1 AND deleted_at IS NULL AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)");
    $cs->execute([$like, $like, $like]);
    $total = (int) $cs->fetchColumn();
} catch (Throwable $e) {
    error_log('search.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'items' => [], 'total' => 0]);
    exit;
}

$items = [];
foreach ($rows as $r) {
    $img = !empty($r['thumbnail']) ? get_image_url($r['thumbnail'], 'news') : '';
    $items[] = [
        'name'  => (string) $r['title'],
        'url'   => postUrl($r['slug']),
        'image' => $img,
        'desc'  => mb_substr(strip_tags((string) ($r['summary'] ?? '')), 0, 80, 'UTF-8'),
    ];
}

echo json_encode(['success' => true, 'items' => $items, 'total' => $total, 'q' => $q], JSON_UNESCAPED_UNICODE);
