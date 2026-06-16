<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/page-cache.php';
require_once '../includes/blog.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function post_rating_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    post_rating_json(['success' => false, 'message' => 'Phương thức không hợp lệ.'], 405);
}

require_valid_csrf_token(true);

if (trim((string) ($_POST['website'] ?? '')) !== '') {
    post_rating_json(['success' => true, 'message' => 'Cảm ơn bạn đã đánh giá.']);
}

$formTs = (int) ($_POST['form_ts'] ?? 0);
if ($formTs <= 0 || time() - $formTs < 3) {
    post_rating_json(['success' => false, 'message' => 'Vui lòng thử lại sau vài giây.'], 429);
}

$postId = (int) ($_POST['post_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? 0);
$name = trim((string) ($_POST['reviewer_name'] ?? ''));
$comment = trim((string) ($_POST['comment'] ?? ''));

if ($postId <= 0 || $rating < 1 || $rating > 5) {
    post_rating_json(['success' => false, 'message' => 'Vui lòng chọn số sao hợp lệ.'], 422);
}

$name = mb_substr(strip_tags($name), 0, 120, 'UTF-8');
$comment = mb_substr(strip_tags($comment), 0, 1000, 'UTF-8');

try {
    blog_ensure_post_ratings_schema($pdo);

    $postStmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND status = 1 LIMIT 1");
    $postStmt->execute([$postId]);
    if (!$postStmt->fetch()) {
        post_rating_json(['success' => false, 'message' => 'Bài viết không tồn tại.'], 404);
    }

    $ip = function_exists('get_client_ip_address') ? get_client_ip_address() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $identityHash = blog_rating_identity_hash();
    $uaHash = hash('sha256', $ua);

    if (function_exists('traffic_is_bot') && traffic_is_bot($ua, $ip)) {
        post_rating_json(['success' => true, 'message' => 'Cảm ơn bạn đã đánh giá.']);
    }

    $recent = $pdo->prepare("SELECT id FROM post_ratings WHERE identity_hash = ? AND updated_at >= (NOW() - INTERVAL 30 SECOND) LIMIT 1");
    $recent->execute([$identityHash]);
    if ($recent->fetch()) {
        post_rating_json(['success' => false, 'message' => 'Bạn thao tác hơi nhanh, vui lòng thử lại sau.'], 429);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO post_ratings
            (post_id, rating, reviewer_name, comment, identity_hash, ip_address, user_agent_hash, status, created_at, updated_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            reviewer_name = VALUES(reviewer_name),
            comment = VALUES(comment),
            ip_address = VALUES(ip_address),
            user_agent_hash = VALUES(user_agent_hash),
            status = 'approved',
            updated_at = NOW()"
    );
    $stmt->execute([
        $postId,
        $rating,
        $name,
        $comment,
        $identityHash,
        mb_substr($ip, 0, 45, 'UTF-8'),
        $uaHash,
    ]);

    if (class_exists('PageCache')) {
        PageCache::flush();
    }

    $summary = blog_post_rating_summary($pdo, $postId);
    post_rating_json([
        'success' => true,
        'message' => 'Cảm ơn bạn đã đánh giá bài viết.',
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    error_log('post-rating error: ' . $e->getMessage());
    post_rating_json(['success' => false, 'message' => 'Chưa thể lưu đánh giá lúc này.'], 500);
}
