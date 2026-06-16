# Implementation Plan

> Kế hoạch triển khai — Blog thuần chuẩn SEO & GEO + migrate từ WordPress (nội dung tiếng Việt).

## Overview

Triển khai theo thứ tự: nền schema → gỡ e-commerce → routing mới → script migrate dữ liệu/ảnh → giao diện frontend → form tài liệu → admin → SEO/GEO → tinh chỉnh UI → kiểm thử. Tuân thủ AGENTS.md: patch tối thiểu, UTF-8 không BOM, prepared statement, CSRF, `php -l` sau khi sửa, rà mojibake sau khi đụng tiếng Việt.

## Task Dependency Graph

```json
{
  "waves": [
    { "wave": 1, "tasks": ["1", "2"] },
    { "wave": 2, "tasks": ["3", "4"] },
    { "wave": 3, "tasks": ["5", "6", "7"] },
    { "wave": 4, "tasks": ["8", "9"] },
    { "wave": 5, "tasks": ["10"] }
  ]
}
```

```
1 (schema nền) ─┬─► 4 (migrate dữ liệu/ảnh) ─┐
                ├─► 5 (frontend) ◄── 3 (routing)│
2 (gỡ e-commerce)┤                              ├─► 8 (SEO/GEO/sitemap)
                ├─► 6 (form tài liệu)           ├─► 9 (UI/UX polish)
                └─► 7 (admin)                   │
3 (routing) ◄── 1                               │
                                                └─► 10 (kiểm thử & rà soát) ← tất cả
```

## Tasks

- [x] 1. Nền schema blog (migration idempotent)
  - Tạo `scripts/blog_schema.php` (chạy CLI + gọi được khi bootstrap): hàm `blog_ensure_schema($pdo)` idempotent, bọc try/catch, dùng `has_table_column`.
  - Mở rộng `posts`: thêm `thumbnail_alt, author_name, views, schema_type, document_path, document_name, primary_category_id, updated_at` (giữ cột `type` cũ).
  - Tạo bảng `tags`, `post_categories`, `post_tags`, `document_requests` (theo Data Models trong design).
  - Tạo thư mục `assets/uploads/documents/` + `.htaccess` chặn thực thi PHP trong thư mục upload.
  - _Requirements: 2.1, 2.2, 2.3, 2.5, 2.6, 6.3_

- [x] 2. Gỡ tính năng affiliate & sản phẩm
  - Tạo `scripts/remove_ecommerce.php` (CLI, idempotent): `DROP TABLE IF EXISTS products, product_affiliate_links, product_clicks, product_ratings, product_registrations, product_views, affiliate_platforms, conversion_logs`; xóa `homepage_blocks` block sản phẩm/services; xóa settings nhóm affiliate.
  - Xóa file: `product.php`, `demo-goiy.php`, `demo-deal-today.html`, `includes/blocks/{products,services,dynamic_card_product}.php`, API sản phẩm (`api/register-product.php`, `api/product-rating.php`, `api/log-conversion.php`, `api/sp-hit.php`, `api/track-click.php` nếu chỉ cho affiliate).
  - Gỡ tham chiếu trong `index.php` (modal đăng ký, block sản phẩm), `includes/functions.php` (hàm affiliate), header/footer, sidebar admin.
  - Gỡ khu admin: `admin/products`, `admin/affiliate-platforms`, `admin/conversions`, `admin/clicks`, `admin/registrations` (sản phẩm) + mục menu liên quan.
  - Chạy `php -l` các file còn lại bị sửa; đảm bảo trang chủ không gọi hàm/biến đã xóa.
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6_

- [x] 3. Routing mới (root post + tag + short link)
  - Tạo `router.php`: nhận `?path={slug}`, phân giải post → short link → `slug_redirects` (301) → 404. Tái dùng logic redirect/log click của `short.php`.
  - Tạo `tag.php`: lấy tag theo slug, liệt kê bài (phân trang), SEO + breadcrumb + canonical `/tag/{slug}/`.
  - Cập nhật `includes/url-helper.php`: `postUrl()` trả `/{slug}` (bỏ prefix); thêm `tagUrl()`; cập nhật `generateRewriteRules()` sinh block mới (category/tag/static/catch-all 1 đoạn → router.php; bỏ rule sản phẩm 2 đoạn).
  - Cập nhật `.htaccess` (qua `saveHtaccess()` hoặc sửa trực tiếp marker "FPTStore Rewrite") + rule 301 `/tin-tuc/{slug}` → `/{slug}`.
  - _Requirements: 5.2, 5.5, 5.6, 8.2, 8.3_

- [x] 4. Script migrate từ WordPress (`scripts/migrate_from_wordpress.php`)
  - Kết nối 2 DB (đích qua config, nguồn `thangdgm_db`), UPSERT theo slug (idempotent).
  - Migrate categories (count>0) + tags + posts (post_type=post, publish): title/slug/content/summary/ngày/author/SEO Rank Math/views/schema_type.
  - Quan hệ `post_categories`/`post_tags` từ `wp_term_relationships`; `primary_category_id` từ `rank_math_primary_category`.
  - Featured image: `_thumbnail_id` → copy ảnh + `thumbnail`/`thumbnail_alt`.
  - ACF document `field_68347d0e25589` → copy file vào `assets/uploads/documents/` + set `document_path`/`document_name`.
  - Rewrite ảnh trong `post_content`: copy từ `wp-content/uploads` → `assets/uploads/wp/` (giữ cây năm/tháng), thay URL domain cũ → nội bộ, thêm `loading="lazy"`; làm sạch shortcode/comment Elementor cơ bản; ảnh thiếu → log.
  - Migrate `wp_betterlinks` → `short_links` (slug/target_url/redirect_type 307/title/tracking).
  - In báo cáo (đếm + lỗi). Chạy 2 lần kiểm tra không nhân đôi.
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 4.1, 4.2, 4.4, 4.5, 5.1_

- [x] 5. Giao diện frontend blog
  - Viết lại `index.php`: bỏ block sản phẩm/modal; bố cục blog (nổi bật/mới nhất + lưới theo category), giữ page cache, JSON-LD WebSite+Organization.
  - Viết lại phần dữ liệu `post.php`: dùng category/tag (bỏ enum type), nhận `$post` từ router hoặc fetch trực tiếp; meta (ngày/tác giả/views), TOC tự sinh (`build_toc`), tag list, bài liên quan cùng category, chèn form tài liệu nếu có document, share.
  - Viết lại `category.php`: bỏ logic sản phẩm; liệt kê bài thuộc category (phân trang), SEO + ItemList + breadcrumb.
  - Hoàn thiện `tag.php` giao diện danh sách bài.
  - Tăng lượt xem bài an toàn với page cache (endpoint nhẹ hoặc ghi bỏ qua cache).
  - _Requirements: 8.1, 8.6, 9.1, 9.4, 9.6, 10.1, 10.2, 10.3, 10.5_

- [x] 6. Form nhận tài liệu (gửi file từ server)
  - Tạo partial `includes/blocks/document_form.php` (fullname, email, honeypot, csrf, post_id) — render trên bài có document.
  - Tạo `api/request-document.php`: validate CSRF/honeypot/email/rate limit/post_id+document; đọc file local theo `document_path` (chống path traversal); gửi email kèm đính kèm qua `includes/mailer.php`; lưu `document_requests` (status sent/failed); trả JSON.
  - JS submit AJAX + hiển thị thông báo xác nhận.
  - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 11.1, 11.2, 11.4_

- [x] 7. Quản trị nội dung blog
  - Viết lại `admin/posts/` form: chọn nhiều category (checkbox), nhập tag (CSV → upsert), ảnh đại diện (Media Library), editor, upload tài liệu (whitelist pdf/docx/xlsx/pptx/zip), trường SEO + schema_type, trạng thái; auto slug tiếng Việt + unique; đổi slug publish → ghi `slug_redirects`.
  - Tạo `admin/tags/`: CRUD tag + slug + SEO cơ bản.
  - Tạo `admin/document-requests/`: danh sách + lọc (bài/ngày/status) + xuất CSV.
  - `admin/categories/`: gỡ field sản phẩm; `admin/short-links/`: cảnh báo trùng slug bài viết.
  - Cập nhật sidebar admin (`admin/includes/`): menu Blog (Bài viết, Category, Tag, Short link, Lead tài liệu); bỏ menu e-commerce.
  - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 5.4, 6.7, 11.1, 11.3_

- [x] 8. SEO & GEO kỹ thuật
  - Mở rộng `SEO::setArticleData` chọn `@type` theo `schema_type` + `author` Person; thêm `mainEntityOfPage`.
  - Viết lại `sitemap.php`: posts `/{slug}/`, categories `/danh-muc/{slug}/`, tags `/tag/{slug}/`; bỏ sản phẩm. Cập nhật `robots.txt`.
  - Tạo `llms.txt` (root, sinh bởi script hoặc động): mô tả site + danh mục + bài tiêu biểu.
  - Đảm bảo canonical dùng domain production; FAQ schema nếu có.
  - _Requirements: 8.1, 8.3, 8.4, 8.5, 9.1, 9.2, 9.3, 9.5_

- [x] 9. Tinh chỉnh giao diện (UI/UX)
  - Áp dụng skill UI/UX (`design1`, `.agent/skills/ui-ux-pro-max/SKILL.md`) cho theme blog: typography đọc tốt, lưới bài, trang đọc bài, responsive, tối ưu CWV (font/ảnh/CSS tới hạn).
  - Nhận diện thương hiệu "Thắng Digital Marketing"; dọn CSS/asset thừa của phần sản phẩm.
  - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 8.5_

- [x] 10. Kiểm thử & rà soát
  - `php -l` toàn bộ file PHP tạo/sửa; rà mojibake (`Ã|Â|á»|Ä`) trên DB + file.
  - Test routing đầy đủ (post root, category, tag, short link redirect, 404, trùng slug → post thắng).
  - Test migrate idempotent (chạy 2 lần, đếm không đổi); kiểm tra 1 vài bài: ảnh nội bộ, không còn URL cũ, tiếng Việt đúng, SEO meta + JSON-LD hợp lệ.
  - Test form tài liệu (gửi mail + đính kèm, honeypot, email sai, rate limit, path traversal an toàn).
  - Test sitemap không còn sản phẩm; canonical/OG đúng.
  - _Requirements: 1.4, 2.4, 3.6, 4.2, 5.2, 6.2, 8.2, 11.4, 11.5, 11.6_

## Notes

- Tái dùng: `SEO` class, Media Library, CSRF, page cache, `slug_redirects`, `short_links`, `has_table_column`, `create_slug`, `includes/mailer.php`.
- Migrate đọc/ghi thẳng PDO utf8mb4 — KHÔNG qua file trung gian (bảo toàn tiếng Việt).
- Sau mỗi task đụng tiếng Việt: rà mojibake ngay; sau mỗi sửa PHP: `php -l`.
- Không xóa dữ liệu ngoài phạm vi e-commerce demo; mọi script DROP chỉ tác động đúng bảng đã liệt kê.
