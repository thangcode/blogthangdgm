<?php
/**
 * admin/ai/provider-delete.php — Xóa provider + các model thuộc nó (AJAX).
 * POST: id, csrf_token
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Thiếu id.']); exit; }

try {
    $pdo->prepare("DELETE FROM ai_models WHERE provider_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM ai_providers WHERE id = ?")->execute([$id]);
    if (function_exists('log_activity')) log_activity('ai_provider_delete', 'ai_provider', $id);
    echo json_encode(['success' => true, 'message' => 'Đã xóa provider và các model liên quan.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('provider-delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi xóa provider.'], JSON_UNESCAPED_UNICODE);
}
