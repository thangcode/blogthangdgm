<?php
/**
 * seed_affiliate_demo.php
 * One-shot migration + demo seed cho mô hình AFFILIATE.
 * - Thêm cột original_url vào products
 * - Drop bảng không dùng: orders, order_items, payments, coupons
 * - Dọn data thừa và tạo bộ data demo (Đồ Công Nghệ, Đồ Gia Dụng, Phụ Kiện)
 * Chạy: F:\Xamp\php\php.exe scripts/seed_affiliate_demo.php
 */

// Chốt an toàn: chỉ cho chạy qua CLI, chặn truy cập qua web (script có lệnh phá hủy).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

mb_internal_encoding('UTF-8');
require __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");

function out($m) { echo $m . PHP_EOL; }

// ─────────────────────────────────────────────────────────────
// 1) SCHEMA: thêm cột original_url (link gốc) nếu chưa có
// ─────────────────────────────────────────────────────────────
$hasCol = $pdo->query("SHOW COLUMNS FROM products LIKE 'original_url'")->fetch();
if (!$hasCol) {
    $pdo->exec("ALTER TABLE products ADD COLUMN original_url VARCHAR(500) DEFAULT NULL AFTER affiliate_url");
    out('[OK] Đã thêm cột products.original_url');
} else {
    out('[SKIP] Cột products.original_url đã tồn tại');
}

// ─────────────────────────────────────────────────────────────
// 2) DROP các bảng không còn dùng (đã backup .sql trước đó)
// ─────────────────────────────────────────────────────────────
foreach (['order_items', 'orders', 'payments', 'coupons'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS `$t`");
    out("[OK] Đã drop bảng $t");
}

// ─────────────────────────────────────────────────────────────
// 3) DỌN data thừa (giữ cấu trúc bảng)
// ─────────────────────────────────────────────────────────────
foreach (['products', 'categories', 'banners', 'posts', 'faqs',
          'product_ratings', 'product_registrations', 'contacts',
          'slug_redirects', 'short_links', 'short_link_clicks', 'menus'] as $t) {
    try { $pdo->exec("TRUNCATE TABLE `$t`"); out("[OK] Đã dọn bảng $t"); }
    catch (Exception $e) { out("[WARN] Không dọn được $t: " . $e->getMessage()); }
}

// ─────────────────────────────────────────────────────────────
// 4) CATEGORIES
// ─────────────────────────────────────────────────────────────
$cats = [
    ['name' => 'Đồ Công Nghệ', 'slug' => 'do-cong-nghe', 'icon' => 'bi-cpu',
     'desc' => 'Thiết bị công nghệ, phụ kiện điện tử thông minh giá tốt mỗi ngày.'],
    ['name' => 'Đồ Gia Dụng', 'slug' => 'do-gia-dung', 'icon' => 'bi-house-gear',
     'desc' => 'Đồ dùng nhà bếp, gia dụng tiện nghi cho gia đình hiện đại.'],
    ['name' => 'Phụ Kiện', 'slug' => 'phu-kien', 'icon' => 'bi-headphones',
     'desc' => 'Phụ kiện máy tính, gaming gear và đồ dùng văn phòng chất lượng.'],
];
$catIns = $pdo->prepare(
    "INSERT INTO categories (name, slug, description, meta_title, meta_description, icon, parent_id, sort_order, status, created_at)
     VALUES (?,?,?,?,?,?,NULL,?,1,NOW())"
);
$catId = [];
foreach ($cats as $i => $c) {
    $catIns->execute([
        $c['name'], $c['slug'], $c['desc'],
        $c['name'] . ' giá tốt | ShopSieuSale',
        $c['desc'], $c['icon'], $i + 1
    ]);
    $catId[$c['slug']] = (int) $pdo->lastInsertId();
    out("[OK] Category: {$c['name']} (#{$catId[$c['slug']]})");
}

file_put_contents(__DIR__ . '/_seed_catmap.json', json_encode($catId));
out('Hoàn tất phần 1 (schema + categories). Chạy tiếp phần products...');

// ─────────────────────────────────────────────────────────────
// 5) PRODUCTS (tất cả product_type = 'affiliate')
// ─────────────────────────────────────────────────────────────
function pcontent($intro, $features) {
    $html = '<p>' . $intro . '</p><h3>Đặc điểm nổi bật</h3><ul>';
    foreach ($features as $f) { $html .= '<li>' . $f . '</li>'; }
    $html .= '</ul><p>Nhấn nút <strong>Mua ngay</strong> để đến nơi bán với ưu đãi tốt nhất.</p>';
    return $html;
}

$img = function ($slug) { return 'https://picsum.photos/seed/' . $slug . '/700/700'; };

$products = [
    // ===== ĐỒ CÔNG NGHỆ =====
    ['do-cong-nghe', 'Tai Nghe Bluetooth SoundPro X2', 'tai-nghe-bluetooth-soundpro-x2',
        'Tai nghe không dây chống ồn chủ động, pin 30 giờ, âm bass mạnh mẽ.',
        ['Chống ồn chủ động ANC', 'Thời lượng pin 30 giờ', 'Bluetooth 5.3 ổn định', 'Chống nước IPX5'],
        890000, 590000, 1,
        'https://s.shopee.vn/aff/soundpro-x2', 'https://shopee.vn/soundpro-x2'],
    ['do-cong-nghe', 'Đồng Hồ Thông Minh FitWatch 5', 'dong-ho-thong-minh-fitwatch-5',
        'Smartwatch theo dõi sức khỏe, đo SpO2, nhịp tim, màn hình AMOLED sắc nét.',
        ['Màn hình AMOLED 1.43"', 'Đo nhịp tim & SpO2', 'Hơn 100 chế độ thể thao', 'Pin 14 ngày'],
        1290000, 990000, 0,
        'https://s.shopee.vn/aff/fitwatch-5', 'https://fitwatch.vn/fitwatch-5'],
    ['do-cong-nghe', 'Sạc Dự Phòng Nhanh PowerMax 20000mAh', 'sac-du-phong-powermax-20000mah',
        'Pin sạc dự phòng dung lượng lớn, hỗ trợ sạc nhanh PD 22.5W cho mọi thiết bị.',
        ['Dung lượng 20000mAh', 'Sạc nhanh PD 22.5W', '2 cổng USB + 1 Type-C', 'Màn hình LED báo %'],
        650000, 0, 1,
        'https://s.shopee.vn/aff/powermax-20000', 'https://powermax.vn/20000mah'],
    ['do-cong-nghe', 'Camera An Ninh WiFi HomeGuard 2K', 'camera-an-ninh-wifi-homeguard-2k',
        'Camera giám sát trong nhà độ phân giải 2K, đàm thoại 2 chiều, xoay 355 độ.',
        ['Độ phân giải 2K sắc nét', 'Xoay 355° / nghiêng 110°', 'Đàm thoại 2 chiều', 'Báo động chuyển động AI'],
        490000, 359000, 0,
        '', 'https://homeguard.vn/camera-2k'], // chỉ có link gốc → demo fallback

    // ===== ĐỒ GIA DỤNG =====
    ['do-gia-dung', 'Nồi Chiên Không Dầu AirChef 5L', 'noi-chien-khong-dau-airchef-5l',
        'Nồi chiên không dầu dung tích 5L, 8 chế độ nấu tự động, tiết kiệm điện.',
        ['Dung tích lớn 5 lít', '8 chế độ nấu cài sẵn', 'Lòng nồi chống dính', 'Hẹn giờ thông minh'],
        1490000, 990000, 1,
        'https://s.shopee.vn/aff/airchef-5l', 'https://airchef.vn/noi-chien-5l'],
    ['do-gia-dung', 'Máy Hút Bụi Cầm Tay CycloneV', 'may-hut-bui-cam-tay-cyclonev',
        'Máy hút bụi không dây lực hút mạnh 18000Pa, nhẹ và linh hoạt cho mọi ngóc ngách.',
        ['Lực hút mạnh 18000Pa', 'Pin dùng 45 phút', 'Trọng lượng chỉ 1.3kg', 'Bộ lọc HEPA'],
        1990000, 1490000, 0,
        'https://s.shopee.vn/aff/cyclonev', 'https://cyclonev.vn/handheld'],
    ['do-gia-dung', 'Bình Đun Siêu Tốc Inox QuickBoil 1.8L', 'binh-dun-sieu-toc-quickboil-1-8l',
        'Ấm đun nước inox 304 dung tích 1.8L, sôi nhanh 5 phút, tự ngắt an toàn.',
        ['Inox 304 an toàn', 'Dung tích 1.8 lít', 'Tự ngắt khi sôi', 'Chống khô cạn'],
        390000, 259000, 0,
        'https://s.shopee.vn/aff/quickboil', 'https://quickboil.vn/1800ml'],
    ['do-gia-dung', 'Máy Lọc Nước RO PureFlow', 'may-loc-nuoc-ro-pureflow',
        'Máy lọc nước RO 9 cấp lọc, công suất 15 lít/giờ, nước tinh khiết cho gia đình.',
        ['9 cấp lọc RO', 'Công suất 15L/giờ', 'Vòi lọc thông minh', 'Bảo hành 24 tháng'],
        4990000, 3690000, 0,
        '', 'https://pureflow.vn/ro-9-cap'], // chỉ có link gốc → demo fallback

    // ===== PHỤ KIỆN =====
    ['phu-kien', 'Bàn Phím Cơ RGB KeyForce', 'ban-phim-co-rgb-keyforce',
        'Bàn phím cơ gaming switch quang, led RGB 16.8 triệu màu, khung nhôm chắc chắn.',
        ['Switch quang siêu nhạy', 'Led RGB tùy biến', 'Khung nhôm cao cấp', 'Kết nối USB-C'],
        790000, 549000, 1,
        'https://s.shopee.vn/aff/keyforce', 'https://keyforce.vn/rgb'],
    ['phu-kien', 'Chuột Không Dây Silent Click', 'chuot-khong-day-silent-click',
        'Chuột không dây click êm, cảm biến 1600DPI, thiết kế công thái học thoải mái.',
        ['Click êm không tiếng ồn', 'Cảm biến 1600DPI', 'Pin dùng 12 tháng', 'Công thái học'],
        250000, 159000, 0,
        'https://s.shopee.vn/aff/silent-click', 'https://silentclick.vn/mouse'],
    ['phu-kien', 'Giá Đỡ Laptop Nhôm ErgoStand', 'gia-do-laptop-nhom-ergostand',
        'Giá đỡ laptop hợp kim nhôm, nâng cao tản nhiệt, gấp gọn tiện mang theo.',
        ['Hợp kim nhôm bền đẹp', 'Tản nhiệt tốt', 'Điều chỉnh nhiều mức', 'Gấp gọn tiện lợi'],
        320000, 219000, 0,
        'https://s.shopee.vn/aff/ergostand', 'https://ergostand.vn/laptop'],
    ['phu-kien', 'Đèn LED Để Bàn Chống Cận StudyLight', 'den-led-de-ban-chong-can-studylight',
        'Đèn bàn LED bảo vệ mắt, 3 chế độ ánh sáng, cảm ứng điều chỉnh độ sáng.',
        ['Ánh sáng chống cận', '3 chế độ màu', 'Cảm ứng chỉnh sáng', 'Tiết kiệm điện'],
        420000, 299000, 1,
        'https://s.shopee.vn/aff/studylight', 'https://studylight.vn/led'],
];

$prodIns = $pdo->prepare(
    "INSERT INTO products
        (category_id, name, slug, description, content, meta_title, meta_description, features,
         price, sale_price, status, sort_order, is_featured, product_type, affiliate_url, original_url, image, views, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?, 'affiliate', ?,?,?,?, NOW(), NOW())"
);
$so = 0;
foreach ($products as $p) {
    [$catSlug, $name, $slug, $desc, $features, $price, $sale, $featured, $aff, $orig] = $p;
    $so++;
    $prodIns->execute([
        $catId[$catSlug], $name, $slug, $desc, pcontent($desc, $features),
        $name . ' giá tốt | ShopSieuSale', $desc,
        json_encode($features, JSON_UNESCAPED_UNICODE),
        $price, $sale > 0 ? $sale : null, $so, $featured,
        $aff !== '' ? $aff : null, $orig !== '' ? $orig : null,
        $img($slug), random_int(30, 500),
    ]);
    out("[OK] Product: $name");
}
out('Hoàn tất phần products.');

// ─────────────────────────────────────────────────────────────
// 6) BANNERS (hero slider)
// ─────────────────────────────────────────────────────────────
$banners = [
    ['Siêu Sale Công Nghệ - Giảm đến 50%', 'https://picsum.photos/seed/banner-tech/1920/560', '/danh-muc/do-cong-nghe'],
    ['Đồ Gia Dụng Chính Hãng - Ưu Đãi Cực Sốc', 'https://picsum.photos/seed/banner-home/1920/560', '/danh-muc/do-gia-dung'],
    ['Phụ Kiện Hot - Giá Chỉ Từ 99K', 'https://picsum.photos/seed/banner-acc/1920/560', '/danh-muc/phu-kien'],
];
$bnIns = $pdo->prepare("INSERT INTO banners (title, image_path, link_url, sort_order, status, created_at) VALUES (?,?,?,?,1,NOW())");
foreach ($banners as $i => $b) { $bnIns->execute([$b[0], $b[1], $b[2], $i + 1]); }
out('[OK] Đã tạo ' . count($banners) . ' banner.');

// ─────────────────────────────────────────────────────────────
// 7) POSTS (Tin tức / Blog)
// ─────────────────────────────────────────────────────────────
$posts = [
    ['Top 5 Thiết Bị Công Nghệ Đáng Mua Năm Nay', 'top-5-thiet-bi-cong-nghe-dang-mua',
        'Tổng hợp 5 món đồ công nghệ hot nhất với giá tốt, đáng để bạn rước về.',
        '<p>Công nghệ ngày càng phát triển với nhiều sản phẩm tiện ích. Dưới đây là 5 thiết bị đáng mua nhất hiện nay.</p><h3>1. Tai nghe chống ồn</h3><p>Trải nghiệm âm thanh đỉnh cao, loại bỏ tiếng ồn xung quanh.</p><h3>2. Đồng hồ thông minh</h3><p>Theo dõi sức khỏe mọi lúc mọi nơi.</p><h3>3. Sạc dự phòng</h3><p>Không lo hết pin khi di chuyển.</p>'],
    ['Mẹo Chọn Đồ Gia Dụng Tiết Kiệm Điện', 'meo-chon-do-gia-dung-tiet-kiem-dien',
        'Bí quyết chọn mua đồ gia dụng vừa bền vừa tiết kiệm điện cho gia đình bạn.',
        '<p>Đồ gia dụng tiết kiệm điện giúp bạn giảm đáng kể hóa đơn hàng tháng.</p><h3>Ưu tiên nhãn năng lượng</h3><p>Chọn sản phẩm có nhãn tiết kiệm điện 5 sao.</p><h3>Chọn công suất phù hợp</h3><p>Đừng mua công suất quá lớn so với nhu cầu.</p>'],
    ['Hướng Dẫn Săn Sale Online Hiệu Quả', 'huong-dan-san-sale-online-hieu-qua',
        'Những mẹo nhỏ giúp bạn săn được deal hời trong các đợt sale lớn.',
        '<p>Săn sale đúng cách giúp bạn tiết kiệm rất nhiều chi phí mua sắm.</p><h3>Canh khung giờ vàng</h3><p>Các deal tốt thường xuất hiện vào khung giờ cố định.</p><h3>So sánh giá trước khi mua</h3><p>Đừng vội vàng, hãy so sánh giá ở nhiều nơi.</p>'],
];
$ptIns = $pdo->prepare("INSERT INTO posts (title, slug, summary, content, meta_title, meta_description, type, status, thumbnail, created_at) VALUES (?,?,?,?,?,?, 'news', 1, ?, NOW())");
foreach ($posts as $i => $p) {
    $ptIns->execute([$p[0], $p[1], $p[2], $p[3], $p[0] . ' | ShopSieuSale', $p[2], 'https://picsum.photos/seed/post-' . ($i + 1) . '/800/450']);
}
out('[OK] Đã tạo ' . count($posts) . ' bài viết.');

// ─────────────────────────────────────────────────────────────
// 8) FAQs
// ─────────────────────────────────────────────────────────────
$faqs = [
    ['ShopSieuSale có bán hàng trực tiếp không?', 'ShopSieuSale là trang giới thiệu sản phẩm. Khi nhấn "Mua ngay", bạn sẽ được chuyển tới nơi bán uy tín để đặt hàng.'],
    ['Giá trên website có chính xác không?', 'Giá mang tính tham khảo và có thể thay đổi theo nơi bán. Vui lòng kiểm tra giá cuối cùng tại trang đặt hàng.'],
    ['Sản phẩm có được bảo hành không?', 'Chính sách bảo hành do nhà bán/nhà sản xuất cung cấp. Thông tin chi tiết có tại trang sản phẩm của nơi bán.'],
    ['Làm sao để được tư vấn thêm?', 'Bạn có thể liên hệ với chúng tôi qua trang Liên hệ hoặc các kênh hỗ trợ ở chân trang.'],
    ['Website có thu phí khi mua hàng không?', 'Hoàn toàn miễn phí. Bạn chỉ thanh toán tại nơi bán theo giá niêm yết của họ.'],
];
$fqIns = $pdo->prepare("INSERT INTO faqs (question, answer, sort_order, status) VALUES (?,?,?,1)");
foreach ($faqs as $i => $f) { $fqIns->execute([$f[0], $f[1], $i + 1]); }
out('[OK] Đã tạo ' . count($faqs) . ' FAQ.');

// ─────────────────────────────────────────────────────────────
// 9) MENUS (header + footer)
// ─────────────────────────────────────────────────────────────
$menus = [
    ['Trang chủ', 'index.php', 'header', 1],
    ['Đồ Công Nghệ', '/danh-muc/do-cong-nghe', 'header', 2],
    ['Đồ Gia Dụng', '/danh-muc/do-gia-dung', 'header', 3],
    ['Phụ Kiện', '/danh-muc/phu-kien', 'header', 4],
    ['Tin Tức', 'news.php', 'header', 5],
    ['Giới Thiệu', '/gioi-thieu', 'header', 6],
    ['Liên Hệ', 'contact.php', 'header', 7],
];
$mnIns = $pdo->prepare("INSERT INTO menus (name, url, parent_id, sort_order, position, status) VALUES (?,?,0,?,?,1)");
foreach ($menus as $m) { $mnIns->execute([$m[0], $m[1], $m[3], $m[2]]); }
out('[OK] Đã tạo menu header.');

// ─────────────────────────────────────────────────────────────
// 10) HOMEPAGE BLOCKS + DYNAMIC BLOCKS
// ─────────────────────────────────────────────────────────────
// Bật hero, tắt consultation_form
$pdo->exec("UPDATE homepage_blocks SET is_visible = 1 WHERE block_key = 'hero'");
$pdo->exec("UPDATE homepage_blocks SET is_visible = 0 WHERE block_key = 'consultation_form'");
$pdo->exec("UPDATE homepage_blocks SET is_visible = 0 WHERE block_key = 'dynamic_1772849543'");

$dynUpd = $pdo->prepare(
    "UPDATE dynamic_blocks SET title=?, subtitle=?, type='products', display_mode=?, rows_count=1,
        items_per_row=4, items_count=?, order_by=?, category_id=?, featured_only=?, layout_style='simple', status=1
     WHERE block_key=?"
);
// Nổi bật
$dynUpd->execute(['Sản Phẩm Nổi Bật', 'Những deal hot được săn đón nhiều nhất', 'slide', 8, 'featured', null, 1, 'dynamic_1772699220']);
// Đồ công nghệ
$dynUpd->execute(['Đồ Công Nghệ', 'Thiết bị thông minh giá tốt mỗi ngày', 'row', 4, 'newest', (string) $catId['do-cong-nghe'], 0, 'dynamic_1772699453']);
// Đồ gia dụng
$dynUpd->execute(['Đồ Gia Dụng', 'Tiện nghi cho gia đình hiện đại', 'row', 4, 'newest', (string) $catId['do-gia-dung'], 0, 'dynamic_1772697701']);
// Phụ kiện
$dynUpd->execute(['Phụ Kiện', 'Gaming gear & đồ dùng văn phòng', 'row', 4, 'newest', (string) $catId['phu-kien'], 0, 'dynamic_1772699850']);
// Tắt block thừa
$pdo->exec("UPDATE dynamic_blocks SET status = 0 WHERE block_key = 'dynamic_1772849543'");
out('[OK] Đã cấu hình lại homepage blocks + dynamic blocks.');

// ─────────────────────────────────────────────────────────────
// 11) SETTINGS
// ─────────────────────────────────────────────────────────────
$setUpsert = $pdo->prepare(
    "INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
);
$setUpsert->execute(['site_name', 'ShopSieuSale']);
out('[OK] Đã cập nhật site_name = ShopSieuSale');

// dọn file tạm
@unlink(__DIR__ . '/_seed_catmap.json');
out('=== HOÀN TẤT SEED AFFILIATE DEMO ===');
