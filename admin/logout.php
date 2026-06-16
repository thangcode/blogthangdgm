<?php
// admin/logout.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

admin_clear_remember_me(isset($pdo) ? $pdo : null, $_SESSION['user_id'] ?? null);
$_SESSION = [];
session_destroy();
header("Location: login.php");
exit;
?>
