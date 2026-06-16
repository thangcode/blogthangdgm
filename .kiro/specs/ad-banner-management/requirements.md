# Requirements Document

## Introduction

Tính năng **Quản lý Banner Quảng cáo** cho phép quản trị viên của ShopSieuSale tạo, cấu hình và hiển thị các banner quảng cáo ở nhiều vị trí khác nhau trên website (ngoài Hero Slider hiện có ở đầu trang chủ). Tính năng hướng tới việc quảng bá sản phẩm, chương trình khuyến mãi, hoặc nội dung của đối tác/affiliate một cách linh hoạt và chuyên nghiệp.

Phạm vi chính:
- Quản lý các **Vị trí đặt banner (Placement)** được định nghĩa sẵn trên site: dưới header, giữa các block trang chủ, sidebar trang sản phẩm/bài viết, trong nội dung, footer, popup.
- Quản lý từng **Banner Quảng cáo (Ad_Banner)** với nhiều loại nội dung: ảnh đơn có liên kết, slider nhiều ảnh, hoặc mã HTML/script của bên thứ ba (ví dụ Google AdSense).
- Lập **lịch chạy** (ngày bắt đầu/kết thúc), bật/tắt trạng thái, **nhắm mục tiêu** theo thiết bị, sắp xếp **thứ tự ưu tiên** và **xoay vòng** khi nhiều banner cùng một vị trí.
- **Đo lường** lượt hiển thị (impressions) và lượt click thực tế, không tạo metric giả.
- Tách biệt rõ với cơ chế Hero Slider hiện tại (bảng `banners` cũ giữ nguyên cho Hero), tránh phá vỡ chức năng đang chạy.

Tính năng tuân thủ nguyên tắc dự án: affiliate trỏ thẳng với thuộc tính `rel="nofollow sponsored noopener noreferrer"`, ảnh tối ưu (lazy-load, có width/height, ưu tiên WebP), bảo vệ CSRF trong khu vực admin, và nội dung tiếng Việt UTF-8 không BOM.

## Glossary

- **Ad_Banner_System (Hệ thống Banner Quảng cáo)**: Toàn bộ phân hệ phần mềm chịu trách nhiệm tạo, lưu trữ, lập lịch, hiển thị và đo lường banner quảng cáo.
- **Ad_Banner (Banner Quảng cáo)**: Một đơn vị quảng cáo có nội dung, vị trí, lịch chạy và cấu hình nhắm mục tiêu riêng.
- **Placement (Vị trí đặt banner)**: Một khu vực hiển thị được định nghĩa sẵn trên website, xác định bằng một khóa định danh (placement key) duy nhất. Ví dụ: `header_below`, `home_between_blocks`, `product_sidebar`, `article_sidebar`, `in_content`, `footer`, `popup`.
- **Banner_Type (Loại banner)**: Phân loại nội dung của một Ad_Banner. Giá trị hợp lệ: `single_image` (ảnh đơn), `image_slider` (nhiều ảnh xoay vòng), `html_embed` (mã HTML/script bên thứ ba).
- **Schedule (Lịch chạy)**: Khoảng thời gian có ngày bắt đầu (start_date) và ngày kết thúc (end_date) mà trong đó Ad_Banner đủ điều kiện hiển thị.
- **Device_Target (Nhắm mục tiêu thiết bị)**: Cấu hình loại thiết bị mà Ad_Banner được phép hiển thị. Giá trị hợp lệ: `all` (mọi thiết bị), `desktop`, `mobile`.
- **Priority (Độ ưu tiên)**: Số nguyên xác định thứ tự hiển thị của các Ad_Banner trong cùng một Placement; số nhỏ hơn được ưu tiên trước.
- **Rotation_Mode (Chế độ xoay vòng)**: Cách chọn banner để hiển thị khi nhiều Ad_Banner đủ điều kiện trong cùng một Placement. Giá trị hợp lệ: `priority` (theo độ ưu tiên), `random` (ngẫu nhiên), `all` (hiển thị tất cả).
- **Impression (Lượt hiển thị)**: Một lần Ad_Banner được render ra trang và hiển thị cho người dùng.
- **Click (Lượt nhấp)**: Một lần người dùng nhấp vào liên kết của Ad_Banner.
- **Active_State (Trạng thái hoạt động)**: Trạng thái bật/tắt của một Ad_Banner. Giá trị hợp lệ: `enabled` (bật), `disabled` (tắt).
- **Eligible_Banner (Banner đủ điều kiện)**: Một Ad_Banner có Active_State là `enabled`, nằm trong khoảng Schedule hợp lệ tại thời điểm yêu cầu, và phù hợp với Device_Target của thiết bị hiện tại.
- **Admin_User (Quản trị viên)**: Người dùng đã đăng nhập vào khu vực admin và có quyền quản lý banner quảng cáo.
- **CSRF_Token (Token CSRF)**: Chuỗi bí mật dùng để xác thực rằng yêu cầu thay đổi dữ liệu xuất phát từ phiên hợp lệ của Admin_User.
- **Hero_Slider**: Cơ chế slider hiện có ở đầu trang chủ, sử dụng bảng `banners` cũ, nằm ngoài phạm vi quản lý của Ad_Banner_System.

## Requirements

### Requirement 1: Quản lý vòng đời Banner Quảng cáo

**User Story:** Là một Admin_User, tôi muốn tạo, xem, sửa và xóa banner quảng cáo, để tôi có thể chủ động kiểm soát nội dung quảng cáo hiển thị trên website.

#### Acceptance Criteria

1. WHEN Admin_User gửi biểu mẫu tạo banner với dữ liệu hợp lệ, THE Ad_Banner_System SHALL lưu một Ad_Banner mới và hiển thị thông báo tạo thành công.
2. WHEN Admin_User mở trang danh sách banner, THE Ad_Banner_System SHALL hiển thị tất cả Ad_Banner kèm theo Placement, Banner_Type, Active_State và Schedule của từng banner.
3. WHEN Admin_User gửi biểu mẫu cập nhật một Ad_Banner với dữ liệu hợp lệ, THE Ad_Banner_System SHALL lưu các thay đổi và hiển thị thông báo cập nhật thành công.
4. WHEN Admin_User xác nhận xóa một Ad_Banner, THE Ad_Banner_System SHALL xóa Ad_Banner đó khỏi danh sách hiển thị.
5. IF Admin_User gửi biểu mẫu tạo hoặc cập nhật banner thiếu trường bắt buộc, THEN THE Ad_Banner_System SHALL từ chối lưu và hiển thị thông báo nêu rõ trường còn thiếu.

### Requirement 2: Quản lý Vị trí đặt banner (Placement)

**User Story:** Là một Admin_User, tôi muốn gán banner vào các vị trí được định nghĩa sẵn trên site, để banner xuất hiện đúng khu vực mong muốn mà không cần sửa code.

#### Acceptance Criteria

1. THE Ad_Banner_System SHALL cung cấp một tập Placement được định nghĩa sẵn gồm: `header_below`, `home_between_blocks`, `product_sidebar`, `article_sidebar`, `in_content`, `footer`, `popup`.
2. WHEN Admin_User tạo hoặc cập nhật một Ad_Banner, THE Ad_Banner_System SHALL yêu cầu Admin_User chọn đúng một Placement từ tập Placement được định nghĩa sẵn.
3. WHEN một trang chứa một Placement được render, THE Ad_Banner_System SHALL chỉ hiển thị các Eligible_Banner được gán cho Placement đó.
4. IF một Placement không có Eligible_Banner nào tại thời điểm render, THEN THE Ad_Banner_System SHALL render trang mà không chèn nội dung banner cho Placement đó.

### Requirement 3: Hỗ trợ nhiều Loại banner (Banner_Type)

**User Story:** Là một Admin_User, tôi muốn chọn loại nội dung banner (ảnh đơn, slider nhiều ảnh, hoặc mã HTML/script bên thứ ba), để đáp ứng các định dạng quảng cáo khác nhau.

#### Acceptance Criteria

1. WHEN Admin_User tạo một Ad_Banner, THE Ad_Banner_System SHALL cho phép chọn đúng một Banner_Type trong tập: `single_image`, `image_slider`, `html_embed`.
2. WHERE Banner_Type là `single_image`, THE Ad_Banner_System SHALL hiển thị một ảnh kèm liên kết đích đã cấu hình.
3. WHERE Banner_Type là `image_slider`, THE Ad_Banner_System SHALL hiển thị các ảnh đã cấu hình dưới dạng slider xoay vòng.
4. WHERE Banner_Type là `html_embed`, THE Ad_Banner_System SHALL render đoạn mã HTML/script do Admin_User cung cấp tại Placement đã chọn.
5. IF Banner_Type là `single_image` hoặc `image_slider` nhưng không có ảnh nào được cung cấp, THEN THE Ad_Banner_System SHALL từ chối lưu và hiển thị thông báo yêu cầu cung cấp ít nhất một ảnh.
6. IF Banner_Type là `html_embed` nhưng nội dung mã rỗng, THEN THE Ad_Banner_System SHALL từ chối lưu và hiển thị thông báo yêu cầu nhập nội dung mã.

### Requirement 4: Lập lịch chạy và Trạng thái hoạt động

**User Story:** Là một Admin_User, tôi muốn đặt ngày bắt đầu/kết thúc và bật/tắt banner, để banner tự động hiển thị và ngừng hiển thị theo đúng kế hoạch.

#### Acceptance Criteria

1. WHEN Admin_User đặt Active_State của một Ad_Banner thành `disabled`, THE Ad_Banner_System SHALL không hiển thị Ad_Banner đó trên website.
2. WHILE thời điểm hiện tại nằm trong khoảng Schedule và Active_State là `enabled`, THE Ad_Banner_System SHALL coi Ad_Banner đó là Eligible_Banner.
3. IF thời điểm hiện tại trước start_date của một Ad_Banner, THEN THE Ad_Banner_System SHALL không hiển thị Ad_Banner đó.
4. IF thời điểm hiện tại sau end_date của một Ad_Banner, THEN THE Ad_Banner_System SHALL không hiển thị Ad_Banner đó.
5. WHERE một Ad_Banner không có start_date, THE Ad_Banner_System SHALL coi banner đó là đủ điều kiện về thời gian bắt đầu kể từ thời điểm tạo.
6. WHERE một Ad_Banner không có end_date, THE Ad_Banner_System SHALL coi banner đó là đủ điều kiện về thời gian kết thúc cho đến khi Admin_User thay đổi cấu hình.
7. IF Admin_User nhập end_date sớm hơn start_date, THEN THE Ad_Banner_System SHALL từ chối lưu và hiển thị thông báo yêu cầu end_date không sớm hơn start_date.

### Requirement 5: Nhắm mục tiêu theo thiết bị

**User Story:** Là một Admin_User, tôi muốn giới hạn banner hiển thị theo loại thiết bị, để tối ưu trải nghiệm và hiệu quả quảng cáo trên desktop và mobile.

#### Acceptance Criteria

1. WHEN Admin_User cấu hình một Ad_Banner, THE Ad_Banner_System SHALL cho phép chọn đúng một Device_Target trong tập: `all`, `desktop`, `mobile`.
2. WHERE Device_Target là `desktop`, THE Ad_Banner_System SHALL chỉ hiển thị Ad_Banner trên thiết bị desktop.
3. WHERE Device_Target là `mobile`, THE Ad_Banner_System SHALL chỉ hiển thị Ad_Banner trên thiết bị mobile.
4. WHERE Device_Target là `all`, THE Ad_Banner_System SHALL hiển thị Ad_Banner trên mọi loại thiết bị.

### Requirement 6: Thứ tự ưu tiên và Xoay vòng trong cùng Vị trí

**User Story:** Là một Admin_User, tôi muốn kiểm soát thứ tự và cách xoay vòng khi nhiều banner cùng một vị trí, để phân bổ hiển thị theo ý đồ quảng cáo.

#### Acceptance Criteria

1. WHEN Admin_User cấu hình một Placement, THE Ad_Banner_System SHALL cho phép chọn đúng một Rotation_Mode trong tập: `priority`, `random`, `all`.
2. WHERE Rotation_Mode của một Placement là `priority` và có nhiều Eligible_Banner, THE Ad_Banner_System SHALL hiển thị Eligible_Banner có Priority nhỏ nhất.
3. WHERE Rotation_Mode của một Placement là `random` và có nhiều Eligible_Banner, THE Ad_Banner_System SHALL chọn ngẫu nhiên một Eligible_Banner để hiển thị cho mỗi lần render.
4. WHERE Rotation_Mode của một Placement là `all` và có nhiều Eligible_Banner, THE Ad_Banner_System SHALL hiển thị tất cả Eligible_Banner theo thứ tự Priority tăng dần.
5. IF hai Eligible_Banner trong cùng Placement có cùng giá trị Priority, THEN THE Ad_Banner_System SHALL sắp xếp chúng theo thứ tự thời gian tạo giảm dần.

### Requirement 7: Liên kết đích và Thuộc tính an toàn

**User Story:** Là một Admin_User, tôi muốn cấu hình liên kết đích cho banner với thuộc tính an toàn và chuẩn affiliate, để bảo vệ SEO và tuân thủ nguyên tắc affiliate của dự án.

#### Acceptance Criteria

1. WHERE một Ad_Banner có liên kết đích, THE Ad_Banner_System SHALL render liên kết đó với thuộc tính `rel="nofollow sponsored noopener noreferrer"`.
2. WHEN Admin_User chọn mở liên kết trong tab mới cho một Ad_Banner, THE Ad_Banner_System SHALL render liên kết với thuộc tính `target="_blank"`.
3. THE Ad_Banner_System SHALL render liên kết affiliate trỏ thẳng đến URL đích mà Admin_User đã cấu hình.
4. IF Admin_User nhập một URL đích sai định dạng, THEN THE Ad_Banner_System SHALL từ chối lưu và hiển thị thông báo yêu cầu nhập URL hợp lệ.

### Requirement 8: Đo lường lượt hiển thị và lượt nhấp

**User Story:** Là một Admin_User, tôi muốn theo dõi lượt hiển thị và lượt nhấp thực tế của từng banner, để đánh giá hiệu quả quảng cáo dựa trên dữ liệu thật.

#### Acceptance Criteria

1. WHEN một Ad_Banner được render cho người dùng, THE Ad_Banner_System SHALL tăng số đếm Impression của Ad_Banner đó thêm 1.
2. WHEN một người dùng nhấp vào liên kết của một Ad_Banner, THE Ad_Banner_System SHALL ghi nhận một Click cho Ad_Banner đó.
3. WHEN Admin_User xem chi tiết một Ad_Banner, THE Ad_Banner_System SHALL hiển thị tổng số Impression và tổng số Click thực tế đã ghi nhận của Ad_Banner đó.
4. THE Ad_Banner_System SHALL ghi nhận chỉ các Impression và Click phát sinh từ tương tác thực tế của người dùng.

### Requirement 9: Tối ưu hiệu năng và hiển thị an toàn

**User Story:** Là một chủ website, tôi muốn banner được tải tối ưu và không chặn hiển thị trang, để giữ tốc độ tải trang và trải nghiệm người dùng.

#### Acceptance Criteria

1. WHEN một Ad_Banner loại ảnh được render ngoài vùng nhìn ban đầu, THE Ad_Banner_System SHALL áp dụng thuộc tính `loading="lazy"` cho ảnh đó.
2. THE Ad_Banner_System SHALL render mỗi ảnh banner kèm thuộc tính `width` và `height` để giảm dịch chuyển bố cục (layout shift).
3. WHERE tồn tại phiên bản ảnh dành cho mobile, THE Ad_Banner_System SHALL phục vụ ảnh mobile cho thiết bị mobile và ảnh desktop cho thiết bị desktop.
4. IF một Ad_Banner tham chiếu đến tệp ảnh không tồn tại, THEN THE Ad_Banner_System SHALL bỏ qua việc render banner đó và tiếp tục render phần còn lại của trang.

### Requirement 10: Phân quyền và Bảo vệ CSRF trong khu vực admin

**User Story:** Là một chủ website, tôi muốn chỉ quản trị viên đã đăng nhập mới được thay đổi banner và mọi thao tác ghi đều được bảo vệ CSRF, để ngăn truy cập và thay đổi trái phép.

#### Acceptance Criteria

1. IF một yêu cầu quản lý banner đến từ người dùng chưa đăng nhập admin, THEN THE Ad_Banner_System SHALL từ chối yêu cầu và chuyển hướng đến trang đăng nhập admin.
2. WHEN Admin_User gửi một thao tác tạo, cập nhật hoặc xóa banner, THE Ad_Banner_System SHALL xác thực CSRF_Token của yêu cầu trước khi thực hiện thao tác.
3. IF CSRF_Token của một yêu cầu ghi không hợp lệ hoặc bị thiếu, THEN THE Ad_Banner_System SHALL từ chối thao tác và hiển thị thông báo lỗi xác thực.

### Requirement 11: Tách biệt với Hero Slider hiện có

**User Story:** Là một nhà phát triển, tôi muốn tính năng banner quảng cáo tách biệt khỏi Hero Slider hiện tại, để không phá vỡ chức năng đang chạy của trang chủ.

#### Acceptance Criteria

1. THE Ad_Banner_System SHALL lưu trữ dữ liệu Ad_Banner tách biệt với dữ liệu của Hero_Slider trong bảng `banners` cũ.
2. WHEN Admin_User quản lý Ad_Banner, THE Ad_Banner_System SHALL không thay đổi dữ liệu hoặc hành vi hiển thị của Hero_Slider.
3. THE Ad_Banner_System SHALL giữ nguyên việc render Hero_Slider hiện có ở đầu trang chủ thông qua cơ chế hiện tại.
