# Requirements Document

> Tài liệu Yêu cầu — Viết lại `blogthangdgm` thành Blog thuần, chuẩn SEO & GEO, di chuyển dữ liệu từ WordPress `thangdgm` (nội dung tiếng Việt).

## Introduction

Dự án `blogthangdgm` hiện là một website affiliate/bán hàng (PHP thuần). Mục tiêu của tính năng này là **viết lại thành một blog nội dung thuần**, gỡ bỏ toàn bộ tính năng affiliate/sản phẩm, đồng thời **di chuyển toàn bộ dữ liệu bài viết, category, tag, ảnh và cấu trúc link** từ website WordPress `thangdgm` (`http://thangdgm.test/`, DB `thangdgm_db`) sang. Trang mới phải **chuẩn SEO** và **chuẩn GEO (Generative Engine Optimization — tối ưu cho công cụ tìm kiếm AI)**, giữ **cấu trúc URL giống y hệt** WordPress để bảo toàn thứ hạng, và bổ sung hai tính năng nghiệp vụ: **rút gọn link** (thay thế BetterLinks) và **form nhận tài liệu** (gửi file trực tiếp từ local).

Bối cảnh kỹ thuật cần tôn trọng:
- PHP thuần, không framework; admin trong `admin/`, helper trong `includes/`, khối hiển thị trong `includes/blocks/`.
- Tiếng Việt UTF-8 không BOM; mọi truy vấn dùng prepared statement; CSRF cho mọi thao tác ghi; chỉ admin đăng nhập mới quản lý.
- Tái dùng hạ tầng sẵn có: `short_links`/`short_link_clicks`, `slug_redirects`, page cache, SEO helper, Media Library, PHPMailer, rate limit, nhận diện bot/IP.
- Migration script idempotent, có thể chạy lại an toàn.
- Domain production dự kiến: `thang-dgm.com` (dùng cho canonical/sitemap/redirect).

## Quyết định mặc định (Assumptions — chỉnh được khi review)

Người dùng yêu cầu "bạn quyết giúp những gì hợp lý". Các quyết định sau được áp dụng; nếu khác ý, sửa ở bước review:

- **A1 — Form tài liệu:** Người dùng nhập **Họ tên + Email** → backend đọc **file tài liệu đính kèm sẵn trong bài viết** (lưu local) và **gửi email kèm file đính kèm trực tiếp** qua PHPMailer ngay từ server web (KHÔNG dùng n8n, KHÔNG webhook). Lead (tên/email/bài viết/file) được **lưu DB** để admin xem. Chống spam bằng **honeypot + rate limit** (không phụ thuộc reCAPTCHA).
- **A2 — Phạm vi dữ liệu:** Migrate **183 bài published + 12 category có bài + 675 tag**, map SEO từ Rank Math (title/description/focus keyword/schema type). **KHÔNG migrate Portfolio** (20 mục `portfolio` chỉ là dữ liệu demo rác "Portfolio Demo 1..20"). 2 category rỗng (Uncategorized, Viết Content) bỏ qua. Các **page** (Giới thiệu/Liên hệ) đã có sẵn dạng tĩnh trong dự án — giữ nguyên, không ghi đè.
- **A3 — URL:** Bài viết `/{slug}/`, category `/danh-muc/{slug}/`, tag `/tag/{slug}/`. Short link ở **root `/{slug}`** (giống BetterLinks) nhưng **thứ tự ưu tiên resolve**: bài viết → category → tag → short link → 404. Phát hiện & cảnh báo đụng slug (dữ liệu hiện tại không trùng).
- **A7 — Dữ liệu cũ blogthangdgm:** Toàn bộ dữ liệu affiliate/sản phẩm hiện tại là **demo**, được phép **xóa sạch**. Tận dụng nền backend sẵn có (admin, auth, helper, page cache, mailer, short_links) để dựng lại blog hoàn toàn mới.
- **A4 — GEO:** Hiểu là **Generative Engine Optimization** — JSON-LD phong phú (Article/NewsArticle, BreadcrumbList, Organization, WebSite, FAQ), semantic HTML, mục lục (TOC), `llms.txt`, nội dung dễ trích dẫn, tốc độ tốt.
- **A5 — Giao diện:** Blog **thiết kế mới**, giữ stack **Bootstrap** (đồng bộ dự án), tối ưu Core Web Vitals. Áp dụng skill UI/UX (`design1`).
- **A6 — Ảnh:** Copy toàn bộ ảnh từ `wp-content/uploads` → `assets/uploads/`, rewrite đường dẫn trong nội dung & thumbnail, lazy-load + width/height. Tối ưu WebP là **tùy chọn** (không bắt buộc cho lần migrate đầu).

## Glossary

- **Blog thuần:** website chỉ gồm nội dung (bài viết, category, tag, trang tĩnh), không có sản phẩm/affiliate.
- **GEO (Generative Engine Optimization):** tối ưu để nội dung được các công cụ AI (AI Overviews, ChatGPT, Perplexity…) hiểu, trích dẫn.
- **Short link (rút gọn link):** đường dẫn ngắn tại domain site, redirect sang URL đích, có thống kê click — thay thế plugin BetterLinks.
- **Form tài liệu:** biểu mẫu trên bài viết để khách để lại tên/email và nhận file tài liệu của bài đó qua email.
- **Tài liệu bài viết (document file):** file đính kèm của một bài (tương ứng ACF `file_document` bên WordPress).
- **Migration:** quá trình đọc dữ liệu từ `thangdgm_db` + file uploads và nạp vào `blogthangdgm`.
- **slug_redirects:** bảng ánh xạ URL cũ → mới để redirect 301, tránh mất SEO.

## Requirements

### Yêu cầu 1 — Gỡ bỏ tính năng affiliate & sản phẩm
**User Story:** Là chủ website, tôi muốn loại bỏ hoàn toàn phần sản phẩm/affiliate, để site trở thành blog nội dung thuần, gọn và đúng mục đích.

#### Acceptance Criteria
1. KHI rà soát mã nguồn, THÌ hệ thống PHẢI gỡ các trang/route công khai liên quan sản phẩm/affiliate (vd `product.php`, block `products/services`, `demo-goiy.php`, `demo-deal-today.html`) khỏi luồng hiển thị.
2. KHI rà soát admin, THÌ hệ thống PHẢI gỡ các khu quản trị: products, affiliate-platforms, conversions, clicks (affiliate), registrations sản phẩm và menu liên quan trong sidebar admin.
3. KHI rà soát API, THÌ hệ thống PHẢI gỡ/không còn tham chiếu tới `api/register-product.php`, `api/track-click.php` (affiliate), `api/product-rating.php`, `api/log-conversion.php`, `api/sp-hit.php`, `api/track-view.php` nếu chỉ phục vụ sản phẩm.
4. KHI gỡ tính năng, THÌ trang chủ và các trang còn lại PHẢI không phát sinh lỗi (không gọi hàm/biến đã xóa).
5. Dữ liệu/bảng sản phẩm-affiliate cũ là **dữ liệu demo** và được phép **xóa sạch**: hệ thống PHẢI có script gỡ (DROP) các bảng `products`, `product_affiliate_links`, `product_clicks`, `product_ratings`, `product_registrations`, `product_views`, `affiliate_platforms`, `conversion_logs` và dọn các block/setting liên quan. Script PHẢI idempotent và an toàn (chỉ tác động đúng các bảng này).
6. Modal đăng ký sản phẩm trên trang chủ và các tham chiếu CTA mua hàng PHẢI được gỡ.

### Yêu cầu 2 — Mô hình dữ liệu blog (bài viết, category, tag)
**User Story:** Là quản trị viên nội dung, tôi muốn mô hình dữ liệu blog đầy đủ, để lưu bài viết với category, tag, tác giả, SEO và tài liệu đính kèm giống bên WordPress.

#### Acceptance Criteria
1. KHI khởi tạo schema, THÌ hệ thống PHẢI mở rộng bảng `posts` để có tối thiểu: tiêu đề, slug, tóm tắt, nội dung, ảnh đại diện + alt, tác giả, ngày publish, ngày cập nhật, lượt xem, trạng thái, và các trường SEO (meta title/description/keywords/focus keyword, loại schema).
2. KHI khởi tạo schema, THÌ hệ thống PHẢI có quan hệ **bài viết ↔ nhiều category** và **bài viết ↔ nhiều tag** (bảng quan hệ riêng) và bảng `tags`.
3. KHI một bài có tài liệu đính kèm, THÌ hệ thống PHẢI lưu được **đường dẫn file tài liệu local + tên file hiển thị** cho bài đó (phục vụ Yêu cầu 6).
4. KHI lưu nội dung tiếng Việt, THÌ dữ liệu PHẢI giữ UTF-8 đúng (không mojibake).
5. Schema migration PHẢI idempotent (chạy lại không lỗi, không tạo trùng cột/bảng) và bọc try/catch theo phong cách dự án.
6. NẾU một bài có nhiều category, THÌ hệ thống PHẢI xác định được **category chính (primary)** để dựng breadcrumb/canonical.

### Yêu cầu 3 — Di chuyển dữ liệu từ WordPress
**User Story:** Là chủ website, tôi muốn toàn bộ bài viết, category, tag và SEO được mang sang chính xác, để không mất nội dung và thứ hạng.

#### Acceptance Criteria
1. KHI chạy script migrate, THÌ hệ thống PHẢI đọc từ `thangdgm_db` các bài `post_type='post' AND post_status='publish'` và nạp vào `posts` với **slug giữ nguyên** (KHÔNG migrate `portfolio` — dữ liệu demo).
2. KHI migrate, THÌ category (`category`, `category_base=/danh-muc`), tag (`post_tag`) và quan hệ bài↔category/tag PHẢI được tạo đúng, **giữ nguyên slug**.
3. KHI migrate, THÌ các trường SEO Rank Math (`rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`, loại schema) PHẢI được map sang trường SEO tương ứng; nếu thiếu thì sinh fallback hợp lý.
4. KHI migrate, THÌ ngày publish/cập nhật, tác giả, ảnh đại diện, và lượt xem (nếu có `penci_post_views_count`) PHẢI được mang sang.
5. KHI một bài có ACF `file_document`, THÌ hệ thống PHẢI gắn tài liệu đó vào bài (đường dẫn + tên file) ở mô hình mới.
6. KHI migrate hoàn tất, THÌ script PHẢI in báo cáo (số bài/category/tag/ảnh xử lý, số lỗi) và **chạy lại được mà không nhân đôi dữ liệu** (so khớp theo slug).
7. NẾU nội dung bài chứa shortcode/HTML đặc thù WordPress (Elementor, block), THÌ hệ thống PHẢI làm sạch ở mức hợp lý để hiển thị đúng (chốt mức độ ở Design).

### Yêu cầu 4 — Di chuyển & xử lý hình ảnh
**User Story:** Là chủ website, tôi muốn ảnh trong bài hiển thị đúng trên site mới, để nội dung trọn vẹn và không vỡ ảnh.

#### Acceptance Criteria
1. KHI migrate ảnh, THÌ hệ thống PHẢI copy file ảnh từ `wp-content/uploads` sang `assets/uploads/` của dự án (giữ cấu trúc thư mục theo năm/tháng hoặc theo quy ước chốt ở Design).
2. KHI nạp nội dung bài, THÌ mọi URL ảnh trỏ tới domain WordPress cũ (`thangdgm.test`, `thang-dgm.com`, `www.thang-dgm.com`) PHẢI được rewrite sang đường dẫn nội bộ mới.
3. KHI render ảnh, THÌ ảnh PHẢI có `loading="lazy"` và thuộc tính kích thước hợp lý để không phá layout/CLS.
4. NẾU một ảnh nguồn không tồn tại, THÌ script PHẢI ghi log và bỏ qua an toàn (không dừng toàn bộ migrate).
5. Ảnh đại diện (featured image) của bài PHẢI được copy và gán đúng.

### Yêu cầu 5 — Rút gọn link (thay thế BetterLinks)
**User Story:** Là quản trị viên, tôi muốn quản lý link rút gọn như BetterLinks, để chia sẻ link ngắn có thống kê và mang dữ liệu cũ sang.

#### Acceptance Criteria
1. KHI migrate, THÌ 25 link trong `wp_betterlinks` PHẢI được nạp vào `short_links` với **slug giữ nguyên**, `target_url`, loại redirect (307), và các cờ `nofollow/sponsored/track`.
2. KHI khách truy cập `/{slug}` trùng một short link (và không trùng bài/category/tag), THÌ hệ thống PHẢI redirect tới `target_url` đúng loại (mặc định 307) và gắn `rel` phù hợp khi cần.
3. KHI redirect short link, THÌ hệ thống PHẢI ghi nhận một lượt click (tái dùng `short_link_clicks`) và KHÔNG làm chậm chuyển hướng đáng kể.
4. KHI admin quản lý short link, THÌ hệ thống PHẢI cho tạo/sửa/xóa/bật-tắt, đặt UTM, parameter forwarding và xem số click.
5. NẾU slug short link trùng slug bài/category/tag, THÌ hệ thống PHẢI cảnh báo admin và ưu tiên nội dung (theo A3).
6. Endpoint redirect PHẢI chỉ chuyển tới URL đã lưu (chống open redirect) và chống lạm dụng ở mức hợp lý.

### Yêu cầu 6 — Form nhận tài liệu (gửi file từ local)
**User Story:** Là khách truy cập, tôi muốn để lại tên/email trên bài viết có tài liệu, để nhận file tài liệu đó qua email ngay lập tức.

#### Acceptance Criteria
1. KHI một bài viết có tài liệu đính kèm, THÌ trang bài viết PHẢI hiển thị form nhận tài liệu (Họ tên + Email) một cách chuyên nghiệp.
2. KHI khách submit form hợp lệ, THÌ hệ thống PHẢI đọc file tài liệu **local của bài đó** và **gửi email kèm file đính kèm** cho địa chỉ khách nhập (qua PHPMailer/cấu hình SMTP hiện có).
3. KHI submit thành công, THÌ hệ thống PHẢI hiển thị thông báo xác nhận (vd "đã gửi, vui lòng kiểm tra email") và **lưu lead** (tên, email, bài viết, file, thời gian, IP) vào DB để admin xem.
4. KHI submit, THÌ hệ thống PHẢI kiểm tra CSRF, honeypot và rate limit để chống spam; email không hợp lệ PHẢI bị từ chối.
5. NẾU gửi email thất bại, THÌ hệ thống PHẢI thông báo lỗi thân thiện và vẫn lưu lead (có cờ trạng thái gửi) để xử lý lại.
6. KHI admin xem danh sách lead nhận tài liệu, THÌ hệ thống PHẢI hiển thị và cho lọc/xuất cơ bản; file tài liệu PHẢI được bảo vệ (không lộ đường dẫn tải tự do nếu là tài liệu giới hạn — chốt mức ở Design).

### Yêu cầu 7 — Quản trị nội dung blog
**User Story:** Là quản trị viên, tôi muốn quản lý bài viết, category, tag và tài liệu trong admin, để vận hành blog hằng ngày.

#### Acceptance Criteria
1. KHI admin quản lý bài viết, THÌ hệ thống PHẢI cho tạo/sửa/xóa, gán nhiều category + tag, đặt ảnh đại diện, soạn nội dung (editor), đính kèm tài liệu, và nhập trường SEO.
2. KHI admin quản lý category/tag, THÌ hệ thống PHẢI cho tạo/sửa/xóa và đặt slug + SEO cơ bản.
3. KHI admin lưu, THÌ slug PHẢI tự sinh từ tiêu đề (hỗ trợ tiếng Việt) nếu để trống, và bảo đảm duy nhất.
4. KHI thao tác ghi, THÌ hệ thống PHẢI yêu cầu đăng nhập admin + CSRF.
5. KHI admin đổi slug của bài/category đã publish, THÌ hệ thống PHẢI tạo bản ghi `slug_redirects` (301) từ slug cũ sang mới.

### Yêu cầu 8 — SEO kỹ thuật
**User Story:** Là chủ website, tôi muốn site đạt chuẩn SEO kỹ thuật, để giữ và tăng thứ hạng sau khi chuyển nền tảng.

#### Acceptance Criteria
1. KHI render bất kỳ trang nào, THÌ hệ thống PHẢI xuất thẻ `<title>`, meta description, canonical, Open Graph và Twitter Card đúng dữ liệu trang đó.
2. KHI render bài viết/category, THÌ URL PHẢI khớp **chính xác** cấu trúc WordPress (`/{slug}/`, `/danh-muc/{slug}/`, `/tag/{slug}/`).
3. KHI có URL cũ khác cấu trúc mới, THÌ hệ thống PHẢI redirect **301** sang URL mới (qua `slug_redirects`), tránh trùng lặp/đường dẫn chết.
4. KHI build sitemap, THÌ `sitemap.php` PHẢI liệt kê bài viết, category, tag (loại bỏ hoàn toàn sản phẩm) và cập nhật `robots.txt` phù hợp.
5. KHI trang tải, THÌ hệ thống PHẢI giữ tối ưu hiệu năng hiện có (page cache, lazy image) và không hồi quy Core Web Vitals.
6. Trang PHẢI có breadcrumb đúng phân cấp category → bài viết.

### Yêu cầu 9 — GEO (tối ưu công cụ tìm kiếm AI)
**User Story:** Là chủ website, tôi muốn nội dung được AI hiểu và trích dẫn, để tăng hiện diện trên công cụ tìm kiếm thế hệ mới.

#### Acceptance Criteria
1. KHI render bài viết, THÌ hệ thống PHẢI xuất JSON-LD phù hợp (Article/NewsArticle/BlogPosting) gồm tác giả, ngày đăng/cập nhật, ảnh, publisher.
2. KHI render trang, THÌ hệ thống PHẢI xuất JSON-LD `BreadcrumbList`, `Organization` và `WebSite` (kèm SearchAction) đúng dữ liệu.
3. NẾU bài/site có FAQ, THÌ hệ thống PHẢI xuất `FAQPage` JSON-LD hợp lệ.
4. KHI render bài dài, THÌ hệ thống NÊN tạo **mục lục (TOC)** từ heading để cải thiện khả năng trích dẫn.
5. KHI bot/AI truy cập, THÌ hệ thống PHẢI cung cấp `llms.txt` mô tả site và nội dung chính.
6. Nội dung PHẢI dùng HTML semantic (`article`, `nav`, `time`, heading có thứ bậc) để máy đọc hiểu tốt.

### Yêu cầu 10 — Giao diện blog mới
**User Story:** Là độc giả, tôi muốn giao diện blog đẹp, dễ đọc, nhanh trên cả mobile, để có trải nghiệm tốt.

#### Acceptance Criteria
1. KHI vào trang chủ, THÌ hệ thống PHẢI hiển thị bố cục blog (bài mới nhất, theo category, nổi bật) thay cho bố cục sản phẩm cũ.
2. KHI đọc một bài, THÌ trang PHẢI rõ ràng: tiêu đề, meta (ngày/tác giả/lượt xem), ảnh, nội dung dễ đọc, mục lục, chia sẻ, bài liên quan.
3. KHI xem trên mobile, THÌ giao diện PHẢI responsive, không vỡ, chạm tốt.
4. KHI tải trang, THÌ giao diện PHẢI tối ưu CWV (font, ảnh, CSS tới hạn) ở mức hợp lý.
5. Giao diện PHẢI giữ tiếng Việt UTF-8 đúng và nhất quán nhận diện thương hiệu "Thắng Digital Marketing".

### Yêu cầu 11 — Bảo mật & toàn vẹn
**User Story:** Là chủ website, tôi muốn hệ thống an toàn và dữ liệu toàn vẹn, để vận hành yên tâm.

#### Acceptance Criteria
1. KHI thực hiện thao tác ghi ở admin hoặc form công khai, THÌ hệ thống PHẢI kiểm tra CSRF và (với admin) đăng nhập.
2. KHI nhận dữ liệu người dùng, THÌ hệ thống PHẢI dùng prepared statement và escape khi xuất HTML.
3. KHI xử lý upload tài liệu/ảnh, THÌ hệ thống PHẢI validate loại file và lưu an toàn (tái dùng cơ chế upload hiện có).
4. KHI gửi file qua email, THÌ hệ thống PHẢI chỉ gửi file thuộc bài viết hợp lệ (không cho chỉ định đường dẫn tùy ý từ client).
5. KHI sửa file có tiếng Việt, THÌ PHẢI giữ UTF-8 không BOM; sau khi sửa PHẢI rà mojibake (`Ã|á»|Ä|Â`).
6. KHI sửa file PHP, THÌ PHẢI chạy `php -l` để xác nhận không lỗi cú pháp.
