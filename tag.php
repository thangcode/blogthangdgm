<?php
// tag.php — Danh sách bài viết theo tag
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/page-cache.php';
require_once 'includes/seo.php';
require_once 'includes/url-helper.php';
require_once 'includes/blog.php';
require_once 'includes/widgets.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$_cache_key = 'tag_' . preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) . ($page > 1 ? '_p' . $page : '');
if ($slug) {
    frontend_cache_prelude($pdo, $_cache_key, ['page_title' => $page_title ?? '']);
    if (PageCache::get($_cache_key)) {
        exit;
    }
    PageCache::start($_cache_key);
}

$tag = null;
if ($slug) {
    $stmt = $pdo->prepare("SELECT * FROM tags WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $tag = $stmt->fetch();
}

if (!$tag) {
    header("HTTP/1.1 404 Not Found");
    require '404.php';
    exit;
}

$per_page = 12;
try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM post_tags pt JOIN posts p ON p.id = pt.post_id WHERE pt.tag_id = ? AND p.status = 1 AND p.deleted_at IS NULL");
    $cnt->execute([(int) $tag['id']]);
    $total = (int) $cnt->fetchColumn();
} catch (Throwable $e) { $total = 0; }
$total_pages = max(1, (int) ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

try {
    $stmt = $pdo->prepare("SELECT p.id, p.title, p.slug, p.summary, p.thumbnail, p.thumbnail_alt, p.created_at
                           FROM post_tags pt JOIN posts p ON p.id = pt.post_id
                           WHERE pt.tag_id = ? AND p.status = 1 AND p.deleted_at IS NULL
                           ORDER BY p.created_at DESC LIMIT {$per_page} OFFSET {$offset}");
    $stmt->execute([(int) $tag['id']]);
    $posts = $stmt->fetchAll();
} catch (Throwable $e) { $posts = []; }

$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$seo = new SEO($site_name, BASE_URL);
$seo->setTitle($tag['meta_title'] ?: ('Tag: ' . $tag['name']))
    ->setDescription($tag['meta_description'] ?: ('Các bài viết gắn thẻ ' . $tag['name']))
    ->setKeywords($tag['name'])
    ->setCanonical(tagUrlPaged($tag['slug'], $page, true));
$seo->addBreadcrumb('Trang chủ', BASE_URL)
    ->addBreadcrumb('Tag: ' . $tag['name'], tagUrl($tag['slug'], true));

$page_title = 'Tag: ' . $tag['name'];

// ── Sidebar: cascade mặc định tổng -> override trang 'tag' ──
[$sb_mode, $sb_pos] = sidebar_page_override($pdo, 'tag');
$sb_cfg  = sidebar_resolve($sb_mode, $sb_pos);
$sb_html = $sb_cfg['enabled'] ? sidebar_render($pdo) : '';
$has_sidebar = $sb_cfg['enabled'] && trim($sb_html) !== '';
$sb_left = $has_sidebar && $sb_cfg['position'] === 'left';

require_once 'includes/header.php';
?>

<div class="blog-archive py-4 py-md-5">
    <div class="container">
        <nav aria-label="breadcrumb" class="breadcrumb-premium">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>"><i class="bi bi-house-door-fill"></i> Trang chủ</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tag: <?php echo e($tag['name']); ?></li>
            </ol>
        </nav>

        <header class="archive-head mb-4">
            <h1><i class="bi bi-tag-fill"></i> <?php echo e($tag['name']); ?></h1>
            <span class="archive-count"><?php echo number_format($total); ?> bài viết</span>
        </header>

        <div class="row g-4 g-lg-5">
            <div class="<?php echo $has_sidebar ? 'col-lg-8' : 'col-12'; ?><?php echo $sb_left ? ' order-lg-2' : ''; ?>">
                <?php if (!empty($posts)): ?>
                    <div class="row g-4">
                        <?php foreach ($posts as $p): ?>
                            <div class="<?php echo $has_sidebar ? 'col-sm-6' : 'col-sm-6 col-lg-4'; ?>"><?php echo blog_card($p); ?></div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($total_pages > 1):
                        $link = fn($p) => e(tagUrlPaged($tag['slug'], $p));
                    ?>
                    <nav class="mt-5" aria-label="Phân trang">
                        <ul class="pagination justify-content-center flex-wrap gap-1">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $link($page - 1); ?>" rel="prev">&laquo;</a></li>
                            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $link($p); ?>"><?php echo $p; ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $link($page + 1); ?>" rel="next">&raquo;</a></li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center py-5 text-muted">Chưa có bài viết với thẻ này.</div>
                <?php endif; ?>
            </div>

            <?php if ($has_sidebar): ?>
            <aside class="col-lg-4<?php echo $sb_left ? ' order-lg-1' : ''; ?>">
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
