# Design Document

> Tài liệu Thiết kế — Tính năng Quản lý Banner Quảng cáo (nội dung tiếng Việt).

## Overview

Tính năng bổ sung một hệ thống **banner quảng cáo theo vị trí (ad slot)** độc lập với Hero Slider hiện có. Gồm 4 phần:

1. **Dữ liệu**: bảng mới `ad_banners` (migration idempotent, tự tạo khi cần).
2. **Quản trị**: khu vực `admin/ad-banners/` (danh sách + form tạo/sửa) — tái dùng layout admin, Media Library, CSRF, mẫu toggle/sort như `admin/products` và `admin/dynamic-blocks`.
3. **Hiển thị**: helper `render_ad_slot($pdo, $slot)` + khối `includes/blocks/ad_slot.php`, nhúng vào các điểm: trang chủ (`index.php` qua block), trang sản phẩm (`product.php`), bài viết (`post.php`), và sticky đáy (footer).
4. **Đo lường**: click qua `sendBeacon` tới `api/track-ad-click.php` (link giữ trỏ thẳng, không redirect trung gian — đồng bộ `affiliate-track.js`); impression đếm gộp 1 truy vấn/lần render trang.

Nguyên tắc bám theo dự án: PHP thuần, prepared statement, escape khi xuất HTML, UTF-8, fail an toàn (try/catch), không phá LCP, link affiliate `rel="nofollow sponsored noopener noreferrer"`.

## Architecture

```
Khách truy cập
   │
   ├── Trang chủ / Sản phẩm / Bài viết
   │        │  render_ad_slot($pdo, '<slot>')  → includes/blocks/ad_slot.php
   │        │      • SELECT banner đang hoạt động theo slot (status + lịch chạy)
   │        │      • render <a data-ad-id> (link trỏ thẳng, nofollow sponsored)
   │        │      • gom id đã hiển thị → 1 UPDATE impressions += 1 (cuối request)
   │        ▼
   │   Click banner → affiliate-track.js (mở rộng bắt [data-ad-id])
   │        │  navigator.sendBeacon → api/track-ad-click.php (id)
   │        ▼  (đồng thời trình duyệt điều hướng thẳng tới link đích)
   │   api/track-ad-click.php → tăng ad_banners.clicks (+ chống trùng theo IP/giây)
   │
Admin (đã đăng nhập)
   └── admin/ad-banners/index.php (danh sách, toggle, sort, lọc)
       admin/ad-banners/form.php  (tạo/sửa: ảnh, link, slot, lịch, thứ tự)
```

### Vị trí (ad slot) — khai báo cố định trong code
Một mảng hằng `AD_SLOTS` (đặt trong `includes/functions.php` hoặc file riêng `includes/ad-banners.php`):

| Khóa slot | Mô tả vị trí | Kiểu render |
|---|---|---|
| `home_top` | Ngay dưới Hero trang chủ | 1 banner ngang (eager nếu là banner đầu) |
| `home_inline` | Chèn giữa các block trang chủ | 1 banner ngang / rotation |
| `product_sidebar` | Cột phải trang chi tiết sản phẩm | banner dọc, rotation |
| `product_below_content` | Dưới nội dung chi tiết sản phẩm | banner ngang |
| `post_inline` | Trong/đầu bài viết | banner ngang |
| `sticky_bottom` | Dải dính đáy màn hình (có nút đóng) | 1 banner, dismiss bằng localStorage |

> Slot rỗng (không banner hoạt động) ⇒ helper trả chuỗi rỗng, không render gì.

## Components and Interfaces

### 1. Migration & hằng số
- `ad_banners_ensure_schema($pdo)`: tạo bảng `ad_banners` nếu chưa có (gọi ở đầu các trang admin liên quan + helper render, bọc try/catch).
- `ad_slots()`: trả mảng `['slot_key' => ['label' => ..., 'desc' => ...], ...]` để admin hiển thị và validate.

### 2. Helper hiển thị (frontend)
```php
// includes/ad-banners.php
function get_active_ad_banners(PDO $pdo, string $slot, int $limit = 5): array;
//   SELECT * FROM ad_banners
//   WHERE slot = ? AND status = 1
//     AND (start_at IS NULL OR start_at <= NOW())
//     AND (end_at   IS NULL OR end_at   >= NOW())
//   ORDER BY sort_order ASC, id DESC LIMIT ?

function render_ad_slot(PDO $pdo, string $slot): string;
//   - Lấy banner active; nếu rỗng → '' .
//   - Render HTML (include includes/blocks/ad_slot.php với $ad_items, $slot).
//   - Đăng ký id đã hiển thị vào $GLOBALS['_ad_impressions'] để flush cuối request.

function flush_ad_impressions(PDO $pdo): void;
//   - Nếu có id: UPDATE ad_banners SET impressions = impressions + 1 WHERE id IN (...) .
//   - Gọi 1 lần ở footer.php (bọc try/catch).
```

- **Rotation**: nếu slot cho phép nhiều banner, `ad_slot.php` render dạng slider nhẹ (tái dùng Swiper đã có) hoặc chọn ngẫu nhiên 1 banner mỗi lần tải (cấu hình theo slot trong `ad_slot.php`). Mặc định: `product_sidebar`/`home_inline` xoay vòng, các slot còn lại hiển thị banner đầu theo `sort_order`.

### 3. Endpoint đo click — `api/track-ad-click.php`
- Nhận `POST id` (qua `sendBeacon`).
- Validate id là số > 0 và tồn tại trong `ad_banners`.
- Chống đếm trùng: cùng IP + cùng banner trong 3 giây → bỏ qua (giống `track-click.php`).
- Loại bot (tái dùng helper nhận diện bot nếu có).
- `UPDATE ad_banners SET clicks = clicks + 1 WHERE id = ?`.
- Luôn trả 204/200 nhanh, không trả dữ liệu nhạy cảm. **Không** nhận URL từ client (tránh open redirect) — link đích nằm ở thuộc tính `href` trỏ thẳng, không qua server.

### 4. JS đo click
- Mở rộng `assets/js/affiliate-track.js` để bắt thêm `[data-ad-id]` và bắn về `api/track-ad-click.php` (cùng cơ chế `sendBeacon`, fire-and-forget, không `preventDefault`). Không tạo file JS mới để tránh thêm request.

### 5. Quản trị
- `admin/ad-banners/index.php`:
  - Bảng danh sách: ảnh thu nhỏ, tiêu đề, slot, lịch chạy, trạng thái thực tế (badge: Đang chạy/Đã hẹn/Hết hạn/Tắt), impressions, clicks, CTR.
  - Toggle bật/tắt nhanh (AJAX, CSRF) — mẫu như `admin/products/index.php`.
  - Sắp thứ tự (ô số sort_order lưu AJAX, hoặc kéo-thả SortableJS như homepage-blocks).
  - Lọc theo slot + trạng thái.
- `admin/ad-banners/form.php`:
  - Field: tiêu đề, ảnh desktop (bắt buộc) + mobile (tùy chọn) qua Media Library, URL đích, slot (select từ `ad_slots()`), thứ tự, start_at/end_at (datetime-local), trạng thái.
  - Validate server-side: ảnh desktop + slot bắt buộc; URL hợp lệ (`http(s)://`, `#`, hoặc nội bộ); slot phải nằm trong `ad_slots()`.
  - Thêm mục vào sidebar admin (`admin/includes/header.php`) mục "Banner quảng cáo" tách khỏi "Banner Slider".

## Data Models

### Bảng `ad_banners`
```sql
CREATE TABLE IF NOT EXISTS `ad_banners` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`             VARCHAR(255) NOT NULL DEFAULT '',
  `image_path`        VARCHAR(500) NOT NULL DEFAULT '',      -- ảnh desktop (đường dẫn tương đối)
  `mobile_image_path` VARCHAR(500) NOT NULL DEFAULT '',      -- ảnh mobile (tùy chọn)
  `link_url`          VARCHAR(1000) NOT NULL DEFAULT '',     -- URL đích (trỏ thẳng)
  `slot`              VARCHAR(40) NOT NULL DEFAULT '',       -- khóa vị trí (ad_slots)
  `sort_order`        INT NOT NULL DEFAULT 0,
  `status`            TINYINT(1) NOT NULL DEFAULT 1,         -- 1 bật / 0 tắt
  `start_at`          DATETIME NULL DEFAULT NULL,
  `end_at`            DATETIME NULL DEFAULT NULL,
  `impressions`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slot_status` (`slot`, `status`),
  KEY `idx_schedule` (`start_at`, `end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Ghi chú:
- Dùng counter `impressions`/`clicks` ngay trên dòng để xem nhanh CTR, không cần bảng log chi tiết ở giai đoạn này (giảm ghi DB). Có thể mở rộng bảng log riêng sau nếu cần phân tích theo thời gian.
- Trạng thái thực tế tính ở tầng hiển thị/khâu render dựa trên `status` + `start_at`/`end_at` so với `NOW()`.

## Error Handling

- Mọi truy vấn frontend (`get_active_ad_banners`, `flush_ad_impressions`) bọc `try/catch`; lỗi → trả rỗng/không làm gì, **không** vỡ trang.
- `ad_banners_ensure_schema` chạy idempotent; nếu tạo bảng lỗi vẫn cho trang chạy tiếp.
- Endpoint click: id sai/không tồn tại → trả 204 im lặng (không lộ lỗi), không tăng đếm.
- Lỗi đo lường (beacon thất bại / endpoint lỗi) KHÔNG ảnh hưởng điều hướng tới link đích (vì link là `href` trỏ thẳng, beacon độc lập).
- Admin: thao tác ghi sai CSRF/chưa đăng nhập → chặn + thông báo; lỗi DB → flash error, không trắng trang.
- Encoding: form admin patch tối thiểu, giữ UTF-8; sau khi sửa rà mojibake.

## Correctness Properties

Các bất biến (invariant) cần luôn đúng — dùng làm cơ sở kiểm thử:

### Property 1: Lịch chạy quyết định hiển thị
Với mọi banner, banner chỉ nằm trong kết quả `get_active_ad_banners` KHI VÀ CHỈ KHI `status = 1` VÀ (`start_at` rỗng hoặc `<= NOW()`) VÀ (`end_at` rỗng hoặc `>= NOW()`).
**Validates: Requirements 3.2, 3.3, 4.1**

### Property 2: Slot rỗng không render
Nếu một slot không có banner hoạt động, `render_ad_slot` luôn trả chuỗi rỗng (không sinh thẻ wrapper trống).
**Validates: Requirements 1.3, 4.6**

### Property 3: Đúng slot
Mọi banner trả về cho slot X đều có `slot == X`.
**Validates: Requirements 1.4, 4.1**

### Property 4: Đúng thứ tự
Danh sách trả về luôn không giảm theo `sort_order` (rồi tới `id` giảm dần khi cùng `sort_order`).
**Validates: Requirements 4.1, 6.2**

### Property 5: Link trỏ thẳng
HTML render cho banner có link ngoài luôn có `href` = `link_url` của banner và `rel` chứa `nofollow sponsored noopener noreferrer`; không URL đích nào đi qua server.
**Validates: Requirements 4.3, 7.4**

### Property 6: Đếm không âm & đơn điệu
`impressions` và `clicks` chỉ tăng (không bao giờ giảm); một lần tải trang làm tăng impression của mỗi banner đã render tối đa 1.
**Validates: Requirements 5.1, 5.2, 5.3**

### Property 7: Chống trùng click
Hai beacon click cùng IP + cùng banner trong vòng 3 giây chỉ làm `clicks` tăng tối đa 1.
**Validates: Requirements 5.2, 7.3**

### Property 8: An toàn endpoint
`api/track-ad-click.php` với id không hợp lệ/không tồn tại không làm thay đổi dữ liệu và không tiết lộ lỗi chi tiết.
**Validates: Requirements 7.3, 7.4**

## Testing Strategy

- **Đơn vị (logic)**: hàm `get_active_ad_banners` lọc đúng theo `status` + lịch chạy (các ca: chưa tới hạn, đang chạy, hết hạn, không đặt lịch); validate URL/slot ở form.
- **Tích hợp (thủ công trên local)**:
  - Tạo banner cho từng slot → kiểm tra hiển thị đúng vị trí, đúng thứ tự, slot rỗng không render.
  - Đặt lịch tương lai/quá khứ → kiểm tra ẩn/hiện đúng.
  - Click banner → `clicks` tăng, link vẫn mở thẳng tab mới; chống trùng 3 giây hoạt động.
  - Reload trang có banner → `impressions` tăng đúng 1/lần tải.
  - Mobile: dùng ảnh mobile; sticky_bottom có nút đóng và nhớ trạng thái đóng.
- **Bảo mật**: gọi endpoint admin không CSRF/không đăng nhập → bị chặn; endpoint click chỉ nhận id, không nhận URL.
- **Kiểm tra kỹ thuật**: `php -l` các file PHP đã sửa; rà mojibake (`Ã|á»|Ä|Â|áº|Æ°`); kiểm tra không phá LCP trang chủ (banner `home_top` xử lý ảnh hợp lý).
