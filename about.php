<?php
// about.php — Trang giới thiệu blog Thắng Digital Marketing
session_start();
$page_title = 'Giới thiệu';

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/page-cache.php';
require_once 'includes/seo.php';
require_once 'includes/url-helper.php';
require_once 'includes/widgets.php';

$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$seo = new SEO($site_name, BASE_URL);
$seo->setTitle('Giới thiệu')
    ->setDescription('Giới thiệu về ' . $site_name . ' — blog chia sẻ kiến thức Facebook Ads, Google Ads, kinh doanh online, AI & automation và digital marketing.')
    ->addBreadcrumb('Trang chủ', BASE_URL)
    ->addBreadcrumb('Giới thiệu', BASE_URL . 'gioi-thieu');

// ── Sidebar: cascade mặc định tổng -> override trang 'about' ──
[$sb_mode, $sb_pos] = sidebar_page_override($pdo, 'about');
$sb_cfg  = sidebar_resolve($sb_mode, $sb_pos);
$sb_html = $sb_cfg['enabled'] ? sidebar_render($pdo) : '';
$has_sidebar = $sb_cfg['enabled'] && trim($sb_html) !== '';
$sb_left = $has_sidebar && $sb_cfg['position'] === 'left';

require_once 'includes/header.php';
?>

<section class="bg-light py-5">
    <div class="container">
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>" class="text-decoration-none">Trang chủ</a></li>
                <li class="breadcrumb-item active" aria-current="page">Giới thiệu</li>
            </ol>
        </nav>

        <div class="row justify-content-center g-4 g-lg-5">
            <div class="<?php echo $has_sidebar ? 'col-lg-8' : 'col-lg-9'; ?><?php echo $sb_left ? ' order-lg-2' : ''; ?>">
                <article class="bg-white rounded-4 shadow-sm p-4 p-md-5">
                    <h1 class="fw-bold mb-4">Về <?php echo e($site_name); ?></h1>
                    <p class="lead text-muted">
                        <?php echo e($site_name); ?> là blog cá nhân chia sẻ kiến thức và kinh nghiệm thực chiến về
                        Facebook Ads, Google Ads, kinh doanh online, AI &amp; automation, thiết kế website và SEO.
                    </p>

                    <h2 class="fw-bold mt-4 mb-3">Bạn sẽ tìm thấy gì ở đây?</h2>
                    <ul class="mb-4">
                        <li>Hướng dẫn chạy quảng cáo Facebook Ads, Google Ads từ cơ bản đến nâng cao.</li>
                        <li>Kinh nghiệm kinh doanh online và xây dựng thương hiệu.</li>
                        <li>Ứng dụng AI &amp; automation (n8n) để tối ưu công việc.</li>
                        <li>Mẹo thiết kế website, SEO và tài liệu miễn phí tải về.</li>
                    </ul>

                    <h2 class="fw-bold mt-4 mb-3">Sứ mệnh</h2>
                    <p>
                        Mình mong muốn chia sẻ những kiến thức thực tế, dễ áp dụng, giúp bạn tự tin
                        triển khai marketing và kinh doanh online hiệu quả hơn mỗi ngày.
                    </p>

                    <h2 class="fw-bold mt-4 mb-3">Liên hệ với chúng tôi</h2>
                    <p class="mb-0">
                        Có câu hỏi hoặc muốn hợp tác? Hãy gửi tin nhắn qua trang
                        <a href="<?php echo BASE_URL; ?>lien-he" class="text-primary fw-bold">Liên hệ</a>.
                    </p>
                </article>
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
</section>

<?php require_once 'includes/footer.php'; ?>
