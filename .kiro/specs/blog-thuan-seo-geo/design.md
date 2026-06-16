# Design Document

> Tài liệu Thiết kế — Viết lại `blogthangdgm` thành Blog thuần, chuẩn SEO & GEO (nội dung tiếng Việt).

## Overview

Thiết kế chuyển `blogthangdgm` từ web affiliate/sản phẩm sang **blog nội dung thuần**, tái dùng tối đa nền backend hiện có (PDO, admin auth, CSRF, page cache, `SEO` class, Media Library, PHPMailer, `short_links`, `slug_redirects`, marker-based `.htaccess`). Trọng tâm:

1. **Routing mới** đặt bài viết ở root `/{slug}/` (giống WordPress), category `/danh-muc/{slug}/`, tag `/tag/{slug}/`, short link ở root với độ ưu tiên thấp nhất.
2. **Mô hình dữ liệu blog** mở rộng `posts` + thêm `tags`, `post_categories`, `post_tags`, `document_requests`.
3. **Migration** đọc trực tiếp từ `thangdgm_db` + copy ảnh từ `wp-content/uploads`.
4. **Form nhận tài liệu** gửi file local qua email (PHPMailer), lưu lead.
5. **SEO/GEO** mở rộng `SEO` class (đã có nền tốt) + `llms.txt` + TOC.
6. **Gỡ sạch** product/affiliate (code + DROP bảng demo).

Nguyên tắc xuyên suốt (theo AGENTS.md): patch tối thiểu, UTF-8 không BOM, prepared statement, CSRF cho mọi ghi, `php -l` sau khi sửa, rà mojibake.

## Architecture

### Sơ đồ luồng request (frontend)

```
                 ┌─────────────── .htaccess (marker "FPTStore Rewrite") ───────────────┐
Request URL ───► │  /                       → index.php                                  │
                 │  /danh-muc/{slug}         → category.php?slug=                         │
                 │  /tag/{slug}              → tag.php?slug=            (MỚI)             │
                 │  /gioi-thieu /lien-he     → about.php / contact.php                    │
                 │  /tin-tuc                 → news.php (trang tổng bài viết)             │
                 │  /{slug}  (1 segment)     → router.php?path={slug}  (MỚI, catch-all)   │
                 └─────────────────────────────────────────────────────────────────────┘
                                                   │
                                                   ▼
                              router.php phân giải theo thứ tự ƯU TIÊN:
                              1) posts.slug   → include post.php
                              2) short_links.slug (status=1) → redirect target_url + log click
                              3) → 404.php
```

Lý do dùng `router.php` cho URL 1 đoạn: WordPress để bài viết ở root, không prefix. Ta không thể tách bài viết vs short link chỉ bằng RewriteRule, nên đẩy về 1 controller PHP phân giải theo DB. Category/tag vẫn có prefix riêng nên match thẳng bằng RewriteRule (nhanh, không đụng router).

### Thứ tự ưu tiên slug (Yêu cầu 5.5, A3)
`post` → `short_link`. (Category/tag đã tách bằng prefix nên không tham gia tranh chấp root.) Khi admin tạo short link trùng slug một bài đang publish → cảnh báo, vẫn lưu nhưng bài viết sẽ thắng khi resolve.

### Tái sử dụng & thay đổi .htaccess
Dùng `replaceHtaccessSection('FPTStore Rewrite', ...)` có sẵn để thay block rewrite. Block mới (thay thế rule sản phẩm 2 đoạn + /tin-tuc):

```apache
# Category
RewriteRule ^danh-muc/([^/]+)/?$ category.php?slug=$1 [L,QSA]
# Tag
RewriteRule ^tag/([^/]+)/?$ tag.php?slug=$1 [L,QSA]
# Trang tổng bài viết (tùy chọn giữ)
RewriteRule ^tin-tuc/?$ news.php [L,QSA]
# Static pages
RewriteRule ^gioi-thieu/?$ about.php [L,QSA]
RewriteRule ^lien-he/?$ contact.php [L,QSA]
# Catch-all 1 đoạn (bài viết / short link) — đặt CUỐI cùng
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^/]+)/?$ router.php?path=$1 [L,QSA]
```

`generateRewriteRules()` trong `url-helper.php` sẽ được cập nhật để sinh đúng block này; `postUrl()` đổi để trả `/{slug}` (bỏ prefix `tin-tuc`).

## Components and Interfaces

### 1. Routing controller — `router.php` (mới)
```php
// Vào từ catch-all 1 đoạn. $_GET['path'] = slug.
// 1. Thử posts theo slug (status=1) → set $post, include 'post.php' (post.php tách phần fetch).
// 2. Thử short_links theo slug (status=1) → redirect + log click (tái dùng logic short.php).
// 3. slug_redirects (old_path = '/'.$slug) → 301 sang new_path.
// 4. 404.
```
- `post.php` được refactor: nếu `$post` đã được set bởi router thì dùng luôn; nếu gọi trực tiếp với `?slug=` vẫn fetch như cũ (tương thích ngược).
- Short link redirect tái dùng cơ chế của `short.php` (đọc `short_links`, ghi `short_link_clicks`, redirect theo `redirect_type`).

### 2. Trang bài viết — `post.php` (viết lại phần dữ liệu)
- Bỏ `type` enum news/info; dùng quan hệ category/tag.
- Hiển thị: tiêu đề, meta (ngày publish, tác giả, lượt xem), ảnh đại diện, **mục lục (TOC)** auto-generate từ `<h2>/<h3>`, nội dung, tag, chia sẻ, **form nhận tài liệu (nếu bài có document)**, bài liên quan (cùng category).
- SEO: `setArticleData()` (BlogPosting/NewsArticle theo `schema_type`), breadcrumb `Trang chủ › {category chính} › {bài}`, canonical `/{slug}/`.
- Tăng `views` (UPDATE nhẹ, bỏ qua bot) — không phá page cache (ghi async hoặc qua endpoint AJAX nhỏ để giữ cache HTML).

### 3. Trang tag — `tag.php` (mới)
- Lấy tag theo slug, liệt kê bài thuộc tag (phân trang), SEO + breadcrumb + canonical `/tag/{slug}/`.

### 4. Trang category — `category.php` (viết lại)
- Bỏ toàn bộ logic sản phẩm (hub/sub/sort theo giá). Liệt kê **bài viết** thuộc category (phân trang 12–15/bài), hỗ trợ category cha/con nếu cần (WP của bạn không lồng sâu — phẳng). SEO + `ItemList` + breadcrumb.

### 5. Trang chủ — `index.php` (viết lại)
- Bỏ block sản phẩm/services/modal đăng ký. Bố cục blog: bài nổi bật/mới nhất, lưới bài theo category, (tùy chọn) bài xem nhiều. Giữ page cache. JSON-LD `WebSite`+`Organization`.

### 6. Form nhận tài liệu
- **Frontend:** partial `includes/blocks/document_form.php` render trên `post.php` khi `posts.document_path` không rỗng. Field: `fullname`, `email`, honeypot `website` (ẩn), `csrf_token`, `post_id` (hidden). Submit AJAX tới `api/request-document.php`.
- **Backend:** `api/request-document.php`
  - Validate: CSRF, honeypot rỗng, email hợp lệ, rate limit theo IP (tái dùng pattern rate-limit hiện có), `post_id` tồn tại & có document.
  - Đọc file local từ `posts.document_path` (đường dẫn nội bộ, KHÔNG nhận path từ client — chống path traversal).
  - Gửi email kèm đính kèm qua `includes/mailer.php` (PHPMailer + cấu hình SMTP trong settings).
  - Lưu `document_requests` (post_id, fullname, email, file_name, ip, status, created_at). status = sent|failed.
  - Trả JSON `{success, message}`. Lỗi gửi mail → status=failed, vẫn lưu lead, trả message thân thiện.

### 7. Admin
- **Bài viết** (`admin/posts/`): viết lại form — chọn nhiều category (checkbox), nhập tag (tách dấu phẩy), ảnh đại diện (Media Library), editor nội dung, **upload tài liệu đính kèm**, trường SEO, schema type, trạng thái. Auto slug tiếng Việt (`create_slug`), unique. Đổi slug bài publish → ghi `slug_redirects`.
- **Tag** (`admin/tags/` mới): CRUD tag + slug + SEO cơ bản.
- **Category** (`admin/categories/`): giữ, gỡ field liên quan sản phẩm.
- **Lead tài liệu** (`admin/document-requests/` mới): danh sách, lọc theo bài/ngày/status, xuất CSV, gửi lại.
- **Short links** (`admin/short-links/`): đã có khung — bổ sung cảnh báo trùng slug bài viết; thêm nhóm (term) nếu cần.
- Gỡ menu sidebar: products, affiliate-platforms, conversions, clicks, registrations.

### 8. Gỡ affiliate/sản phẩm
- **Script** `scripts/remove_ecommerce.php` (CLI, idempotent): `DROP TABLE IF EXISTS` cho `products, product_affiliate_links, product_clicks, product_ratings, product_registrations, product_views, affiliate_platforms, conversion_logs`; xóa settings/blocks liên quan (`homepage_blocks` có block_key products/services; settings nhóm affiliate).
- **Code:** xóa `product.php`, `demo-goiy.php`, `demo-deal-today.html`, các `includes/blocks/{products,services,dynamic_card_product}.php`, API sản phẩm; gỡ tham chiếu trong `index.php`, `includes/functions.php` (hàm affiliate), `header/footer`.
- Endpoint dùng chung còn giá trị cho blog (vd `api/search.php`, `api/csrf-token.php`, `api/address-proxy.php` nếu không dùng → cân nhắc giữ/bỏ; `api/track-view.php` có thể tái dùng cho lượt xem bài).

## Data Models

### Bảng `posts` (mở rộng — migration idempotent thêm cột thiếu)
| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | INT PK | |
| title | VARCHAR(255) | |
| slug | VARCHAR(255) UNIQUE | giữ nguyên từ WP |
| summary | TEXT | excerpt |
| content | LONGTEXT | đã rewrite ảnh |
| thumbnail | VARCHAR(255) | ảnh đại diện (đường dẫn nội bộ) |
| thumbnail_alt | VARCHAR(255) | alt ảnh |
| author_name | VARCHAR(150) | tên tác giả (từ WP user) |
| views | INT default 0 | từ penci_post_views_count |
| meta_title | VARCHAR(70) | Rank Math |
| meta_description | TEXT | Rank Math |
| meta_keywords | VARCHAR(255) | |
| focus_keyword | VARCHAR(255) | Rank Math |
| schema_type | VARCHAR(30) | Article/BlogPosting/NewsArticle |
| document_path | VARCHAR(255) NULL | file tài liệu local (nếu có) |
| document_name | VARCHAR(255) NULL | tên hiển thị file |
| status | TINYINT | 1=publish |
| primary_category_id | INT NULL | category chính (breadcrumb/canonical) |
| created_at | DATETIME | = post_date WP |
| updated_at | DATETIME | = post_modified WP |

> Cột `type` enum cũ: giữ lại (không drop) để tránh phá dữ liệu, nhưng ngừng dùng; có thể đặt mặc định.

### Bảng `tags` (mới)
`id PK, name VARCHAR(150), slug VARCHAR(191) UNIQUE, description TEXT NULL, meta_title, meta_description, created_at`.

### Bảng `post_categories` (mới, N-N)
`post_id INT, category_id INT, PRIMARY KEY(post_id, category_id)` + index.

### Bảng `post_tags` (mới, N-N)
`post_id INT, tag_id INT, PRIMARY KEY(post_id, tag_id)` + index.

### Bảng `document_requests` (mới)
`id PK, post_id INT, fullname VARCHAR(150), email VARCHAR(191), file_name VARCHAR(255), ip_address VARCHAR(45), status ENUM('sent','failed') default 'sent', error_note VARCHAR(255) NULL, created_at DATETIME`.

### `categories` (tái dùng)
Giữ cấu trúc; dùng `slug`, `name`, `description`, `content`, các trường SEO. Quan hệ bài↔category qua `post_categories` (bỏ `products.category_id` cũ).

### `short_links` + `short_link_clicks` (tái dùng nguyên trạng)
Map BetterLinks → `short_links`: `link_slug→slug`, `target_url→target_url`, `redirect_type→redirect_type(307)`, `link_title→title`, `track_me→is_tracking_enabled`. Cờ `nofollow/sponsored` lưu thêm (cân nhắc cột phụ hoặc bỏ qua vì redirect server-side không cần rel).

## URL & Redirect Strategy

| Loại | URL mới (khớp WP) | Controller |
|---|---|---|
| Trang chủ | `/` | index.php |
| Bài viết | `/{slug}/` | router.php → post.php |
| Category | `/danh-muc/{slug}/` | category.php |
| Tag | `/tag/{slug}/` | tag.php |
| Short link | `/{slug}` | router.php (ưu tiên sau post) |
| Giới thiệu/Liên hệ | `/gioi-thieu`, `/lien-he` | about/contact |

- **Redirect 301:** URL nội bộ cũ (`/tin-tuc/{slug}`, `/san-pham/...`) → URL mới qua `slug_redirects` + rule .htaccess cho `/tin-tuc/{slug}` → `/{slug}`.
- **Canonical & sitemap:** domain production `thang-dgm.com`. `sitemap.php` viết lại: liệt kê posts (`/{slug}/`), categories (`/danh-muc/{slug}/`), tags (`/tag/{slug}/`); bỏ sản phẩm. `robots.txt` trỏ sitemap + cho phép crawl.
- **Trailing slash:** chuẩn hóa có dấu `/` cuối cho post/category/tag (giống WP) để tránh trùng lặp; rule redirect non-slash → slash (tùy chọn, cân nhắc tránh vòng lặp với catch-all).

## Migration Design

### Script `scripts/migrate_from_wordpress.php` (CLI, idempotent)
Kết nối song song 2 DB: `blogthangdgm_db` (đích, qua config) + `thangdgm_db` (nguồn, DSN cố định localhost/root). Đối chiếu theo **slug** để chạy lại không nhân đôi (UPSERT).

**Các bước:**
1. **Categories:** đọc `wp_terms`+`wp_term_taxonomy` taxonomy=`category` (count>0) → upsert `categories` (name, slug, description). Map `term_id` cũ → `id` mới.
2. **Tags:** taxonomy=`post_tag` → upsert `tags`. Map id.
3. **Posts:** `wp_posts` post_type=`post`, status=`publish`:
   - title, slug, content (`post_content`), summary (`post_excerpt` hoặc trích từ content), created_at(`post_date`), updated_at(`post_modified`).
   - author_name từ `wp_users` qua `post_author`.
   - SEO từ `wp_postmeta`: `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`; schema_type suy từ `rank_math_rich_snippet`/`rank_math_schema_*` (mặc định BlogPosting).
   - views từ `penci_post_views_count`.
   - thumbnail: `_thumbnail_id` → `wp_posts(attachment).guid`/`_wp_attached_file` → copy ảnh + lưu path nội bộ; thumbnail_alt từ `_wp_attachment_image_alt`.
   - document: ACF `file_document` (field key `field_68347d0e25589`). Postmeta lưu attachment ID → tra `wp_posts`/`_wp_attached_file` lấy file thật → copy vào `assets/uploads/documents/` → set `document_path`, `document_name`.
   - quan hệ: `wp_term_relationships` → `post_categories`, `post_tags`; `rank_math_primary_category` → `primary_category_id`.
4. **Content image rewrite:** trong `post_content`, tìm URL ảnh trỏ `thang-dgm.com|www.thang-dgm.com|thangdgm.test` trong `wp-content/uploads/...` → copy file tương ứng sang `assets/uploads/wp/...` (giữ cấu trúc năm/tháng) → thay URL thành đường dẫn nội bộ. Làm sạch shortcode/comment Elementor cơ bản (giữ HTML hiển thị được).
5. **Short links:** `wp_betterlinks` → upsert `short_links` (theo slug).
6. **Báo cáo:** in số lượng xử lý + lỗi; log ảnh thiếu.

### Migrate ảnh — `lib` dùng chung trong script
- Nguồn: `F:\Xamp\htdocs\thangdgm\wp-content\uploads`. Đích: `assets/uploads/wp/`.
- Copy giữ cây thư mục `YYYY/MM/file.ext`. Bỏ qua nếu đã tồn tại (idempotent). Ảnh thiếu → log + skip.
- Tùy chọn WebP: KHÔNG bắt buộc lần đầu (giữ nguyên định dạng để chắc đúng). Có thể chạy `convert_to_webp` có sẵn sau.
- Khi render: thêm `loading="lazy"` cho ảnh trong nội dung (xử lý ở bước hiển thị post.php hoặc trong rewrite migrate).

> Lưu ý encoding: nội dung tiếng Việt đọc từ MySQL utf8mb4 → ghi sang DB đích utf8mb4 qua PDO (charset=utf8mb4). KHÔNG đi qua file trung gian để tránh hỏng mã. Sau migrate, rà mojibake bằng query/grep.

## SEO/GEO Design

- **Tái dùng `SEO` class** (đã có Article/FAQ/WebSite/Breadcrumb/Organization/ItemList). Bổ sung:
  - `setArticleData` hỗ trợ chọn `@type` theo `schema_type` (Article/BlogPosting/NewsArticle) + `author` là Person (tên tác giả) thay vì chỉ Organization.
  - Thêm `mainEntityOfPage`, `wordCount` (tùy chọn) cho Article để tăng tín hiệu GEO.
- **TOC:** hàm `build_toc($html)` parse `<h2>/<h3>`, gắn `id`, trả về mục lục + nội dung đã chèn anchor. Render đầu bài (semantic `<nav aria-label="Mục lục">`).
- **`llms.txt`** (mới, ở root, qua rule .htaccess hoặc file tĩnh sinh bởi script): mô tả site, danh mục chính, link bài tiêu biểu — chuẩn đề xuất cho AI engines.
- **HTML semantic:** `<article>`, `<time datetime>`, heading có thứ bậc, breadcrumb `<nav>`.
- **FAQ:** nếu có khối FAQ trong bài/site → `setFaqData`.

## Error Handling

- Mọi truy vấn bọc try/catch; thiếu bảng/cột → fail an toàn (không vỡ trang), tái dùng `has_table_column`.
- `router.php`: không tìm thấy → 404 chuẩn (`404.php`, header 404).
- Form tài liệu: lỗi mail → lưu lead status=failed + message thân thiện; không lộ đường dẫn file.
- Migration: lỗi từng bản ghi → log & tiếp tục; không dừng toàn bộ. Chạy lại an toàn (UPSERT theo slug).
- Short link redirect lỗi → trả 404, không open redirect (chỉ dùng URL đã lưu).

## Security

- CSRF cho form tài liệu + mọi thao tác admin (tái dùng `generate_csrf_token`/verify).
- Prepared statement toàn bộ; escape `e()` khi xuất HTML.
- Upload tài liệu/ảnh: validate loại file (tái dùng `upload_file` + mở rộng cho pdf/docx/xlsx/zip với whitelist mime + ext), lưu tên ngẫu nhiên, thư mục `assets/uploads/documents/` có `.htaccess` chặn thực thi PHP.
- Gửi file qua email: chỉ từ `posts.document_path` của `post_id` hợp lệ — không nhận path từ client (chống path traversal).
- Rate limit + honeypot cho form công khai.
- Giữ security headers + CSP hiện có trong `.htaccess`.

## Testing Strategy

- `php -l` mọi file PHP tạo/sửa.
- Rà mojibake sau migrate: `SELECT ... REGEXP 'Ã|Â|á»|Ä'` trên `posts/categories/tags` + grep file.
- Test routing: `/`, `/{slug-bài}/`, `/danh-muc/{slug}/`, `/tag/{slug}/`, `/{slug-shortlink}` (redirect), slug không tồn tại → 404, trùng slug post-vs-short (post thắng).
- Test migrate idempotent: chạy 2 lần, đếm bản ghi không đổi.
- Test form tài liệu: submit hợp lệ → nhận mail kèm file + lead lưu; honeypot có giá trị → chặn; email sai → từ chối; rate limit.
- Test SEO: view-source kiểm tra title/canonical/OG + JSON-LD hợp lệ (Article/Breadcrumb/WebSite), sitemap không còn sản phẩm.
- Kiểm tra ảnh: bài mẫu hiển thị đủ ảnh nội bộ, không còn URL `thang-dgm.com`.

## Correctness Properties

Các thuộc tính phải luôn đúng (dùng làm tiêu chí kiểm thử/nghiệm thu):

### Property 1: Bảo toàn slug
Với mỗi bài/category/tag/short link được migrate, slug ở hệ mới PHẢI bằng slug ở WordPress (so khớp 1-1).
**Validates: Requirements 3.1, 3.2, 5.1**

### Property 2: URL khớp WordPress
Bài viết phân giải tại `/{slug}/`, category tại `/danh-muc/{slug}/`, tag tại `/tag/{slug}/` — đúng cấu trúc nguồn.
**Validates: Requirements 8.2**

### Property 3: Ưu tiên resolve đơn định
Với một slug ở root, kết quả phân giải là duy nhất theo thứ tự post → short link → 404 (không nhập nhằng).
**Validates: Requirements 5.2, 5.5**

### Property 4: Idempotent migrate
Chạy script migrate N lần cho cùng dữ liệu nguồn → số bản ghi và nội dung ở đích không đổi sau lần đầu.
**Validates: Requirements 2.5, 3.6**

### Property 5: Toàn vẹn ảnh
Sau migrate, nội dung bài KHÔNG còn URL trỏ domain WordPress cũ; mọi ảnh tham chiếu đều tồn tại nội bộ hoặc được log là thiếu.
**Validates: Requirements 4.2, 4.4**

### Property 6: Bảo toàn tiếng Việt
Không xuất hiện chuỗi mojibake (`Ã`, `Â`, `á»`, `Ä`) ở bất kỳ trường nội dung nào sau migrate.
**Validates: Requirements 2.4, 11.5**

### Property 7: An toàn file tài liệu
Email chỉ đính kèm file thuộc `document_path` của `post_id` hợp lệ; không tồn tại đường đi từ input client tới một path tùy ý.
**Validates: Requirements 6.2, 11.4**

### Property 8: Không vỡ trang khi thiếu schema
Thiếu bảng/cột → trang vẫn render (fail an toàn), không fatal error.
**Validates: Requirements 1.4, 2.5**

### Property 9: Redirect an toàn
Short link chỉ chuyển tới URL đã lưu trong DB (không open redirect).
**Validates: Requirements 5.6**

### Property 10: Mọi ghi đều có guard
Thao tác ghi (admin + form công khai) đều qua CSRF; thao tác admin đều qua kiểm tra đăng nhập.
**Validates: Requirements 7.4, 11.1**

## Design Decisions & Rationales

1. **`router.php` cho root 1 đoạn** thay vì nhiều RewriteRule: vì post (root) và short link (root) không phân biệt được ở tầng Apache; phân giải DB ở PHP rõ ràng, dễ kiểm soát ưu tiên và logging.
2. **Tái dùng `short_links` thay vì tạo bảng BetterLinks**: schema đã tương đương, giảm trùng lặp, tận dụng admin/clicks có sẵn.
3. **Không migrate qua file trung gian**: đọc/ghi thẳng PDO utf8mb4 để bảo toàn tiếng Việt (tuân thủ Encoding Rules).
4. **Giữ cột `type` cũ trong `posts`**: tránh thao tác phá hủy không cần thiết; chỉ thêm cột mới.
5. **WebP để tùy chọn**: ưu tiên đúng đắn dữ liệu trước, tối ưu sau (giảm rủi ro hỏng ảnh khi migrate lần đầu).
6. **Gửi mail trực tiếp (bỏ n8n)**: theo yêu cầu; đơn giản hóa hạ tầng, kiểm soát hoàn toàn ở server.
