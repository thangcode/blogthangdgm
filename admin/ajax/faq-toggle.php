<?php
// admin/ajax/faq-toggle.php
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

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {
    // Toggle status
    $stmt = $pdo->prepare("UPDATE faqs SET status = 1 - status WHERE id = ?");
    $stmt->execute([$id]);

    // Get new status
    $stmt = $pdo->prepare("SELECT status FROM faqs WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    echo json_encode(['success' => true, 'status' => (int) $row['status']]);
} catch (PDOException $e) {
    error_log('FAQ toggle error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
