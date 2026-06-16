# Thiết kế lại giao diện block "Gợi ý hôm nay"

## Bối cảnh

Block hiện tại: `includes/blocks/deal_today.php` (class `dealx`).

- Logic dữ liệu: random theo ngày bằng `RAND(Ymd)`, loại trùng TOP bán chạy, lấy tối đa 8 sản phẩm; có fallback bỏ điều kiện loại trùng nếu còn dưới 4 sản phẩm.
- Mỗi sản phẩm có sẵn các trường dùng cho UI:
  - `name`, `slug`, `category_slug`, `image`
  - `price`, `sale_price` (chỉ hiển thị sale khi `sale_price > 0 && sale_price < price`)
  - `click_count` (mức độ quan tâm), `is_featured` (HOT)
  - URL: `productUrl()`, link mua `product_buy_url()`, ảnh `get_image_url()`
- Có đồng hồ "Làm mới sau" đếm ngược tới 0h.
- Hỗ trợ nhiều nền theo `block.layout_style`: `simple, wave, gradient, glass, aurora, sunset, minimal, neon, editorial` (một số là nền tối — `dealx--dark`).

Ràng buộc chính sách (giữ nguyên, bắt buộc):
- KHÔNG dùng giá khuyến mãi ảo, KHÔNG ép mua gấp.
- Không nhái nhận diện Shopee (tên/giao diện).
- Giữ tiếng Việt UTF-8 không BOM, không làm hỏng mojibake.

Phạm vi spec này: **chỉ thiết kế lại phần trình bày (markup + CSS + JS UI)** của block. Không đổi truy vấn dữ liệu, không đổi cơ chế chọn sản phẩm theo ngày, không đổi đồng hồ đếm ngược (có thể tinh chỉnh vị trí hiển thị).

## Mục tiêu thiết kế

1. Giao diện mới mẻ, gọn gàng, không trùng cảm giác "rail cuộn ngang" hiện tại.
2. Tận dụng đúng dữ liệu sẵn có: rank `#n`, tag HOT, giá, thanh mức độ quan tâm, lượt quan tâm, nút mua.
3. Responsive tốt: desktop, tablet, mobile.
4. Hiệu năng: ảnh đầu `fetchpriority=high`, các ảnh còn lại `loading=lazy`; tôn trọng `prefers-reduced-motion`.
5. Tương thích nền theo `layout_style` (sáng/tối) như block hiện tại.

## Các phương án đã thử nghiệm (trong `demo-goiy.php`)

| Mã | Tên | Đặc điểm | Ưu | Nhược |
|----|-----|----------|-----|-------|
| A | Bento Mosaic | Lưới bất đối xứng, 1 ô lớn nổi bật + các ô nhỏ | Ấn tượng, làm nổi sản phẩm #1 | Phức tạp ở mobile, ảnh phải đẹp |
| C | Marquee tự trôi | Băng chạy ngang vô tận, hover để dừng | Sinh động, tiết kiệm chiều cao | Chuyển động liên tục có thể gây nhiễu, khó bấm trên mobile |
| D | Coupon / Ticket | Thẻ phiếu răng cưa, lưới đều | Gợi cảm giác ưu đãi, đồng đều | Cảm giác "phiếu giảm giá" dễ gần với giá ảo |
| E | Editorial số lớn | Danh sách ngang, số thứ tự lớn mờ | Sang, gọn, đọc nhanh | Ít hình ảnh nổi bật, kém "bắt mắt" |

## Phương án đề xuất

**Đề xuất chính: A — Bento Mosaic** (kết hợp tinh thần "bảng xếp hạng theo ngày").

Lý do:
- Làm nổi bật sản phẩm #1 (ô lớn) phù hợp tính chất "gợi ý chọn lọc mỗi ngày".
- Thoát hẳn cảm giác rail cuộn ngang cũ.
- Vẫn hiển thị đủ rank, HOT, giá, lượt quan tâm — dùng đúng dữ liệu hiện có.

Phương án dự phòng nếu cần ít rủi ro layout mobile: **D — Coupon/Ticket** dạng lưới đều (bỏ yếu tố "phiếu giảm giá" để tránh hiểu nhầm giá ảo).

> Cần bạn xác nhận chọn A (hay phương án khác) trước khi mình chi tiết hóa thành markup áp vào block thật.

## Cấu trúc layout đề xuất (Phương án A)

```
section.dealx (giữ class nền theo layout_style)
  .container
    .dealx__head
      .dealx__brand  (icon + "GỢI Ý HÔM NAY" + phụ đề)
      .dealx__timer  (đồng hồ "Làm mới sau")
    .dealx__bento
      .dealx-cell.dealx-cell--feat   -> sản phẩm #1 (ô 2x2)
      .dealx-cell  x N               -> các sản phẩm còn lại
```

Bento grid:
- Desktop (>=992px): `grid-template-columns: repeat(4, 1fr)`, ô feature `span 2 / span 2`, tổng ~7 ô.
- Tablet (768–991px): `repeat(3, 1fr)`, ô feature `span 2`.
- Mobile (<768px): `repeat(2, 1fr)`, ô feature `span 2` (1 hàng ngang), các ô còn lại vuông.

Mỗi ô (`.dealx-cell`):
- Ảnh nền phủ kín (`object-fit: cover`), lớp `shade` gradient tối ở đáy để chữ dễ đọc.
- Góc trên trái: rank `#n`. Góc trên phải: tag HOT (khi `is_featured`).
- Đáy: tên (clamp 2 dòng; ô feature 3 dòng), giá, dòng "lượt quan tâm".
- Ô feature: thêm nút "Mua ngay" hiển thị trực tiếp; các ô nhỏ click cả thẻ để vào trang sản phẩm.

## Thành phần hiển thị (map dữ liệu)

| Phần tử UI | Nguồn dữ liệu | Ghi chú |
|------------|---------------|---------|
| Ảnh | `get_image_url(item.image,'product')` | Có nhánh no-image |
| Rank #n | chỉ số vòng lặp `$i+1` | |
| HOT | `item.is_featured` | |
| Giá | `sale_price` nếu hợp lệ, ngược lại `price` | `number_format(...,0,',','.')` + `₫` |
| Lượt quan tâm | `click_count` | rút gọn `k` khi >= 1000 |
| Thanh quan tâm | `click_count / max_click` | min 12% (giữ như hiện tại) |
| Link sản phẩm | `productUrl(slug, category_slug)` | |
| Nút mua | `product_buy_url(item)` | `rel="nofollow sponsored noopener noreferrer"`, `target=_blank`, `data-aff-id` |

## Responsive

- Desktop: bento 4 cột, ô feature 2x2, hover phóng nhẹ ảnh + nâng thẻ.
- Tablet: 3 cột, ô feature 2 cột.
- Mobile: 2 cột; ô feature trải 2 cột tỉ lệ 16/10; chữ và nút thu nhỏ.

## Khả năng truy cập (Accessibility)

- Mỗi thẻ là link có `alt` ảnh = tên sản phẩm.
- Rank/HOT là phần trang trí nhưng tên + giá vẫn là text thật (không nằm trong ảnh).
- Tương phản chữ trên ảnh đảm bảo bằng lớp `shade` tối ở đáy.
- Tôn trọng `prefers-reduced-motion`: tắt animation icon/thanh quan tâm.
- Nút mua đủ lớn để bấm trên mobile (>= 40px chiều cao).

## Hiệu năng

- Ảnh ô feature: `fetchpriority="high"`; các ảnh khác `loading="lazy" decoding="async"`.
- CSS in-block, nạp 1 lần qua cờ `$GLOBALS['_dealx_css']` (giữ cơ chế hiện tại).
- Không thêm thư viện JS ngoài; đồng hồ đếm ngược tái dùng script hiện tại.

## Tác động & rủi ro

- Thay markup + CSS trong `deal_today.php`; giữ nguyên phần truy vấn ở đầu file.
- Các nền `layout_style` cần kiểm tra lại tương phản chữ với layout bento mới (đặc biệt nền tối).
- Sau khi chốt và áp dụng: xóa file demo `demo-goiy.php`.

## Việc cần xác nhận từ bạn

1. Chọn phương án giao diện: A (đề xuất) hay C/D/E?
2. Số lượng sản phẩm hiển thị giữ 8 hay đổi (bento đẹp nhất với 7)?
3. Giữ đồng hồ "Làm mới sau" ở header như hiện tại?

Sau khi bạn chốt, mình sẽ tạo `tasks.md` và bắt đầu áp dụng vào block thật theo nguyên tắc patch tối thiểu, giữ tiếng Việt UTF-8.
