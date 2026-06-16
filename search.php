<?php
// search.php — Trang kết quả tìm kiếm bài viết
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/seo.php';
require_once 'includes/url-helper.php';
require_once 'includes/blog.php';
require_once 'includes/widgets.php';

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 12;

$posts = [];
$total = 0;
$total_pages = 1;
if ($q !== '') {
    $like = '%' . $q . '%';
    try {
        $cs = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE status = 1 AND deleted_at IS NULL AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)");
        $cs->execute([$like, $like, $like]);
        $total = (int) $cs->fetchColumn();
        $total_pages = max(1, (int) ceil($total / $per_page));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;
        $st = $pdo->prepare("SELECT id, title, slug, summary, thumbnail, thumbnail_alt, created_at
                             FROM posts WHERE status = 1 AND deleted_at IS NULL AND (title LIKE ? OR summary LIKE ? OR content LIKE ?)
                             ORDER BY (title LIKE ?) DESC, created_at DESC LIMIT {$per_page} OFFSET {$offset}");
        $st->execute([$like, $like, $like, $like]);
        $posts = $st->fetchAll();
    } catch (Throwable $e) { $posts = []; }
}

$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$seo = new SEO($site_name, BASE_URL);
$seo->setTitle($q !== '' ? ('Tìm kiếm: ' . $q) : 'Tìm kiếm')
    ->setDescription($q !== '' ? ('Kết quả tìm kiếm cho "' . $q . '"') : 'Tìm kiếm bài viết')
    ->setRobots('noindex,follow');
$seo->addBreadcrumb('Trang chủ', BASE_URL)->addBreadcrumb('Tìm kiếm', BASE_URL . 'search.php');

$page_title = 'Tìm kiếm';

// ── Sidebar: cascade mặc định tổng -> override trang 'search' ──
[$sb_mode, $sb_pos] = sidebar_page_override($pdo, 'search');
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
                <li class="breadcrumb-item active" aria-current="page">Tìm kiếm</li>
            </ol>
        </nav>

        <header class="archive-head mb-4">
            <h1><i class="bi bi-search"></i> Tìm kiếm</h1>
            <?php if ($q !== ''): ?>
                <p class="text-muted mb-0">Tìm thấy <strong><?php echo number_format($total); ?></strong> bài viết cho "<strong><?php echo e($q); ?></strong>"</p>
            <?php endif; ?>
        </header>

        <form class="mb-4" action="<?php echo BASE_URL; ?>search.php" method="get" role="search">
            <div class="input-group input-group-lg" style="max-width:640px;">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="search" name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="Tìm bài viết..." autocomplete="off">
                <button class="btn btn-primary px-4" type="submit">Tìm</button>
            </div>
        </form>

        <div class="row g-4 g-lg-5">
            <div class="<?php echo $has_sidebar ? 'col-lg-8' : 'col-12'; ?><?php echo $sb_left ? ' order-lg-2' : ''; ?>">
                <?php if ($q !== '' && empty($posts)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-emoji-frown fs-1 d-block mb-2"></i>
                        <p class="mb-0">Không tìm thấy bài viết phù hợp với "<strong><?php echo e($q); ?></strong>".</p>
                        <a href="<?php echo BASE_URL; ?>" class="btn btn-outline-primary rounded-pill mt-3">Về trang chủ</a>
                    </div>
                <?php elseif (!empty($posts)): ?>
                    <div class="row g-4">
                        <?php foreach ($posts as $p): ?>
                            <div class="<?php echo $has_sidebar ? 'col-sm-6' : 'col-sm-6 col-lg-4'; ?>"><?php echo blog_card($p); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <nav class="mt-5" aria-label="Phân trang">
                            <ul class="pagination justify-content-center flex-wrap gap-1">
                                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?q=<?php echo urlencode($q); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
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

<?php require_once 'includes/footer.php'; ?>
