<?php
// router.php — phân giải URL root 1 đoạn theo thứ tự ưu tiên:
//   bài viết -> short link -> redirect cũ (slug_redirects) -> 404
require_once 'config/database.php';
require_once 'includes/functions.php';

$path = isset($_GET['path']) ? trim((string) $_GET['path'], '/') : '';

if ($path === '' || strpos($path, '/') !== false) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// 1) Bài viết theo slug (ưu tiên cao nhất)
try {
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE slug = ? AND status = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$path]);
    if ($stmt->fetchColumn()) {
        $_GET['slug'] = $path;
        require __DIR__ . '/post.php';
        exit;
    }
} catch (Throwable $e) {
    // fail an toàn -> thử bước kế
}

// 2) Trang tĩnh (page) theo slug
try {
    $stmt = $pdo->prepare("SELECT id FROM pages WHERE slug = ? AND status = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$path]);
    if ($stmt->fetchColumn()) {
        $_GET['slug'] = $path;
        require __DIR__ . '/page.php';
        exit;
    }
} catch (Throwable $e) {
    // bảng có thể chưa có -> bỏ qua
}

// 3) Short link đang bật -> delegate short.php (xử lý redirect + log click)
try {
    $stmt = $pdo->prepare("SELECT id FROM short_links WHERE slug = ? AND status = 1 LIMIT 1");
    $stmt->execute([$path]);
    if ($stmt->fetchColumn()) {
        $_GET['slug'] = $path;
        require __DIR__ . '/short.php';
        exit;
    }
} catch (Throwable $e) {
    // bảng có thể chưa có -> bỏ qua
}

// 3) Redirect URL cũ (301) nếu có ánh xạ
try {
    $stmt = $pdo->prepare("SELECT new_path FROM slug_redirects WHERE old_path = ? OR old_path = ? LIMIT 1");
    $stmt->execute(['/' . $path, '/' . $path . '/']);
    $new = $stmt->fetchColumn();
    if ($new) {
        header('Location: ' . $new, true, 301);
        exit;
    }
} catch (Throwable $e) {
    // bỏ qua
}

// 4) Không khớp -> 404
http_response_code(404);
require __DIR__ . '/404.php';
exit;
