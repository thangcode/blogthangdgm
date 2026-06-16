<?php
// admin/ajax/sort-banners.php
// AJAX endpoint to update banner sort order via drag-and-drop

session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

require_admin_login();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

require_valid_csrf_token(true);

// Get order data
$input = json_decode(file_get_contents('php://input'), true);
$order = $input['order'] ?? [];

if (empty($order) || !is_array($order)) {
    echo json_encode(['success' => false, 'message' => 'No order data']);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE banners SET sort_order = ? WHERE id = ?");
    foreach ($order as $index => $id) {
        $stmt->execute([$index + 1, (int)$id]);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Đã cập nhật thứ tự']);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Banner sort error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Không thể cập nhật thứ tự banner.']);
}
