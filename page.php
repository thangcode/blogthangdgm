<?php
// page.php — Trang tĩnh (giữ URL /{slug}/ như WordPress)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/page-cache.php';
require_once 'includes/seo.php';
require_once 'includes/url-helper.php';
require_once 'includes/blog.php';
require_once 'includes/widgets.php';
require_once 'includes/ad-banners.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';

$_cache_key = 'page_' . preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
if ($slug) {
    frontend_cache_prelude($pdo, $_cache_key, ['page_title' => $page_title ?? '']);
    if (PageCache::get($_cache_key)) {
        exit;
    }
    PageCache::start($_cache_key);
}

$page = null;
if ($slug) {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = ? AND status = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$slug]);
    $page = $stmt->fetch();
}

if (!$page) {
    header("HTTP/1.1 404 Not Found");
    require '404.php';
    exit;
}

$content = blog_wrap_tables((string) ($page['content'] ?? ''));
$content = blog_demote_headings($content);
[$toc_html, $content] = blog_build_toc($content);

// ── Sidebar (giống trang bài viết): mặc định bật, có thể tắt bằng setting sidebar_enabled ──
$sb_cfg  = sidebar_resolve('default', 'default');
$sb_html = '';
if ($sb_cfg['enabled']) {
    $sb_html = sidebar_render($pdo);
    if (function_exists('render_ad_slot')) {
        $sb_html = render_ad_slot($pdo, 'post_sidebar') . $sb_html;
    }
}
$has_sidebar = $sb_cfg['enabled'] && trim($sb_html) !== '';
$sb_left = $has_sidebar && $sb_cfg['position'] === 'left';

$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$seo = new SEO($site_name, BASE_URL);
$seo->setTitle($page['meta_title'] ?: $page['title'])
    ->setDescription($page['meta_description'] ?: ($page['summary'] ?? ''))
    ->setKeywords($page['meta_keywords'] ?: $page['title'])
    ->setCanonical(BASE_URL . ltrim($page['slug'], '/') . '/');
$seo->addBreadcrumb('Trang chủ', BASE_URL)
    ->addBreadcrumb($page['title'], BASE_URL . ltrim($page['slug'], '/') . '/');

$page_title = $page['title'];
require_once 'includes/header.php';
?>

<div class="blog-single py-4 py-md-5">
    <div class="container">
        <nav aria-label="breadcrumb" class="breadcrumb-premium">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>"><i class="bi bi-house-door-fill"></i> Trang chủ</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo e(mb_substr($page['title'], 0, 60, 'UTF-8')); ?></li>
            </ol>
        </nav>

        <div class="row g-4 g-lg-5<?php echo $has_sidebar ? '' : ' justify-content-center'; ?>">
            <div class="<?php echo $has_sidebar ? 'col-lg-9' : 'col-12'; ?><?php echo $sb_left ? ' order-lg-2' : ''; ?>">
                <article>
                    <header class="mb-4">
                        <h1 class="single-title"><?php echo e($page['title']); ?></h1>
                    </header>
                    <?php if ($toc_html) echo $toc_html; ?>
                    <div class="article-content">
                        <?php echo $content; ?>
                    </div>
                </article>
            </div>

            <?php if ($has_sidebar): ?>
            <aside class="col-lg-3<?php echo $sb_left ? ' order-lg-1' : ''; ?>">
                <div class="blog-sidebar sticky-lg-top">
                    <?php echo $sb_html; ?>
                </div>
            </aside>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
if ($slug) { PageCache::save(); }
