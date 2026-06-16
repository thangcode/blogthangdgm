<?php
/**
 * diag_ad_banners.php — Chẩn đoán vì sao banner quảng cáo không hiển thị.
 * Dùng (trên server): php scripts/diag_ad_banners.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
@require __DIR__ . '/../includes/page-cache.php';

echo "PHP now : " . date('Y-m-d H:i:s') . " (tz=" . date_default_timezone_get() . ")\n";
try {
    echo "MySQL now: " . $pdo->query("SELECT NOW()")->fetchColumn() . "\n";
    echo "MySQL tz : " . $pdo->query("SELECT @@session.time_zone")->fetchColumn() . "\n";
} catch (Throwable $e) { echo "MySQL now: lỗi " . $e->getMessage() . "\n"; }

echo "\nhàm render_ad_slot tồn tại: " . (function_exists('render_ad_slot') ? 'CÓ' : 'KHÔNG') . "\n";

// Kiểm tra bảng tồn tại
try {
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM ad_banners")->fetchColumn();
    echo "Tổng số ad_banners: $cnt\n";
} catch (Throwable $e) {
    echo "BẢNG ad_banners CHƯA TỒN TẠI hoặc lỗi: " . $e->getMessage() . "\n";
    exit;
}

echo "\n-- Tất cả bản ghi --\n";
$rows = $pdo->query("SELECT id, title, slot, status, start_at, end_at, LEFT(image_path,60) AS img FROM ad_banners ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo sprintf(
        "#%d | slot=%s | status=%s | start=%s | end=%s | img=%s | %s\n",
        $r['id'], $r['slot'], $r['status'],
        $r['start_at'] ?? 'NULL', $r['end_at'] ?? 'NULL', $r['img'], $r['title']
    );
}

echo "\n-- get_active_ad_banners theo từng slot --\n";
foreach (array_keys(ad_slots()) as $slot) {
    $active = get_active_ad_banners($pdo, $slot, 10);
    echo sprintf("%-22s : %d banner active\n", $slot, count($active));
}

echo "\n-- render_ad_slot('home_top') có ra HTML không --\n";
$html = render_ad_slot($pdo, 'home_top');
echo "Độ dài HTML: " . strlen($html) . (strlen($html) ? " (CÓ render)\n" : " (RỖNG)\n");

echo "\n-- index.php trên server đã có code chèn banner chưa? --\n";
$indexFile = __DIR__ . '/../index.php';
$indexSrc = is_file($indexFile) ? (string) file_get_contents($indexFile) : '';
$hasHomeTop = strpos($indexSrc, "render_ad_slot(\$pdo, 'home_top')") !== false || strpos($indexSrc, 'render_ad_slot($pdo, "home_top")') !== false;
echo "index.php có gọi render_ad_slot('home_top'): " . ($hasHomeTop ? 'CÓ' : 'KHÔNG (CHƯA DEPLOY index.php mới!)') . "\n";

echo "\n-- PageCache --\n";
if (class_exists('PageCache')) {
    echo "PageCache enabled: " . (PageCache::isEnabled() ? 'CÓ' : 'KHÔNG') . "\n";
    $deleted = PageCache::flush();
    echo "Đã xóa $deleted file cache (bao gồm trang chủ).\n";
} else {
    echo "Không nạp được class PageCache.\n";
}

