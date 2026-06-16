<?php
/**
 * add_trash_columns.php — Thêm cột deleted_at/deleted_by cho posts & pages để hỗ trợ Thùng rác.
 * Idempotent. Chạy: php scripts/add_trash_columns.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo->exec("SET NAMES utf8mb4");

foreach (['posts', 'pages'] as $t) {
    if (!has_table_column($pdo, $t, 'deleted_at')) {
        try { $pdo->exec("ALTER TABLE `$t` ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL"); echo "[$t] + deleted_at\n"; }
        catch (Throwable $e) { echo "[$t] loi deleted_at: " . $e->getMessage() . "\n"; }
    } else echo "[$t] deleted_at da co\n";
    if (!has_table_column($pdo, $t, 'deleted_by')) {
        try { $pdo->exec("ALTER TABLE `$t` ADD COLUMN deleted_by INT NULL DEFAULT NULL"); echo "[$t] + deleted_by\n"; }
        catch (Throwable $e) { echo "[$t] loi deleted_by: " . $e->getMessage() . "\n"; }
    } else echo "[$t] deleted_by da co\n";
}
echo "Xong.\n";
