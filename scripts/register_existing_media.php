<?php
/**
 * register_existing_media.php — Đưa các ảnh có sẵn (đã migrate từ WordPress + ảnh upload trực tiếp)
 * vào bảng media_library để hiển thị/quản lý trong Thư viện Media của admin.
 *
 * Quét: assets/uploads/wp, assets/uploads/media, assets/uploads/editor, và ảnh ở gốc assets/uploads.
 * Idempotent: dựa trên sha256 (register_media_file dùng ON DUPLICATE) nên chạy lại an toàn.
 *
 * Chạy: php scripts/register_existing_media.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo->exec("SET NAMES utf8mb4");
ensure_media_library_table($pdo);

$root = rtrim(defined('ROOT_PATH') ? ROOT_PATH : (__DIR__ . '/../'), '/\\') . DIRECTORY_SEPARATOR;
$dirs = ['assets/uploads/wp', 'assets/uploads/media', 'assets/uploads/editor', 'assets/uploads'];
$exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

$seen = [];   // tránh quét trùng file (assets/uploads bao gồm cả con)
$added = 0; $skipped = 0; $errors = 0;

foreach ($dirs as $d) {
    $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, $d);
    if (!is_dir($abs)) continue;

    // assets/uploads (gốc) chỉ lấy file trực tiếp; các thư mục con đã quét riêng
    $rootOnly = ($d === 'assets/uploads');
    $iter = $rootOnly
        ? new IteratorIterator(new DirectoryIterator($abs))
        : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));

    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, $exts, true)) continue;
        $path = $f->getPathname();
        if (isset($seen[$path])) continue;
        $seen[$path] = true;

        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');

        // SVG không qua getimagesize tốt -> vẫn đăng ký nhưng register_media_file tự xử lý mime
        try {
            // bỏ qua nếu đã có cùng file_path
            $chk = $pdo->prepare("SELECT id FROM media_library WHERE file_path = ? LIMIT 1");
            $chk->execute([$rel]);
            if ($chk->fetchColumn()) { $skipped++; continue; }

            if (register_media_file($pdo, $path, $rel, basename($rel))) $added++;
            else $errors++;
        } catch (Throwable $e) {
            $errors++;
        }
    }
}

$total = $pdo->query("SELECT COUNT(*) FROM media_library")->fetchColumn();
echo "Da dang ky moi: $added | bo qua (da co): $skipped | loi: $errors\n";
echo "Tong media_library hien tai: $total\n";
