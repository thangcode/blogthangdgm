<?php
// admin/ajax/backup.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/backup-manager.php';

require_admin_login();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$backupManager = new BackupManager($pdo);

if ($action === 'create') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    require_valid_csrf_token(true);
    // Increase limits for potentially large zip operations
    set_time_limit(600);
    ini_set('memory_limit', '512M');

    $filename = $backupManager->createFullBackup();
    if ($filename) {
        if (function_exists('log_activity')) {
            log_activity('create', 'backup', null, "Tạo bản sao lưu: $filename");
        }
        echo json_encode(['success' => true, 'filename' => $filename]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Lỗi khi tạo file backup. Hãy kiểm tra quyền ghi folder /backups.']);
    }
} elseif ($action === 'delete') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    require_valid_csrf_token(true);
    $filename = $_POST['filename'] ?? '';
    if ($backupManager->deleteBackup($filename)) {
        if (function_exists('log_activity')) {
            log_activity('delete', 'backup', null, "Xóa bản sao lưu: $filename");
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Không thể xóa file.']);
    }
} elseif ($action === 'get_status') {
    header('Content-Type: application/json');
    $status = $_SESSION['backup_status'] ?? ['message' => 'Đang chờ...', 'percent' => 0];
    echo json_encode($status);
} elseif ($action === 'download') {
    $filename = $_GET['filename'] ?? '';
    $filePath = dirname(dirname(__DIR__)) . '/backups/' . $filename;

    if (!empty($filename) && file_exists($filePath) && strpos($filename, '..') === false) {
        if (function_exists('log_activity')) {
            log_activity('download', 'backup', null, "Tải xuống bản sao lưu: $filename");
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Pragma: no-cache');
        readfile($filePath);
        exit;
    } else {
        die("File không tồn tại hoặc truy cập không hợp lệ.");
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.']);
}
