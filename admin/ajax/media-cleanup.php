<?php
// admin/ajax/media-cleanup.php — Dọn dẹp record media mồ côi (file không tồn tại trên disk)
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Auth check
if (!is_admin_logged_in()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF check
require_valid_csrf_token(true);

try {
    ensure_media_library_table($pdo);

    $stmt = $pdo->query("SELECT id, file_path, original_name FROM media_library ORDER BY id DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);

    $orphans = [];
    $ok = 0;

    foreach ($rows as $row) {
        $abs = ROOT_PATH . $row['file_path'];
        if (!file_exists($abs)) {
            $orphans[] = [
                'id' => (int) $row['id'],
                'name' => $row['original_name'],
                'path' => $row['file_path'],
            ];
            $pdo->prepare("DELETE FROM media_library WHERE id = ?")->execute([$row['id']]);
        } else {
            $ok++;
        }
    }

    if (function_exists('log_activity')) {
        log_activity('cleanup', 'media_library', null, 'Dọn dẹp media: ' . count($orphans) . ' orphan đã xóa / ' . $total . ' tổng');
    }

    echo json_encode([
        'success' => true,
        'total_scanned' => $total,
        'ok_count' => $ok,
        'orphan_count' => count($orphans),
        'orphans' => array_slice($orphans, 0, 50), // Chỉ trả về tối đa 50 cái
        'message' => count($orphans) > 0
            ? 'Đã xóa ' . count($orphans) . ' record mồ côi.'
            : 'Không tìm thấy record mồ côi nào. Thư viện sạch!',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
