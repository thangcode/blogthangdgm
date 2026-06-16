<?php
/**
 * migrate_llm_unify.php
 * Hợp nhất cấu hình AI: chuyển setting Groq SEO cũ -> LLM dùng chung.
 *  - seo_groq_api_key  -> llm_api_key (nếu llm_api_key đang trống)
 *  - seo_groq_model    -> llm_model
 *  - Nếu llm_endpoint trống: mặc định endpoint Groq để flow cũ vẫn chạy
 * An toàn chạy nhiều lần (idempotent).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");
function out($m) { echo $m . PHP_EOL; }

function get_val($pdo, $key) {
    $s = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}
function set_val($pdo, $key, $value, $group = 'general') {
    $s = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
    $s->execute([$key]);
    if ($s->fetch()) {
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)")->execute([$key, $value, $group]);
    }
}

$groq_key   = trim((string) get_val($pdo, 'seo_groq_api_key'));
$groq_model = trim((string) get_val($pdo, 'seo_groq_model'));
$llm_key    = trim((string) get_val($pdo, 'llm_api_key'));
$llm_ep     = trim((string) get_val($pdo, 'llm_endpoint'));
$llm_model  = trim((string) get_val($pdo, 'llm_model'));

// Endpoint mặc định: nếu trống -> dùng Groq (giữ flow cũ chạy ngay)
if ($llm_ep === '') {
    set_val($pdo, 'llm_endpoint', 'https://api.groq.com/openai/v1');
    out('[OK] llm_endpoint = https://api.groq.com/openai/v1 (mặc định)');
} else {
    out('[SKIP] llm_endpoint đã có: ' . $llm_ep);
}

// Key: copy từ Groq nếu LLM chưa có
if ($llm_key === '' && $groq_key !== '') {
    set_val($pdo, 'llm_api_key', $groq_key);
    out('[OK] Đã copy seo_groq_api_key -> llm_api_key');
} else {
    out('[SKIP] llm_api_key ' . ($llm_key !== '' ? 'đã có' : 'trống, không có Groq key để copy'));
}

// Model: copy từ Groq nếu LLM model trống/đang để mặc định khác
if ($groq_model !== '' && ($llm_model === '' || $llm_model === 'gpt-4o-mini')) {
    set_val($pdo, 'llm_model', $groq_model);
    set_val($pdo, 'llm_model_fallback', $groq_model);
    out('[OK] llm_model/llm_model_fallback = ' . $groq_model);
} else {
    out('[SKIP] llm_model giữ nguyên: ' . $llm_model);
}

// Đảm bảo temperature/max_tokens tồn tại
if (get_val($pdo, 'llm_temperature') === null) set_val($pdo, 'llm_temperature', '0.6');
if (get_val($pdo, 'llm_max_tokens') === null) set_val($pdo, 'llm_max_tokens', '1200');

out('==> HOÀN TẤT hợp nhất LLM');
out('   llm_endpoint = ' . get_val($pdo, 'llm_endpoint'));
out('   llm_model    = ' . get_val($pdo, 'llm_model'));
out('   llm_api_key  = ' . (trim((string) get_val($pdo, 'llm_api_key')) !== '' ? '(đã có)' : '(trống)'));
