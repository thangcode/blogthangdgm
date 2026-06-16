<?php
// admin/users/delete.php - Xóa Admin
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_admin_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'admin/users/index.php');
    exit;
}

require_valid_csrf_token();

$id = (int)($_POST['id'] ?? 0);

// Không cho xóa chính mình
if ($id === (int)$_SESSION['user_id']) {
    header('Location: ' . BASE_URL . 'admin/users/index.php?error=cannot_delete_self');
    exit;
}

// Không cho xóa nếu chỉ còn 1 admin
$count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($count <= 1) {
    header('Location: ' . BASE_URL . 'admin/users/index.php?error=last_admin');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if ($user) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        if (function_exists('log_activity')) {
            log_activity('delete', 'user', $id, 'Xóa admin: ' . $user['username']);
        }
    }
}
catch (PDOException $e) {
// Silently ignore
}

header('Location: ' . BASE_URL . 'admin/users/index.php?success=deleted');
exit;
