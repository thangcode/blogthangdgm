<?php
/**
 * migrate_llm_providers.php
 * Nâng cấp cấu hình AI sang mô hình Provider / Model + gán model theo tính năng.
 *  - Tạo bảng ai_providers, ai_models nếu chưa có.
 *  - Nếu chưa có provider nào: tạo provider "CLIProxy" từ llm_endpoint/llm_api_key hiện có,
 *    tạo 2 model chat từ llm_model + llm_model_fallback, gán vào write/seo/vision.
 *  - Đảm bảo các setting tham số chung tồn tại.
 * An toàn chạy nhiều lần (idempotent).
 *
 * Chạy: php scripts/migrate_llm_providers.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
$pdo->exec("SET NAMES utf8mb4");

function out($m) { echo $m . PHP_EOL; }

function get_val(PDO $pdo, string $key): ?string {
    $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}
function set_val(PDO $pdo, string $key, string $value, string $group = 'ai'): void {
    $s = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
    $s->execute([$key]);
    if ($s->fetch()) {
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)")->execute([$key, $value, $group]);
    }
}
function ensure_val(PDO $pdo, string $key, string $default, string $group = 'ai'): void {
    if (get_val($pdo, $key) === null) { set_val($pdo, $key, $default, $group); }
}

// 1) Tạo bảng ---------------------------------------------------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS `ai_providers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `api_type` VARCHAR(20) NOT NULL DEFAULT 'openai',
    `endpoint` VARCHAR(255) NOT NULL DEFAULT '',
    `api_key_enc` TEXT,
    `status` TINYINT NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
out('[OK] Bảng ai_providers');

$pdo->exec("CREATE TABLE IF NOT EXISTS `ai_models` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `provider_id` INT NOT NULL,
    `model_name` VARCHAR(120) NOT NULL,
    `label` VARCHAR(120) NOT NULL DEFAULT '',
    `kind` VARCHAR(20) NOT NULL DEFAULT 'chat',
    `can_vision` TINYINT NOT NULL DEFAULT 0,
    `status` TINYINT NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_provider` (`provider_id`),
    KEY `idx_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
out('[OK] Bảng ai_models');

// 2) Tham số chung ----------------------------------------------------------
ensure_val($pdo, 'llm_temperature', '0.6');
ensure_val($pdo, 'llm_max_tokens', '1200');
ensure_val($pdo, 'ai_image_size', '1024x1024');
ensure_val($pdo, 'ai_vision_enabled', '1');
foreach (['write', 'seo', 'vision', 'image'] as $f) {
    ensure_val($pdo, 'ai_' . $f . '_primary', '0');
    ensure_val($pdo, 'ai_' . $f . '_fallback', '0');
}
out('[OK] Đảm bảo các setting tham số chung + gán tính năng');

// 3) Migrate provider/model từ cấu hình cũ ----------------------------------
$provCount = (int) $pdo->query("SELECT COUNT(*) FROM ai_providers")->fetchColumn();
if ($provCount > 0) {
    out('[SKIP] Đã có ' . $provCount . ' provider — không tạo tự động.');
    out('==> HOÀN TẤT.');
    return;
}

$oldEndpoint = trim((string) get_val($pdo, 'llm_endpoint'));
$oldKey      = trim((string) get_val($pdo, 'llm_api_key'));
$oldModel    = trim((string) get_val($pdo, 'llm_model'));
$oldFallback = trim((string) get_val($pdo, 'llm_model_fallback'));

if ($oldEndpoint === '' || $oldKey === '') {
    out('[SKIP] Chưa có llm_endpoint/llm_api_key cũ để migrate. Hãy thêm provider thủ công trong admin.');
    out('==> HOÀN TẤT.');
    return;
}

$keyEnc = app_encrypt($oldKey);
$pdo->prepare("INSERT INTO ai_providers (name, api_type, endpoint, api_key_enc, status, sort_order) VALUES (?, 'openai', ?, ?, 1, 0)")
    ->execute(['CLIProxy', $oldEndpoint, $keyEnc]);
$providerId = (int) $pdo->lastInsertId();
out('[OK] Tạo provider CLIProxy (id=' . $providerId . ') endpoint=' . $oldEndpoint);

$insModel = $pdo->prepare("INSERT INTO ai_models (provider_id, model_name, label, kind, can_vision, status, sort_order) VALUES (?, ?, ?, 'chat', 0, 1, ?)");

$primaryId = 0;
$fallbackId = 0;
if ($oldModel !== '') {
    $insModel->execute([$providerId, $oldModel, $oldModel, 0]);
    $primaryId = (int) $pdo->lastInsertId();
    out('[OK] Model chính: ' . $oldModel . ' (id=' . $primaryId . ')');
}
if ($oldFallback !== '' && $oldFallback !== $oldModel) {
    $insModel->execute([$providerId, $oldFallback, $oldFallback, 1]);
    $fallbackId = (int) $pdo->lastInsertId();
    out('[OK] Model dự phòng: ' . $oldFallback . ' (id=' . $fallbackId . ')');
}

// 4) Gán vào các tính năng chat (write/seo/vision) --------------------------
foreach (['write', 'seo', 'vision'] as $f) {
    if ($primaryId > 0)  set_val($pdo, 'ai_' . $f . '_primary', (string) $primaryId);
    if ($fallbackId > 0) set_val($pdo, 'ai_' . $f . '_fallback', (string) $fallbackId);
}
out('[OK] Gán model vào tính năng: write, seo, vision (image để trống — chưa có model image).');

out('==> HOÀN TẤT nâng cấp Provider/Model.');
out('   provider_id=' . $providerId . ' primary=' . $primaryId . ' fallback=' . $fallbackId);
