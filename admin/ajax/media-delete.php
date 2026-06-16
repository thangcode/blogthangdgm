<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();
ensure_media_library_table($pdo);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token không hợp lệ.']);
    exit;
}

// Support both 'ids' (array) and 'id' (single)
$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
} elseif (isset($_POST['id'])) {
    $ids = [(int) $_POST['id']];
}

$ids = array_filter($ids, function ($id) {
    return $id > 0;
});

if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Không có ID nào được chọn.']);
    exit;
}

$deleted_count = 0;
$failed_items = [];

// Helper to check references
function check_references($pdo, $file_path)
{
    $refs = [];
    $checks = [
        'posts.thumbnail' => "SELECT COUNT(*) FROM posts WHERE thumbnail = ?",
        'banners.image_path' => "SELECT COUNT(*) FROM banners WHERE image_path = ?",
        'banners.mobile_image_path' => "SELECT COUNT(*) FROM banners WHERE mobile_image_path = ?",
        'settings.site_logo' => "SELECT COUNT(*) FROM settings WHERE setting_key = 'site_logo' AND setting_value = ?",
        'settings.site_favicon' => "SELECT COUNT(*) FROM settings WHERE setting_key = 'site_favicon' AND setting_value = ?",
        'settings.seo_default_og_image' => "SELECT COUNT(*) FROM settings WHERE setting_key = 'seo_default_og_image' AND setting_value = ?"
    ];

    foreach ($checks as $label => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $param = (strpos($sql, 'LIKE') !== false) ? '%' . $file_path . '%' : $file_path;
            $stmt->execute([$param]);
            if ($stmt->fetchColumn() > 0) {
                $refs[] = $label;
            }
        } catch (Exception $e) {
        }
    }
    return $refs;
}

foreach ($ids as $id) {
    $stmt = $pdo->prepare("SELECT id, original_name, file_path FROM media_library WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) {
        $failed_items[] = ['id' => $id, 'reason' => 'Không tìm thấy file.'];
        continue;
    }

    $file_path = $media['file_path'];
    $original_name = $media['original_name'];

    // Check usage
    $refs = check_references($pdo, $file_path);
    if (!empty($refs)) {
        $failed_items[] = [
            'id' => $id,
            'name' => $original_name,
            'reason' => 'Đang được sử dụng (' . implode(', ', $refs) . ')'
        ];
        continue;
    }

    // Attempt delete
    try {
        $pdo->beginTransaction();

        $stmt_del = $pdo->prepare("DELETE FROM media_library WHERE id = ?");
        $stmt_del->execute([$id]);

        if ($stmt_del->rowCount() === 1) {
            $abs_path = ROOT_PATH . $file_path;
            if (is_file($abs_path)) {
                @unlink($abs_path);
            }
            $pdo->commit();
            $deleted_count++;
        } else {
            $pdo->rollBack();
            $failed_items[] = ['id' => $id, 'name' => $original_name, 'reason' => 'Lỗi DB.'];
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        $failed_items[] = ['id' => $id, 'name' => $original_name, 'reason' => 'Lỗi hệ thống.'];
    }
}

$success = $deleted_count > 0 || empty($failed_items);
$message = "Đã xóa $deleted_count file.";
if (!empty($failed_items)) {
    $message .= " Có " . count($failed_items) . " file không thể xóa.";
}

echo json_encode([
    'success' => $success,
    'message' => $message,
    'deleted_count' => $deleted_count,
    'failed_items' => $failed_items
], JSON_UNESCAPED_UNICODE);
exit;
