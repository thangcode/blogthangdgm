<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

require_valid_csrf_token(true);

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Không nhận được file upload.'
    ]);
    exit;
}

$file = $_FILES['file'];

$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Ảnh vượt quá dung lượng 5MB.'
    ]);
    exit;
}

$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Định dạng ảnh không hợp lệ. Chỉ hỗ trợ JPG, PNG, GIF, WEBP.'
    ]);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowedMime, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'File upload không phải ảnh hợp lệ.'
    ]);
    exit;
}

// Xác thực nội dung là ảnh THẬT (chống file giả mạo đuôi/MIME)
$imgInfo = @getimagesize($file['tmp_name']);
if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Nội dung file không phải ảnh hợp lệ.'
    ]);
    exit;
}

$uploadDir = ROOT_PATH . 'assets/uploads/editor/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Không thể tạo thư mục upload.'
    ]);
    exit;
}

$safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
$safeBase = trim($safeBase, '-');
if ($safeBase === '') {
    $safeBase = 'image';
}

$newName = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeBase . '.' . $ext;
$targetPath = $uploadDir . $newName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Upload ảnh thất bại.'
    ]);
    exit;
}

$relativePath = 'assets/uploads/editor/' . $newName;

// Re-encode bằng GD để loại payload nhúng (chống RCE) nếu có thể
if (function_exists('sanitize_uploaded_image')) {
    @sanitize_uploaded_image($targetPath, $mime);
}
// Đăng ký vào Thư viện Media để quản lý/tái sử dụng
if (function_exists('register_media_file')) {
    @register_media_file($pdo, $targetPath, $relativePath, $file['name']);
}

echo json_encode([
    'success' => true,
    'url' => BASE_URL . $relativePath,
    'name' => $newName
], JSON_UNESCAPED_UNICODE);
exit;
