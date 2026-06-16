# Implementation Plan

> Kế hoạch triển khai — Quản lý Banner Quảng cáo (nội dung tiếng Việt).

## Overview

Triển khai hệ thống banner quảng cáo theo vị trí (ad slot), độc lập Hero Slider: nền tảng dữ liệu + helper, khối hiển thị, đo click/impression, nhúng vào trang, và khu quản trị (danh sách + form).

## Task Dependency Graph

```json
{
  "waves": [
    { "wave": 1, "tasks": ["1"] },
    { "wave": 2, "tasks": ["2", "3", "5", "6"] },
    { "wave": 3, "tasks": ["4"] },
    { "wave": 4, "tasks": ["7"] }
  ]
}
```

```
1 (nền tảng dữ liệu & helper)
├── 2 (khối hiển thị) ──┐
├── 3 (endpoint click + JS) ─┤
│                            ├── 4 (nhúng vị trí + flush)
├── 5 (admin danh sách)      │
└── 6 (admin form)           │
                             └── 7 (kiểm thử & rà soát) ← phụ thuộc 2,3,4,5,6
```

## Tasks

- [x] 1. Tạo nền tảng dữ liệu & helper lõi (`includes/ad-banners.php`)
  - Hàm `ad_banners_ensure_schema($pdo)` tạo bảng `ad_banners` (idempotent, bọc try/catch).
  - Hàm `ad_slots()` trả mảng các slot cố định (key, label, mô tả).
  - Hàm `get_active_ad_banners($pdo, $slot, $limit)` lọc theo status + lịch chạy + slot, sắp theo sort_order.
  - Hàm `flush_ad_impressions($pdo)` gộp 1 UPDATE tăng impressions cho id đã render.
  - _Requirements: 1.1, 1.4, 3.2, 3.3, 4.1, 5.1_

- [x] 2. Khối hiển thị banner (`includes/blocks/ad_slot.php`) + hàm `render_ad_slot()`
  - `render_ad_slot($pdo, $slot)` lấy banner active; rỗng → trả ''.
  - Render `<a data-ad-id href link trỏ thẳng rel="nofollow sponsored noopener noreferrer" target="_blank">` + ảnh desktop/mobile (lazy + width/height).
  - Hỗ trợ rotation/slider cho slot nhiều banner; ghi id đã hiển thị để flush impression.
  - CSS gọn cho từng kiểu slot (ngang/dọc/sticky có nút đóng nhớ bằng localStorage).
  - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_

- [x] 3. Endpoint đo click `api/track-ad-click.php` + mở rộng JS
  - Nhận POST id, validate id tồn tại, chống trùng IP/3s, loại bot, `UPDATE clicks = clicks + 1`.
  - Không nhận URL từ client (chống open redirect); luôn trả nhanh, không lộ lỗi.
  - Mở rộng `assets/js/affiliate-track.js` bắt thêm `[data-ad-id]` → beacon tới endpoint mới.
  - _Requirements: 5.2, 5.4, 5.5, 5.6, 7.3, 7.4_

- [x] 4. Nhúng vị trí vào giao diện + flush impression
  - Nhúng `render_ad_slot` vào: `index.php` (home_top, home_inline), `product.php` (product_sidebar, product_below_content), `post.php` (post_inline), `includes/footer.php` (sticky_bottom).
  - Gọi `flush_ad_impressions($pdo)` ở cuối `includes/footer.php` (bọc try/catch).
  - Đảm bảo không phá LCP (banner home_top xử lý ảnh hợp lý).
  - _Requirements: 1.3, 4.1, 4.5, 4.6, 5.1_

- [x] 5. Trang quản trị — danh sách (`admin/ad-banners/index.php`)
  - Bảng: ảnh nhỏ, tiêu đề, slot, lịch, trạng thái thực tế (Đang chạy/Đã hẹn/Hết hạn/Tắt), impressions, clicks, CTR.
  - Toggle bật/tắt nhanh (AJAX + CSRF); ô sort_order lưu AJAX; lọc theo slot + trạng thái; xóa có xác nhận.
  - Thêm mục "Banner quảng cáo" vào sidebar admin (`admin/includes/header.php`).
  - _Requirements: 2.4, 3.4, 5.3, 6.1, 6.2, 6.3, 7.1, 7.2_

- [x] 6. Trang quản trị — form tạo/sửa (`admin/ad-banners/form.php`)
  - Field: tiêu đề, ảnh desktop (bắt buộc) + mobile (tùy chọn) qua Media Library, URL đích, slot (select), thứ tự, start_at/end_at, trạng thái.
  - Validate server-side: ảnh desktop + slot bắt buộc, URL hợp lệ, slot thuộc `ad_slots()`; CSRF; giữ UTF-8.
  - Lưu đường dẫn ảnh tương đối; tạo/cập nhật đúng bản ghi.
  - _Requirements: 2.1, 2.2, 2.3, 2.5, 2.6, 7.1, 7.2_

- [x] 7. Kiểm thử & rà soát
  - `php -l` toàn bộ file PHP đã tạo/sửa; rà mojibake (`Ã|á»|Ä|Â|áº|Æ°`).
  - Test logic trên DB local: tạo banner từng slot, ca lịch chạy (tương lai/quá khứ/không lịch), slot rỗng không render, đếm click + chống trùng, impression +1/lần tải.
  - Kiểm tra bảo mật: endpoint admin chặn khi thiếu CSRF/đăng nhập; endpoint click chỉ nhận id.
  - _Requirements: 2.2, 3.2, 4.6, 5.2, 7.1, 7.3, 7.4_

## Notes

- Tái dùng: Media Library (`register_media_file`/`compress_to_webp`), CSRF (`generate_csrf_token`/`require_valid_csrf_token`), nhận diện bot/IP như `api/track-click.php`, mẫu toggle/sort như `admin/products`.
- Giữ UTF-8 không BOM; sau mỗi sửa file tiếng Việt phải rà mojibake. `php -l` sau khi sửa.
- Không thay đổi Hero Slider/bảng `banners` hiện có.
