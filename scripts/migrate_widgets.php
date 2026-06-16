<?php
/**
 * migrate_widgets.php — Tạo schema cho hệ thống widget sidebar.
 * Idempotent: kiểm tra tồn tại trước khi tạo/thêm.
 *
 *  - Bảng widgets: mỗi widget đặt trong sidebar.
 *  - Bảng page_sidebar_settings: override sidebar cho các trang file PHP.
 *  - posts: thêm cột sidebar_mode + sidebar_position.
 *  - settings (group 'sidebar'): mặc định tổng sidebar_enabled + sidebar_position.
 *
 * Chạy: php scripts/migrate_widgets.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../config/database.php'; // $pdo
$pdo->exec("SET NAMES utf8mb4");

function log_line($m) { echo $m . PHP_EOL; }

function table_exists(PDO $pdo, string $t): bool
{
    return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
}

function column_exists(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (int) $s->fetchColumn() > 0;
}

// ---------- 1) Bảng widgets ----------
if (!table_exists($pdo, 'widgets')) {
    $pdo->exec("CREATE TABLE widgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(40) NOT NULL,
        title VARCHAR(150) NOT NULL DEFAULT '',
        settings LONGTEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active_sort (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    log_line('[OK] tao bang widgets');
} else {
    log_line('[skip] bang widgets da co');
}

// ---------- 2) Bảng page_sidebar_settings ----------
if (!table_exists($pdo, 'page_sidebar_settings')) {
    $pdo->exec("CREATE TABLE page_sidebar_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        page_key VARCHAR(40) NOT NULL UNIQUE,
        page_label VARCHAR(100) NOT NULL DEFAULT '',
        sidebar_mode ENUM('default','show','hide') NOT NULL DEFAULT 'default',
        sidebar_position ENUM('default','left','right') NOT NULL DEFAULT 'default',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    log_line('[OK] tao bang page_sidebar_settings');

    // Seed các trang file PHP cố định
    $pages = [
        ['home',     'Trang chủ'],
        ['news',     'Tin Tức'],
        ['category', 'Trang danh mục'],
        ['tag',      'Trang tag'],
        ['about',    'Giới Thiệu'],
        ['contact',  'Liên Hệ'],
        ['search',   'Trang tìm kiếm'],
    ];
    $ins = $pdo->prepare("INSERT INTO page_sidebar_settings (page_key, page_label) VALUES (?, ?)");
    foreach ($pages as $p) { $ins->execute($p); }
    log_line('[OK] seed ' . count($pages) . ' trang mac dinh');
} else {
    log_line('[skip] bang page_sidebar_settings da co');
}

// ---------- 3) posts: cột sidebar ----------
if (!column_exists($pdo, 'posts', 'sidebar_mode')) {
    $pdo->exec("ALTER TABLE posts ADD COLUMN sidebar_mode ENUM('default','show','hide') NOT NULL DEFAULT 'default' AFTER type");
    log_line('[OK] them cot posts.sidebar_mode');
} else {
    log_line('[skip] posts.sidebar_mode da co');
}
if (!column_exists($pdo, 'posts', 'sidebar_position')) {
    $pdo->exec("ALTER TABLE posts ADD COLUMN sidebar_position ENUM('default','left','right') NOT NULL DEFAULT 'default' AFTER sidebar_mode");
    log_line('[OK] them cot posts.sidebar_position');
} else {
    log_line('[skip] posts.sidebar_position da co');
}

// ---------- 4) settings group 'sidebar' ----------
$defaults = [
    ['sidebar_enabled',  '1',     'sidebar'],
    ['sidebar_position', 'right', 'sidebar'],
];
$chk = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
$insS = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)");
foreach ($defaults as $d) {
    $chk->execute([$d[0]]);
    if ((int) $chk->fetchColumn() === 0) {
        $insS->execute($d);
        log_line("[OK] them setting {$d[0]} = {$d[1]}");
    } else {
        log_line("[skip] setting {$d[0]} da co");
    }
}

// ---------- 5) Seed vài widget mặc định nếu bảng trống ----------
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM widgets")->fetchColumn();
if ($cnt === 0) {
    $seed = [
        ['popular_posts', 'Xem nhiều nhất', json_encode(['limit' => 5], JSON_UNESCAPED_UNICODE), 1],
        ['categories',    'Chủ đề',         json_encode(['limit' => 8], JSON_UNESCAPED_UNICODE), 2],
        ['tags',          'Tag phổ biến',   json_encode(['limit' => 20], JSON_UNESCAPED_UNICODE), 3],
    ];
    $insW = $pdo->prepare("INSERT INTO widgets (type, title, settings, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
    foreach ($seed as $w) { $insW->execute($w); }
    log_line('[OK] seed ' . count($seed) . ' widget mac dinh');
} else {
    log_line("[skip] widgets da co $cnt ban ghi");
}

log_line('=== HOAN TAT ===');
