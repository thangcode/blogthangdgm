<?php
/**
 * clean_unused_media.php — Dọn ảnh "rác" trong thư viện không còn được dùng.
 *
 * An toàn:
 *  - Phát hiện ảnh mồ côi bằng cách quét TẤT CẢ nội dung tham chiếu ảnh
 *    (posts, pages, categories, banners, ad_banners, settings, seo_settings, blocks, faqs).
 *  - File bị xóa được CHUYỂN vào backups/removed_media_<timestamp>/ (giữ cấu trúc) => có thể khôi phục.
 *  - Đồng thời gỡ 2 banner demo TMĐT "Siêu Sale" (ảnh shop-sieu-sale) khỏi bảng banners.
 *
 * Chạy:  php scripts/clean_unused_media.php          (chỉ liệt kê, KHÔNG xóa)
 *        php scripts/clean_unused_media.php --apply  (thực hiện dọn)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo->exec("SET NAMES utf8mb4");

$apply = in_array('--apply', $argv ?? [], true);
$root  = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR : __DIR__ . '/../';

echo "=== DON THU VIEN ANH (" . ($apply ? 'APPLY' : 'DRY-RUN') . ") ===\n\n";

/* B1: Gỡ banner demo TMĐT (Siêu Sale / shop-sieu-sale) khỏi bảng banners */
$demoBanners = $pdo->query("SELECT id, title, image_path FROM banners
    WHERE image_path LIKE '%shop-sieu-sale%' OR title LIKE '%Si%u Sale%' OR title LIKE '%SLIDER%'")->fetchAll();
echo "Banner demo se go (" . count($demoBanners) . "):\n";
foreach ($demoBanners as $b) echo "  #{$b['id']} {$b['title']}\n";
if ($apply && $demoBanners) {
    $ids = implode(',', array_map(fn($b) => (int)$b['id'], $demoBanners));
    $pdo->exec("DELETE FROM banners WHERE id IN ($ids)");
}
echo "\n";

/* B2: Xây corpus tham chiếu ảnh (sau khi đã gỡ banner demo) */
$corpus = '';
$add = function (&$c, $pdo, $sql) { try { foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $row) foreach ($row as $v) $c .= ' ' . (string)$v; } catch (Throwable $e) {} };
$add($corpus, $pdo, "SELECT content, thumbnail FROM posts");
$add($corpus, $pdo, "SELECT content FROM pages");
$add($corpus, $pdo, "SELECT content, og_image, icon, description FROM categories");
$add($corpus, $pdo, "SELECT image_path, mobile_image_path FROM banners");
$add($corpus, $pdo, "SELECT image_path, mobile_image_path FROM ad_banners");
$add($corpus, $pdo, "SELECT setting_value FROM settings");
$add($corpus, $pdo, "SELECT og_image FROM seo_settings");
$add($corpus, $pdo, "SELECT * FROM dynamic_blocks");
$add($corpus, $pdo, "SELECT settings FROM homepage_blocks");
$add($corpus, $pdo, "SELECT settings FROM widgets");
$add($corpus, $pdo, "SELECT answer, question FROM faqs");

/* B3: Tìm ảnh mồ côi trong media_library */
$rows = $pdo->query("SELECT id, file_path, stored_name, original_name FROM media_library ORDER BY id")->fetchAll();
$orphans = [];
foreach ($rows as $r) {
    $base = basename($r['file_path']);
    $stored = $r['stored_name'] ?: '';
    $hit = ($base !== '' && strpos($corpus, $base) !== false)
        || ($stored !== '' && strpos($corpus, $stored) !== false)
        || strpos($corpus, $r['file_path']) !== false;
    if (!$hit) $orphans[] = $r;
}
echo "media_library: tong=" . count($rows) . ", mo coi=" . count($orphans) . "\n";

$backupDir = $root . 'backups' . DIRECTORY_SEPARATOR . 'removed_media_' . date('Ymd_His');
$moved = 0; $recDel = 0; $missing = 0;
foreach ($orphans as $r) {
    $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, $r['file_path']);
    if ($apply) {
        if (is_file($abs)) {
            $dest = $backupDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $r['file_path']);
            @mkdir(dirname($dest), 0775, true);
            if (@rename($abs, $dest)) $moved++;
        } else {
            $missing++;
        }
        $pdo->prepare("DELETE FROM media_library WHERE id = ?")->execute([$r['id']]);
        $recDel++;
    }
}

/* B4: Dọn thư mục rỗng trong uploads/products (ảnh affiliate cũ) */
$prodDir = $root . 'uploads' . DIRECTORY_SEPARATOR . 'products';
$removedDirs = 0;
if ($apply && is_dir($prodDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prodDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isDir() && !(new FilesystemIterator($f->getPathname()))->valid()) { @rmdir($f->getPathname()); $removedDirs++; }
    }
    if (is_dir($prodDir) && !(new FilesystemIterator($prodDir))->valid()) { @rmdir($prodDir); $removedDirs++; }
}

if ($apply && function_exists('log_activity')) {
    log_activity('cleanup', 'media_library', null, "Don rac media: $recDel record, $moved file -> backups, " . count($demoBanners) . " banner demo");
}

echo "\n=== KET QUA ===\n";
if (!$apply) {
    echo "DRY-RUN: chua thay doi gi. Chay lai voi --apply de thuc hien.\n";
} else {
    echo "Banner demo da go: " . count($demoBanners) . "\n";
    echo "Record media da xoa: $recDel\n";
    echo "File da chuyen vao backups: $moved (thieu tren disk: $missing)\n";
    echo "Backup tai: " . $backupDir . "\n";
    echo "Thu muc rong da don: $removedDirs\n";
}
echo "Con lai trong media_library: " . $pdo->query("SELECT COUNT(*) FROM media_library")->fetchColumn() . "\n";
