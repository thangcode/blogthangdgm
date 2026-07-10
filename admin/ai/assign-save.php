<?php
/**
 * admin/ai/assign-save.php — Lưu gán model theo tính năng + tham số chung (AJAX).
 * POST: ai_write_primary, ai_write_fallback, ai_seo_primary, ..., ai_image_fallback,
 *       llm_temperature, llm_max_tokens, ai_image_size, ai_vision_enabled, csrf_token
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

function ai_upsert(PDO $pdo, string $key, string $value): void {
    $s = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
    $s->execute([$key]);
    if ($s->fetch()) {
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')")->execute([$key, $value]);
    }
}

try {
    // Gán model theo tính năng (lưu id, mặc định 0).
    foreach (['write', 'seo', 'vision', 'image'] as $f) {
        foreach (['primary', 'fallback'] as $slot) {
            $key = 'ai_' . $f . '_' . $slot;
            ai_upsert($pdo, $key, (string) (int) ($_POST[$key] ?? 0));
        }
    }

    // Tham số chung.
    $temp = (float) ($_POST['llm_temperature'] ?? 0.6);
    if ($temp < 0) $temp = 0; if ($temp > 2) $temp = 2;
    ai_upsert($pdo, 'llm_temperature', (string) $temp);

    $maxtok = (int) ($_POST['llm_max_tokens'] ?? 1200);
    if ($maxtok < 100) $maxtok = 100; if ($maxtok > 8000) $maxtok = 8000;
    ai_upsert($pdo, 'llm_max_tokens', (string) $maxtok);

    $size = trim((string) ($_POST['ai_image_size'] ?? '1024x1024'));
    if (!preg_match('/^\d{2,4}x\d{2,4}$/', $size)) { $size = '1024x1024'; }
    ai_upsert($pdo, 'ai_image_size', $size);

    ai_upsert($pdo, 'ai_vision_enabled', isset($_POST['ai_vision_enabled']) && $_POST['ai_vision_enabled'] !== '0' ? '1' : '0');

    if (function_exists('log_activity')) log_activity('ai_assign_save', 'settings', null, 'Cập nhật gán model theo tính năng');
    echo json_encode(['success' => true, 'message' => 'Đã lưu cấu hình gán model.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('assign-save error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi lưu cấu hình.'], JSON_UNESCAPED_UNICODE);
}
