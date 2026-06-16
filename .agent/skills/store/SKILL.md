# SKILL — ShopSieuSale (Affiliate Product Showcase)

> File tham khảo nội bộ cho dự án `shopsieusale` (PHP thuần, MariaDB, XAMPP).
> Mục tiêu: ghi lại kiến trúc + **chính sách affiliate Shopee** để các phiên làm việc sau tuân thủ.

---

## 1. Tổng quan dự án
- **Loại site:** Trang giới thiệu sản phẩm **affiliate** (không bán hàng trực tiếp). Click "Mua ngay" → chuyển sang nơi bán (Shopee/link gốc).
- **Stack:** PHP 8.0 (PDO), MariaDB `shopsieusale_db`, Bootstrap 5, kiến trúc CMS tự xây (header/footer/blocks).
- **Local:** `http://shopsieusale.test` (Apache), DB qua `F:/Xamp/mysql/bin/mysql.exe`, PHP CLI `F:/Xamp/php/php.exe`.
- **Tông màu:** cam-đỏ TMĐT (`--primary-color: #ee4d2d`) trong `assets/css/critical.css`.

## 2. Mô hình link mua hàng (QUAN TRỌNG)
- Mỗi sản phẩm có 2 cột: `affiliate_url` (ưu tiên) và `original_url` (link gốc dự phòng).
- Helper: `product_buy_url($p)` → trả `affiliate_url` nếu có, ngược lại `original_url`, rỗng nếu không có.
- Helper: `product_buy_link_type($p)` → `'affiliate'` | `'original'`.
- Nút "Mua ngay" **trỏ THẲNG** tới link đó + `target="_blank"` + `rel="nofollow sponsored noopener noreferrer"`, kèm `data-aff-id="<product_id>"`.

## 3. Đo lường click (KHÔNG dùng trang trung gian)
- **Lý do:** tránh mất cookie/attribution affiliate của Shopee và tránh bị xem là "cloaking".
- **Cơ chế:** link đi thẳng Shopee; JS `assets/js/affiliate-track.js` bắt `click`/`auxclick` trên `[data-aff-id]`, bắn `navigator.sendBeacon()` về `api/track-click.php` (fire-and-forget, KHÔNG preventDefault).
- `api/track-click.php`: ghi `product_clicks` (ip, user_agent, device, browser, os, referrer, link_type, is_bot) + tăng `products.click_count` (bỏ qua bot, chống trùng cùng IP+SP trong 3s). Luôn trả 204.
- `products.click_count`: bộ đếm hiển thị/xếp hạng, **sửa được trong admin để giả lập**; click thật cộng dồn.
- Bảng `product_clicks` (InnoDB) lưu chi tiết. Trang admin: `admin/clicks/index.php` (menu "Lượt click Affiliate").

## 4. Khối trang chủ
- `index.php` lặp `homepage_blocks` (is_visible, sort_order), include `includes/blocks/{block_key}.php`. Block động (`dynamic_*`) đọc `dynamic_blocks`.
- Block "TOP BÁN CHẠY" = `includes/blocks/hot_products.php` (block_key `hot_products`, thay khối `categories`). Xếp hạng theo `click_count` DESC, lưới 2 cột desktop, top 3 vàng/bạc/đồng.
- Các block honor `layout_style` (đọc `$block['layout_style']`): `hot_products`, `news`, `faq`, `consultation_form`, `dynamic.*`. Hero KHÔNG (banner slider).
- Card sản phẩm dùng chung: `includes/blocks/dynamic_card_product.php` (class `ssale-card`).

## 5. CHÍNH SÁCH AFFILIATE SHOPEE — phải tuân thủ
> Nguồn: help.shopee.vn/portal/10/article/122944 (đã diễn giải lại). Shopee có quyền quyết định cuối cùng.

**BẮT BUỘC / NÊN làm:**
- **Đăng ký website làm "Phương Tiện TTLK" và được Shopee phê duyệt** trước khi đặt link (Điều 3.5, 3.20).
- Link "Mua ngay" trỏ thẳng, người dùng tự nguyện click (Điều 3.25 — KHÔNG popup/auto-redirect/cookie-dropping/iframe/postview).
- Dữ liệu sản phẩm phải **chính xác**; giá ghi rõ "tham khảo, có thể thay đổi". Không bịa giá/đánh giá/lượt bán.
- Có **disclaimer**: site độc lập, không phải/không liên kết sở hữu với Shopee (đã đặt ở footer + `about.php`).
- Nhập thông tin sản phẩm **thủ công**.

**CẤM:**
- ❌ Trỏ link / quảng bá sản phẩm, mã giảm, voucher của **sàn đối thủ**: Lazada, Tiki, Sendo, TikTok Shop (Điều 3.27). `original_url` chỉ nên là trang chính hãng hoặc Shopee.
- ❌ Cào (scrape) dữ liệu từ Shopee (Điều 3.4).
- ❌ Dùng logo/tên "Shopee" hoặc gây nhầm site là của/ liên kết với Shopee (Điều 3.4).
- ❌ SEM/đấu thầu từ khoá chứa "Shopee" (Điều 3.22).
- ❌ Email/SMS marketing có nhắc Shopee khi chưa được duyệt bằng văn bản (Điều 3.17).
- ❌ Mua hàng qua chính link của mình / nhờ người thân mua để bán lại (Điều 3.30) → đơn bị loại, có thể bị phạt.

**Lưu ý kỹ thuật chính sách:**
- Cookie affiliate Shopee: 7 ngày. Đơn hợp lệ: hoàn tất trong 30 ngày, loại đơn từ bot/script/auto.
- Vi phạm → Shopee có thể khoá tài khoản, từ chối/thu hồi hoa hồng, phạt tới 10.000.000đ/lần gian lận, hoặc 30% số tiền đã thanh toán.

## 6. Quy tắc encoding (theo AGENTS.md)
- Tất cả file có tiếng Việt: **UTF-8 không BOM**. Ưu tiên patch cục bộ, không ghi đè cả file.
- Sau khi sửa: rà mojibake (`Ã`, `á»`, `Ä`, `Â`) và chạy `php -l` cho file PHP.
- Lưu ý: collation `utf8mb4_general_ci` accent-insensitive → LIKE `%Ã%` cho false positive; kiểm tra mojibake nên dùng so khớp byte trong PHP, không dùng SQL LIKE.

## 7. Dữ liệu / bảng
- Đã DROP: `orders`, `order_items`, `payments`, `coupons` (đã backup `backups/*.sql`).
- Bảng dùng: `products`, `categories`, `posts`, `faqs`, `banners`, `homepage_blocks`, `dynamic_blocks`, `menus`, `settings`, `seo_settings`, `product_clicks`, `traffic_*`, `users`...
- Demo: 3 danh mục (Đồ Công Nghệ, Đồ Gia Dụng, Phụ Kiện), 12 sản phẩm `product_type='affiliate'`, ảnh dùng picsum.
- Script seed/migrate giữ lại trong `scripts/`: `seed_affiliate_demo.php`, `seed_affiliate_settings.php`, `migrate_click_tracking.php`.

## 8. Đã gỡ (mô hình bán hàng cũ)
- File: `cart.php`, `checkout.php`, `pay.php`, `includes/cart.php`, `assets/js/cart.js`, các `api/cart-*`, `api/place-order.php`, `api/check-payment.php`, `api/apply-coupon.php`, `api/remove-coupon.php`, `api/sepay-webhook.php`, `admin/orders/*`, `admin/coupons/*`, `admin/ajax/{order-status,payment-status,send-software-key}.php`.
- Route `.htaccess`: đã bỏ `gio-hang`, `thanh-toan`.

## 9. Test nhanh
- Lint: `F:\Xamp\php\php.exe -l <file>`.
- Dev server tạm: `F:\Xamp\php\php.exe -S 127.0.0.1:<port> -t f:\Xamp\htdocs\shopsieusale` rồi fetch các trang (PageCache đang tắt nên không lo cache).
- URL test: `index.php`, `category.php?slug=do-cong-nghe`, `product.php?category_slug=...&slug=...`, `news.php`, `post.php?slug=...`, `contact.php`, `about.php`.
- File test tạm nên đặt tên `_*.php` trong `scripts/` và xoá sau khi xong.
