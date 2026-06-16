---
description: FPTSTORE project conventions, architecture, critical technical guardrails, and subscription service design guide
---

# FPTSTORE System Architecture & Conventions

This document serves as the absolute "Source of Truth" for the FPTSTORE project. AI agents and developers must strictly adhere to these patterns to maintain system integrity and avoid conflicts.

## 1. Core Technology Stack
- **Backend:** Native PHP (Procedural/Small Object-oriented mix).
- **Database:** MySQL/MariaDB using **PDO** with `utf8mb4_general_ci`.
- **Frontend:** HTML5, CSS3 (Vanilla), Bootstrap 5.3, Bootstrap Icons.
- **Dependency Management:** PHPMailer via Composer (`vendor/`).
- **Routing:** File-based routing with SEO-friendly overrides via `.htaccess`.

## 2. Technical Architecture
### Routing & SEO URLs
- **SEO Prefixes:** Managed in `.htaccess`. All user-facing pages use SEO-friendly URLs.
- **URL Mapping:**
  | SEO URL | File | Type |
  |---|---|---|
  | `/` | `index.php` | Trang chủ |
  | `/danh-muc/{slug}` | `category.php?slug=` | Danh mục |
  | `/{cat-slug}/{service-slug}` | `service.php` | Chi tiết dịch vụ |
  | `/tin-tuc` | `news.php` | Danh sách tin tức |
  | `/tin-tuc/{slug}` | `post.php?slug=` | Chi tiết bài viết |
  | `/lien-he` | `contact.php` | Liên hệ |
  | `/gioi-thieu` | `about.php` | Giới thiệu |
- **301 Redirect Policy:** Mọi URL `.php` gốc (ví dụ `/news.php`, `/category.php?slug=X`) PHẢI có 301 redirect về SEO URL tương ứng trong `.htaccess` để tránh duplicate content. Dùng `RewriteCond %{THE_REQUEST}` để tránh redirect loop.
- **URL Helpers:** Never hardcode URLs. Use:
  - `serviceUrl($slug, $category_slug, $abs = false)`
  - `categoryUrl($slug, $abs = false)`
  - `postUrl($slug, $abs = false)`

### Development Standards
- **Encoding:** **REQUIRED** `UTF-8 (no BOM)`. Never use tools that strip BOM or change encoding.
- **Naming Conventions:**
  - PHP/SQL: `snake_case`.
  - CSS/HTML Classes: `kebab-case`.
  - JS specifically for premium UI: use `premium_` prefix or isolated scopes.
- **Security Standards:**
  - **SQLi:** Always use PDO prepared statements.
  - **XSS:** Use `e($string)` helper (wraps `htmlspecialchars`) for all dynamic output.
  - **CSRF:** Use `generate_csrf_token()` and `verify_csrf_token($token)` for all POST requests.
  - **Admin POST Rule:** Do not assume `require_once '../includes/header.php'` is enough. Every admin page that accepts `POST` must explicitly call `require_valid_csrf_token()` (or `verify_csrf_token(...)`) inside the POST branch and include a hidden `csrf_token` field in the matching form.
  - **Passwording:** Never store plain text. Use `secure_password()` which implements `password_hash` with `SECURITY_KEY` salt.
  - **Password Consistency:** All admin password create/update/reset flows must use `secure_password()` only. Do not mix raw `password_hash()` in one screen and `verify_password()` in login on another screen.
  - **Credentials:** Site-specific secrets (DB, Keys) must remain in `config/config.php` and be excluded from Git via `.gitignore`.
  - **Session Hardening:** PHP session cookies must be hardened at server/runtime level before `session_start()` runs. Current baseline:
    - `session.cookie_httponly = 1`
    - `session.cookie_samesite = Lax`
    - `session.use_strict_mode = 1`
    - `session.cookie_secure = 1` on HTTPS
  - **Session Verification:** Confirm live response headers contain `Set-Cookie: PHPSESSID=...; HttpOnly; SameSite=Lax; Secure`.
  - **Session Fixation:** Admin login and remember-me restore flows must call `session_regenerate_id(true)` immediately after authentication succeeds.
  - **Transport Security:** HTTPS-only deployments must send `Strict-Transport-Security` with a safe baseline of `max-age=31536000`. Only add `includeSubDomains` / `preload` after validating every subdomain can serve HTTPS correctly.
  - **Environment Management:** `display_errors` must be `0` on production to avoid leaking server paths/logic.
  - **Anti-Spam:** All public forms MUST implement `check_bot_submission()` (Honeypot + Time Check) via `includes/functions.php`. Hidden inputs validation is mandatory.
  - **Checkout Polling Security:** Public payment polling endpoints must not reveal arbitrary order status by `order_code` alone. Bind each polling request to the same checkout session (or a dedicated per-order token) that created the order. Never protect third-party payment webhooks with browser CSRF because providers like SePay call server-to-server.
  - **Login Abuse Protection:** Admin login throttling must be stored server-side in the database (for example by IP/window table), not only in `$_SESSION`, so repeated attempts remain blocked across new sessions/browser restarts.
  - **Public Script Injection Guard:** Admin-configured public tracking snippets (`custom_script_header/body/footer`) must be validated on save and filtered again on render. Allow only trusted analytics/marketing domains and block unsafe inline handlers / storage / XHR / fetch patterns.

## 3. Database Schema Conventions
### Important Tables
- `services`: Uses **Dual Pricing** (`price_city` for HN/HCM, `price_province` for others). `features` is a JSON array.
- `categories`: `description` (Plain text/Short), `content` (Rich HTML/SEO Long).
- `homepage_blocks`: Controls the order and visibility of sections on `index.php`.
- `settings`: Key-Value pair storage for system-wide configuration.

### Migration Policy
- All schema changes **MUST** be documented in `database/migrations/`.
- Use idempotent SQL (e.g., `IF NOT EXISTS`).
- Do not rely solely on "Auto-migration" logic if present.

## 4. Admin System Capabilities
### Settings Tabs
1.  **General:** Site name, Logo, Favicon, Public email.
2.  **Contact:** Hotline, Zalo, Messenger (mapped to floating buttons).
3.  **Footer:** 4 columns of HTML content.
    - **Note:** Column 2 automatically loads *Active Root Categories* at the top. Any content configured in Admin for Col 2 will appear *below* this list.
4.  **Email SMTP:** Amazon SES compatible config (Host, Port, User, Pass, Secure).
5.  **URL:** Custom prefixes for clean URLs.

### Registration Management
- `service_registrations`: Stores customer leads from service detail pages.
- `contacts`: Stores leads from the general consultation form.
- **Workflow:** Submission -> Save to DB -> Trigger SMTP Notification to Admin.

## 5. Mailer Mechanism
- **Implementation:** `includes/mailer.php` (uses PHPMailer).
- **Triggers:** Automatically sends an HTML-styled email to the address in `smtp_to_email` upon new registration.
- **Order Flow Triggers:**
  - New order creation (`api/place-order.php`) should send a non-blocking admin notification email.
  - Successful payment confirmation from SePay webhook (`api/sepay-webhook.php`) should send:
    - customer fulfillment / confirmation email
    - separate admin payment-success notification email
  - Do not send customer course/software fulfillment email before payment is truly marked successful by webhook/admin payment confirmation.
- **Configuration:** Managed via Admin Settings. Test email available via `test-email.php`.

## 6. Directory & File Security
### Protective .htaccess
The root `.htaccess` must strictly protect against:
- **Directory Listing:** `Options -Indexes`.
- **Core Access:** Deny from all for `config/`, `database/`, `includes/`, and files starting with `.`.
- **Upload Execution:** Disable PHP execution in `assets/uploads/` via a local `.htaccess` to prevent remote code execution (RCE).
- **Security Headers Baseline:**
  - `Strict-Transport-Security: max-age=31536000`
  - `X-Frame-Options: SAMEORIGIN`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - Keep HSTS in `.htaccess` / server config, not only in application PHP output.

### Live Security Verification
- After changing cookie/session config, verify with:
  - `curl -I https://your-domain/`
  - `curl -s -D - -o /dev/null https://your-domain/ | grep -i set-cookie`
- Expected baseline on production HTTPS:
  - `PHPSESSID` includes `HttpOnly`, `SameSite=Lax`, `Secure`
  - response includes `Strict-Transport-Security: max-age=31536000`
- If scanner still reports old findings after headers are correct, treat cached scan results as a likely cause and rescan.

### File Upload Rules
- Always validate **Extension** + **MIME Type** (using `finfo_file`).
- Rename files using random strings (`bin2hex(random_bytes(4))`) + `time()`.
- Set file size limits (default: 5MB).

## 7. Frontend "Pro Max" Design Language
- **Aesthetic:** Glassmorphism, vibrant gradients (`#ff6b35` to `#f7931e`), and modern typography (Inter/Outfit).
- **Service Detail Patterns:**
  - **Sticky Sidebar:** Unifies Price Card and Contact Sidebar into a single fixed container.
  - **Price Switching:** Dynamic JS updates without page reload when switching regions (HN/TP.HCM vs Tỉnh khác).
  - **JS Isolation:** Local functions in `service.php` (e.g., `updatePremiumDetailPrice`) must be named uniquely to avoid being overwritten by global logic in `footer.php`.
- **Global JS Variables:** Always use `window.BASE_URL` (not `const BASE_URL`) when the value needs to be accessed by external `.js` files like `locations.js`. A `const` inside a `<script>` block is scoped to that block and invisible to other scripts checking `window.XXX`.

## 8. Encoding Integrity (Guardrail against Mojibake)
### The "Why"
In the past, some files were saved using **ANSI** or **Windows-1252** encoding instead of **UTF-8**. This causes Vietnamese characters (which are multi-byte in UTF-8) to be misinterpreted as multiple single-byte characters. 
- **Example:** `chủ` (UTF-8) becomes `chá»§` (misinterpreted as individual bytes).
- **Risk:** This breaks the user interface and SEO, making the site look broken or unprofessional.

### The General Rule
- **Mandatory Encoding:** `UTF-8 (no BOM)`.
- **Validation:** After any edit involving Vietnamese text, search the file content or the rendered page for patterns like `Ã`, `Â`, `Ä`, or the replacement character `�`.
- **Affected Areas:** All `.php`, `.js`, `.css`, and `.sql` files.

### Vietnamese Text Safety Checklist
- **Database layer:**
  - DB collation: `utf8mb4_general_ci` for every table storing Vietnamese text.
  - DB connection must set charset explicitly:
    - `mysql:host=...;dbname=...;charset=utf8mb4`
    - `SET NAMES utf8mb4`
- **HTTP/output layer:**
  - All HTML pages must include `<meta charset="UTF-8">` in `<head>`.
  - API responses with Vietnamese content should include `Content-Type` header in UTF-8 (`text/html; charset=UTF-8` / `application/json; charset=UTF-8`).
- **File layer (must be enforced before save):**
  - Source files must be stored as `UTF-8 (no BOM)`.
  - Do **not** use non-deterministic shell writers on files with Vietnamese text (`Set-Content`, `Out-File`) unless encoding is explicitly set to UTF-8.
  - Prefer patch edits or tools that preserve encoding.
- **Post-edit check (mandatory):**
  - Run `rg -n "Ã|Â|Ä|�" <file-or-folder>` for files that include Vietnamese text.
  - If match exists, stop and restore encoding before commit.
- **Recovery recipe (one-liner):**
  - When a file is visibly mojibake and is likely UTF-8 bytes decoded as Latin-1/Windows-1252, recover with:
    - `UTF8(Windows-1252(raw_text))`
  - Example in PowerShell:
    - `[System.Text.Encoding]::UTF8.GetString([System.Text.Encoding]::GetEncoding(1252).GetBytes((Get-Content .\path\to\file.php -Raw))) | Set-Content .\path\to\file.php -Encoding UTF8`

### Preventive Commit Rule
- Any commit touching files containing Vietnamese text must include:
  - A quick `rg` mojibake scan.
  - Manual spot-check of at least 2 UI screens in browser (cache hard reset required).

## 9. Git & Exclusion Standards (.gitignore)
### Rules for `.gitignore`
To prevent leaking sensitive data and committing "junk" files, every FPTSTORE instance must have a `.gitignore` containing:
- **Sensitive Config:** `config/config.php` (contains DB pass & Security Key).
- **Vendor:** `vendor/` (Composer dependencies should be installed on-site).
- **User Data:** `assets/uploads/` (images uploaded by users).
- **Environment:** `.env`, `.DS_Store`, `Thumbs.db`.
- **IDE:** `.idea/`, `.vscode/`.

### Deployment Note
- Always provide a `config/config.example.php` for new installations.
- Ensure `assets/uploads/` is created on the server with correct write permissions.

## 10. Conversion Tracking & Analytics
### Architecture
- **Hybrid Tracking:** Combines Client-side (GTM) and Server-side (Database) logging for maximum reliability.
- **Data Flow:**
  - **Forms:** Submission -> PHP Process -> `log_conversion()` -> DB `conversion_logs`.
  - **Contacts:** Click -> JS `fetch` -> `api/log-contact.php` -> DB `conversion_logs`.
  - **GTM:** All successful actions trigger `dataLayer.push({ event, ... })`.

### Key Components
- **Admin Interface:** `admin/conversions/index.php` (Manage Scripts, GTM IDs, Events, View Logs).
- **Database:** `conversion_logs` table (IP, User Agent, Page URL, Referrer, Form Data JSON).
- **Functions:** `log_conversion()` in `includes/functions.php`.
- **Schema Requirement:** `conversion_logs` must stay in sync with the current `log_conversion()` insert payload. At minimum, production schema must include:
  - `type`, `session_key`, `visitor_key`, `ip_address`, `user_agent`
  - `page_url`, `referrer`
  - `source_type`, `source_name`, `utm_source`, `utm_medium`, `utm_campaign`
  - `form_data`, `gtm_event`, `device_type`, `created_at`
- **Debug Rule:** If contact / conversion events stop recording, verify real table structure first (`SHOW CREATE TABLE conversion_logs` / phpMyAdmin columns) before blaming frontend click handlers, fetch/beacon, or GTM.
- **APIs:** 
  - `api/log-contact.php`: Logs server-side for Hotline/Zalo/Messenger clicks.
  - `ajax_contact.php` & `register-service.php`: Log form submissions.

## 11. Incident/Lesson Log
- **Incident #1:** Global `updateDetailPrice` in `footer.php` conflicted with local logic in `service.php`, causing dual-active tabs. **Lesson:** Always use unique or scoped JS function names.
- **Incident #2:** Category `description` was used for rich text, breaking homepage card layout. **Lesson:** Use `content` for HTML, `description` for plain text.
- **Incident #3:** Encoding changed to ANSI on some editors, breaking Vietnamese characters. **Lesson:** Force UTF-8 in IDE and verify before commit.
- **Incident #4:** `footer.php` smooth-scroll code called `document.querySelector('#')` when `<a href="#">` existed → JS crash. **Lesson:** Always guard `querySelector` with `if (href === '#') return;`.
- **Incident #5:** `header.php` declared `const BASE_URL` (block-scoped) but `locations.js` reads `window.BASE_URL` → API proxy URL became relative → 404 on SEO pages like `/truyen-hinh-fpt/slug`. **Lesson:** Use `window.BASE_URL` for any value consumed by external `.js` files.
- **Incident #6:** `csrf_token` added to `service.php` only, but registration modal is duplicated in `index.php` and `category.php` → those forms rejected. **Lesson:** When adding security features, `grep` the entire codebase for ALL instances of the form.
- **Incident #7:** `registerForm` ID existed in multiple files (`index.php`, `service.php`, `category.php`, `footer.php`) but only `footer.php` had `data-gtm-event`. JS selected the first instance (without attribute) causing GTM events to fail. **Lesson:** When using global modals that might be duplicated, ensure all instances have necessary data attributes, or better yet, use a single global instance included via `footer.php`.
- **Incident #8:** Vietnamese text became mojibake (`Dá»‹ch vá»¥`, `KhÃ¡ch hÃ ng`) after shell-based file writes. **Lesson:** For files containing Vietnamese, avoid raw shell write operations (`Set-Content`, `Out-File`) unless encoding is explicitly controlled; prefer patch-based edits and run mojibake scan (`Ã`, `Â`, `Ä`, `á»`) after every change.
- **Incident #9:** Some hosts returned `403 Forbidden` for nested admin routes (e.g. `/admin/media/index.php`) despite valid PHP. **Lesson:** Add local `.htaccess` in new admin subfolders with explicit `Require all granted` (or legacy `Allow from all`) and verify folder/file permissions on server (`755` dirs, `644` files).
- **Incident #10:** Production scanner flagged missing `HttpOnly` on `PHPSESSID`. The app already hardened custom cookies, but raw `session_start()` entrypoints still depended on server defaults. **Lesson:** Set PHP session cookie flags centrally in `.htaccess` / `.user.ini` so every `session_start()` inherits the same security baseline.
- **Incident #11:** Production scanner flagged missing `Strict-Transport-Security` even though HTTP already redirected to HTTPS. **Lesson:** HTTPS redirect alone is not enough; HSTS must be returned explicitly in response headers to harden future browser connections.
- **Incident #12:** Contact conversion logs stopped recording even though legacy rows existed. Root cause was not the click JS flow but an out-of-date `conversion_logs` table schema that no longer matched the current `log_conversion()` insert fields. **Lesson:** When logs stop recording, compare live DB schema against `ensure_conversion_logs_table()` and the actual `INSERT` columns before spending time on frontend/beacon debugging.
- **Incident #13:** Several admin screens (`users/add`, `users/edit`, `users/profile`, `banners/add`, `banners/edit`) relied on admin auth only and forgot explicit CSRF verification. **Lesson:** For every admin `POST` page, verify both sides exist together: backend token check in the `POST` branch and hidden `csrf_token` in the exact form being submitted.
- **Incident #14:** `api/check-payment.php` exposed payment status to anyone who knew or guessed an `order_code`, and it also cleared cart state during a public GET poll. **Lesson:** Payment polling must be bound to the checkout session (or signed order token), while SePay webhook auth remains server-to-server via secret header only.
- **Incident #15:** Admin profile password change used raw `password_hash()` while login verified through `verify_password()` with `SECURITY_KEY`. **Lesson:** All password write paths must use the same helper pair: `secure_password()` + `verify_password()`.
- **Incident #16:** Public tracking fields in conversion settings accepted arbitrary script/html, which made stored XSS possible through admin-configured snippets and UTM-style payloads. **Lesson:** Treat marketing snippets as untrusted input: validate on save, filter again on frontend render, and maintain a tight allowlist of trusted analytics hosts.
- **Incident #17:** Admin login brute-force throttling stored attempts only in session, so opening a new browser/session could bypass the limit. **Lesson:** Persist login rate-limit state in DB (for example `admin_login_attempts`) and clear it only after successful authentication or window expiry.

---

## 12. SEO System

### 12.1 SEO Helper Class
File: `includes/seo.php` — Lớp `SEO` quản lý toàn bộ meta tags, Open Graph, Twitter Cards, JSON-LD Structured Data, Canonical URLs.

**Khởi tạo và sử dụng:**
```php
require_once 'includes/seo.php';
$seo = init_seo(get_setting('site_name'), BASE_URL);

// Thiết lập cho từng trang
$seo->setTitle('Tên trang');
$seo->setDescription('Mô tả trang');
$seo->setKeywords('từ khóa 1, từ khóa 2');
$seo->setCanonical($url);
$seo->setOgImage($image_url);
$seo->setOgType('website'); // hoặc 'article', 'product'

// Breadcrumbs
$seo->addBreadcrumb('Trang chủ', BASE_URL);
$seo->addBreadcrumb('Danh mục', categoryUrl($slug, true));

// Structured Data (JSON-LD)
$seo->setProductData(['name' => ..., 'price' => ..., 'image' => ...]);
$seo->setArticleData(['headline' => ..., 'datePublished' => ...]);
$seo->setOrganizationData(['name' => ..., 'logo' => ...]);

// Render trong <head>
$seo->render(); // outputs meta + OG + Twitter + canonical + JSON-LD + breadcrumbs
```

**Page-specific SEO từ DB:**
```php
// Lấy SEO custom cho từng page (home, news, contact)
$page_seo = get_page_seo('home', $pdo);
// Trả về: ['meta_title', 'meta_description', 'meta_keywords', 'og_image']
```

### 12.2 Admin SEO Settings
File: `admin/seo/index.php`

**Cấu hình chung:**
- `seo_title_separator`: Ký tự ngăn cách title (mặc định ` | `)
- `seo_default_og_image`: Ảnh OG mặc định
- `google_analytics_id`: GA4 ID (G-XXXXXXXXXX)
- `google_site_verification`: Meta verification cho Search Console

**SEO từng trang:**
- Mỗi page (home, news, contact) có meta_title, meta_description, meta_keywords, og_image riêng
- Lưu trong bảng `page_seo` (page_key, meta_title, meta_description, meta_keywords, og_image)

### 12.3 AI SEO Generation (Groq)
File: `admin/ajax/groq-seo.php`

- Sử dụng **Groq API** (LLM model `llama-3.3-70b-versatile`) để tự động sinh SEO metadata
- Inputs: title, description, content của dịch vụ/bài viết
- Outputs: `focus_keyword`, `meta_title` (52-58 ký tự), `meta_description` (130-150 ký tự), `meta_keywords[]`
- Cấu hình: `seo_groq_api_key` và `seo_groq_model` trong bảng `settings`
- Nút "AI Generate" trong form edit dịch vụ/bài viết → gọi AJAX → điền kết quả vào form

**Settings keys:**
| Key | Mô tả |
|-----|-------|
| `seo_groq_api_key` | API Key từ console.groq.com |
| `seo_groq_model` | Model AI (mặc định: `llama-3.3-70b-versatile`) |

### 12.4 Per-entity SEO Fields
Bảng `services` và `categories` có các cột SEO riêng:
- `meta_title`, `meta_description`, `meta_keywords`, `focus_keyword`, `og_image`
- Được edit trong form admin với nút "AI Generate" tích hợp

---

## 13. Media Library

### 13.1 Architecture
File: `admin/media_library/index.php`, `admin/ajax/media-upload.php`, `admin/ajax/media-list.php`, `admin/ajax/media-delete.php`

**Bảng `media_library`** (tự động tạo qua `ensure_media_library_table($pdo)`):
- `id`, `filename`, `original_name`, `file_path`, `file_size`, `mime_type`
- `width`, `height` (cho ảnh)
- `created_at`

### 13.2 Features
- **Drag & Drop upload** — kéo thả file vào dropzone
- **Auto WebP conversion** — tự động convert JPG/PNG → WebP khi upload (dùng `compress_to_webp()`)
- **Grid view** với thumbnail — hiển thị dạng lưới, 48 items/page
- **Search & Filter** — tìm theo tên, lọc theo tháng upload
- **Bulk delete** — chọn nhiều file và xóa cùng lúc
- **Copy URL** — click để copy đường dẫn file
- **Pagination** — phân trang AJAX
- **Summernote integration** — upload ảnh từ WYSIWYG editor (`admin/ajax/summernote-upload.php`)

### 13.3 Upload Flow
```php
// admin/ajax/media-upload.php
// 1. Validate extension + MIME type (finfo_file)
// 2. Rename: bin2hex(random_bytes(4)) + time() + extension
// 3. Move to assets/uploads/media/YYYY/MM/
// 4. compress_to_webp() — convert + resize nếu > 1920px
// 5. INSERT INTO media_library
// 6. Return JSON { success, file: { id, url, filename, size } }
```

### 13.4 Security
- `is_safe_media_path($path)` kiểm tra path nằm trong thư mục upload
- Chỉ xóa file nếu path bắt đầu bằng `assets/uploads/media/`
- CSRF token cho mọi action

---

## 14. Menu Management

### 14.1 Architecture
File: `admin/menus/index.php`

**Bảng `menus`:**
- `id`, `title`, `url`, `icon` (Bootstrap Icon class)
- `position`: `header` hoặc `footer`
- `parent_id`: Hỗ trợ **nested menu** (2 cấp)
- `sort_order`, `is_active`

### 14.2 Features
- **Drag & Drop reorder** — SortableJS với nested support
- **Dual position** — Quản lý menu Header và Footer riêng biệt (tab switch)
- **CRUD inline** — Thêm/sửa/xóa menu item ngay trong trang
- **Parent-child** — Chọn parent để tạo submenu
- **Icon picker** — Chọn Bootstrap Icon cho mỗi item
- **Bulk save** — Lưu toàn bộ cây menu qua AJAX (hàm `update_menu_tree()`)

### 14.3 Key Functions
```php
fetch_menus_by_position($pdo, $position)  // Lấy menu theo vị trí
build_admin_menu_tree(array $items)       // Xây dựng cây menu
flatten_tree_options(array $items)        // Flatten cho select dropdown
render_admin_menu_items(array $items)     // Render HTML cho admin
update_menu_tree($pdo, $nodes, $position, $parentId) // Lưu cây menu
collect_descendant_ids($childrenMap, $startId, &$result) // Thu thập ID con
```

---

## 15. Homepage Blocks System

### 15.1 Architecture
File: `admin/homepage-blocks.php`, `includes/blocks/`

**Bảng `homepage_blocks`:**
- `id`, `block_key` (unique identifier), `name` (display name)
- `sort_order`: Thứ tự hiển thị trên trang chủ
- `is_visible`: Toggle ẩn/hiện

### 15.2 Block Types
Các block được render từ `includes/blocks/`:

| Block Key | File | Mô tả |
|-----------|------|-------|
| `hero` | `blocks/hero.php` | Banner slider (Swiper) |
| `categories` | `blocks/categories.php` | Danh mục dịch vụ |
| `services` | `blocks/services.php` | Danh sách dịch vụ nổi bật |
| `internet` | `blocks/internet.php` | Gói internet đặc biệt |
| `consultation_form` | `blocks/consultation_form.php` | Form tư vấn |
| `news` | `blocks/news.php` | Tin tức mới nhất |
| `faq` | `blocks/faq.php` | Câu hỏi thường gặp |

### 15.3 Admin Features
- **Drag & Drop reorder** — SortableJS, lưu qua AJAX
- **Toggle visibility** — Bật/tắt từng block bằng switch
- **Rename** — Đổi tên hiển thị block
- **Preview** — Link xem trang chủ

### 15.4 Rendering Pattern (index.php)
```php
// index.php — render blocks theo thứ tự DB
$blocks = $pdo->query("SELECT * FROM homepage_blocks WHERE is_visible = 1 ORDER BY sort_order ASC")->fetchAll();
foreach ($blocks as $block) {
    $block_file = 'includes/blocks/' . $block['block_key'] . '.php';
    if (file_exists($block_file)) {
        include $block_file;
    }
}
```

---

## 16. Banner Management

### 16.1 Architecture
Files: `admin/banners/index.php`, `admin/banners/add.php`, `admin/banners/edit.php`, `admin/banners/delete.php`

**Bảng `banners`:**
- `id`, `title`, `image` (path), `link` (URL khi click)
- `sort_order`, `status` (active/inactive)

### 16.2 Features
- **CRUD** đầy đủ — Thêm/sửa/xóa banner
- **Drag & Drop sort** — Sắp xếp thứ tự hiển thị (`admin/ajax/sort-banners.php`)
- **Image picker** — Chọn ảnh từ Media Library hoặc upload mới
- **Desktop/Mobile** — Có thể upload ảnh riêng cho mobile
- Hiển thị trong `blocks/hero.php` dưới dạng Swiper slider

---

## 17. FAQ Management

### 17.1 Architecture
Files: `admin/faq/index.php`, `admin/faq/add.php`, `admin/faq/edit.php`

**Bảng `faqs`:**
- `id`, `question`, `answer` (rich text)
- `sort_order`, `status` (active/inactive)

### 17.2 Features
- **CRUD** đầy đủ
- **Drag & Drop reorder** — SortableJS (`admin/ajax/faq-reorder.php`)
- **Toggle visibility** — Bật/tắt FAQ (`admin/ajax/faq-toggle.php`)
- Hiển thị trên frontend trong `blocks/faq.php` (accordion Bootstrap)

---

## 18. Backup System (FPTSTORE)

### 18.1 BackupManager Class
File: `includes/backup-manager.php`

**Khác biệt so với backup trong Subscription:** FPTSTORE sử dụng **PHP-based SQL export** (không dùng `mysqldump`) để tương thích với shared hosting bị disable `exec()`.

```php
$manager = new BackupManager($pdo);

// Tạo backup (DB + Files → encrypted ZIP)
$filename = $manager->createFullBackup();
// → "site_backup_2026-03-04_20-30-00.zip"

// Liệt kê backups
$backups = $manager->getBackups();
// → [['filename', 'size', 'date'], ...]

// Xóa backup
$manager->deleteBackup($filename);
```

### 18.2 Backup Flow
1. Export database bằng PHP (`exportDatabase()`) — dùng PDO query từng bảng
2. Tạo ZIP chứa toàn bộ source code + file SQL
3. **Mã hóa AES-256** với `BACKUP_PASSWORD` (nếu có trong config)
4. Lưu vào `backups/` — exclude: `backups/`, `node_modules/`, `.git/`
5. Progress tracking qua `$_SESSION['backup_status']`

### 18.3 Admin UI
File: `admin/backup/index.php`, `admin/ajax/backup.php`
- Nút "Tạo backup ngay" với progress bar real-time
- Danh sách backups: filename, size, date
- Download và Delete

---

## 19. Address Proxy API

File: `api/address-proxy.php`

CORS proxy cho **CASSO AddressKit** — lấy danh sách tỉnh/thành và phường/xã cho form đăng ký dịch vụ.

```
GET /api/address-proxy.php?action=provinces
→ Proxy tới https://production.cas.so/address-kit/latest/provinces

GET /api/address-proxy.php?action=communes&provinceId=01
→ Proxy tới https://production.cas.so/address-kit/latest/provinces/01/communes
```

**Frontend integration** (`assets/js/locations.js`):
- Dropdown Tỉnh/Thành → chọn → load Quận/Huyện/Xã tự động
- Sử dụng `window.BASE_URL` để xây dựng URL API

---

## 20. Dynamic .htaccess Management

### 20.1 Architecture
File: `includes/url-helper.php`

Sử dụng **marker-based sections** (kiểu WordPress) để quản lý `.htaccess` an toàn — chỉ sửa phần giữa `# BEGIN marker` và `# END marker`.

### 20.2 URL Rewrite Rules
```php
// Tự động generate rewrite rules từ URL prefixes trong DB
saveHtaccess(); // Ghi vào section "# BEGIN FPTStore Rewrite"
```

**URL Prefixes (setting_group = 'url'):**
| Setting Key | Default | Mô tả |
|-------------|---------|-------|
| `url_category_prefix` | `danh-muc` | Prefix cho danh mục |
| `url_post_prefix` | `tin-tuc` | Prefix cho bài viết |

**Service URL Pattern:** `/{category-slug}/{service-slug}` — không dùng prefix, match bằng wildcard cuối `.htaccess`.

### 20.3 Performance Rules
```php
// Tự động generate GZIP + Cache rules
savePerformanceHtaccess($gzip_enabled, $cache_enabled);
// → Ghi vào section "# BEGIN FPTStore Performance"
```

### 20.4 Key Functions
```php
getUrlPrefixes()              // Lấy prefixes (cached)
serviceUrl($slug, $cat_slug)  // Generate URL dịch vụ
categoryUrl($slug)            // Generate URL danh mục
postUrl($slug)                // Generate URL bài viết
replaceHtaccessSection($marker, $content) // Sửa section trong .htaccess
generateRewriteRules()        // Generate rewrite rules
generatePerformanceRules()    // Generate GZIP + cache rules
clearUrlPrefixCache()         // Clear cache khi thay đổi settings
```

---

## 21. Activity Logging

### 21.1 Function
File: `includes/functions.php` → `log_activity()`

```php
log_activity($action, $resource_type, $resource_id, $details);
// Ví dụ:
log_activity('create', 'service', $id, "Thêm dịch vụ: $name");
log_activity('update', 'setting', null, "Cập nhật cấu hình SMTP");
log_activity('delete_permanent', 'service', $id, "Force delete service: $name");
log_activity('restore', 'service', $id, "Restore service: $name");
```

### 21.2 Database
Bảng `activity_logs`:
- `id`, `admin_id` (từ session), `action`, `resource_type`, `resource_id`
- `details` (text), `ip_address`, `user_agent`, `created_at`

### 21.3 Admin Viewer
File: `admin/logs/index.php` — Xem lịch sử hoạt động admin

---

## 22. Soft Delete / Trash System

### 22.1 Pattern
Files: `admin/services/trash.php`, `admin/categories/trash.php`

**Cơ chế:**
- Thêm `deleted_at` (DATETIME NULL) và `deleted_by` (INT NULL) vào bảng
- **Auto-migration:** Code tự kiểm tra và thêm cột nếu chưa có (`has_table_column()`)
- Soft delete: `UPDATE SET deleted_at = NOW(), deleted_by = :admin_id`
- Frontend filter: `WHERE deleted_at IS NULL` (chỉ hiển thị items chưa xóa)

### 22.2 Trash Page Features
- **Danh sách** items đã xóa (tên, danh mục, ngày xóa)
- **Khôi phục**: `UPDATE SET deleted_at = NULL, deleted_by = NULL`
- **Xóa vĩnh viễn**: `DELETE WHERE id = ? AND deleted_at IS NOT NULL` (chỉ cho phép xóa item đã trong trash)
- **Confirmation dialog** cho cả 2 action

### 22.3 Auto-migration Pattern
```php
// Tự động thêm cột nếu chưa có — không cần migration file
if (!has_table_column($pdo, 'services', 'deleted_at')) {
    $pdo->exec("ALTER TABLE services ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status");
}
```

---

## 23. Admin Dashboard

### 23.1 Architecture
File: `admin/index.php`

**Stats cards:**
| Metric | Query |
|--------|-------|
| Dịch vụ | `COUNT(*) FROM services` |
| Đăng ký | `COUNT(*) FROM service_registrations` |
| Liên hệ | `COUNT(*) FROM contacts` |
| Lượt xem | `SUM(views) FROM services` |

### 23.2 Charts (Chart.js 4)
- **Line chart** — Đăng ký + Liên hệ theo ngày (7 ngày gần nhất)
- **Donut chart** — Dịch vụ theo danh mục
- **Bar chart** — Top dịch vụ có lượt xem cao nhất

### 23.3 Quick Actions
- Links nhanh đến: Thêm dịch vụ, Thêm bài viết, Cài đặt, Xóa cache
- Real-time data via AJAX (`admin/ajax/get_recent_registrations.php`)

---

## 24. Asset URL Cache Busting

### 24.1 Function
File: `includes/functions.php` → `asset_url()`

```php
// Tạo URL asset với cache-busting parameter
echo asset_url('assets/css/style.css');
// → /assets/css/style.css?v=12345

// Logic:
// 1. Đọc setting 'cache_version' từ DB (set khi admin click "Xóa cache")
// 2. Fallback: dùng filemtime() của file
// 3. Kết quả: path?v={version}
```

> **Lưu ý:** Không dùng `?t=time()` cho critical.css — sẽ bust cache mỗi request.

---

## 25. Services CRUD (Admin)

### 25.1 Architecture
Files: `admin/services/index.php`, `add.php`, `edit.php`, `trash.php`

### 25.2 Service Data Model
Bảng `services`:
- `id`, `name`, `slug` (UNIQUE), `category_id`
- **Dual Pricing**: `price_city` (HN/HCM), `price_province` (tỉnh khác)
- `content` (LONGTEXT — Summernote WYSIWYG)
- `features` (JSON array), `image` (main), `gallery` (JSON array of paths)
- `meta_title`, `meta_description`, `meta_keywords`, `focus_keyword`, `og_image`
- `views` (counter), `status`, `sort_order`
- `deleted_at`, `deleted_by` (soft delete)

### 25.3 Image Handling
- Main image: chọn từ Media Library (`image` field)
- Gallery: chọn nhiều ảnh từ Media Library (`gallery` JSON array)
- **Không xóa file vật lý** khi xóa reference — file thuộc về Media Library

### 25.4 Categories
Bảng `categories`:
- `id`, `name`, `slug`, `description` (plain text), `content` (rich HTML)
- `parent_id`: Hỗ trợ hierarchical categories
- `image`, `icon`, `status`, `sort_order`
- `meta_title`, `meta_description`, `meta_keywords`, `focus_keyword`, `og_image`
- Soft delete: `deleted_at`, `deleted_by`

**Helper functions:**
```php
get_hierarchical_categories($pdo, $selected_id, $exclude_id, $active_only)
render_category_options($pdo, $selected_id, $exclude_id, $active_only)
build_category_tree($categories, &$result, $parent_id, $level, ...)
```

---

## 26. Posts / Content Management (FPTSTORE)

### 26.1 Admin
Files: `admin/posts/index.php`, `add.php`, `edit.php`, `trash.php`

### 26.2 WYSIWYG Editor
- **Summernote** editor cho content
- Image upload trong editor: `admin/ajax/summernote-upload.php`
- Auto WebP conversion khi upload

---

## 27. Registration & Contact System

### 27.1 Service Registration
File: `api/register-service.php`

**Flow:**
```
[Form trên service.php/index.php/category.php]
   → POST /api/register-service.php
   → Validate + check_bot_submission()
   → INSERT INTO service_registrations
   → log_conversion('registration', $data)
   → send_registration_notification($payload) (SMTP)
   → JSON response
```

**Bảng `service_registrations`:**
- `id`, `service_id`, `fullname`, `phone`, `province`, `district`, `address`
- `source` (trang gốc), `created_at`

### 27.2 Contact Form
File: `ajax_contact.php`

**Flow tương tự** nhưng lưu vào bảng `contacts`:
- `id`, `name`, `phone`, `email`, `message`, `service_interest`
- `created_at`

### 27.3 Admin Views
- `admin/registrations/index.php`: Danh sách đăng ký dịch vụ
- AJAX load recent registrations cho dashboard

---

## 28. FPTSTORE Complete File Structure

```
project/
├── api/
│   ├── address-proxy.php       # CASSO AddressKit CORS proxy
│   ├── log-contact.php         # Log clicks (Hotline/Zalo/Messenger)
│   └── register-service.php    # Service registration API
├── admin/
│   ├── index.php               # Dashboard (Chart.js)
│   ├── ajax/
│   │   ├── backup.php          # Backup AJAX handler
│   │   ├── clear-cache.php     # Page cache flush
│   │   ├── faq-reorder.php     # FAQ drag-drop save
│   │   ├── faq-toggle.php      # FAQ visibility toggle
│   │   ├── groq-seo.php        # AI SEO generation
│   │   ├── media-upload.php    # Media Library upload
│   │   ├── media-list.php      # Media Library listing
│   │   ├── media-delete.php    # Media Library delete
│   │   ├── sort-banners.php    # Banner drag-drop save
│   │   ├── summernote-upload.php # WYSIWYG image upload
│   │   └── get_recent_registrations.php
│   ├── backup/index.php        # Backup & Restore UI
│   ├── banners/                # Banner CRUD (index, add, edit, delete)
│   ├── categories/             # Category CRUD + trash
│   ├── conversions/index.php   # GTM & Conversion tracking
│   ├── faq/                    # FAQ CRUD (index, add, edit)
│   ├── homepage-blocks.php     # Homepage block ordering
│   ├── logs/index.php          # Activity logs viewer
│   ├── media_library/index.php # Media Library UI
│   ├── menus/index.php         # Menu Management (header/footer)
│   ├── posts/                  # Post CRUD + trash
│   ├── registrations/index.php # Service registration list
│   ├── seo/index.php           # SEO Settings + Groq AI
│   ├── services/               # Service CRUD + trash
│   ├── settings/index.php      # General Settings (51KB)
│   ├── users/                  # User management (CRUD + profile)
│   ├── login.php, logout.php
│   ├── forgot-password.php, reset-password.php
│   └── includes/               # Admin header, footer, sidebar
├── includes/
│   ├── functions.php           # Core utilities (upload, slug, CSRF, etc.)
│   ├── backup-manager.php      # BackupManager class
│   ├── footer.php              # Public frontend footer
│   ├── header.php              # Public frontend header
│   ├── mailer.php              # PHPMailer wrapper (Amazon SES)
│   ├── page-cache.php          # PageCache class
│   ├── seo.php                 # SEO Helper class
│   ├── url-helper.php          # URL helpers + .htaccess management
│   └── blocks/                 # Homepage block templates
│       ├── hero.php            # Banner slider
│       ├── categories.php      # Category grid
│       ├── services.php        # Service cards
│       ├── internet.php        # Internet packages
│       ├── consultation_form.php # Registration form
│       ├── news.php            # Latest news
│       └── faq.php             # FAQ accordion
├── config/
│   ├── config.php              # DB credentials, SECURITY_KEY (gitignored)
│   ├── config.example.php      # Template for new installs
│   └── database.php            # PDO connection setup
├── assets/
│   ├── css/                    # Stylesheets
│   ├── js/                     # JavaScript (locations.js, etc.)
│   ├── images/                 # Static images
│   └── uploads/                # User uploads (gitignored)
│       └── media/YYYY/MM/      # Media Library files
├── index.php                   # Homepage (block-based)
├── service.php                 # Service detail page
├── category.php                # Category listing page
├── news.php                    # News listing page
├── post.php                    # Post detail page
├── contact.php                 # Contact page
├── ajax_contact.php            # Contact form handler
├── sitemap.php                 # Dynamic XML sitemap
├── 404.php                     # Custom 404 page
├── robots.txt                  # Search engine directives
├── .htaccess                   # Routing + security + performance
└── run_migration.php           # Database migration runner
```

---

# PHẦN 2: Subscription Web Service Design Guide

> Hướng dẫn xây dựng website cung cấp dịch vụ theo gói đăng ký (subscription-based SaaS) với PHP/MySQL.

---

## 29. Đăng Ký & Xác Thực Người Dùng

### 29.1 Quy trình
```
[Đăng ký] → [Gửi email xác thực] → [Click link] → [Kích hoạt] → [Đăng nhập]
```

### 29.2 Database
**Main tables:**
- `users` - User accounts, subscription info, verification tokens
- `payments` - Payment transactions (Sepay integration)
- `subscription_plans` - Plan definitions (Free, Pro)
- `audit_logs` - Activity tracking
- `login_attempts` - Rate limiting
- `site_settings` - Dynamic configuration

### 29.3 Security
- **Password hash**: `password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`
- **Verification token**: `bin2hex(random_bytes(32))` (64 chars)
- **Rate limiting**: 5 lần đăng nhập sai → khóa 15 phút
- **Session regeneration** sau khi login thành công

### 29.4 Email Verification (PHPMailer)
- Sử dụng **PHPMailer** qua SMTP (Gmail, SendGrid, v.v.)
- Database lưu `email_verify_token` (64 chars) và `email_verified` (tinyint).

**Helper Function Example:**
```php
function sendVerificationEmail($email, $name, $token) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email);

        $verifyUrl = APP_URL . "/auth/verify-email.php?token=$token";
        
        $mail->isHTML(true);
        $mail->Subject = 'Xác thực tài khoản của bạn';
        $mail->Body = "Chào $name,<br>Vui lòng click vào link sau để xác thực: <a href='$verifyUrl'>$verifyUrl</a>";
        
        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}
```

### 29.5 Xử lý xác thực (verify-email.php)
- Nhận `token` từ URL.
- Tìm user có token khớp và chưa xác thực.
- Cập nhật `email_verified = 1`, `email_verify_token = NULL`.
- Chuyển hướng về login với thông báo thành công.

```php
$token = $_GET['token'] ?? '';
$user = dbQueryOne("SELECT id FROM users WHERE email_verify_token = ?", [$token]);

if ($user) {
    dbExecute("UPDATE users SET email_verified = 1, email_verify_token = NULL WHERE id = ?", [$user['id']]);
    setFlash('success', 'Xác thực thành công! Bạn có thể đăng nhập.');
    header("Location: login.php");
} else {
    setFlash('error', 'Token không hợp lệ hoặc đã hết hạn.');
}
```

---

## 30. Mã Hóa Dữ Liệu Nhạy Cảm

### 30.1 Thuật toán
**AES-256-GCM** (Galois/Counter Mode)
- Key: 256 bits (64 hex chars)
- IV: 12 bytes random mỗi lần
- Authentication Tag: 16 bytes

### 30.2 Implementation
File: `includes/encryption.php`

**Key functions:**
- `encrypt($plaintext)` - Mã hóa dữ liệu, return `'ENC:' . base64_encode(iv + tag + ciphertext)`
- `decrypt($encrypted)` - Giải mã, kiểm tra prefix 'ENC:'
- `getSensitiveFields($table)` - Danh sách trường cần mã hóa

**Trường cần mã hóa:**
- `users`: `phone`
- `businesses`: `tax_code`, `phone`, `email`, `representative_name`

> **QUAN TRỌNG:** Cột database phải VARCHAR(255) để chứa ciphertext (~60-100 chars)

---

## 31. Thanh Toán Qua Sepay

### 31.1 Flow
```
[Chọn gói] → [Tạo đơn hàng] → [Hiển thị QR] → [Khách chuyển tiền]
                                     ↓
[Kích hoạt PRO] ← [Webhook xử lý] ← [Sepay gửi webhook]
```

### 31.2 Database
Tạo bảng `payments`:
- `id`, `user_id`, `order_code` (UNIQUE)
- `amount`, `subscription_months`
- `status` ('pending'|'completed'|'failed')
- `sepay_transaction_id`, `payment_details` (JSON)
- `created_at`, `paid_at`

### 31.3 Generate Order Code
```php
// Format: QLHKD + YYMMDD + 4 hex = 15 chars
function generateOrderCode(): string {
    return 'QLHKD' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
}
// Output: QLHKD2601293A7B
```

### 31.4 QR Code URL
```php
$sepayUrl = sprintf(
    "https://qr.sepay.vn/img?acc=%s&bank=%s&amount=%d&des=%s",
    $bankAccNum,
    $bankCode,      // MB, VCB, TCB, etc.
    $amount,
    urlencode('SEVQR ' . $orderCode)  // SEVQR prefix required!
);
```

### 31.5 Webhook Handler
File: `api/sepay-webhook.php`

**Steps:**
1. Verify `Authorization: Apikey <secret>` header
2. Parse JSON payload, extract `id`, `content`, `transferAmount`
3. Extract order code: `preg_match('/SEVQR\s+(QLHKD\w+)/i', $content, $matches)`
4. Check idempotency (đã xử lý chưa?)
5. Find pending payment, verify amount
6. Update payment status = 'completed'
7. Extend user subscription
8. Return `{'success': true}` (required by Sepay!)

### 31.6 Cấu hình Sepay
1. Vào **my.sepay.vn > Công ty > Cấu hình chung**
2. Thêm mã thanh toán: Prefix `QLHKD`, Suffix 10 ký tự
3. Webhooks > Thêm URL: `https://yourdomain.com/api/sepay-webhook.php`
4. Chọn **API Key** authentication
5. Copy API Key vào admin settings (`sepay_webhook_secret`)

---

## 32. Multi-step Checkout

### 32.1 Flow
```
[subscription/index.php]  →  [checkout.php]  →  [pay.php]
   So sánh gói               Chọn thời hạn      QR + Polling
```

### 32.2 Checkout (Chọn thời hạn)
File: `subscription/checkout.php`

- Radio buttons cho 1/6/12 tháng
- Tính giá + discount (VD: 6 tháng giảm 10%, 12 tháng giảm 20%)
- JavaScript update giá real-time khi chọn
- Hiển thị summary: Original price, Discount, Total

### 32.3 Payment Page
File: `subscription/pay.php`

- Hiển thị QR code từ Sepay URL
- Thông tin: Order code, Amount, Bank info
- **Auto-polling** payment status mỗi 5 giây
- Nút "Kiểm tra thủ công"
- Redirect khi thanh toán thành công

---

## 33. Payment Status Polling

### 33.1 API
File: `api/check-payment.php`

```php
// Input: ?order_code=QLHKD...
// Output: {'success': true, 'status': 'pending'|'completed', 'paid_at': ...}
```

### 33.2 Frontend
```javascript
// Auto-poll mỗi 5 giây
setInterval(async () => {
    const res = await fetch(`/api/check-payment.php?order_code=${ORDER_CODE}`);
    const data = await res.json();
    if (data.status === 'completed') {
        showSuccessMessage();
        redirect('/subscription/?success=paid');
    }
}, 5000);
```

---

## 34. Rate Limiting

### 34.1 Database
Tạo bảng `login_attempts`:
- `id`, `ip_address`, `email`, `attempted_at`
- Index: `(ip_address, attempted_at)`

### 34.2 Logic
- Max 5 attempts trong 15 phút
- Record mỗi lần đăng nhập thất bại
- Clear attempts khi đăng nhập thành công
- Hiển thị thời gian còn lại khi bị lock

Functions: `isRateLimited()`, `recordLoginAttempt()`, `clearLoginAttempts()`, `getRemainingLockoutTime()`

---

## 35. Audit Logging

### 35.1 Database
Tạo bảng `audit_logs`:
- `id`, `user_id`, `action`, `category`, `item_id`
- `details` (JSON), `ip_address`, `user_agent`, `created_at`

### 35.2 Common Actions
```php
// Auth
logAudit('login_success', 'auth');
logAudit('password_reset', 'auth');

// Payment
logAudit('order_create', 'payment', null, ['order_code' => $code]);
logAudit('payment_completed', 'payment', $paymentId);

// Admin
logAudit('user_update', 'admin', $userId, ['changes' => $changes]);
```

---

## 36. Flash Messages & Notifications

### 36.1 Bootstrap Toast
- Auto-hide sau 8 giây (trừ `.alert-permanent`)
- Types: `success`, `error`, `warning`, `info`
- Icon: Bootstrap Icons (bi-check-circle, bi-x-circle, etc.)

### 36.2 Payment Success Notification
- Hiển thị thông tin đơn hàng: Mã đơn, Số tiền, Thời hạn, Hết hạn
- Badges cho gói PRO và expiry date
- Nút dismiss

---

## 37. Admin Panel (Subscription)

### 37.1 Site Settings (General Configuration)
File: `admin/site-settings.php`

#### 37.1.1 Logo & Favicon Upload
- **Function**: `uploadBrandingFile($file, $type)` in `includes/functions.php`
- **Validation**: PNG, JPG, SVG for Logo; ICO, PNG for Favicon
- **Max size**: 2MB cho Logo, 1MB cho Favicon
- **Logic**: Tự động xóa file cũ trước khi lưu file mới

```php
// Save setting after upload
updateSetting('site_logo', $newFilename);  // or 'site_favicon'

// Get URL
$logoUrl = getLogoUrl();     // Returns asset('images/' . getSetting('site_logo'))
$faviconUrl = getFaviconUrl();
```

#### 37.1.2 Dynamic Footer (4 Columns)
Footer được chia làm 4 cột, cấu hình qua Admin:

| Cột | Nội dung | Setting Keys |
|-----|----------|--------------|
| 1 | Thương hiệu (Tiêu đề, Mô tả, Link) | `footer_col1_title`, `footer_col1_desc`, `footer_col1_link_*` |
| 2 | Chính sách (max 5 link) | `footer_col2_title`, `footer_col2_link_*` |
| 3 | Thông tin (max 5 link) | `footer_col3_title`, `footer_col3_link_*` |
| 4 | Liên hệ (Email, Phone, Zalo, Telegram) | `footer_contact_*` |

#### 37.1.3 Copyright & Custom Scripts
- **Copyright**: Hỗ trợ HTML và biến động như `{year}`, `{app_name}`
- **Custom Scripts**: Inject vào 3 vị trí:
  - `script_header`: Trong `<head>` (Analytics, Fonts)
  - `script_body`: Sau `<body>` (Chat widgets)
  - `script_footer`: Trước `</body>` (Tracking pixels)

#### 37.1.4 Payment Gateway (Sepay)
- Account number, Bank code, Bank name, Account name
- Webhook secret (API Key authentication)

### 37.2 Subscription Plans Management
File: `admin/plans.php`

Database: `subscription_plans` table
- Edit giá theo tháng/năm
- Quản lý features (JSON)
- Quản lý limits (JSON): records_per_month, export_months, storage_years

---

## 38. User Management (Advanced)

### 38.1 Chức năng cơ bản
File: `admin/users.php`, `admin/edituser.php`

- **Bộ lọc & Tìm kiếm**: Lọc theo status, gói (Free/Pro), tìm kiếm theo tên/email
- **Chỉnh sửa thông tin**:
  - Tên, Email, SĐT (mã hóa với `encrypt()`)
  - Xác thực email thủ công
  - Ban/Unban user

### 38.2 Subscription Management
```php
// Cấp Pro thủ công (thêm số tháng)
$newExpires = strtotime("+{$months} months", strtotime($currentExpires ?: 'now'));
dbExecute("UPDATE users SET subscription_type = 'pro', subscription_expires = ? WHERE id = ?", 
    [date('Y-m-d H:i:s', $newExpires), $userId]);
logAudit('grant_pro', 'admin', $userId, ['months' => $months]);

// Thu hồi Pro
dbExecute("UPDATE users SET subscription_type = 'free', subscription_expires = NULL WHERE id = ?", [$userId]);
logAudit('revoke_pro', 'admin', $userId);
```

### 38.3 Custom Limits (Override)
Ghi đè giới hạn cho từng user riêng lẻ:

```php
// Database column: users.custom_limits (JSON)
// Example: {"records_per_month": 500, "voice_input_per_month": 100}

// Get effective limit (priority: custom > plan)
function getUserLimit($feature) {
    $customLimits = json_decode($user['custom_limits'], true);
    if (isset($customLimits[$feature])) {
        return $customLimits[$feature];
    }
    return getPlanLimit($user['subscription_type'], $feature);
}
```

### 38.4 Soft Delete & Hard Delete
**Quy trình 2 bước để xóa user:**

1. **Soft Delete (Vô hiệu hóa)**:
   - Đổi `status` → `deleted`
   - Gọi `backupUserData($userId)`: Xuất dữ liệu user ra file Excel, gửi email cho admin
   - User không thể login nhưng data vẫn còn

2. **Hard Delete (Xóa vĩnh viễn)**:
   - Chỉ cho phép nếu user đã bị Soft Delete trước đó
   - Xóa toàn bộ data liên quan (revenues, businesses, etc.)
   - Confirmation dialog với nhập tên user để xác nhận

---

## 39. Content Management (Posts)

### 39.1 Database
Bảng `posts` lưu trữ tất cả nội dung tĩnh:
- `id`, `title`, `slug` (UNIQUE)
- `type`: `post` (bài viết), `info` (trang thông tin), `update` (tin tức/changelog)
- `content` (LONGTEXT), `excerpt`, `thumbnail`
- `status`: `published`, `draft`, `hidden`
- `created_at`, `updated_at`

### 39.2 Admin Pages
- **posts.php**: Danh sách + lọc theo type/status
- **post-editor.php**: WYSIWYG editor (TinyMCE/CKEditor)
- **upload-image.php**: Upload hình ảnh cho bài viết

### 39.3 Frontend Display
```php
// Hiển thị trang theo slug
// File: page.php?slug=chinh-sach-bao-mat
$post = dbQueryOne("SELECT * FROM posts WHERE slug = ? AND status = 'published'", [$slug]);
```

### 39.4 Loại bài viết
| Type | Mô tả | Ví dụ |
|------|-------|-------|
| `post` | Bài viết blog | Hướng dẫn sử dụng |
| `info` | Trang thông tin tĩnh | Chính sách bảo mật, Điều khoản |
| `update` | Tin tức/Changelog | Cập nhật phiên bản |

---

## 40. System Announcements

### 40.1 Database
```sql
CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','danger') DEFAULT 'info',
  `is_active` tinyint(1) DEFAULT 1,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 40.2 Logic hiển thị
```php
// Lấy announcements đang active và trong khoảng ngày cho phép
function getActiveAnnouncements(): array {
    $now = date('Y-m-d H:i:s');
    return dbQuery(
        "SELECT * FROM announcements 
         WHERE is_active = 1
         AND (start_date IS NULL OR start_date <= ?)
         AND (end_date IS NULL OR end_date >= ?)
         ORDER BY display_order ASC",
        [$now, $now]
    );
}
```

### 40.3 Admin Functions
File: `admin/announcements.php`
- CRUD thông báo
- Toggle active/inactive
- Lên lịch hiển thị (start_date, end_date)
- Sắp xếp thứ tự (display_order)

---

## 41. Maintenance Mode

### 41.1 Cơ chế hoạt động
Hệ thống lưu cấu hình trong file `config/maintenance.php` (không phải database) để tránh query khi web đang khóa.

```php
// config/maintenance.php
return [
    'enabled' => true,
    'message' => 'Website đang được bảo trì. Vui lòng quay lại sau!',
    'estimated_time' => '2026-02-07 10:00:00'
];
```

### 41.2 Core Functions
```php
// Check maintenance status
function isMaintenanceMode(): bool {
    $config = require ROOT_PATH . '/config/maintenance.php';
    return $config['enabled'] === true;
}

// Toggle maintenance
function setMaintenanceMode(bool $enabled, ?string $message = null, ?string $estimatedTime = null): bool {
    $config = ['enabled' => $enabled, 'message' => $message, 'estimated_time' => $estimatedTime];
    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    return file_put_contents(ROOT_PATH . '/config/maintenance.php', $content) !== false;
}
```

### 41.3 Features
- **Bật/Tắt**: Khi bật, user thường thấy trang `maintenance.php`. Admin vẫn truy cập bình thường (check session/role).
- **Thông báo tùy chỉnh**: Nhập nội dung HTML.
- **Countdown**: Đặt thời gian dự kiến để hiển thị bộ đếm ngược.

### 41.4 Middleware Check
```php
// bootstrap.php hoặc đầu mỗi page
if (isMaintenanceMode() && !isAdmin()) {
    include ROOT_PATH . '/maintenance.php';
    exit;
}
```

---

## 42. Backup & Restore System

### 42.1 Local Backup
Sử dụng `mysqldump` và `gzip` để tạo backup database.

```php
// Create backup command
$filename = 'backup_' . date('Y-m-d_His') . '.sql.gz';
$cmd = sprintf(
    'mysqldump -h %s -u %s -p%s %s | gzip > %s',
    DB_HOST, DB_USER, DB_PASS, DB_NAME,
    escapeshellarg($backupPath . '/' . $filename)
);
exec($cmd, $output, $returnCode);
```

**Lưu trữ theo thư mục:**
- `backups/daily/` - Giữ 7 ngày gần nhất
- `backups/weekly/` - Giữ 4 tuần gần nhất
- `backups/monthly/` - Giữ 12 tháng gần nhất

### 42.2 Cloud Backup (Google Drive)
File: `includes/GoogleDriveBackup.php`

**Features:**
- Upload file backup lên Google Drive
- Xem danh sách backup trên cloud
- Check dung lượng Drive còn trống
- Download/Delete backup từ cloud

**Setup:**
1. Tạo project trên Google Cloud Console
2. Enable Google Drive API
3. Tạo Service Account, download JSON key
4. Share folder Drive với email của Service Account
5. Lưu credentials vào `config/google-credentials.json`

### 42.3 Restore
```php
// Restore from backup file
function restoreDatabase(string $backupFile): bool {
    if (pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz') {
        $cmd = sprintf('gunzip -c %s | mysql -h %s -u %s -p%s %s',
            escapeshellarg($backupFile), DB_HOST, DB_USER, DB_PASS, DB_NAME);
    } else {
        $cmd = sprintf('mysql -h %s -u %s -p%s %s < %s',
            DB_HOST, DB_USER, DB_PASS, DB_NAME, escapeshellarg($backupFile));
    }
    exec($cmd, $output, $returnCode);
    return $returnCode === 0;
}
```

### 42.4 Admin UI
File: `admin/backups.php`
- Tạo backup thủ công (nút "Tạo backup ngay")
- Xem danh sách local + cloud backups
- Download, Delete, Restore từ bất kỳ backup nào
- Hiển thị kích thước file và ngày tạo

---

## 43. Voice Input (AI Transcription)

### 43.1 Tính năng
- Nhập liệu bằng giọng nói cho các form
- Hỗ trợ tiếng Việt
- Chỉ dành cho gói PRO

### 43.2 Providers
- **OpenAI Whisper**: Chất lượng cao, ~$0.006/phút
- **Groq**: Nhanh, miễn phí (giới hạn)
- **Gemini**: Alternative option

### 43.3 Database
Bảng `voice_input_usage` theo dõi sử dụng:
- `user_id`, `provider`
- `duration_seconds`, `file_size`
- `estimated_cost`, `transcription_status`
- `used_at`

### 43.4 Admin
File: `admin/voice-logs.php`
- Thống kê sử dụng theo user/provider
- Theo dõi chi phí ước tính
- Xem lỗi transcription

---

## 44. Security Checklist (Subscription)

- [ ] CSRF token cho tất cả form POST
- [ ] Password hash với bcrypt cost >= 12
- [ ] Rate limiting cho login/register
- [ ] Input sanitization: `htmlspecialchars()`
- [ ] Prepared statements cho SQL
- [ ] HTTPS cho production
- [ ] Encryption key 256-bit random
- [ ] Session regeneration sau login
- [ ] Webhook authentication

---

## 45. Subscription File Structure

```
project/
├── api/
│   ├── check-payment.php       # Polling status
│   ├── sepay-webhook.php       # Webhook listener
│   └── transcribe.php          # Voice input API
├── auth/
│   ├── login.php
│   ├── register.php
│   ├── verify.php              # Email verification
│   └── forgot-password.php
├── subscription/
│   ├── index.php               # Plan comparison
│   ├── checkout.php            # Select duration
│   └── pay.php                 # QR + payment
├── admin/
│   ├── index.php               # Dashboard
│   ├── site-settings.php       # Logo, Footer, Scripts, Sepay
│   ├── users.php               # User list
│   ├── edituser.php            # Edit user & subscription
│   ├── plans.php               # Manage subscription plans
│   ├── posts.php               # Content management
│   ├── post-editor.php         # WYSIWYG editor
│   ├── announcements.php       # System notifications
│   ├── maintenance.php         # Maintenance mode toggle
│   ├── backups.php             # Backup & Restore
│   └── voice-logs.php          # Voice usage stats
├── includes/
│   ├── functions.php
│   ├── auth.php
│   ├── encryption.php
│   ├── page-cache.php          # Output cache (see §46)
│   └── GoogleDriveBackup.php   # Cloud backup helper
├── page.php                    # Display posts by slug
├── maintenance.php             # Maintenance page
└── config/
    ├── app.php                 # ENCRYPTION_KEY
    └── maintenance.php         # Maintenance settings
```

---

# PHẦN 3: Performance Optimization

> Áp dụng cho bất kỳ project PHP/MySQL nào. Không cần cấu hình server đặc biệt.

---

## 46. Performance Optimization

### 46.1 PHP Output Cache (File-based)

Cache toàn bộ HTML output ra file. Request tiếp theo đọc file → skip PHP + DB hoàn toàn.

**Tạo `includes/page-cache.php`:**

```php
<?php
class PageCache
{
    private static string $cacheDir  = __DIR__ . '/../cache/pages/';
    private static int    $ttl       = 300; // 5 phút
    private static ?string $activeKey = null;

    /** Trả về true (và echo HTML) nếu có cache HIT */
    public static function get(string $key): bool
    {
        if (!self::isEnabled()) return false;
        if (!empty($_SESSION['user_id'])) return false; // Không cache khi admin login

        $file = self::path($key);
        if (!file_exists($file)) return false;
        if ((time() - filemtime($file)) > self::$ttl) {
            @unlink($file);
            return false;
        }

        $age = time() - filemtime($file);
        echo file_get_contents($file);
        echo "\n<!-- Page Cache: HIT (age: {$age}s / ttl: " . self::$ttl . "s) -->";
        return true;
    }

    /** Bắt đầu capture output (cache MISS) */
    public static function start(string $key): void
    {
        if (!self::isEnabled()) return;
        self::$activeKey = $key;
        ob_start();
    }

    /** Lưu output vào file cache rồi flush ra browser */
    public static function save(): void
    {
        if (!self::isEnabled() || self::$activeKey === null) {
            ob_end_flush();
            return;
        }

        $html = ob_get_clean();
        if ($html === false) return;

        self::ensureDir();
        file_put_contents(self::path(self::$activeKey), $html);

        echo $html;
        echo "\n<!-- Page Cache: MISS (generated: " . round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000) . "ms) -->";
        self::$activeKey = null;
    }

    /** Xóa toàn bộ file cache, trả về số file đã xóa */
    public static function flush(): int
    {
        $count = 0;
        foreach (glob(self::$cacheDir . '*.html') ?: [] as $f) {
            if (@unlink($f)) $count++;
        }
        return $count;
    }

    /** Thống kê cache hiện tại */
    public static function stats(): array
    {
        $files = glob(self::$cacheDir . '*.html') ?: [];
        $size  = array_sum(array_map('filesize', $files));
        $oldest = $files ? min(array_map('filemtime', $files)) : time();
        return [
            'count'      => count($files),
            'size_kb'    => round($size / 1024, 1),
            'oldest_age' => time() - $oldest,
        ];
    }

    private static function isEnabled(): bool
    {
        return function_exists('get_setting') && get_setting('page_cache_enabled', '0') === '1';
    }

    private static function path(string $key): string
    {
        return self::$cacheDir . preg_replace('/[^a-z0-9_-]/', '', $key) . '.html';
    }

    private static function ensureDir(): void
    {
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
            file_put_contents(self::$cacheDir . '.htaccess', "Deny from all\n");
        }
    }
}
```

**Tích hợp vào từng page:**

```php
<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/page-cache.php';

$_cache_key = 'homepage'; // thay đổi tùy trang
if (PageCache::get($_cache_key)) exit;  // HIT → serve + exit
PageCache::start($_cache_key);          // MISS → bắt đầu capture

// ... toàn bộ code PHP bình thường ...

// Cuối trang: footer TRƯỚC save
require_once 'includes/footer.php';  // ← phải nằm TRƯỚC
PageCache::save();                   // ← capture toàn bộ kể cả footer
```

**Cache key pattern:**

| Trang | Cache key |
|-------|-----------|
| Trang chủ | `'homepage'` |
| Dịch vụ | `'service_' . preg_replace('/[^a-z0-9-]/', '', strtolower($slug))` |
| Bài viết | `'post_' . preg_replace('/[^a-z0-9-]/', '', strtolower($slug))` |
| Danh mục | `'category_' . preg_replace('/[^a-z0-9-]/', '', strtolower($slug))` |

**Setting key trong DB:**

| Setting | Giá trị | Ý nghĩa |
|---------|---------|---------|
| `page_cache_enabled` | `'1'` | Bật cache |
| `page_cache_enabled` | `'0'` | Tắt cache |

**Kiểm tra qua View Source:**
```html
<!-- Page Cache: MISS (generated: 48ms) -->   ← lần đầu, PHP chạy + lưu cache
<!-- Page Cache: HIT (age: 23s / ttl: 300s) --> ← lần sau, serve từ file
```

**Xóa cache qua AJAX (admin):**
```php
// admin/ajax/clear-cache.php
require_once '../../includes/page-cache.php';
PageCache::flush(); // Xóa tất cả *.html trong cache/pages/
```

> **Không cần cấu hình server.** Hoạt động ngay trên mọi shared hosting có PHP 7.4+.

---

### 46.2 WebP Conversion & Image Compression

Tự động convert ảnh upload sang WebP để giảm 60–80% dung lượng.

```php
function compress_to_webp(string $source, int $max_width = 1920, int $quality = 82): bool
{
    if (!function_exists('imagecreatefromjpeg')) return false;

    $ext  = strtolower(pathinfo($source, PATHINFO_EXTENSION));
    $dest = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $source);

    $img = match ($ext) {
        'jpg', 'jpeg' => imagecreatefromjpeg($source),
        'png'         => imagecreatefrompng($source),
        'gif'         => imagecreatefromgif($source),
        default       => false,
    };
    if (!$img) return false;

    // Resize nếu quá rộng
    [$w, $h] = [imagesx($img), imagesy($img)];
    if ($w > $max_width) {
        $ratio   = $max_width / $w;
        $resized = imagescale($img, (int)($w * $ratio), (int)($h * $ratio));
        if ($resized) { imagedestroy($img); $img = $resized; }
    }

    $ok = imagewebp($img, $dest, $quality);
    imagedestroy($img);

    if ($ok && $dest !== $source) @unlink($source); // Xóa file gốc
    return $ok;
}
```

**Gọi trong upload handler:**
```php
if (move_uploaded_file($tmp, $dest_path)) {
    compress_to_webp($dest_path); // Tự động convert + xóa file gốc
}
```

> **Yêu cầu:** PHP GD Library. WebP chỉ áp dụng cho ảnh upload mới. Ảnh cũ cần re-upload hoặc chạy script batch conversion.

---

### 46.3 Lazy Loading Images

```php
<!-- Ảnh đầu tiên (LCP) - KHÔNG lazy -->
<img src="..." fetchpriority="high" alt="...">

<!-- Ảnh tiếp theo - lazy load -->
<img src="..." loading="lazy" decoding="async" alt="...">
```

**Gallery images trong Swiper slider:**
```php
<?php foreach ($gallery_images as $loop_index => $img): ?>
    <div class="swiper-slide">
        <img src="<?php echo BASE_URL . $img; ?>"
             alt="<?php echo e($service['name']); ?>"
             class="gallery-main-image"
             <?php echo $loop_index === 0
                 ? 'fetchpriority="high"'
                 : 'loading="lazy" decoding="async"'; ?>>
    </div>
<?php endforeach; ?>
```

> **Lưu ý:** Không dùng `loading="lazy"` cho ảnh đầu tiên trong Swiper — user thấy ngay, nếu lazy sẽ thấy khoảng trắng.

---

### 46.4 LCP Image — Preload Đúng Vị Trí

#### ❌ Bug: `<link rel="preload">` đặt trong body gây double-fetch

**Triệu chứng:** Banner ảnh load chậm hơn trước khi tối ưu, 1 ảnh xuất hiện 2 lần trong Network tab.

**Nguyên nhân:** `<link rel="preload">` chỉ có hiệu lực khi đặt trong `<head>`. Nếu đặt trong `<body>`:
1. Nhận hint preload quá muộn (vô tác dụng)
2. Vẫn fetch ảnh từ `<img>` tag
3. Một số browser còn tạo **double request** → double bandwidth → chậm hơn

```php
// ❌ SAI — trong hero.php (render trong body)
<link rel="preload" as="image" href="<?php echo $banner_url; ?>">
<img src="..." fetchpriority="high">
// → double request, chậm hơn!

// ✅ ĐÚNG — chỉ cần fetchpriority="high" trên img tag
<img src="<?php echo $banner_url; ?>"
     fetchpriority="high"
     alt="...">
// → browser tự ưu tiên, không double request
```

**Nếu thực sự muốn dùng preload** → phải truyền URL banner vào `header.php` để render trong `<head>`:

```php
// Trong index.php, TRƯỚC khi include header
$lcp_image_url = $banners[0]['image_path'] ?? '';

// Trong header.php, bên trong <head>
<?php if (!empty($lcp_image_url)): ?>
<link rel="preload" as="image" href="<?php echo BASE_URL . $lcp_image_url; ?>">
<?php endif; ?>
```

**Hero banner pattern:**
```php
<?php foreach ($banners as $index => $banner): ?>
    <img src="<?php echo BASE_URL . $banner['image_path']; ?>"
         alt="<?php echo e($banner['title']); ?>"
         class="img-fluid w-100"
         <?php echo $index === 0 ? 'fetchpriority="high"' : 'loading="lazy" decoding="async"'; ?>>
<?php endforeach; ?>
```

---

### 46.5 Browser Cache Headers (.htaccess)

```apache
# Browser Cache Headers
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/webp            "access plus 30 days"
    ExpiresByType image/jpeg            "access plus 30 days"
    ExpiresByType image/png             "access plus 30 days"
    ExpiresByType text/css              "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    ExpiresByType font/woff2            "access plus 1 year"
</IfModule>

<IfModule mod_headers.c>
    <FilesMatch "\.(webp|jpg|jpeg|png|gif)$">
        Header set Cache-Control "public, max-age=2592000"
    </FilesMatch>
    <FilesMatch "\.(css|js)$">
        Header set Cache-Control "public, max-age=604800"
    </FilesMatch>
    <FilesMatch "\.(woff|woff2|ttf|eot)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
```

---

### 46.6 Google Fonts Async Loading

Tránh render-blocking fonts với pattern `media="print"`:

```html
<!-- Preconnect (thêm vào <head>) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<!-- Load async – không block render -->
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;600;700&display=swap"
      media="print"
      onload="this.media='all'">
<noscript>
  <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;600;700&display=swap">
</noscript>
```

> **Lưu ý:** Giảm số font-weight xuống chỉ còn những weight thực sự dùng (400, 600, 700).

---

### 46.7 Critical CSS — Tắt Cache Busting

```php
// ❌ SAI — load lại mỗi request
<link href="<?php echo asset_url('assets/css/critical.css') . '&t=' . time(); ?>">

// ✅ ĐÚNG — browser cache được
<link href="<?php echo asset_url('assets/css/critical.css'); ?>">
```

---

### 46.8 Checklist Performance

| Kỹ thuật | Lợi ích | Yêu cầu |
|----------|---------|---------|
| PHP Output Cache | Giảm TTFB 90%+ | PHP 7.4+, quyền ghi file |
| WebP Conversion | Giảm 60–80% dung lượng ảnh | PHP GD Library |
| Lazy Loading | Giảm tải trang đầu | HTML5 |
| Browser Cache Headers | Trang 2+ tải ngay từ local | mod_expires / mod_headers |
| Fonts Async | Không block render | JS |
| LCP Preload | Cải thiện Core Web Vitals | HTML5 |

---

# PHẦN 4: Technical Notes & Bugs Thường Gặp

> Ghi chú kỹ thuật thực tế, bugs thường gặp, và lessons learned khi build website với PHP/MySQL.

---

## 47. Technical Notes — Lỗi Thường Gặp

### 47.1 PHP Output Cache — footer.php không được lưu vào cache

**Triệu chứng:** Cache HIT nhưng Swiper slider không hiện đúng, lỗi `Swiper is not defined` trong Console.

**Nguyên nhân:** `PageCache::save()` gọi `ob_get_clean()` để lấy buffered output. Nếu `footer.php` (chứa Swiper JS, Bootstrap JS) được `require` SAU `save()`, footer không nằm trong buffer → không được lưu vào file cache → HIT cache thiếu JS.

```php
// ❌ SAI — footer bị exclude khỏi cache
PageCache::save();
require_once 'includes/footer.php';

// ✅ ĐÚNG — footer nằm trong ob_start() buffer, được cache cùng
require_once 'includes/footer.php';
PageCache::save();  // ob_get_clean() bắt được cả footer
```

**Áp dụng cho:** `index.php`, `service.php`, `post.php`, `category.php` — bất kỳ file nào dùng PageCache.

---

### 47.2 Admin bypass cache khi test

PageCache tự động **bỏ qua cache** khi admin đang đăng nhập (`$_SESSION['user_id']` có giá trị). Điều này đúng về mặt nghiệp vụ (admin thấy real-time), nhưng khi test performance:

```
❌ Test sai: Mở trang khi đang đăng nhập admin → không có cache → PHP + DB mỗi request → chậm bình thường
✅ Test đúng: Mở tab ẩn danh (Incognito) → không đăng nhập → cache hoạt động → thấy đúng tốc độ thật
```

### 47.3 Đọc Network tab đúng

Trong Chrome DevTools → Network, cột **Time** là thời điểm request **hoàn thành** tính từ lúc navigation bắt đầu (không phải download duration). Để xem thời gian download thực tế: hover vào request → xem phần **Content Download** trong tooltip.

---

## 48. Best Practices

### Code Organization
- Tách logic thành functions tái sử dụng
- Validation ở cả client (JS) và server (PHP)
- Error handling với try-catch
- Log mọi hành động quan trọng

### UI/UX
- Loading states cho async operations
- Clear error messages
- Confirmation dialogs cho destructive actions
- Responsive design (mobile-friendly)

### Performance
- Index database columns thường query
- Cache subscription plans in memory
- Optimize QR code generation
- Lazy load images

---

## PHẦN 5: Templates & Triển Khai

Để giúp việc triển khai hệ thống FPTSTORE nhanh chóng và đồng bộ, các file mẫu (templates) đã được chuẩn bị sẵn:

### §49. Database Schema (SQL)
Toàn bộ cấu trúc bảng và dữ liệu mẫu cần thiết để khởi tạo hệ thống.
- **File mẫu:** `templates/schema.sql`
- **Cách sử dụng:** Import nội dung file này vào database (MySQL/MariaDB) của bạn.

### §50. Cấu hình hệ thống (PHP)
File mẫu chứa các hằng số hệ thống, thông tin database và các key bảo mật.
- **File mẫu:** `templates/config.example.php`
- **Cách sử dụng:** Đổi tên thành `config.php`, đặt vào thư mục `config/` và điền các thông tin thực tế.

---

## Tài liệu tham khảo

- [Sepay Documentation](https://my.sepay.vn)
- [PHP Password Hashing](https://www.php.net/manual/en/function.password-hash.php)
- [OpenSSL Encryption](https://www.php.net/manual/en/function.openssl-encrypt.php)

---

_Cập nhật lần cuối: 2026-03-04_
