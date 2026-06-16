<?php
/**
 * Cập nhật các settings còn dính Thắng DGM về phong cách ShopSieuSale.
 * Chỉ chỉnh các key footer + smtp_from_name, không động vào key kỹ thuật khác.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");

$updates = [
    'smtp_from_name' => 'ShopSieuSale',
    'footer_col1'    => '<h3>Về chúng tôi</h3>'
        . '<p>ShopSieuSale là trang giới thiệu sản phẩm affiliate, '
        . 'tổng hợp deal hot mỗi ngày từ các sàn thương mại điện tử uy tín.</p>',
    'footer_col2'    => '<h3>Danh mục</h3>'
        . '<ul>'
        . '<li><a href="/danh-muc/do-cong-nghe">Đồ Công Nghệ</a></li>'
        . '<li><a href="/danh-muc/do-gia-dung">Đồ Gia Dụng</a></li>'
        . '<li><a href="/danh-muc/phu-kien">Phụ Kiện</a></li>'
        . '</ul>',
    'footer_col3'    => '<h3>Thông tin</h3>'
        . '<ul>'
        . '<li><a href="/gioi-thieu">Giới thiệu</a></li>'
        . '<li><a href="/tin-tuc">Tin tức &amp; Mẹo mua sắm</a></li>'
        . '<li><a href="/lien-he">Liên hệ</a></li>'
        . '</ul>',
    'footer_col4'    => '<h3>Liên hệ</h3>'
        . '<ul>'
        . '<li>Hotline: 0362 360 364</li>'
        . '<li>Email: support@shopsieusale.test</li>'
        . '<li>Địa chỉ: TP. Hồ Chí Minh</li>'
        . '</ul>',
    'footer_ecosystem' => '',
];

$stmt = $pdo->prepare(
    "INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
);
foreach ($updates as $k => $v) {
    $stmt->execute([$k, $v]);
    echo "[OK] $k\n";
}
echo "Done.\n";
