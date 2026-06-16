<?php
/**
 * admin/ajax/llm-test.php
 * Test nhanh kết nối LLM (OpenAI-compatible).
 * Cho phép override endpoint/api_key/model từ form chưa lưu để test trực tiếp.
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
require_valid_csrf_token(true);

// Override tạm thời nếu form gửi lên (chưa lưu vào DB)
$ov_endpoint = trim($_POST['endpoint'] ?? '');
$ov_key      = trim($_POST['api_key'] ?? '');
$ov_model    = trim($_POST['model'] ?? '');

$opts = [];
if ($ov_model !== '') { $opts['model'] = $ov_model; }
$opts['max_tokens'] = 20;
$opts['temperature'] = 0;

// Nếu có override endpoint/key, dùng llm_chat_raw để không phụ thuộc DB
if ($ov_endpoint !== '' || $ov_key !== '') {
    $endpoint = $ov_endpoint !== '' ? $ov_endpoint : (string) get_setting('llm_endpoint', '');
    $api_key  = $ov_key !== '' ? $ov_key : (string) get_setting('llm_api_key', '');
    $model    = $ov_model !== '' ? $ov_model : (string) get_setting('llm_model', 'gpt-4o-mini');
    $res = llm_chat_raw($endpoint, $api_key, $model, [
        ['role' => 'user', 'content' => 'Trả lời đúng 1 từ: OK'],
    ], ['max_tokens' => 20, 'temperature' => 0]);
} else {
    $res = llm_chat([
        ['role' => 'user', 'content' => 'Trả lời đúng 1 từ: OK'],
    ], $opts);
}

if (!empty($res['ok'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Kết nối thành công! Model: ' . ($res['model_used'] ?? '?') . ' — Phản hồi: ' . mb_substr($res['text'], 0, 40),
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Thất bại: ' . ($res['error'] ?? 'không rõ lỗi'),
    ], JSON_UNESCAPED_UNICODE);
}
