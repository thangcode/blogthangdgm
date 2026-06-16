<?php
// admin/users/toggle-active.php - Khóa/Mở khóa tài khoản admin
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_admin_login();
ensure_admin_security_columns($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'admin/users/index.php');
    exit;
}

require_valid_csrf_token();

$id = (int) ($_POST['id'] ?? 0);

// Không cho tự khóa chính mình
if ($id === (int) $_SESSION['user_id']) {
    header('Location: ' . BASE_URL . 'admin/users/index.php?error=cannot_delete_self');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT username, is_active FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u) {
        header('Location: ' . BASE_URL . 'admin/users/index.php?error=not_found');
        exit;
    }

    $new = ((int) ($u['is_active'] ?? 1) === 1) ? 0 : 1;

    // Không cho khóa admin cuối cùng đang hoạt động
    if ($new === 0) {
        $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($activeCount <= 1) {
            header('Location: ' . BASE_URL . 'admin/users/index.php?error=last_admin');
            exit;
        }
    }

    $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new, $id]);
    if (function_exists('log_activity')) {
        log_activity('update', 'user', $id, ($new ? 'Mở khóa' : 'Khóa') . ' tài khoản: ' . $u['username']);
    }
} catch (PDOException $e) {
    error_log('Toggle active error: ' . $e->getMessage());
    header('Location: ' . BASE_URL . 'admin/users/index.php?error=delete_failed');
    exit;
}

header('Location: ' . BASE_URL . 'admin/users/index.php?success=updated');
exit;
