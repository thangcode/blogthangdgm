<?php
// admin/ajax/get_recent_registrations.php
// Trả về danh sách liên hệ gần đây (bảng contacts) cho dropdown ở thanh admin.
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT id, name, city, message, status, created_at
                         FROM contacts
                         ORDER BY created_at DESC
                         LIMIT 8");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // tinyint status (0/1/2) -> nhãn tương thích với JS dropdown (pending/completed)
    $statusMap = [0 => 'pending', 1 => 'contacted', 2 => 'completed'];

    $data = array_map(function ($r) use ($statusMap) {
        $name = trim((string) ($r['name'] ?? '')) ?: 'Khách liên hệ';
        $excerpt = trim((string) ($r['message'] ?? ''));
        if ($excerpt !== '' && function_exists('mb_substr')) {
            $excerpt = mb_substr($excerpt, 0, 50, 'UTF-8');
        }
        $svc = $excerpt !== '' ? $excerpt : (trim((string) ($r['city'] ?? '')) ?: 'Liên hệ chung');
        return [
            'fullname'     => $name,
            'service_name' => $svc,
            'product_name' => '',
            'status'       => $statusMap[(int) ($r['status'] ?? 0)] ?? 'pending',
            'created_at'   => $r['created_at'] ?? date('Y-m-d H:i:s'),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_recent_registrations error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'error']);
}
