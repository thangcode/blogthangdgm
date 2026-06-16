<?php
// admin/banners/delete.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';

if ($id <= 0) {
    $_SESSION['error'] = "Invalid banner ID.";
} elseif (!verify_csrf_token($csrfToken)) {
    $_SESSION['error'] = "Invalid security token.";
} elseif ($id > 0) {
    try {
        // Get banner info to delete images
        $stmt = $pdo->prepare("SELECT image_path, mobile_image_path FROM banners WHERE id = ?");
        $stmt->execute([$id]);
        $banner = $stmt->fetch();

        if ($banner) {
            // Delete record
            $delStmt = $pdo->prepare("DELETE FROM banners WHERE id = ?");
            $delStmt->execute([$id]);
            if (function_exists('log_activity')) {
                log_activity('delete', 'banner', $id, "Xóa banner ID: $id");
            }

            // Delete files
            if (!empty($banner['image_path']) && file_exists('../../' . $banner['image_path'])) {
                unlink('../../' . $banner['image_path']);
            }
            if (!empty($banner['mobile_image_path']) && file_exists('../../' . $banner['mobile_image_path'])) {
                unlink('../../' . $banner['mobile_image_path']);
            }

            $_SESSION['success'] = "Đã xóa banner thành công.";
        } else {
            $_SESSION['error'] = "Banner không tồn tại.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Lỗi xóa banner: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "ID không hợp lệ.";
}

header("Location: index.php");
exit;
?>
