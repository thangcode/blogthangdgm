<?php
/**
 * API: Log a contact conversion (Hotline / Zalo / Messenger)
 * Called via AJAX from app.js when user confirms a contact action.
 */
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

enforce_traffic_ip_block($pdo, ['json' => true]);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$csrfToken = get_request_csrf_token();
if ($csrfToken !== '' && !verify_csrf_token($csrfToken)) {
    http_response_code(204);
    exit;
}

$type = trim($_POST['type'] ?? '');
$label = trim($_POST['label'] ?? '');
$event = trim($_POST['event'] ?? '');
$page_url = trim($_POST['page_url'] ?? '');

$allowed_types = ['contact_hotline', 'contact_zalo', 'contact_messenger'];
if (!in_array($type, $allowed_types)) {
    http_response_code(204);
    exit;
}

log_conversion($type, [
    'contact_value' => $label,
], $event, $page_url);

http_response_code(204);
echo json_encode(['ok' => true]);
