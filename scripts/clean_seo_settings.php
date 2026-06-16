<?php
/**
 * clean_seo_settings.php — Dọn dữ liệu SEO "rác" còn sót từ template thương mại điện tử cũ.
 *
 * - seo_settings (home, news): meta_keywords đang là từ khóa TMĐT (mua sắm online, deal hot...).
 *   Rank Math (WP) KHÔNG lưu meta keywords nên không có gì để migrate — thay bằng từ khóa blog đúng chủ đề.
 * - settings.smtp_from_name = 'ShopSieuSale' -> đổi về tên site.
 *
 * Idempotent: chạy lại nhiều lần đều an toàn.
 * Chạy: php scripts/clean_seo_settings.php
 */
require_once __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");

$site_name = (string) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_name'")->fetchColumn() ?: 'Thắng Digital Marketing');

// Từ khóa blog đúng chủ đề (thay cho rác TMĐT)
$kw_home = 'facebook ads, google ads, kinh doanh online, ai automation, n8n, seo, thiết kế website, digital marketing';
$kw_news = 'blog digital marketing, facebook ads, google ads, kinh doanh online, ai automation, n8n, seo';

$junkPattern = '/(mua s\S*m online|deal hot|gi\S+ t\S+t|đồ công nghệ|đồ gia dụng|phụ kiện|khuyến mãi|mẹo mua sắm)/iu';

$changed = 0;
foreach ([['home', $kw_home], ['news', $kw_news]] as [$key, $newKw]) {
    $cur = $pdo->prepare("SELECT meta_keywords FROM seo_settings WHERE page_key = ?");
    $cur->execute([$key]);
    $val = $cur->fetchColumn();
    if ($val === false) { echo "[BỎ QUA] không có seo_settings[$key]\n"; continue; }
    if (preg_match($junkPattern, (string) $val) || trim((string) $val) === '') {
        $pdo->prepare("UPDATE seo_settings SET meta_keywords = ? WHERE page_key = ?")->execute([$newKw, $key]);
        echo "[ĐÃ DỌN] keywords [$key]: \"$val\" -> \"$newKw\"\n";
        $changed++;
    } else {
        echo "[OK] keywords [$key] không phải rác, giữ nguyên: \"$val\"\n";
    }
}

// smtp_from_name
$smtp = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='smtp_from_name'")->fetchColumn();
if ($smtp !== false && stripos((string) $smtp, 'shopsieusale') !== false) {
    $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key='smtp_from_name'")->execute([$site_name]);
    echo "[ĐÃ DỌN] smtp_from_name: \"$smtp\" -> \"$site_name\"\n";
    $changed++;
} else {
    echo "[OK] smtp_from_name: " . var_export($smtp, true) . "\n";
}

echo "\nXong. Số mục đã dọn: $changed\n";
