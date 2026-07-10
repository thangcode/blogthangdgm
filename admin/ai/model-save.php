<?php
/**
 * admin/ai/model-save.php — Thêm/sửa model (AJAX).
 * POST: id(0=thêm), provider_id, model_name, label, kind, can_vision, status, sort_order, csrf_token
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

$id          = (int) ($_POST['id'] ?? 0);
$provider_id = (int) ($_POST['provider_id'] ?? 0);
$model_name  = trim((string) ($_POST['model_name'] ?? ''));
$label       = trim((string) ($_POST['label'] ?? ''));
$kind        = (string) ($_POST['kind'] ?? 'chat');
$can_vision  = isset($_POST['can_vision']) && $_POST['can_vision'] !== '0' ? 1 : 0;
$status      = isset($_POST['status']) && $_POST['status'] !== '0' ? 1 : 0;
$sort        = (int) ($_POST['sort_order'] ?? 0);

if ($provider_id <= 0 || $model_name === '') {
    echo json_encode(['success' => false, 'message' => 'Vui lòng chọn provider và nhập tên model.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($kind, ['chat', 'image'], true)) { $kind = 'chat'; }
if ($kind === 'image') { $can_vision = 0; }
if ($label === '') { $label = $model_name; }

try {
    // Provider phải tồn tại.
    $chk = $pdo->prepare("SELECT id FROM ai_providers WHERE id = ? LIMIT 1");
    $chk->execute([$provider_id]);
    if (!$chk->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Provider không tồn tại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE ai_models SET provider_id=?, model_name=?, label=?, kind=?, can_vision=?, status=?, sort_order=? WHERE id=?")
            ->execute([$provider_id, $model_name, $label, $kind, $can_vision, $status, $sort, $id]);
        $msg = 'Đã cập nhật model.';
    } else {
        $pdo->prepare("INSERT INTO ai_models (provider_id, model_name, label, kind, can_vision, status, sort_order) VALUES (?,?,?,?,?,?,?)")
            ->execute([$provider_id, $model_name, $label, $kind, $can_vision, $status, $sort]);
        $id = (int) $pdo->lastInsertId();
        $msg = 'Đã thêm model.';
    }
    if (function_exists('log_activity')) log_activity('ai_model_save', 'ai_model', $id, $label);
    echo json_encode(['success' => true, 'message' => $msg, 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('model-save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi lưu model.'], JSON_UNESCAPED_UNICODE);
}
