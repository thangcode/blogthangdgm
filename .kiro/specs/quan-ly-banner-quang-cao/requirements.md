# Requirements Document

> Tài liệu Yêu cầu — Tính năng Quản lý Banner Quảng cáo (nội dung tiếng Việt).

## Introduction

Tính năng cho phép quản trị viên tạo, quản lý và hiển thị các **banner quảng cáo** tại nhiều **vị trí (ad slot)** trên website (sidebar trang sản phẩm, giữa các block trang chủ, đầu/cuối bài viết, sticky đáy màn hình, v.v.). Mỗi banner có ảnh (desktop + mobile), link đích (thường là link affiliate Shopee), lịch chạy theo thời gian, thứ tự/xoay vòng khi nhiều banner cùng vị trí, và đo lường lượt hiển thị + lượt click.

Tính năng này **độc lập với Hero Slider** hiện có (bảng `banners` dùng cho slider trang chủ sẽ không bị thay đổi). Banner quảng cáo dùng bảng dữ liệu riêng và khu vực quản trị riêng.

Bối cảnh kỹ thuật cần tôn trọng:
- PHP thuần, không framework; admin nằm trong `admin/`, khối hiển thị trong `includes/blocks/`.
- Link affiliate phải gắn `rel="nofollow sponsored noopener noreferrer"`, mở tab mới, trỏ thẳng (không redirect trung gian) — đồng bộ chuẩn affiliate đang dùng.
- Ảnh giữ tối ưu (lazy-load, có `width/height`, không phá LCP của trang).
- Tiếng Việt UTF-8 không BOM; mọi truy vấn dùng prepared statement; chỉ admin đã đăng nhập mới quản lý được; CSRF cho mọi thao tác ghi.
- Migration tự tạo bảng/cột (idempotent) theo phong cách hiện tại của dự án.

## Glossary

- **Banner quảng cáo**: hình ảnh quảng cáo có link đích, đặt tại một vị trí trên site (khác Hero Slider trang chủ).
- **Vị trí / Ad slot**: khu vực cố định trong giao diện nơi banner được render (vd `product_sidebar`, `home_inline`).
- **Impression (lượt hiển thị)**: một lần banner được render hiển thị cho khách.
- **Click**: một lần khách bấm vào banner để sang URL đích.
- **CTR**: tỷ lệ click trên lượt hiển thị (clicks / impressions).
- **Rotation (xoay vòng)**: luân phiên hiển thị nhiều banner trong cùng một vị trí.
- **Hero Slider**: slider ảnh ở đầu trang chủ hiện có (bảng `banners`), KHÔNG nằm trong phạm vi tính năng này.

## Requirements

### Yêu cầu 1 — Quản lý vị trí quảng cáo (ad slot)
**User Story:** Là quản trị viên, tôi muốn có các vị trí quảng cáo định sẵn trên trang, để biết banner sẽ xuất hiện ở đâu và gắn banner vào đúng chỗ.

#### Acceptance Criteria
1. KHI hệ thống khởi tạo, THÌ hệ thống PHẢI cung cấp sẵn một tập vị trí (slot) chuẩn, tối thiểu gồm: `home_top` (đầu trang chủ), `home_inline` (giữa các block trang chủ), `product_sidebar` (cạnh nội dung trang sản phẩm), `product_below_content` (dưới nội dung sản phẩm), `post_inline` (trong bài viết), `sticky_bottom` (dải dính đáy màn hình).
2. KHI admin xem danh sách vị trí, THÌ hệ thống PHẢI hiển thị tên vị trí, mô tả ngắn vị trí xuất hiện, và số banner đang gắn.
3. NẾU một vị trí không có banner nào đang hoạt động, THÌ khu vực đó trên giao diện người dùng PHẢI không render gì (không để khoảng trống).
4. Tên/khóa của vị trí PHẢI cố định trong code để khối hiển thị tham chiếu; admin không cần tạo vị trí mới ở giai đoạn này (chỉ gắn banner vào vị trí có sẵn).

### Yêu cầu 2 — Tạo/sửa/xóa banner quảng cáo
**User Story:** Là quản trị viên, tôi muốn tạo và chỉnh sửa banner quảng cáo, để chủ động thiết lập nội dung quảng cáo theo nhu cầu.

#### Acceptance Criteria
1. KHI admin tạo banner, THÌ hệ thống PHẢI cho nhập: tiêu đề (alt/để quản lý), ảnh desktop (bắt buộc), ảnh mobile (tùy chọn), URL đích, vị trí (slot), thứ tự, trạng thái bật/tắt.
2. KHI admin lưu banner mà thiếu ảnh desktop hoặc thiếu vị trí, THÌ hệ thống PHẢI báo lỗi và không lưu.
3. KHI admin tải ảnh lên, THÌ hệ thống PHẢI tái dùng cơ chế ảnh hiện có (Media Library / nén WebP) và lưu đường dẫn tương đối (chạy đúng cả local lẫn production).
4. KHI admin xóa banner, THÌ hệ thống PHẢI yêu cầu xác nhận trước khi xóa.
5. NẾU URL đích không phải `http(s)://`, `#`, hoặc đường dẫn nội bộ hợp lệ, THÌ hệ thống PHẢI từ chối lưu và báo lỗi.
6. KHI admin chỉnh sửa nội dung tiếng Việt, THÌ dữ liệu PHẢI được giữ UTF-8 đúng (không mojibake).

### Yêu cầu 3 — Lịch chạy banner theo thời gian
**User Story:** Là quản trị viên, tôi muốn đặt thời gian bắt đầu/kết thúc cho banner, để chiến dịch quảng cáo tự bật/tắt đúng hạn mà không cần thao tác thủ công.

#### Acceptance Criteria
1. KHI admin tạo/sửa banner, THÌ hệ thống PHẢI cho phép đặt thời điểm bắt đầu và thời điểm kết thúc (đều tùy chọn).
2. NẾU thời điểm hiện tại trước thời gian bắt đầu HOẶC sau thời gian kết thúc, THÌ banner PHẢI không hiển thị trên giao diện người dùng dù trạng thái đang bật.
3. NẾU không đặt thời gian bắt đầu/kết thúc, THÌ banner PHẢI hiển thị bất cứ khi nào trạng thái đang bật.
4. KHI admin xem danh sách, THÌ hệ thống PHẢI hiển thị rõ trạng thái thực tế (Đang chạy / Đã hẹn / Hết hạn / Đã tắt).

### Yêu cầu 4 — Hiển thị banner trên giao diện người dùng
**User Story:** Là khách truy cập, tôi muốn thấy banner quảng cáo phù hợp ở đúng vị trí, để biết các ưu đãi liên quan.

#### Acceptance Criteria
1. KHI một trang có vị trí quảng cáo, THÌ hệ thống PHẢI render các banner đang hoạt động của vị trí đó theo thứ tự đã cấu hình.
2. NẾU một vị trí có nhiều banner đang hoạt động, THÌ hệ thống PHẢI hỗ trợ xoay vòng (rotation) — hiển thị luân phiên hoặc dạng slider tùy vị trí (sẽ chốt ở bước Design).
3. KHI banner có link đích là liên kết ngoài, THÌ thẻ `<a>` PHẢI có `target="_blank"` và `rel="nofollow sponsored noopener noreferrer"`.
4. KHI banner render, THÌ ảnh PHẢI có `width/height`, `loading="lazy"` (trừ banner above-the-fold), và dùng ảnh mobile khi ở màn hình nhỏ nếu có.
5. NẾU banner đặt ở vị trí ảnh hưởng LCP (vd `home_top`), THÌ hệ thống PHẢI không làm xấu điểm hiệu năng (cân nhắc preload/eager hợp lý).
6. Việc render banner PHẢI không gây lỗi trang nếu bảng/dữ liệu chưa tồn tại (bọc try/catch, fail an toàn).

### Yêu cầu 5 — Đo lường hiệu quả (hiển thị + click)
**User Story:** Là quản trị viên, tôi muốn biết banner được xem và bấm bao nhiêu lần, để đánh giá hiệu quả quảng cáo.

#### Acceptance Criteria
1. KHI một banner được hiển thị cho khách (không phải bot), THÌ hệ thống NÊN ghi nhận một lượt hiển thị (impression).
2. KHI khách bấm vào banner, THÌ hệ thống PHẢI ghi nhận một lượt click trước khi chuyển tới URL đích.
3. KHI admin xem chi tiết banner, THÌ hệ thống PHẢI hiển thị tổng lượt hiển thị, tổng lượt click và tỷ lệ CTR.
4. Việc ghi nhận click PHẢI không chặn/không làm chậm cảm nhận chuyển trang của người dùng.
5. Hệ thống NÊN loại trừ bot khỏi số liệu (tái dùng cơ chế nhận diện bot/IP tin cậy hiện có nếu phù hợp).
6. NẾU cơ chế đo lường lỗi, THÌ việc chuyển tới URL đích vẫn PHẢI hoạt động bình thường.

### Yêu cầu 6 — Sắp xếp, bật/tắt nhanh
**User Story:** Là quản trị viên, tôi muốn bật/tắt và sắp thứ tự banner nhanh chóng, để điều phối quảng cáo tức thì.

#### Acceptance Criteria
1. KHI admin bật/tắt một banner từ danh sách, THÌ trạng thái PHẢI được cập nhật ngay (không cần vào trang sửa).
2. KHI admin thay đổi thứ tự banner trong cùng một vị trí, THÌ thứ tự hiển thị ngoài giao diện PHẢI thay đổi tương ứng.
3. KHI admin lọc danh sách theo vị trí hoặc trạng thái, THÌ hệ thống PHẢI hiển thị đúng tập banner phù hợp.

### Yêu cầu 7 — Bảo mật & toàn vẹn
**User Story:** Là chủ website, tôi muốn tính năng an toàn, để tránh lạm dụng và lỗi dữ liệu.

#### Acceptance Criteria
1. KHI thực hiện bất kỳ thao tác ghi (tạo/sửa/xóa/bật-tắt) ở admin, THÌ hệ thống PHẢI kiểm tra đăng nhập admin và token CSRF.
2. KHI nhận tham số từ người dùng, THÌ hệ thống PHẢI dùng prepared statement và escape dữ liệu khi xuất ra HTML.
3. KHI ghi nhận click qua endpoint công khai, THÌ hệ thống PHẢI chống lạm dụng ở mức hợp lý (vd chỉ chấp nhận id banner hợp lệ, có thể giới hạn tần suất) và không tiết lộ thông tin nhạy cảm.
4. Endpoint chuyển hướng click PHẢI chỉ chuyển tới URL đã lưu của banner (không nhận URL tùy ý từ query để tránh open redirect).
