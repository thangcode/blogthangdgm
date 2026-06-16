<?php
// admin/ajax/widget-reorder.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_valid_csrf_token(true);

$input = json_decode(file_get_contents('php://input'), true);
$order = $input['order'] ?? [];

if (empty($order) || !is_array($order)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE widgets SET sort_order = ? WHERE id = ?");
    foreach ($order as $position => $id) {
        $stmt->execute([(int) $position + 1, (int) $id]);
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Widget reorder error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
