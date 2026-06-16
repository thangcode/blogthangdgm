<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
$r = $pdo->query('SELECT COUNT(*) c, COALESCE(SUM(views),0) v, COALESCE(SUM(views_real),0) vr, MAX(views_real) mx FROM products WHERE deleted_at IS NULL')->fetch();
echo "products={$r['c']} | SUM views={$r['v']} | SUM views_real={$r['vr']} | MAX views_real={$r['mx']}\n";
echo "Top views_real:\n";
foreach ($pdo->query('SELECT id,name,views,views_real FROM products WHERE deleted_at IS NULL ORDER BY views_real DESC LIMIT 5') as $row) {
    echo "  #{$row['id']} views={$row['views']} views_real={$row['views_real']} | {$row['name']}\n";
}
echo "Co bang product_views? ";
try { $pdo->query('SELECT 1 FROM product_views LIMIT 1'); echo "CO\n"; } catch (Throwable $e) { echo "KHONG\n"; }
