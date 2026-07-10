<?php
/**
 * admin/ai/model-delete.php — Xóa model (AJAX). Gỡ luôn khỏi các gán tính năng.
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
    $pdo->prepare("DELETE FROM ai_models WHERE id = ?")->execute([$id]);
    // Gỡ model khỏi các setting gán tính năng (đặt về 0).
    $keys = [];
    foreach (['write', 'seo', 'vision', 'image'] as $f) {
        $keys[] = 'ai_' . $f . '_primary';
        $keys[] = 'ai_' . $f . '_fallback';
    }
    $ph = implode(',', array_fill(0, count($keys), '?'));
    $params = array_merge([(string) $id], $keys);
    $pdo->prepare("UPDATE settings SET setting_value='0' WHERE setting_value=? AND setting_key IN ($ph)")->execute($params);

    if (function_exists('log_activity')) log_activity('ai_model_delete', 'ai_model', $id);
    echo json_encode(['success' => true, 'message' => 'Đã xóa model.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('model-delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi xóa model.'], JSON_UNESCAPED_UNICODE);
}
