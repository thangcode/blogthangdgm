<?php
/**
 * URL Helper Functions
 * Generates URLs dynamically based on settings
 */

// Cache for URL prefixes to avoid repeated DB queries
$_url_prefixes_cache = null;

/**
 * Get all URL prefixes from settings (cached)
 */
function getUrlPrefixes()
{
    global $pdo, $_url_prefixes_cache;

    if ($_url_prefixes_cache !== null) {
        return $_url_prefixes_cache;
    }

    // Default values
    $_url_prefixes_cache = [
        'product' => 'san-pham',
        'category' => 'danh-muc',
        'post' => 'tin-tuc'
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'url'");
        while ($row = $stmt->fetch()) {
            switch ($row['setting_key']) {
                case 'url_product_prefix':
                case 'url_service_prefix':
                    $_url_prefixes_cache['product'] = $row['setting_value'];
                    break;
                case 'url_category_prefix':
                    $_url_prefixes_cache['category'] = $row['setting_value'];
                    break;
                case 'url_post_prefix':
                    $_url_prefixes_cache['post'] = $row['setting_value'];
                    break;
            }
        }
    } catch (Exception $e) {
        // Use defaults if query fails
    }

    return $_url_prefixes_cache;
}

/**
 * Get single URL prefix
 */
function getUrlPrefix($type)
{
    $prefixes = getUrlPrefixes();
    return $prefixes[$type] ?? $type;
}

/**
 * Generate product URL: /{category-slug}/{product-slug}
 */
function productUrl($slug, $category_slug = '', $absolute = false)
{
    if ($category_slug) {
        $url = '/' . $category_slug . '/' . $slug;
    } else {
        // Fallback: just slug
        $url = '/' . $slug;
    }
    return $absolute ? BASE_URL . ltrim($url, '/') : $url;
}

/**
 * Alias for backward compatibility
 */
function serviceUrl($slug, $category_slug = '', $absolute = false)
{
    return productUrl($slug, $category_slug, $absolute);
}

/**
 * Generate category URL
 */
function categoryUrl($slug, $absolute = false)
{
    $prefix = getUrlPrefix('category');
    $url = '/' . $prefix . '/' . $slug;
    return $absolute ? BASE_URL . ltrim($url, '/') : $url;
}

/**
 * Generate post URL — blog đặt bài ở ROOT /{slug}/ (khớp WordPress).
 */
function postUrl($slug, $absolute = false)
{
    $url = '/' . $slug . '/';
    return $absolute ? BASE_URL . ltrim($url, '/') : $url;
}

/**
 * Generate tag URL: /tag/{slug}/
 */
function tagUrl($slug, $absolute = false)
{
    $url = '/tag/' . $slug . '/';
    return $absolute ? BASE_URL . ltrim($url, '/') : $url;
}

/**
 * URL phân trang chuyên mục: /danh-muc/{slug}/page/{n}/ (giống WordPress).
 * Trang 1 trả về URL gốc (không gắn /page/).
 */
function categoryUrlPaged($slug, $page = 1, $absolute = false)
{
    $prefix = getUrlPrefix('category');
    $page = max(1, (int) $page);
    $url = '/' . $prefix . '/' . $slug . '/';
    if ($page > 1) {
        $url .= 'page/' . $page . '/';
    }
    return $absolute ? BASE_URL . ltrim($url, '/') : $url;
}

/**
 * URL phân trang tag: /tag/{slug}/page/{n}/ (giống WordPress).
 */
function tagUrlPaged($slug, $page = 1, $absolute = false)
{
    $page = max(1, (int) $page);
    $url = '/tag/' . $slug . '/';
    if ($page > 1) {
        $url .= 'page/' . $page . '/';
    }
    return $absolute ? BASE_URL . ltrim($url, '/') : $url;
}

/**
 * Replace a marker-delimited section in .htaccess (WordPress-style).
 * Only the content between # BEGIN {marker} and # END {marker} is replaced.
 * If the marker block doesn't exist, it is appended before ErrorDocument.
 * If $content is empty, the marker block is removed.
 */
function replaceHtaccessSection($marker, $content)
{
    $path = dirname(__DIR__) . '/.htaccess';

    if (!file_exists($path)) {
        return false;
    }

    $htaccess = file_get_contents($path);
    if ($htaccess === false) {
        return false;
    }

    $beginMarker = "# BEGIN {$marker}";
    $endMarker   = "# END {$marker}";

    // Build the new block (empty content = remove block entirely)
    $newBlock = '';
    if (trim($content) !== '') {
        $newBlock = $beginMarker . "\n" . trim($content) . "\n" . $endMarker;
    }

    // Check if marker section already exists
    $pattern = '/'.preg_quote($beginMarker, '/').'.*?'.preg_quote($endMarker, '/').'/s';

    if (preg_match($pattern, $htaccess)) {
        // Replace existing block.
        // Dùng callback để chuỗi thay thế được giữ NGUYÊN VĂN: tránh preg_replace hiểu
        // nhầm $1, $2 (backreference của RewriteRule) thành backreference của pattern.
        $htaccess = preg_replace_callback($pattern, static function () use ($newBlock) {
            return $newBlock;
        }, $htaccess);
    } elseif ($newBlock !== '') {
        // Append before ErrorDocument line, or at end of file
        $errorDocPos = strpos($htaccess, '# Handle 404');
        if ($errorDocPos !== false) {
            $htaccess = substr($htaccess, 0, $errorDocPos)
                      . $newBlock . "\n\n"
                      . substr($htaccess, $errorDocPos);
        } else {
            $htaccess = rtrim($htaccess) . "\n\n" . $newBlock . "\n";
        }
    }

    // Clean up multiple blank lines
    $htaccess = preg_replace('/\n{3,}/', "\n\n", $htaccess);

    return file_put_contents($path, $htaccess) !== false;
}

/**
 * Generate URL rewrite rules content (for the Rewrite marker block)
 */
function generateRewriteRules()
{
    $prefixes = getUrlPrefixes();
    $category = $prefixes['category'];

    // Domain chính (cấu hình được). Khóa rewrite của blog chỉ chạy cho domain chính + môi trường dev,
    // tránh ảnh hưởng các addon domain dùng chung thư mục public_html.
    $primaryDomain = function_exists('get_setting') ? trim((string) get_setting('site_primary_domain', 'thang-dgm.com')) : 'thang-dgm.com';
    if ($primaryDomain === '') {
        $primaryDomain = 'thang-dgm.com';
    }
    $primaryDomain = preg_replace('/[^a-z0-9.\-]/i', '', $primaryDomain); // chỉ giữ ký tự hợp lệ của domain
    $domainEsc = str_replace('.', '\.', $primaryDomain);                  // escape dấu chấm cho regex Apache

    return <<<RULES
# Khóa host: chỉ áp dụng rewrite của blog cho domain chính + dev (bỏ qua addon domain cùng public_html)
RewriteCond %{HTTP_HOST} !^(www\.)?{$domainEsc}$ [NC]
RewriteCond %{HTTP_HOST} !^(localhost|127\.0\.0\.1)(:\d+)?$ [NC]
RewriteCond %{HTTP_HOST} !\.(test|local)$ [NC]
RewriteRule ^ - [L]

# Friendly URLs for Categories
RewriteCond %{THE_REQUEST} \s/category\.php\?slug=([^\s&]+) [NC]
RewriteRule ^ {$category}/%1/? [R=301,L]
RewriteRule ^{$category}/([^/]+)/page/([0-9]+)/?$ category.php?slug=\$1&page=\$2 [L,QSA]
RewriteRule ^{$category}/([^/]+)/?$ category.php?slug=\$1 [L,QSA]

# Friendly URLs for Tags
RewriteCond %{THE_REQUEST} \s/tag\.php\?slug=([^\s&]+) [NC]
RewriteRule ^ tag/%1/? [R=301,L]
RewriteRule ^tag/([^/]+)/page/([0-9]+)/?$ tag.php?slug=\$1&page=\$2 [L,QSA]
RewriteRule ^tag/([^/]+)/?$ tag.php?slug=\$1 [L,QSA]

# Friendly URLs for Static Pages
RewriteCond %{THE_REQUEST} \s/contact\.php [NC]
RewriteRule ^ lien-he [R=301,L]
RewriteRule ^lien-he/?$ contact.php [L,QSA]
RewriteRule ^gioi-thieu/?$ about.php [L,QSA]

# llms.txt cho công cụ AI (GEO)
RewriteRule ^llms\.txt$ llms.php [L]
# sitemap.xml động
RewriteRule ^sitemap\.xml$ sitemap.php [L]
# RSS feed
RewriteRule ^feed(\.xml)?/?$ feed.php [L]

# Catch-all 1 đoạn (bài viết / short link) — đặt CUỐI cùng
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^/]+)/?$ router.php?path=\$1 [L,QSA]
RULES;
}

/**
 * Generate performance rules (GZIP + Cache) based on settings
 */
function generatePerformanceRules($gzip_enabled, $cache_enabled)
{
    $rules = '';

    if ($gzip_enabled) {
        $rules .= <<<'GZIP'
# GZIP Compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css
    AddOutputFilterByType DEFLATE text/javascript application/javascript application/json
    AddOutputFilterByType DEFLATE application/xml application/xhtml+xml
    AddOutputFilterByType DEFLATE image/svg+xml font/ttf font/otf
    AddOutputFilterByType DEFLATE application/vnd.ms-fontobject application/x-font-ttf
</IfModule>
GZIP;
    }

    if ($gzip_enabled && $cache_enabled) {
        $rules .= "\n\n";
    }

    if ($cache_enabled) {
        $rules .= <<<'CACHE'
# Browser Cache Headers
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresDefault "access plus 1 month"
    ExpiresByType text/html "access plus 1 hour"
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType text/javascript "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
    ExpiresByType image/svg+xml "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/x-icon "access plus 1 year"
    ExpiresByType font/ttf "access plus 1 year"
    ExpiresByType font/otf "access plus 1 year"
    ExpiresByType font/woff "access plus 1 year"
    ExpiresByType font/woff2 "access plus 1 year"
</IfModule>
CACHE;

        $rules .= "\n\n";
        $rules .= <<<'WEBP'
# Serve WebP when browser supports it and a .webp twin exists
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{DOCUMENT_ROOT}/$1.webp -f
    RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1,L]
</IfModule>
<IfModule mod_headers.c>
    Header append Vary Accept env=REDIRECT_accept
</IfModule>
WEBP;
    }

    return $rules;
}

/**
 * Save URL rewrite rules to .htaccess (marker-based, safe)
 */
function saveHtaccess()
{
    $rules = generateRewriteRules();
    return replaceHtaccessSection('FPTStore Rewrite', $rules);
}

/**
 * Save performance rules to .htaccess (marker-based, safe)
 */
function savePerformanceHtaccess($gzip_enabled, $cache_enabled)
{
    $rules = generatePerformanceRules($gzip_enabled, $cache_enabled);
    return replaceHtaccessSection('FPTStore Performance', $rules);
}

/**
 * Clear URL prefix cache (call after updating settings)
 */
function clearUrlPrefixCache()
{
    global $_url_prefixes_cache;
    $_url_prefixes_cache = null;
}
