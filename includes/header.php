<?php
// includes/header.php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/seo.php';
require_once 'includes/url-helper.php';

// Get Settings
$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$site_logo = get_setting('site_logo', 'assets/images/logo.png');
$site_favicon = get_setting('site_favicon', 'assets/images/favicon.ico');
$preloader_enabled = in_array(strtolower(trim((string) get_setting('preloader_enabled', '1'))), ['1', 'true', 'yes', 'on'], true);
$preloader_logo = trim((string) get_setting('preloader_logo', ''));
$contact_email = get_setting('contact_email');
$contact_phone = get_setting('contact_phone', '');
$contact_address = get_setting('contact_address', '');
$contact_zalo = get_setting('contact_zalo', '');
$contact_messenger = get_setting('contact_messenger', '');
$custom_script_header = filter_public_custom_script_markup(get_setting('custom_script_header', ''));
$custom_script_body = filter_public_custom_script_markup(get_setting('custom_script_body', ''));

if (function_exists('enforce_traffic_ip_block')) {
    enforce_traffic_ip_block($pdo, [
        'skip_admin' => true,
    ]);
}

if (function_exists('security_fw_guard')) {
    security_fw_guard($pdo, [
        'skip_admin' => true,
    ]);
}

if (function_exists('track_frontend_traffic')) {
    track_frontend_traffic($pdo, [
        'page_title' => $page_title ?? $site_name,
    ]);
}

// Get Menus
try {
    $menu_stmt = $pdo->query("SELECT * FROM menus WHERE status = 1 AND position = 'header' ORDER BY parent_id ASC, sort_order ASC, id ASC");
    $menus = $menu_stmt->fetchAll();
} catch (PDOException $e) {
    $menus = [];
}

// Ẩn mục menu trỏ tới danh mục đang TẮT hoặc đã xóa (dù menu item vẫn còn).
try {
    $delFilter = (function_exists('has_table_column') && has_table_column($pdo, 'categories', 'deleted_at')) ? ' AND deleted_at IS NULL' : '';
    $activeCatSlugs = $pdo->query("SELECT slug FROM categories WHERE status = 1{$delFilter}")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $activeCatSlugs = null;
}
if (is_array($activeCatSlugs) && !empty($menus)) {
    $activeCatSet = array_flip($activeCatSlugs);
    $catPrefix = function_exists('getUrlPrefix') ? getUrlPrefix('category') : 'danh-muc';
    // 1) Bỏ các mục trỏ tới danh mục không còn hoạt động
    $removedMenuIds = [];
    $menus = array_values(array_filter($menus, function ($m) use ($activeCatSet, $catPrefix, &$removedMenuIds) {
        $u = trim((string) ($m['url'] ?? ''), '/');
        if (preg_match('~^' . preg_quote($catPrefix, '~') . '/([^/?#]+)~u', $u, $mm)) {
            if (!isset($activeCatSet[$mm[1]])) { $removedMenuIds[(int) $m['id']] = true; return false; }
        }
        return true;
    }));
    // 2) Bỏ luôn các mục con mồ côi (cha đã bị ẩn) để không nhảy lên cấp gốc
    if (!empty($removedMenuIds)) {
        do {
            $changed = false;
            $menus = array_values(array_filter($menus, function ($m) use (&$removedMenuIds, &$changed) {
                $pid = (int) ($m['parent_id'] ?? 0);
                if ($pid > 0 && isset($removedMenuIds[$pid])) {
                    $removedMenuIds[(int) $m['id']] = true; $changed = true; return false;
                }
                return true;
            }));
        } while ($changed);
    }
}

if (!function_exists('normalize_menu_url')) {
    function normalize_menu_url($url)
    {
        $url = trim((string) $url);

        if ($url === '' || $url === '#') {
            return '#';
        }

        if (preg_match('#^(https?:)?//#i', $url) || preg_match('#^(mailto:|tel:)#i', $url)) {
            return $url;
        }

        if (preg_match('#^(javascript:|data:|vbscript:)#i', $url)) {
            return '#';
        }

        return BASE_URL . ltrim($url, '/');
    }
}

if (!function_exists('build_menu_tree')) {
    function build_menu_tree(array $items)
    {
        $map = [];
        foreach ($items as $item) {
            $item['id'] = (int) $item['id'];
            $item['parent_id'] = (int) ($item['parent_id'] ?? 0);
            $item['children'] = [];
            $map[$item['id']] = $item;
        }

        $tree = [];
        foreach ($map as $id => &$item) {
            $parentId = (int) $item['parent_id'];
            if ($parentId > 0 && isset($map[$parentId])) {
                $map[$parentId]['children'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        return $tree;
    }
}

if (!function_exists('render_header_menu')) {
    function render_header_menu(array $items, $level = 0)
    {
        foreach ($items as $item) {
            $hasChildren = !empty($item['children']);
            $rawUrl = trim((string) ($item['url'] ?? ''));
            $url = normalize_menu_url($rawUrl);
            $name = e($item['name'] ?? 'Menu');
            $isActive = !empty($item['is_active']) || !empty($item['has_active_child']);
            $activeClass = $isActive ? ' active' : '';
            $ariaCurrent = $isActive ? ' aria-current="page"' : '';
            $linkTargetAttrs = !empty($item['target_blank']) ? ' target="_blank" rel="noopener noreferrer"' : '';
            // Trang chủ -> hiển thị icon home (giữ chữ cho mobile + screen reader).
            // So theo path (bỏ qua domain) để đúng trên mọi môi trường (local/prod/đổi domain).
            $home_mpath = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $home_basepath = trim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
            $home_basehost = strtolower((string) parse_url(BASE_URL, PHP_URL_HOST));
            $home_mhost = strtolower((string) parse_url($url, PHP_URL_HOST));
            $isExternalMenu = ($home_mhost !== '' && $home_basehost !== '' && $home_mhost !== $home_basehost);
            if ($home_basepath !== '' && $home_mpath === $home_basepath) {
                $home_mpath = '';
            }
            $isHome = (!$isExternalMenu && $rawUrl !== '' && $rawUrl !== '#' && ($url === '/' || $home_mpath === '' || $home_mpath === 'index.php'));

            if ($hasChildren) {
                if ($level === 0) {
                    echo '<li class="nav-item dropdown">';
                    echo '<a class="nav-link dropdown-toggle' . $activeClass . '" href="' . e($url) . '" role="button" data-bs-toggle="dropdown" aria-expanded="false"' . $ariaCurrent . $linkTargetAttrs . '>' . $name . '</a>';
                    echo '<ul class="dropdown-menu">';
                    render_header_menu($item['children'], $level + 1);
                    echo '</ul>';
                    echo '</li>';
                } else {
                    echo '<li class="dropdown-submenu">';
                    echo '<a class="dropdown-item dropdown-toggle' . $activeClass . '" href="' . e($url) . '"' . $ariaCurrent . $linkTargetAttrs . '>' . $name . '</a>';
                    echo '<ul class="dropdown-menu">';
                    render_header_menu($item['children'], $level + 1);
                    echo '</ul>';
                    echo '</li>';
                }
            } else {
                if ($level === 0) {
                    echo '<li class="nav-item">';
                    if ($isHome) {
                        echo '<a class="nav-link nav-home-link' . $activeClass . '" href="' . e($url) . '"' . $ariaCurrent . $linkTargetAttrs . ' aria-label="' . $name . '" title="' . $name . '"><i class="bi bi-house-door-fill"></i><span class="nav-home-text d-lg-none ms-2">' . $name . '</span></a>';
                    } else {
                        echo '<a class="nav-link' . $activeClass . '" href="' . e($url) . '"' . $ariaCurrent . $linkTargetAttrs . '>' . $name . '</a>';
                    }
                    echo '</li>';
                } else {
                    echo '<li><a class="dropdown-item' . $activeClass . '" href="' . e($url) . '"' . $ariaCurrent . $linkTargetAttrs . '>' . $name . '</a></li>';
                }
            }
        }
    }
}

if (!function_exists('canonicalize_internal_path')) {
    function canonicalize_internal_path($path, array $query = [])
    {
        $normalizedPath = trim(rawurldecode((string) $path), '/');
        $normalizedPath = strtolower($normalizedPath);

        if ($normalizedPath === '' || $normalizedPath === 'index.php') {
            return '';
        }

        $fileName = basename($normalizedPath);

        if ($fileName === 'category.php' && !empty($query['slug'])) {
            return strtolower(trim(getUrlPrefix('category') . '/' . trim((string) $query['slug'], '/'), '/'));
        }

        if ($fileName === 'post.php' && !empty($query['slug'])) {
            return strtolower(trim(getUrlPrefix('post') . '/' . trim((string) $query['slug'], '/'), '/'));
        }

        if ($fileName === 'product.php') {
            $slug = trim((string) ($query['slug'] ?? ''), '/');
            $categorySlug = trim((string) ($query['category_slug'] ?? ''), '/');

            if ($categorySlug !== '' && $slug !== '') {
                return strtolower($categorySlug . '/' . $slug);
            }

            if ($slug !== '') {
                return strtolower($slug);
            }
        }

        return $normalizedPath;
    }
}

if (!function_exists('get_current_menu_path')) {
    function get_current_menu_path()
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $requestPath = trim((string) parse_url($requestUri, PHP_URL_PATH), '/');
        $requestQuery = (string) parse_url($requestUri, PHP_URL_QUERY);
        $basePath = trim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');

        if ($basePath !== '' && $requestPath !== '' && strpos($requestPath, $basePath) === 0) {
            $requestPath = trim(substr($requestPath, strlen($basePath)), '/');
        }

        $queryParams = [];
        if ($requestQuery !== '') {
            parse_str($requestQuery, $queryParams);
        } elseif (!empty($_GET)) {
            $queryParams = $_GET;
        }

        return canonicalize_internal_path($requestPath, $queryParams);
    }
}

if (!function_exists('get_menu_relative_path')) {
    function get_menu_relative_path($url)
    {
        $url = trim((string) $url);

        if ($url === '' || $url === '#' || preg_match('#^(mailto:|tel:|javascript:|data:|vbscript:)#i', $url)) {
            return null;
        }

        $baseHost = strtolower((string) parse_url(BASE_URL, PHP_URL_HOST));
        $menuHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($menuHost !== '' && $baseHost !== '' && $menuHost !== $baseHost) {
            return null;
        }

        $menuPath = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $menuQuery = (string) parse_url($url, PHP_URL_QUERY);
        $basePath = trim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');

        if ($basePath !== '' && $menuPath !== '' && strpos($menuPath, $basePath) === 0) {
            $menuPath = trim(substr($menuPath, strlen($basePath)), '/');
        }

        $queryParams = [];
        if ($menuQuery !== '') {
            parse_str($menuQuery, $queryParams);
        }

        return canonicalize_internal_path($menuPath, $queryParams);
    }
}

if (!function_exists('mark_active_menu_items')) {
    function is_menu_path_active($menuPath, $currentPath)
    {
        $menuPath = strtolower(trim((string) $menuPath, '/'));
        $currentPath = strtolower(trim((string) $currentPath, '/'));

        if ($menuPath === '') {
            return $currentPath === '';
        }

        if ($currentPath === $menuPath || strpos($currentPath, $menuPath . '/') === 0) {
            return true;
        }

        // Fallback for admin-entered short slugs (e.g. "camera-fpt")
        // while current URL is prefixed (e.g. "danh-muc/camera-fpt").
        if (strpos($menuPath, '/') === false && strpos($currentPath, '/') !== false) {
            $segments = explode('/', $currentPath);
            $lastSegment = end($segments);
            if ($lastSegment === $menuPath) {
                return true;
            }
        }

        // When menu URL is category page (e.g. "danh-muc/truyen-hinh-fpt")
        // and current URL is service detail (e.g. "truyen-hinh-fpt/fpt-play-vvip-1"),
        // keep category menu item active.
        $categoryPrefix = strtolower(trim((string) getUrlPrefix('category'), '/'));
        if ($categoryPrefix !== '' && strpos($menuPath, $categoryPrefix . '/') === 0 && strpos($currentPath, '/') !== false) {
            $menuSegments = explode('/', $menuPath);
            $currentSegments = explode('/', $currentPath);
            $menuCategorySlug = end($menuSegments);
            $currentCategorySlug = $currentSegments[0] ?? '';

            if ($menuCategorySlug !== '' && $menuCategorySlug === $currentCategorySlug) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('mark_active_menu_items')) {
    function mark_active_menu_items(array &$items, $currentPath)
    {
        foreach ($items as &$item) {
            $item['is_active'] = false;
            $item['has_active_child'] = false;

            $menuPath = get_menu_relative_path($item['url'] ?? '');
            if ($menuPath !== null) {
                $item['is_active'] = is_menu_path_active($menuPath, $currentPath);
            }

            if (!empty($item['children'])) {
                mark_active_menu_items($item['children'], $currentPath);
                foreach ($item['children'] as $child) {
                    if (!empty($child['is_active']) || !empty($child['has_active_child'])) {
                        $item['has_active_child'] = true;
                        break;
                    }
                }
            }
        }
        unset($item);
    }
}

$menu_tree = build_menu_tree($menus);
$current_menu_path = get_current_menu_path();
mark_active_menu_items($menu_tree, $current_menu_path);

// Initialize SEO (can be overridden by individual pages)
if (!isset($seo)) {
    $seo = new SEO($site_name, BASE_URL);
    $seo->setTitle($page_title ?? '');

    // Set default SEO from page SEO settings if available
    $page_seo = null;
    $page_has_meta_title = false;
    $page_has_meta_description = false;
    $page_has_meta_keywords = false;
    $page_has_og_image = false;
    if (isset($page_key)) {
        $page_seo = get_page_seo($page_key, $pdo);
        if ($page_seo) {
            if (!empty($page_seo['meta_title'])) {
                $page_has_meta_title = true;
                $seo->setTitle($page_seo['meta_title']);
            }
            if (!empty($page_seo['meta_description'])) {
                $page_has_meta_description = true;
                $seo->setDescription($page_seo['meta_description']);
            }
            if (!empty($page_seo['meta_keywords'])) {
                $page_has_meta_keywords = true;
                $seo->setKeywords($page_seo['meta_keywords']);
            }
            if (!empty($page_seo['og_image'])) {
                $page_has_og_image = true;
                $seo->setOgImage($page_seo['og_image']);
            }
            if (array_key_exists('robots_index', $page_seo) && $page_seo['robots_index'] !== null && $page_seo['robots_index'] !== '') {
                $seo->setRobots((int) $page_seo['robots_index']);
            }
        }
    }

    if (function_exists('get_setting') && (int) get_setting('seo_global_robots_index', 1) === 0) {
        $seo->setRobots(false);
    }

    // Use page-specific SEO data if set
    if (isset($seo_data)) {
        if (!$page_has_meta_title && !empty($seo_data['title'])) {
            $seo->setTitle($seo_data['title']);
        }
        if (!$page_has_meta_description && !empty($seo_data['description'])) {
            $seo->setDescription($seo_data['description']);
        }
        if (!$page_has_meta_keywords && !empty($seo_data['keywords'])) {
            $seo->setKeywords($seo_data['keywords']);
        }
        if (!$page_has_og_image && !empty($seo_data['image'])) {
            $seo->setOgImage($seo_data['image']);
        }
        if (!empty($seo_data['canonical'])) {
            $seo->setCanonical($seo_data['canonical']);
        }
    }

    // Schema trang chủ: Organization + WebSite (chuẩn SEO/GEO)
    if (($page_key ?? '') === 'home') {
        $home_logo = $site_logo ?? get_setting('site_logo', '');
        $home_logo_abs = ($home_logo === '') ? '' : ((strpos($home_logo, 'http') === 0 || strpos($home_logo, '//') === 0) ? $home_logo : BASE_URL . $home_logo);
        $seo->setOrganizationData([
            'name' => $site_name,
            'url' => BASE_URL,
            'logo' => $home_logo_abs,
            'phone' => $contact_phone ?? get_setting('contact_phone', ''),
        ]);
        if (method_exists($seo, 'setWebsiteData')) {
            $seo->setWebsiteData();
        }
        $seo->addBreadcrumb('Trang chủ', BASE_URL);
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    // Khi Page Cache bật, KHÔNG nhúng CSRF token vào HTML (bản cache dùng chung sẽ làm lộ/
    // sai token giữa các phiên). Client tự nạp token đúng phiên qua api/csrf-token.php
    // (xem assets/js/app.js). Khi cache tắt: render token như cũ (không đổi hành vi).
    $csrf_cache_on = class_exists('PageCache') && PageCache::isEnabled();
    ?>
    <meta name="csrf-token" content="<?php echo $csrf_cache_on ? '' : e(generate_csrf_token()); ?>"<?php echo $csrf_cache_on ? ' data-csrf-hydrate="1"' : ''; ?>>

    <?php
    // Favicon — cache version bằng cache_version setting (tránh filemtime() filesystem IO mỗi request)
    $favicon_path = $site_favicon;
    $favicon_ver  = get_setting('cache_version', '1');
    $is_fav_abs   = (strpos($favicon_path, 'http') === 0 || strpos($favicon_path, '//') === 0);
    $favicon_url  = ($is_fav_abs ? $favicon_path : BASE_URL . $favicon_path) . '?v=' . $favicon_ver;
    $favicon_ext  = strtolower(pathinfo($favicon_path, PATHINFO_EXTENSION));
    $favicon_types = ['ico' => 'image/x-icon', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'];
    $favicon_type = $favicon_types[$favicon_ext] ?? 'image/x-icon';
    ?>
    <link rel="icon" href="<?php echo $favicon_url; ?>" type="<?php echo $favicon_type; ?>">
    <link rel="apple-touch-icon" href="<?php echo $favicon_url; ?>">

    <!-- SEO Meta Tags -->
    <?php echo $seo->render(); ?>

    <?php
    // Preload LCP image: first active banner for faster mobile rendering
    // Chỉ preload nếu đang ở trang chủ và block slider (hero) đang được bật
    $is_homepage = isset($page_key) && $page_key === 'home';
    $is_hero_active = false;
    
    if ($is_homepage) {
        try {
            $hero_stmt = $pdo->query("SELECT 1 FROM homepage_blocks WHERE block_key = 'hero' AND is_visible = 1 LIMIT 1");
            if ($hero_stmt && $hero_stmt->fetchColumn()) {
                $is_hero_active = true;
            }
        } catch (Exception $e) {
            // Fallback trong trường hợp bảng chưa tồn tại hoặc lỗi
            $is_hero_active = true; 
        }
    }

    if ($is_hero_active):
        try {
            $lcp_stmt = $pdo->query("SELECT image_path, mobile_image_path FROM banners WHERE status = 1 ORDER BY sort_order ASC, id DESC LIMIT 1");
            $lcp_banner = $lcp_stmt ? $lcp_stmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Exception $e) {
            $lcp_banner = null;
        }
        if ($lcp_banner && !empty($lcp_banner['image_path'])):
            $lcp_desktop = get_image_url($lcp_banner['image_path'], 'banner');
            $lcp_mobile = !empty($lcp_banner['mobile_image_path']) ? get_image_url($lcp_banner['mobile_image_path'], 'banner') : null;
    ?>
    <?php if ($lcp_mobile): ?>
    <link rel="preload" as="image" href="<?php echo $lcp_mobile; ?>" media="(max-width: 768px)">
    <link rel="preload" as="image" href="<?php echo $lcp_desktop; ?>" media="(min-width: 769px)">
    <?php else: ?>
    <link rel="preload" as="image" href="<?php echo $lcp_desktop; ?>">
    <?php endif; ?>
    <?php 
        endif;
    endif; 
    ?>

    <!-- CDN preconnect (giảm latency DNS + TLS cho Bootstrap) -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <!-- Bootstrap CSS (render-blocking — cần cho grid, form, layout) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons — async (không block render) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"
          media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"></noscript>
    <?php
    $script_name = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $needs_swiper_assets = in_array($script_name, ['index.php', 'product.php'], true);
    ?>
    <?php if ($needs_swiper_assets): ?>
    <!-- Swiper CSS — async (không block render) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css"
          media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css"></noscript>
    <?php endif; ?>
    <!-- Montserrat Font (self-hosted) - Async -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/fonts.css'); ?>" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="<?php echo asset_url('assets/css/fonts.css'); ?>"></noscript>

    <?php $critical_css_mtime = @filemtime(__DIR__ . '/../assets/css/critical.css') ?: time(); ?>
    <!-- Critical CSS (render-blocking — above the fold) -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/critical.css'); ?>&m=<?php echo $critical_css_mtime; ?>">
    <!-- Component CSS (non-blocking — loaded async) -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/components.css'); ?>" media="print"
        onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="<?php echo asset_url('assets/css/components.css'); ?>">
    </noscript>
    <!-- Blog CSS (Async) -->
    <?php $blog_css_mtime = @filemtime(__DIR__ . '/../assets/css/blog.css') ?: time(); ?>
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/blog.css'); ?>&m=<?php echo $blog_css_mtime; ?>" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="<?php echo asset_url('assets/css/blog.css'); ?>&m=<?php echo $blog_css_mtime; ?>"></noscript>
    <style id="site-theme-vars">
        :root { <?php echo site_theme_css_vars(); ?> }
    </style>
    <script>window.BASE_URL = '<?php echo BASE_URL; ?>'; const BASE_URL = window.BASE_URL;</script>
    <link rel="alternate" type="application/rss+xml" title="<?php echo e(get_setting('site_name', 'Blog')); ?> &raquo; RSS Feed" href="<?php echo BASE_URL; ?>feed">
    <script src="<?php echo asset_url('assets/js/app.js'); ?>" defer></script>
    <?php if ($custom_script_header): ?>
        <!-- Custom Header Scripts (Delayed for PageSpeed) -->
        <?php echo preg_replace('/<script\b([^>]*)>/i', '<script type="text/delayscript"$1>', $custom_script_header); ?>
    <?php endif; ?>
</head>

<body class="<?php echo isset($_SESSION['user_id']) ? 'has-adminbar' : ''; ?>">
    <?php if ($custom_script_body): ?>
        <!-- Custom Body Scripts (Delayed for PageSpeed) -->
        <?php echo preg_replace('/<script\b([^>]*)>/i', '<script type="text/delayscript"$1>', $custom_script_body); ?>
    <?php endif; ?>
    <?php if ($preloader_enabled): ?>
        <!-- Premium Preloader -->
        <div id="preloader">
            <div class="preloader-bg"></div>
            <div class="loader-content">
                <?php
                    $loader_logo_path = $preloader_logo !== '' ? $preloader_logo : $site_logo;
                ?>
                <?php if ($loader_logo_path): ?>
                    <?php 
                        $loader_logo_src = (strpos($loader_logo_path, 'http') === 0 || strpos($loader_logo_path, '//') === 0) ? $loader_logo_path : BASE_URL . $loader_logo_path;
                    ?>
                    <img src="<?php echo $loader_logo_src; ?>" alt="Loading..." class="loader-logo">
                <?php else: ?>
                    <h2 class="fw-bold text-primary mb-3"><?php echo e($site_name); ?></h2>
                <?php endif; ?>
                <div class="loader-progress">
                    <div class="loader-bar"></div>
                </div>
                <div class="mt-3 text-muted small fw-light" style="letter-spacing: 2px; text-transform: uppercase;">Loading
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="toast-container" class="toast-container"></div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <!-- Admin Toolbar (WordPress-style) -->
        <div id="wpadminbar"
            style="background:#23282d;height:32px;position:fixed;top:0;left:0;right:0;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;font-size:13px;box-shadow:0 1px 3px rgba(0,0,0,.3);">
            <div style="display:flex;align-items:center;height:100%;padding:0 8px;">
                <!-- Left items -->
                <div style="display:flex;align-items:center;gap:0;">
                    <!-- Site name / Dashboard -->
                    <a href="<?php echo BASE_URL; ?>admin/" title="Dashboard"
                        style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:6px;transition:color .1s,background .1s;"
                        onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
                        onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                        <i class="bi bi-grid-fill" style="font-size:14px;"></i>
                        <span class="wp-adminbar-text"
                            style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($site_name); ?></span>
                    </a>

                    <span style="color:#555;margin:0 2px;">│</span>

                    <?php
                    // Contextual edit links based on current page
                    if (isset($product) && !empty($product['id'])): ?>
                        <a href="<?php echo BASE_URL; ?>admin/products/edit.php?id=<?php echo (int) $product['id']; ?>"
                            title="Chỉnh sửa sản phẩm"
                            style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                            onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
                            onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                            <i class="bi bi-pencil-square" style="font-size:12px;"></i> <span class="wp-adminbar-text">Sửa sản phẩm</span>
                        </a>
                    <?php endif; ?>

                    <?php if (isset($category) && !empty($category['id'])): ?>
                        <a href="<?php echo BASE_URL; ?>admin/categories/edit.php?id=<?php echo (int) $category['id']; ?>"
                            title="Chỉnh sửa danh mục"
                            style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                            onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
                            onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                            <i class="bi bi-pencil-square" style="font-size:12px;"></i> <span class="wp-adminbar-text">Sửa danh mục</span>
                        </a>
                    <?php endif; ?>

                    <?php if (isset($page) && !empty($page['id'])): ?>
    <a href="<?php echo BASE_URL; ?>admin/pages/edit.php?id=<?php echo (int) $page['id']; ?>"
        title="Chỉnh sửa trang tĩnh"
        style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
        onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
        onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
        <i class="bi bi-pencil-square" style="font-size:12px;"></i> <span class="wp-adminbar-text">Sửa trang tĩnh</span>
    </a>
<?php endif; ?>

<?php if (isset($post) && !empty($post['id'])): ?>
                        <a href="<?php echo BASE_URL; ?>admin/posts/edit.php?id=<?php echo (int) $post['id']; ?>"
                            title="Chỉnh sửa bài viết"
                            style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                            onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
                            onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                            <i class="bi bi-pencil-square" style="font-size:12px;"></i> <span class="wp-adminbar-text">Sửa bài viết</span>
                        </a>
                    <?php endif; ?>

                    <!-- Quick Actions Dropdown -->
                    <div id="wp-quick-actions" style="position:relative;display:flex;align-items:center;">
                        <a href="#" title="Thao tác nhanh" onclick="event.preventDefault();toggleQuickActions();"
                            style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                            onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
                            onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                            <i class="bi bi-grid-3x3-gap-fill" style="font-size:13px;"></i> <span class="wp-adminbar-text">Quản lý <i class="bi bi-chevron-down" style="font-size:9px;opacity:.7;"></i></span>
                        </a>
                        <div id="wp-quick-actions-dropdown"
                            style="display:none;position:absolute;top:32px;left:0;width:220px;background:#2c3338;box-shadow:0 8px 20px rgba(0,0,0,.35);border-radius:0 0 8px 8px;padding:8px;z-index:999999;border:1px solid #3c434a;border-top:2px solid #6366f1;">
                            <a href="<?php echo BASE_URL; ?>admin/" class="wp-qa-item">
                                <i class="bi bi-speedometer2" style="color:#6366f1;"></i> Dashboard
                            </a>
                            <a href="<?php echo BASE_URL; ?>admin/posts/add.php" class="wp-qa-item">
                                <i class="bi bi-pencil-square" style="color:#d97706;"></i> Viết bài mới
                            </a>
                            <a href="<?php echo BASE_URL; ?>admin/banners/index.php" class="wp-qa-item">
                                <i class="bi bi-images" style="color:#16a34a;"></i> Banner
                            </a>
                            <div style="border-top:1px solid #3c434a;margin:4px 0;"></div>
                            <a href="<?php echo BASE_URL; ?>admin/document-requests/index.php" class="wp-qa-item">
                                <i class="bi bi-people-fill" style="color:#db2777;"></i> Lead tài liệu
                            </a>
                            <a href="<?php echo BASE_URL; ?>admin/settings/index.php" class="wp-qa-item">
                                <i class="bi bi-gear-fill" style="color:#0284c7;"></i> Cấu hình
                            </a>
                            <div style="border-top:1px solid #3c434a;margin:4px 0;"></div>
                            <a href="<?php echo BASE_URL; ?>" class="wp-qa-item">
                                <i class="bi bi-eye" style="color:#94a3b8;"></i> Xem trang chủ
                                <i class="bi bi-box-arrow-up-right" style="font-size:9px;opacity:.4;margin-left:auto;"></i>
                            </a>
                        </div>
                    </div>
                    <style>
                        .wp-qa-item {
                            display: flex;
                            align-items: center;
                            gap: 8px;
                            padding: 7px 10px;
                            border-radius: 6px;
                            color: #c3c4c7;
                            text-decoration: none;
                            font-size: 12.5px;
                            transition: all .15s;
                        }

                        .wp-qa-item:hover {
                            background: #3c434a;
                            color: #fff;
                        }

                        .wp-qa-item i:first-child {
                            font-size: 14px;
                            width: 18px;
                            text-align: center;
                        }
                    </style>
                    <script>
                        function toggleQuickActions() {
                            var dd = document.getElementById('wp-quick-actions-dropdown');
                            dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
                        }
                        document.addEventListener('click', function (e) {
                            var el = document.getElementById('wp-quick-actions');
                            if (el && !el.contains(e.target)) {
                                document.getElementById('wp-quick-actions-dropdown').style.display = 'none';
                            }
                        });
                    </script>

                    <!-- Lead tài liệu -->
                    <div style="position:relative;display:flex;align-items:center;">
                        <a href="<?php echo BASE_URL; ?>admin/document-requests/index.php" title="Lead tài liệu"
                            style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                            onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
                            onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                            <i class="bi bi-file-earmark-arrow-down" style="font-size:13px;"></i> <span class="wp-adminbar-text">Lead tài liệu</span>
                            <?php
                            try {
                                $pending_stmt = $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'failed'");
                                $pending_num = $pending_stmt ? (int) $pending_stmt->fetchColumn() : 0;
                                if ($pending_num > 0): ?>
                                    <span style="background:#d63638;color:#fff;font-size:9px;font-weight:600;padding:0 5px;border-radius:10px;min-width:16px;text-align:center;line-height:16px;"><?php echo $pending_num; ?></span>
                                <?php endif;
                            } catch (Exception $e) {
                            }
                            ?>
                        </a>
                    </div>

                    <!-- Clear Cache -->
                    <span style="color:#555;margin:0 2px;">│</span>
                    <a href="#" id="wp-clear-cache" title="Xóa cache"
                        style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                        onmouseover="this.style.color='#6366f1';this.style.background='#32373c'"
                        onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'"
                        onclick="event.preventDefault();wpClearCache(this);">
                        <i class="bi bi-arrow-clockwise" style="font-size:13px;"></i> <span class="wp-adminbar-text">Xóa cache</span>
                    </a>
                </div>

                <!-- Right items -->
                <div style="margin-left:auto;display:flex;align-items:center;">
                    <span style="color:#c3c4c7;padding:0 8px;display:flex;align-items:center;gap:5px;">
                        <i class="bi bi-person-circle" style="font-size:14px;"></i>
                        <span class="wp-adminbar-text"><?php echo e($_SESSION['username'] ?? 'Admin'); ?></span>
                    </span>
                    <a href="<?php echo BASE_URL; ?>admin/logout.php" title="Đăng xuất"
                        style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                        onmouseover="this.style.color='#d63638';this.style.background='#32373c'"
                        onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                        <i class="bi bi-box-arrow-right" style="font-size:13px;"></i>
                    </a>
                </div>
            </div>
        </div>
        <style>
            body {
                padding-top: 32px !important;
            }
            .navbar.sticky-top {
                top: 32px !important;
            }
            
            @media (max-width: 768px) {
                #wpadminbar {
                    position: fixed !important;
                    height: 32px !important;
                    padding-bottom: 0;
                }
                #wpadminbar > div {
                    flex-wrap: nowrap !important;
                    overflow-x: auto !important;
                    overflow-y: visible !important;
                    -webkit-overflow-scrolling: touch;
                }
                #wpadminbar > div::-webkit-scrollbar {
                    display: none;
                }
                #wpadminbar > div > div {
                    flex-wrap: nowrap !important;
                    white-space: nowrap !important;
                }
                #wpadminbar .wp-adminbar-text {
                    display: inline !important;
                }
            }

            #wp-registrations-item:hover #wp-registrations-dropdown {
                display: block !important;
            }

            .wp-reg-item {
                padding: 8px 0;
                border-bottom: 1px solid #3c434a;
            }

            .wp-reg-item:last-child {
                border-bottom: none;
            }

            .wp-reg-name {
                font-weight: 600;
                display: block;
                color: #fff;
                margin-bottom: 2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .wp-reg-meta {
                display: flex;
                justify-content: space-between;
                align-items: center;
                color: #999;
                font-size: 11px;
                margin-top: 4px;
            }

            .wp-reg-status {
                font-size: 9px;
                padding: 1px 6px;
                border-radius: 4px;
                text-transform: uppercase;
                font-weight: 700;
                letter-spacing: 0.3px;
                display: inline-block;
            }

            .wp-status-pending {
                background: rgba(214, 54, 56, 0.2);
                color: #ff5f61;
                border: 1px solid rgba(214, 54, 56, 0.3);
            }

            .wp-status-completed {
                background: rgba(70, 181, 73, 0.2);
                color: #5ce360;
                border: 1px solid rgba(70, 181, 73, 0.3);
            }

            .wp-status-cancelled {
                background: rgba(153, 153, 153, 0.2);
                color: #ccc;
                border: 1px solid rgba(153, 153, 153, 0.3);
            }

            #wp-reg-list-content::-webkit-scrollbar {
                width: 4px;
            }

            #wp-reg-list-content::-webkit-scrollbar-thumb {
                background: #444;
                border-radius: 4px;
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const regItem = document.getElementById('wp-registrations-item');
                const regContent = document.getElementById('wp-reg-list-content');
                let isLoaded = false;

                if (regItem) {
                    regItem.addEventListener('mouseenter', function () {
                        if (isLoaded) return;

                        fetch('<?php echo BASE_URL; ?>admin/ajax/get_recent_registrations.php')
                            .then(response => response.json())
                            .then(result => {
                                if (result.success && result.data) {
                                    if (result.data.length === 0) {
                                        regContent.innerHTML = '<div style="text-align:center;padding:10px;color:#888;">Chưa có đăng ký mới.</div>';
                                        isLoaded = true;
                                        return;
                                    }

                                    let html = '';
                                    result.data.forEach(reg => {
                                        const date = new Date(reg.created_at);
                                        const timeStr = date.toLocaleDateString('vi-VN') + ' ' + date.toLocaleTimeString('vi-VN', {
                                            hour: '2-digit',
                                            minute: '2-digit'
                                        });

                                        let statusLabel = '';
                                        if (reg.status === 'pending') statusLabel = '<span class="wp-reg-status wp-status-pending">Mới</span>';
                                        else if (reg.status === 'completed') statusLabel = '<span class="wp-reg-status wp-status-completed">Xong</span>';
                                        else if (reg.status === 'cancelled') statusLabel = '<span class="wp-reg-status wp-status-cancelled">Hủy</span>';

                                        html += `
                                                <div class="wp-reg-item">
                                                    <span class="wp-reg-name" title="${reg.fullname}">${reg.fullname}</span>
                                                    <div class="wp-reg-meta">
                                                        <span style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${reg.product_name || reg.service_name}</span>
                                                        <div style="display:flex;align-items:center;gap:8px;">
                                                            ${statusLabel}
                                                            <span>${timeStr}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            `;
                                    });
                                    regContent.innerHTML = html;
                                    isLoaded = true;
                                } else {
                                    regContent.innerHTML = '<div style="padding:10px;color:#6366f1;">Lỗi tải dữ liệu.</div>';
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                regContent.innerHTML = '<div style="padding:10px;color:#6366f1;">Lỗi kết nối.</div>';
                            });
                    });
                }
            });

            function wpClearCache(el) {
                const icon = el.querySelector('i');
                const origClass = icon.className;
                icon.className = 'spinner-border spinner-border-sm';
                icon.style.width = '13px';
                icon.style.height = '13px';

                fetch(BASE_URL + 'admin/ajax/clear-cache.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': '<?php echo e(generate_csrf_token()); ?>'
                    },
                    body: 'csrf_token=<?php echo urlencode(generate_csrf_token()); ?>'
                })
                    .then(r => r.json())
                    .then(data => {
                        icon.className = origClass;
                        icon.style.width = '';
                        icon.style.height = '';
                        if (data.success) {
                            showToast('Thành công', data.message, 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showToast('Lỗi', data.message || 'Xóa cache thất bại', 'error');
                        }
                    })
                    .catch(() => {
                        icon.className = origClass;
                        icon.style.width = '';
                        icon.style.height = '';
                        showToast('Lỗi', 'Không thể kết nối server', 'error');
                    });
            }
        </script>
    <?php endif; ?>

    <!-- Wrapper to handle reveal animation -->
    <div id="page-wrapper" class="<?php echo $preloader_enabled ? 'content-hidden' : ''; ?>">
        <!-- Main Navigation -->
        <nav class="navbar navbar-expand-lg navbar-light nav-premium-nav sticky-top">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo BASE_URL; ?>">
                    <?php if ($site_logo): ?>
                        <?php 
                            $logo_src = (strpos($site_logo, 'http') === 0 || strpos($site_logo, '//') === 0) ? $site_logo : BASE_URL . $site_logo;
                        ?>
                        <img src="<?php echo $logo_src; ?>" alt="<?php echo e($site_name); ?>">
                    <?php endif; ?>
                    <span class="fw-bold brand-text">
                        <?php echo e($site_name); ?>
                    </span>
                </a>
                <div class="nav-mobile-actions d-lg-none">
                    <button class="navbar-toggler nav-mobile-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainNav" aria-controls="mainNav" aria-label="Mở menu">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>

                <!-- Tìm kiếm (desktop) -->
                <form class="ssale-search d-none d-lg-flex flex-grow-1 mx-3" role="search" action="<?php echo BASE_URL; ?>search.php" method="get" autocomplete="off">
                    <div class="ssale-search-wrap">
                        <i class="bi bi-search ssale-search-ico"></i>
                        <input type="search" name="q" class="ssale-search-input" placeholder="Tìm bài viết..." aria-label="Tìm bài viết" maxlength="120">
                        <button type="button" class="ssale-search-clear" aria-label="Xóa" tabindex="-1"><i class="bi bi-x-lg"></i></button>
                        <div class="ssale-search-panel" role="listbox"></div>
                    </div>
                </form>

                <div class="navbar-collapse offcanvas-lg offcanvas-end" tabindex="-1" id="mainNav" aria-labelledby="mainNavLabel">
                    <div class="offcanvas-header d-lg-none">
                        <h5 class="offcanvas-title fw-bold" id="mainNavLabel">Menu</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#mainNav" aria-label="Close"></button>
                    </div>
                    <div class="offcanvas-body">
                    <!-- Tìm kiếm (mobile) -->
                    <form class="ssale-search d-lg-none mb-3" role="search" action="<?php echo BASE_URL; ?>search.php" method="get" autocomplete="off">
                        <div class="ssale-search-wrap">
                            <i class="bi bi-search ssale-search-ico"></i>
                            <input type="search" name="q" class="ssale-search-input" placeholder="Tìm bài viết..." aria-label="Tìm bài viết" maxlength="120">
                            <button type="button" class="ssale-search-clear" aria-label="Xóa" tabindex="-1"><i class="bi bi-x-lg"></i></button>
                            <div class="ssale-search-panel" role="listbox"></div>
                        </div>
                    </form>
                    <ul class="navbar-nav nav-main-links mb-2 mb-lg-0">
                        <?php render_header_menu($menu_tree); ?>
                    </ul>
                    <style>
                    /* Submenu lồng nhiều cấp (cha-con) cho menu */
                    .nav-main-links .dropdown-submenu { position: relative; }
                    .nav-main-links .dropdown-submenu > .dropdown-toggle::after {
                        float: right; margin-top: .5em; border-top: .3em solid transparent;
                        border-bottom: .3em solid transparent; border-left: .3em solid; border-right: 0;
                    }
                    @media (min-width: 992px) {
                        .nav-main-links .dropdown-submenu > .dropdown-menu {
                            top: 0; left: 100%; margin-top: -.4rem; margin-left: .1rem;
                        }
                        .nav-main-links .dropdown-submenu:hover > .dropdown-menu { display: block; }
                    }
                    @media (max-width: 991.98px) {
                        .nav-main-links .dropdown-submenu > .dropdown-menu {
                            display: block; border: 0; box-shadow: none; padding-left: 1rem; margin: 0;
                        }
                    }
                    </style>
                    </div>
                </div>
            </div>
        </nav>

        <style>
            .nav-home-link i { font-size: 1.18rem; line-height: 1; }
            @media (max-width: 991.98px) {
                .nav-home-link { justify-content: flex-start !important; gap: 8px; }
                .nav-home-text { margin-left: 0 !important; }
            }
            .ssale-search { max-width: 520px; }
            .ssale-search-wrap { position: relative; width: 100%; }
            .ssale-search-ico { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9aa0ab; font-size: .95rem; pointer-events: none; }
            .ssale-search-input { width: 100%; height: 42px; border: 1.5px solid var(--primary-line); border-radius: 999px; padding: 0 40px 0 38px; font-size: .92rem; outline: none; background: #fff; transition: border-color .2s, box-shadow .2s; }
            .ssale-search-input:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px var(--primary-glow); }
            .ssale-search-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: #bcc2cc; width: 26px; height: 26px; border-radius: 50%; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: .68rem; }
            .ssale-search-clear:hover { background: var(--primary-soft); color: var(--primary-color); }
            .ssale-search-wrap.has-value .ssale-search-clear { display: flex; }
            .ssale-search-panel { position: absolute; top: calc(100% + 8px); left: 0; right: 0; background: #fff; border: 1px solid #eee; border-radius: 14px; box-shadow: 0 16px 40px -12px rgba(0,0,0,.25); padding: 6px; max-height: 70vh; overflow-y: auto; z-index: 1080; display: none; }
            .ssale-search-panel.open { display: block; }
            .ssale-sr-item { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 10px; text-decoration: none; color: #222; }
            .ssale-sr-item:hover, .ssale-sr-item.active { background: #fff5f2; color: #222; }
            .ssale-sr-thumb { width: 46px; height: 46px; flex: 0 0 46px; border-radius: 8px; overflow: hidden; background: #f6f7fb; display: grid; place-items: center; color: #c3c9d4; }
            .ssale-sr-thumb img { width: 100%; height: 100%; object-fit: cover; }
            .ssale-sr-info { min-width: 0; flex: 1; }
            .ssale-sr-name { font-size: .86rem; font-weight: 600; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            .ssale-sr-price { font-size: .82rem; font-weight: 800; color: var(--primary-color); margin-top: 2px; }
            .ssale-sr-old { color: #9aa0ab; text-decoration: line-through; font-weight: 500; font-size: .72rem; margin-left: 6px; }
            .ssale-sr-foot { display: block; text-align: center; padding: 10px; font-size: .82rem; font-weight: 700; color: var(--primary-color); text-decoration: none; border-top: 1px solid #f3f3f3; margin-top: 4px; }
            .ssale-sr-empty, .ssale-sr-loading { padding: 18px; text-align: center; color: #8b909a; font-size: .85rem; }
            @media (max-width: 991.98px) {
                .ssale-search { max-width: none; }
                .ssale-search-panel { position: static; box-shadow: none; border: 1px solid #f0f0f0; max-height: 60vh; margin-top: 8px; }
            }
        </style>
        <script>
            (function () {
                var BASE = <?php echo json_encode(rtrim(BASE_URL, '/') . '/'); ?>;
                function fmt(n) { n = Math.round(n || 0); return n.toLocaleString('vi-VN'); }
                function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }
                document.querySelectorAll('.ssale-search').forEach(function (form) {
                    var input = form.querySelector('.ssale-search-input');
                    var panel = form.querySelector('.ssale-search-panel');
                    var wrap = form.querySelector('.ssale-search-wrap');
                    var clearBtn = form.querySelector('.ssale-search-clear');
                    var t = null, lastQ = '', activeIdx = -1, ctrl = null;
                    function close() { panel.classList.remove('open'); activeIdx = -1; }
                    function open() { panel.classList.add('open'); }
                    function render(data) {
                        var items = data.items || [];
                        if (!input.value.trim()) { close(); return; }
                        var h = '';
                        if (items.length === 0) {
                            h = '<div class="ssale-sr-empty">Không tìm thấy bài viết phù hợp</div>';
                        } else {
                            items.forEach(function (it, i) {
                                var thumb = it.image ? '<img src="' + esc(it.image) + '" alt="" loading="lazy">' : '<i class="bi bi-file-earmark-text"></i>';
                                var desc = it.desc ? '<span class="ssale-sr-price">' + esc(it.desc) + '</span>' : '';
                                h += '<a class="ssale-sr-item" data-idx="' + i + '" href="' + esc(it.url) + '"><span class="ssale-sr-thumb">' + thumb + '</span><span class="ssale-sr-info"><span class="ssale-sr-name">' + esc(it.name) + '</span>' + desc + '</span></a>';
                            });
                            if (data.total > items.length) {
                                h += '<a class="ssale-sr-foot" href="' + BASE + 'search.php?q=' + encodeURIComponent(input.value.trim()) + '">Xem tất cả ' + data.total + ' kết quả</a>';
                            }
                        }
                        panel.innerHTML = h; open();
                    }
                    function doSearch() {
                        var q = input.value.trim();
                        wrap.classList.toggle('has-value', q.length > 0);
                        if (q.length < 2) { close(); panel.innerHTML = ''; return; }
                        if (q === lastQ && panel.innerHTML) { open(); return; }
                        lastQ = q;
                        panel.innerHTML = '<div class="ssale-sr-loading"><span class="spinner-border spinner-border-sm"></span></div>'; open();
                        if (ctrl) ctrl.abort();
                        ctrl = new AbortController();
                        fetch(BASE + 'api/search.php?q=' + encodeURIComponent(q), { signal: ctrl.signal })
                            .then(function (r) { return r.json(); })
                            .then(function (d) { if (d && d.success) render(d); else panel.innerHTML = '<div class="ssale-sr-empty">Lỗi tìm kiếm</div>'; })
                            .catch(function (e) { if (e.name !== 'AbortError') panel.innerHTML = '<div class="ssale-sr-empty">Lỗi kết nối</div>'; });
                    }
                    function upd(links) { links.forEach(function (l, i) { l.classList.toggle('active', i === activeIdx); if (i === activeIdx) l.scrollIntoView({ block: 'nearest' }); }); }
                    input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(doSearch, 250); });
                    input.addEventListener('focus', function () { if (input.value.trim().length >= 2 && panel.innerHTML) open(); });
                    clearBtn.addEventListener('click', function () { input.value = ''; wrap.classList.remove('has-value'); close(); panel.innerHTML = ''; input.focus(); });
                    input.addEventListener('keydown', function (e) {
                        var links = panel.querySelectorAll('.ssale-sr-item');
                        if (e.key === 'ArrowDown' && links.length) { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, links.length - 1); upd(links); }
                        else if (e.key === 'ArrowUp' && links.length) { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); upd(links); }
                        else if (e.key === 'Enter') { if (activeIdx >= 0 && links[activeIdx]) { e.preventDefault(); window.location.href = links[activeIdx].href; } }
                        else if (e.key === 'Escape') { close(); }
                    });
                    document.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
                });
            })();
        </script>
