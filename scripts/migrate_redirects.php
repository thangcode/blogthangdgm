<?php
/**
 * migrate_redirects.php — Đưa các 301 redirect URL cũ (.html) từ WordPress (Rank Math)
 * vào bảng slug_redirects để KHÔNG mất traffic/SEO khi truy cập link cũ.
 *
 * Nguồn: wp_rank_math_redirections (7 rule, 1 cái 410 tag/soledad bỏ qua).
 * Đích cũ là các trang dịch vụ đã gỡ -> ánh xạ về chuyên mục liên quan nhất hiện có.
 * new_path lưu DẠNG TƯƠNG ĐỐI để hoạt động đúng với mọi domain.
 *
 * Idempotent: chạy lại nhiều lần không tạo bản ghi trùng.
 * Chạy: php scripts/migrate_redirects.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/url-helper.php';
$pdo->exec("SET NAMES utf8mb4");

// old_path (đúng dạng router truy vấn: '/' + path) => category slug đích
$map = [
    '/thiet-ke-website.html'              => 'thiet-ke-website',
    '/day-quang-cao-google-ads.html'      => 'quang-cao-google-ads',
    '/dich-vu-quang-cao-google-ads.html'  => 'quang-cao-google-ads',
    '/day-quang-cao-facebook-ads.html'    => 'quang-cao-facebook-ads',
    '/dich-vu-quang-cao-facebook-ads.html'=> 'quang-cao-facebook-ads',
    '/thiet-ke-do-hoa.html'               => 'thiet-ke-website', // không có chuyên mục đồ họa -> gần nhất
];

$inserted = 0; $skipped = 0; $missing = 0;
foreach ($map as $oldPath => $catSlug) {
    $st = $pdo->prepare("SELECT id FROM categories WHERE slug = ? AND deleted_at IS NULL LIMIT 1");
    $st->execute([$catSlug]);
    $catId = $st->fetchColumn();
    if (!$catId) { echo "[BỎ QUA] không thấy chuyên mục '$catSlug' cho $oldPath\n"; $missing++; continue; }

    $newPath = categoryUrl($catSlug); // dạng tương đối /danh-muc/{slug}

    $chk = $pdo->prepare("SELECT id FROM slug_redirects WHERE old_path = ? LIMIT 1");
    $chk->execute([$oldPath]);
    if ($chk->fetchColumn()) {
        $pdo->prepare("UPDATE slug_redirects SET new_path = ?, entity_type = 'category', entity_id = ? WHERE old_path = ?")
            ->execute([$newPath, (int)$catId, $oldPath]);
        echo "[CẬP NHẬT] $oldPath -> $newPath\n";
        $skipped++;
        continue;
    }
    $pdo->prepare("INSERT INTO slug_redirects (old_path, new_path, entity_type, entity_id) VALUES (?, ?, 'category', ?)")
        ->execute([$oldPath, $newPath, (int)$catId]);
    echo "[THÊM] $oldPath -> $newPath (301)\n";
    $inserted++;
}

echo "\nXong. Thêm mới: $inserted, cập nhật: $skipped, thiếu chuyên mục: $missing\n";
echo "Lưu ý: rule 410 'tag/soledad' không migrate (URL có dấu '/', router trả 404 — đúng).\n";
