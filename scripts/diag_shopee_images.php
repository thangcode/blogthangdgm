<?php
/**
 * diag_shopee_images.php — Chẩn đoán ảnh import của 1 item Shopee.
 * Dùng: php scripts/diag_shopee_images.php 25334769895
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';

$itemId = $argv[1] ?? '25334769895';
echo "=== ITEM: $itemId ===\n\n";

echo "-- shopee_import_log --\n";
$st = $pdo->prepare("SELECT id, shop_id, item_id, product_id, status, created_at FROM shopee_import_log WHERE item_id = ?");
$st->execute([$itemId]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n-- products khớp item (kể cả đã xóa) --\n";
$st = $pdo->prepare("SELECT id, name, image, gallery, deleted_at FROM products WHERE source_url LIKE ? OR original_url LIKE ?");
$st->execute(['%' . $itemId . '%', '%' . $itemId . '%']);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "product #{$r['id']} | deleted_at=" . ($r['deleted_at'] ?? 'NULL') . "\n";
    echo "  image  : {$r['image']}\n";
    echo "  gallery: {$r['gallery']}\n";
}

echo "\n-- file thực tế trong thư mục --\n";
$dir = __DIR__ . '/../uploads/products/shopee/' . $itemId;
if (!is_dir($dir)) {
    echo "(thư mục không tồn tại: $dir)\n";
} else {
    foreach (glob($dir . '/*') ?: [] as $f) {
        echo '  ' . basename($f) . '  ' . filesize($f) . ' bytes  sha1=' . substr(sha1_file($f), 0, 12) . "\n";
    }
}
