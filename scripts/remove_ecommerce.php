<?php
/**
 * remove_ecommerce.php
 * Gỡ sạch dữ liệu affiliate/sản phẩm (DEMO) khỏi DB. Idempotent.
 * - DROP các bảng sản phẩm/affiliate.
 * - Xóa các homepage_blocks liên quan sản phẩm.
 * - Xóa settings nhóm/khoá liên quan sản phẩm-affiliate.
 *
 * CHỈ tác động đúng các bảng/khoá liệt kê. Chạy: php scripts/remove_ecommerce.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");

function out($m) { echo $m . PHP_EOL; }

$drop_tables = [
    'product_affiliate_links',
    'product_clicks',
    'product_ratings',
    'product_registrations',
    'product_views',
    'products',
    'affiliate_platforms',
    'conversion_logs',
    'shopee_import_log',
];

out("=== GỠ E-COMMERCE (DEMO) ===");
foreach ($drop_tables as $t) {
    try {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
        out("[OK] DROP {$t}");
    } catch (Throwable $e) {
        out("[ERR] DROP {$t}: " . $e->getMessage());
    }
}

// Xóa homepage_blocks liên quan sản phẩm (giữ hero, news, faq, categories, dynamic_*).
$remove_blocks = ['hot_products', 'deal_today', 'consultation_form', 'services', 'products'];
try {
    $in = implode(',', array_fill(0, count($remove_blocks), '?'));
    $stmt = $pdo->prepare("DELETE FROM homepage_blocks WHERE block_key IN ({$in})");
    $stmt->execute($remove_blocks);
    out("[OK] Xóa {$stmt->rowCount()} homepage_blocks sản phẩm");
} catch (Throwable $e) {
    out("[ERR] homepage_blocks: " . $e->getMessage());
}

// Xóa settings liên quan sản phẩm/affiliate.
try {
    $stmt = $pdo->prepare(
        "DELETE FROM settings WHERE setting_group IN ('affiliate','conversion')
         OR setting_key LIKE '%affiliate%'
         OR setting_key LIKE '%product%'
         OR setting_key = 'url_service_prefix'
         OR setting_key LIKE 'gtm_event_form_registration%'"
    );
    $stmt->execute();
    out("[OK] Xóa {$stmt->rowCount()} settings sản phẩm/affiliate");
} catch (Throwable $e) {
    out("[ERR] settings: " . $e->getMessage());
}

// Dọn slug_redirects loại 'product' (URL sản phẩm cũ không còn ý nghĩa).
try {
    $n = $pdo->exec("DELETE FROM slug_redirects WHERE entity_type = 'product'");
    out("[OK] Xóa {$n} slug_redirects sản phẩm");
} catch (Throwable $e) {
    out("[SKIP] slug_redirects: " . $e->getMessage());
}

out("=== HOÀN TẤT ===");
