<?php
/**
 * backfill_media_shopee.php
 * Đăng ký các ảnh đã import (uploads/products/shopee/...) vào media_library.
 * Idempotent: chạy lại không tạo trùng (UNIQUE sha256 + ON DUPLICATE).
 * Chạy: F:\Xamp\php\php.exe scripts/backfill_media_shopee.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';

$root = dirname(__DIR__);
$base = $root . '/uploads/products/shopee';
function out($m) { echo $m . PHP_EOL; }

if (!is_dir($base)) {
    out('Không có thư mục uploads/products/shopee — chưa import ảnh nào.');
    exit;
}

ensure_media_library_table($pdo);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
$ok = 0; $skip = 0;
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['webp', 'jpg', 'jpeg', 'png', 'gif'], true)) continue;
    $abs = $file->getPathname();
    // đường dẫn tương đối từ web root
    $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
    if (register_media_file($pdo, $abs, $rel, basename($abs))) {
        $ok++;
    } else {
        $skip++;
    }
}
out("[OK] Đã đăng ký/cập nhật: $ok ảnh; bỏ qua/lỗi: $skip");
out('==> HOÀN TẤT backfill media Shopee');
