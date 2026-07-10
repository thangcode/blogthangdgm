<?php
/**
 * admin/ai/provider-save.php — Thêm/sửa provider (AJAX).
 * POST: id(0=thêm), name, api_type, endpoint, api_key(trống=giữ cũ), status, sort_order, csrf_token
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

$id        = (int) ($_POST['id'] ?? 0);
$name      = trim((string) ($_POST['name'] ?? ''));
$api_type  = (string) ($_POST['api_type'] ?? 'openai');
$endpoint  = trim((string) ($_POST['endpoint'] ?? ''));
$api_key   = trim((string) ($_POST['api_key'] ?? ''));
$status    = isset($_POST['status']) && $_POST['status'] !== '0' ? 1 : 0;
$sort      = (int) ($_POST['sort_order'] ?? 0);

if ($name === '' || $endpoint === '') {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập tên và endpoint.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($api_type, ['openai', 'anthropic'], true)) { $api_type = 'openai'; }

try {
    if ($id > 0) {
        // Sửa: chỉ cập nhật key khi có nhập mới.
        if ($api_key !== '') {
            $pdo->prepare("UPDATE ai_providers SET name=?, api_type=?, endpoint=?, api_key_enc=?, status=?, sort_order=? WHERE id=?")
                ->execute([$name, $api_type, $endpoint, app_encrypt($api_key), $status, $sort, $id]);
        } else {
            $pdo->prepare("UPDATE ai_providers SET name=?, api_type=?, endpoint=?, status=?, sort_order=? WHERE id=?")
                ->execute([$name, $api_type, $endpoint, $status, $sort, $id]);
        }
        $msg = 'Đã cập nhật provider.';
    } else {
        $pdo->prepare("INSERT INTO ai_providers (name, api_type, endpoint, api_key_enc, status, sort_order) VALUES (?,?,?,?,?,?)")
            ->execute([$name, $api_type, $endpoint, app_encrypt($api_key), $status, $sort]);
        $id = (int) $pdo->lastInsertId();
        $msg = 'Đã thêm provider.';
    }
    if (function_exists('log_activity')) log_activity('ai_provider_save', 'ai_provider', $id, $name);
    echo json_encode(['success' => true, 'message' => $msg, 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('provider-save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi lưu provider.'], JSON_UNESCAPED_UNICODE);
}
