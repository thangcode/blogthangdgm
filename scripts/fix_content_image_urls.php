<?php
/**
 * fix_content_image_urls.php
 * Thay URL local tuyệt đối nhúng trong HTML (ảnh bài viết...) -> đường dẫn tương đối gốc.
 * Chạy được nhiều lần (idempotent). Dùng cho cả local và server sau khi import DB.
 * Chạy: php scripts/fix_content_image_urls.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");

// Các tiền tố domain local cần đổi về '/'
$bases = [
    'http://shopsieusale.test/',
    'https://shopsieusale.test/',
    'http://www.shopsieusale.test/',
    'https://www.shopsieusale.test/',
    'http://localhost/shopsieusale/',
    'https://localhost/shopsieusale/',
];

// Các cột HTML có thể chứa URL ảnh
$targets = [
    ['products', 'content'],
    ['products', 'description'],
    ['posts', 'content'],
    ['settings', 'setting_value'],
    ['dynamic_blocks', 'content'],
    ['homepage_blocks', 'content'],
];

$totalChanged = 0;
foreach ($targets as [$table, $col]) {
    try {
        // Đếm số dòng còn chứa domain local (bất kỳ base nào)
        $likeParts = [];
        $likeParams = [];
        foreach ($bases as $b) { $likeParts[] = "`$col` LIKE ?"; $likeParams[] = '%' . $b . '%'; }
        $cntSql = "SELECT COUNT(*) FROM `$table` WHERE " . implode(' OR ', $likeParts);
        $cnt = (int) ($pdo->prepare($cntSql))->execute($likeParams);
        $stmtCnt = $pdo->prepare($cntSql);
        $stmtCnt->execute($likeParams);
        $affected = (int) $stmtCnt->fetchColumn();

        if ($affected === 0) {
            echo "[skip] $table.$col: 0 dòng dính URL local\n";
            continue;
        }

        // Lồng REPLACE() cho từng base
        $expr = "`$col`";
        foreach ($bases as $b) {
            $expr = "REPLACE($expr, " . $pdo->quote($b) . ", '/')";
        }
        $updSql = "UPDATE `$table` SET `$col` = $expr WHERE " . implode(' OR ', $likeParts);
        $upd = $pdo->prepare($updSql);
        $upd->execute($likeParams);
        $rows = $upd->rowCount();
        $totalChanged += $rows;
        echo "[OK]   $table.$col: đã sửa $rows dòng (phát hiện $affected)\n";
    } catch (Throwable $e) {
        echo "[err]  $table.$col: " . $e->getMessage() . "\n";
    }
}
echo "DONE. Tổng dòng đã cập nhật: $totalChanged\n";
