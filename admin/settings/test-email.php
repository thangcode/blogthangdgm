<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/mailer.php';

if (!is_admin_logged_in()) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

require_valid_csrf_token(true);

$toEmail = trim((string)get_setting('smtp_to_email', get_setting('contact_email', '')));
$toEmail2 = trim((string)get_setting('smtp_to_email_2', ''));
$toEmail3 = trim((string)get_setting('smtp_to_email_3', ''));
$siteName = trim((string)get_setting('site_name', 'ShopSieuSale'));
$host = trim((string)get_setting('smtp_host', ''));
$port = trim((string)get_setting('smtp_port', ''));
$secure = trim((string)get_setting('smtp_secure', 'tls'));

// Danh sách email nhận (bỏ email trống)
$allRecipients = array_filter([$toEmail, $toEmail2, $toEmail3], fn($e) => $e !== '');
$targetDisplay = implode(', ', $allRecipients);

$result = send_smtp_email(
    '[' . $siteName . '] SMTP test',
    '<p>Email test SMTP from admin settings.</p><p>Time: <strong>' . date('Y-m-d H:i:s') . '</strong></p>',
    'Email test SMTP from admin settings. Time: ' . date('Y-m-d H:i:s'),
    '',
    '',
    ['debug' => true]
);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Gửi email test thành công.',
        'target' => $targetDisplay,
        'smtp' => $host . ':' . $port . ' (' . $secure . ')',
        'site' => $siteName
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Gửi email test thất bại: ' . $result['message'],
    'target' => $targetDisplay,
    'smtp' => $host . ':' . $port . ' (' . $secure . ')',
    'debug' => trim((string)($result['debug'] ?? ''))
]);
