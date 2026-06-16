<?php
/**
 * admin/ajax/groq-test.php
 * Test nhanh kết nối LLM dùng chung (giữ tên cũ để tương thích nút Test cũ).
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_valid_csrf_token(true);

$api_key = trim((string) get_setting('llm_api_key', ''));
$model   = trim((string) get_setting('llm_model', 'llama-3.3-70b-versatile'));

if ($api_key === '') {
    echo json_encode(['success' => false, 'error' => 'no_key', 'message' => 'Chưa có API key. Lưu cấu hình AI trước.']);
    exit;
}

$t0 = microtime(true);
$res = llm_chat([
    ['role' => 'user', 'content' => 'Say "OK" in one word.'],
], ['max_tokens' => 10, 'temperature' => 0]);
$latency = round((microtime(true) - $t0) * 1000);

if (!empty($res['ok'])) {
    $used = $res['model_used'] ?? $model;
    echo json_encode([
        'success' => true,
        'message' => "✓ Kết nối thành công! Model: {$used} | Latency: {$latency}ms",
        'latency' => $latency,
        'model' => $used,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Lỗi: ' . ($res['error'] ?? 'không rõ')], JSON_UNESCAPED_UNICODE);
}
