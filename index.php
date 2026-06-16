<?php
// index.php — Trang chủ blog (block động kéo-thả + sidebar tuỳ chọn)
session_start();
$page_title = 'Trang chủ';
$page_key   = 'home';

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/page-cache.php';
require_once 'includes/url-helper.php';
require_once 'includes/blog.php';
require_once 'includes/widgets.php';

$_cache_key = 'homepage';
frontend_cache_prelude($pdo, $_cache_key, ['page_title' => $page_title ?? '']);
// Không phục vụ/không lưu cache khi có query-string (tránh cache biến thể ?p=...).
if (empty($_GET)) {
    if (PageCache::get($_cache_key)) {
        exit;
    }
    PageCache::start($_cache_key);
}

$seo_data = [
    'title'       => '',
    'description' => 'Blog Thắng Digital Marketing — chia sẻ kiến thức Facebook Ads, Google Ads, kinh doanh online, AI & automation, thiết kế website và SEO.',
    'keywords'    => 'facebook ads, google ads, kinh doanh online, ai automation, n8n, seo, digital marketing'
];

require_once 'includes/header.php';

// H1 trang chủ phục vụ SEO (trang chủ dạng block-driven không có H1 hiển thị).
// Ẩn trực quan nhưng vẫn được công cụ tìm kiếm đọc — mỗi trang nên có đúng 1 H1.
$home_seo = function_exists('get_page_seo') ? get_page_seo('home', $pdo) : null;
$home_h1 = !empty($home_seo['meta_title']) ? $home_seo['meta_title'] : get_setting('site_name', 'Blog');
echo '<h1 class="visually-hidden">' . e($home_h1) . '</h1>';

// ── Dữ liệu cho hard block ──────────────────────────────────────────────────
try { $news = $pdo->query("SELECT id, title, slug, summary, thumbnail, thumbnail_alt, created_at FROM posts WHERE status=1 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 3")->fetchAll(); } catch (Throwable $e) { $news = []; }
try { $faqs = $pdo->query("SELECT * FROM faqs WHERE status=1 ORDER BY sort_order ASC")->fetchAll(); } catch (Throwable $e) { $faqs = []; }

// ── Cấu hình sidebar trang chủ ──────────────────────────────────────────────
[$sb_mode, $sb_pos] = sidebar_page_override($pdo, 'home');
$sb_cfg  = sidebar_resolve($sb_mode, $sb_pos);
$sb_html = $sb_cfg['enabled'] ? sidebar_render($pdo) : '';
// Banner quảng cáo cột bên trang chủ (slot home_sidebar; nếu trống dùng tạm banner post_sidebar).
if ($sb_cfg['enabled'] && function_exists('render_ad_slot')) {
    $sb_ad = render_ad_slot($pdo, 'home_sidebar');
    if (trim($sb_ad) === '') $sb_ad = render_ad_slot($pdo, 'post_sidebar');
    $sb_html = $sb_ad . $sb_html;
}
$has_sidebar = $sb_cfg['enabled'] && trim($sb_html) !== '';
$sb_left = $has_sidebar && $sb_cfg['position'] === 'left';

// ── Lấy block ───────────────────────────────────────────────────────────────
try { $homepage_blocks = $pdo->query("SELECT * FROM homepage_blocks WHERE is_visible=1 ORDER BY sort_order ASC")->fetchAll(); }
catch (Throwable $e) { $homepage_blocks = []; }
if (empty($homepage_blocks)) {
    $homepage_blocks = [['block_key'=>'hero'],['block_key'=>'news'],['block_key'=>'faq','layout_style'=>'wave']];
}

// Tách hero (luôn full-width) và phần còn lại
$hero_blocks = [];
$content_blocks = [];
foreach ($homepage_blocks as $b) {
    if (($b['block_key'] ?? '') === 'hero') $hero_blocks[] = $b; else $content_blocks[] = $b;
}

/** Render 1 block (hard hoặc dynamic). */
$render_block = function (array $block) use ($pdo, &$news, &$faqs) {
    // Spacing per-block (padding/margin) từ homepage_blocks.settings JSON
    $bs = json_decode((string) ($block['settings'] ?? ''), true);
    $GLOBALS['block_spacing'] = is_array($bs) ? $bs : [];

    $key = $block['block_key'];
    if (strpos($key, 'dynamic_') === 0) {
        try {
            $dyn = $pdo->prepare("SELECT * FROM dynamic_blocks WHERE block_key=? AND status=1 LIMIT 1");
            $dyn->execute([$key]);
            $dynamic_block = $dyn->fetch();
            if ($dynamic_block) include __DIR__ . '/includes/blocks/dynamic.php';
        } catch (Throwable $e) {}
        return;
    }
    $f = __DIR__ . '/includes/blocks/' . $key . '.php';
    if (file_exists($f)) include $f;
};

// Hero full-width
foreach ($hero_blocks as $b) { $render_block($b); }
if (function_exists('render_ad_slot')) { echo '<div class="container">' . render_ad_slot($pdo, 'home_top') . '</div>'; }

// ── Vùng nội dung ───────────────────────────────────────────────────────────
if ($has_sidebar && !empty($content_blocks)) {
    $GLOBALS['block_context'] = 'boxed';
    echo '<div class="blog-home-sidebar py-5"><div class="container"><div class="row g-4 g-lg-5">';
    echo '<main class="col-lg-8' . ($sb_left ? ' order-lg-2' : '') . '">';
    foreach ($content_blocks as $i => $b) {
        echo '<div class="home-block-wrap">';
        $render_block($b);
        echo '</div>';
        if ($i === 0 && function_exists('render_ad_slot')) { echo render_ad_slot($pdo, 'home_inline'); }
    }
    echo '</main>';
    echo '<aside class="col-lg-4' . ($sb_left ? ' order-lg-1' : '') . '"><div class="blog-sidebar sticky-lg-top">' . $sb_html . '</div></aside>';
    echo '</div></div></div>';
    $GLOBALS['block_context'] = 'full';
} else {
    $GLOBALS['block_context'] = 'full';
    $ci = 0;
    foreach ($content_blocks as $b) {
        $render_block($b);
        $ci++;
        if ($ci === 1 && function_exists('render_ad_slot')) { echo '<div class="container">' . render_ad_slot($pdo, 'home_inline') . '</div>'; }
    }
}

require_once 'includes/footer.php';
if (empty($_GET)) {
    PageCache::save();
}
