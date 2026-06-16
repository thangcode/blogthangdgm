<?php
/**
 * purge_shopee_item.php — Dọn sạch ảnh mồ côi của 1 item Shopee (file + media_library).
 * CHỈ dùng khi sản phẩm của item đó đã bị xóa. Dùng: php scripts/purge_shopee_item.php 25334769895
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';

$itemId = $argv[1] ?? '';
if (!preg_match('/^\d+$/', $itemId)) {
    exit("Cách dùng: php scripts/purge_shopee_item.php <item_id>\n");
}

$root = realpath(__DIR__ . '/..');
$relBase = 'uploads/products/shopee/' . $itemId;
$dir = $root . '/' . $relBase;

echo "=== Dọn item $itemId ===\n";

// 1) Xóa bản ghi media_library trỏ vào thư mục item này
try {
    $st = $pdo->prepare("SELECT id, file_path FROM media_library WHERE file_path LIKE ?");
    $st->execute([$relBase . '/%']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo " - media #{$r['id']}: {$r['file_path']}\n";
    }
    $del = $pdo->prepare("DELETE FROM media_library WHERE file_path LIKE ?");
    $del->execute([$relBase . '/%']);
    echo "Đã xóa " . $del->rowCount() . " bản ghi media_library.\n";
} catch (Throwable $e) {
    echo "media_library: " . $e->getMessage() . "\n";
}

// 2) Xóa toàn bộ file + thư mục
$count = 0;
if (is_dir($dir)) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && @unlink($f)) { $count++; }
    }
    @rmdir($dir);
    echo "Đã xóa $count file và thư mục $relBase.\n";
} else {
    echo "Thư mục $relBase không tồn tại.\n";
}
echo "DONE\n";
