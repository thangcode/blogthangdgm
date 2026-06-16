<?php
// admin/ajax/clear-cache.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/page-cache.php';

header('Content-Type: application/json');

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_valid_csrf_token(true);

try {
    $version = (string) time();

    $stmt = $pdo->prepare('SELECT id FROM settings WHERE setting_key = ?');
    $stmt->execute(['cache_version']);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $stmt->execute([$version, 'cache_version']);
    } else {
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)');
        $stmt->execute(['cache_version', $version, 'performance']);
    }

    // Xóa file cache HTML (page cache)
    $flushed = PageCache::flush();

    // Reset PHP OPCache để luôn dùng code PHP mới nhất sau khi clear cache.
    $opcacheReset = null;
    if (function_exists('opcache_reset')) {
        $opcacheReset = @opcache_reset();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Đã xóa cache thành công! Phiên bản mới: v' . $version
                   . ($flushed > 0 ? " (đã xóa {$flushed} file page cache)" : '')
                   . ($opcacheReset === true ? ' (OPCache đã reset)' : ($opcacheReset === false ? ' (không thể reset OPCache)' : '')),
        'version' => $version
    ]);
} catch (Exception $e) {
    error_log('Clear cache error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Không thể xóa cache lúc này.']);
}
