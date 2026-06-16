<?php
/**
 * migrate_click_tracking.php
 * - Thêm cột products.click_count (cho phép sửa tay để giả lập)
 * - Tạo bảng product_clicks lưu chi tiết từng lượt click
 * - Khởi tạo click_count theo views để có dữ liệu xếp hạng demo
 * Chạy: F:\Xamp\php\php.exe scripts/migrate_click_tracking.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");
function out($m) { echo $m . PHP_EOL; }

// 1) Cột click_count
$has = $pdo->query("SHOW COLUMNS FROM products LIKE 'click_count'")->fetch();
if (!$has) {
    $pdo->exec("ALTER TABLE products ADD COLUMN click_count INT NOT NULL DEFAULT 0 AFTER views");
    out('[OK] Đã thêm cột products.click_count');
} else {
    out('[SKIP] Cột products.click_count đã tồn tại');
}

// 2) Bảng product_clicks
$pdo->exec("CREATE TABLE IF NOT EXISTS `product_clicks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `link_type` VARCHAR(20) NOT NULL DEFAULT 'affiliate',
    `target_url` VARCHAR(500) NOT NULL DEFAULT '',
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
    `device_type` VARCHAR(30) NOT NULL DEFAULT '',
    `browser_name` VARCHAR(80) NOT NULL DEFAULT '',
    `os_name` VARCHAR(80) NOT NULL DEFAULT '',
    `referrer_url` VARCHAR(1000) NOT NULL DEFAULT '',
    `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_product` (`product_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_is_bot` (`is_bot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
out('[OK] Đã tạo bảng product_clicks');

// 3) Khởi tạo click_count theo views (chỉ khi đang = 0) để có dữ liệu xếp hạng
$updated = $pdo->exec("UPDATE products SET click_count = views WHERE click_count = 0 AND views > 0");
out("[OK] Khởi tạo click_count theo views cho $updated sản phẩm");

out('=== HOÀN TẤT MIGRATE CLICK TRACKING ===');
