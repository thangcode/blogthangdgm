<?php
/**
 * migrate_shopee_import.php
 *  - Bảng shopee_import_log (lưu raw JSON crawl + dedupe theo shop_id/item_id)
 *  - Cột products.source_url (link Shopee gốc)
 *  - Settings cho LLM (endpoint, key, model, fallback, temperature, max_tokens)
 *  - Setting shopee_import_api_key (auto generate 1 lần)
 *  - Tạo category "Chưa phân loại" (chua-phan-loai) nếu chưa có
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");
function out($m) { echo $m . PHP_EOL; }

// 1) Bảng shopee_import_log
$pdo->exec("CREATE TABLE IF NOT EXISTS `shopee_import_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_url` VARCHAR(500) NOT NULL,
    `shop_id` VARCHAR(40) NOT NULL DEFAULT '',
    `item_id` VARCHAR(40) NOT NULL DEFAULT '',
    `raw_json` LONGTEXT NULL,
    `product_id` INT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'imported',
    `note` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_shop_item` (`shop_id`, `item_id`),
    KEY `idx_product` (`product_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
out('[OK] Bảng shopee_import_log sẵn sàng');

// 2) Cột products.source_url
$has = $pdo->query("SHOW COLUMNS FROM products LIKE 'source_url'")->fetch();
if (!$has) {
    $pdo->exec("ALTER TABLE products ADD COLUMN source_url VARCHAR(500) DEFAULT NULL AFTER original_url");
    out('[OK] Đã thêm products.source_url');
} else {
    out('[SKIP] products.source_url đã tồn tại');
}

// 3) Settings LLM + import key
$ins = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key = setting_key");
$apiKey = bin2hex(random_bytes(20));
$defaults = [
    'llm_endpoint'             => 'https://cli.thangdgm.io.vn/v1',
    'llm_api_key'              => '',
    'llm_model'                => 'gpt-4o-mini',
    'llm_model_fallback'       => 'gpt-4o-mini',
    'llm_temperature'          => '0.6',
    'llm_max_tokens'           => '1200',
    'shopee_import_api_key'    => $apiKey,
    'shopee_default_category'  => 'chua-phan-loai',
];
foreach ($defaults as $k => $v) {
    $ins->execute([$k, $v]);
}
out('[OK] Đã đảm bảo settings LLM + shopee_import_api_key');

// 4) Category "Chưa phân loại"
$cat = $pdo->query("SELECT id FROM categories WHERE slug = 'chua-phan-loai' LIMIT 1")->fetch();
if (!$cat) {
    $pdo->prepare("INSERT INTO categories (name, slug, status) VALUES (?, ?, 1)")->execute(['Chưa phân loại', 'chua-phan-loai']);
    out('[OK] Đã tạo danh mục "Chưa phân loại"');
} else {
    out('[SKIP] Danh mục "Chưa phân loại" đã tồn tại');
}

// 5) Hiện api key để user copy
$key = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'shopee_import_api_key'")->fetchColumn();
out('==> shopee_import_api_key (copy vào extension): ' . $key);
out('==> HOÀN TẤT migrate shopee import');
