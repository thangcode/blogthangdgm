<?php
// post.php — Trang chi tiết bài viết (blog)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/page-cache.php';
require_once 'includes/seo.php';
require_once 'includes/url-helper.php';
require_once 'includes/blog.php';
require_once 'includes/widgets.php';

$slug = isset($_GET['slug']) ? (string) $_GET['slug'] : '';

$_cache_key = 'post_' . preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
if ($slug) {
    frontend_cache_prelude($pdo, $_cache_key, ['page_title' => $page_title ?? '']);
    if (PageCache::get($_cache_key)) {
        exit;
    }
    PageCache::start($_cache_key);
}

$post = null;
if ($slug) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = ? AND status = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$slug]);
    $post = $stmt->fetch();
}

if (!$post) {
    header("HTTP/1.1 404 Not Found");
    require '404.php';
    exit;
}

// Tăng lượt xem (không chặn render; bỏ qua nếu lỗi)
try {
    $pdo->prepare("UPDATE posts SET views = views + 1 WHERE id = ?")->execute([(int) $post['id']]);
} catch (Throwable $e) {}

$categories = blog_post_categories($pdo, (int) $post['id']);
$primary_cat = blog_primary_category($pdo, $post);
$tags = blog_post_tags($pdo, (int) $post['id']);
$related = blog_related_posts($pdo, $post, 3);
$post_summary = blog_clean_wp_text((string) ($post['summary'] ?? ''));
$post_meta_description = blog_clean_wp_text((string) ($post['meta_description'] ?? ''));

// Nội dung: nhúng video YouTube + bọc bảng + sinh TOC
$post_content = blog_embed_youtube((string) ($post['content'] ?? ''));
$post_content = blog_normalize_wp_content($post_content);
$post_content = blog_demote_headings($post_content);
$post_content = blog_wrap_tables($post_content);
[$toc_html, $post_content] = blog_build_toc($post_content);

$has_document = !empty($post['document_path']);
$rating_summary = blog_post_rating_summary($pdo, (int) $post['id']);

// Banner "Shop Khóa Học" cũ dùng slot post_inline. Giữ fallback để banner đã cấu hình vẫn hiện ở đầu bài.
$post_above_html = '';
$post_below_html = '';
if (function_exists('render_ad_slot')) {
    $post_above_html = render_ad_slot($pdo, 'post_above_content');
    if ($post_above_html === '') {
        $post_above_html = render_ad_slot($pdo, 'post_inline');
    }
    $post_below_html = render_ad_slot($pdo, 'post_below_content');
}

// ── Sidebar: cascade mặc định tổng -> override của bài viết ──
$sb_cfg  = sidebar_resolve($post['sidebar_mode'] ?? 'default', $post['sidebar_position'] ?? 'default');
$sb_html = '';
if ($sb_cfg['enabled']) {
    $sb_html  = sidebar_render($pdo);
    // Banner quảng cáo cột bên bài viết (giống widget "Shop Khóa Học" trên WordPress) — đặt trên cùng.
    if (function_exists('render_ad_slot')) {
        $sb_html = render_ad_slot($pdo, 'post_sidebar') . $sb_html;
    }
}
$has_sidebar = $sb_cfg['enabled'] && trim($sb_html) !== '';
$sb_left = $has_sidebar && $sb_cfg['position'] === 'left';

// ── SEO ───────────────────────────────────────────────────────────────────
$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$seo = new SEO($site_name, BASE_URL);
$seo->setTitle($post['meta_title'] ?: $post['title'])
    ->setDescription($post_meta_description ?: $post_summary)
    ->setKeywords($post['meta_keywords'] ?: ($post['focus_keyword'] ?: $post['title']))
    ->setCanonical(postUrl($post['slug'], true));

if (!empty($post['thumbnail'])) {
    $seo->setOgImage($post['thumbnail']);
}

$seo->setArticleData([
    'title'          => $post['title'],
    'summary'        => $post_meta_description ?: $post_summary,
    'published_date' => date('c', strtotime($post['created_at'])),
    'modified_date'  => date('c', strtotime($post['updated_at'] ?: $post['created_at'])),
    'image'          => $post['thumbnail'] ?? '',
    'author'         => $post['author_name'] ?? '',
    'type'           => $post['schema_type'] ?: 'BlogPosting',
]);

$seo->addBreadcrumb('Trang chủ', BASE_URL);
if ($primary_cat) {
    $seo->addBreadcrumb($primary_cat['name'], categoryUrl($primary_cat['slug'], true));
}
$seo->addBreadcrumb($post['title'], postUrl($post['slug'], true));

// VideoObject schema nếu bài có video YouTube (giữ thế mạnh SEO video của bản WordPress)
$video_id = blog_extract_youtube_id((string) ($post['content'] ?? ''));
if ($video_id) {
    $seo->setVideoData([
        'name'        => $post['title'],
        'description' => $post_meta_description ?: $post_summary,
        'thumbnail'   => 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg',
        'upload_date' => date('c', strtotime($post['created_at'])),
        'embed_url'   => 'https://www.youtube.com/embed/' . $video_id,
        'content_url' => 'https://www.youtube.com/watch?v=' . $video_id,
    ]);
}

$page_title = $post['title'];
require_once 'includes/header.php';
?>

<div class="blog-single py-4 py-md-5">
    <div class="container">
        <nav aria-label="breadcrumb" class="breadcrumb-premium">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>"><i class="bi bi-house-door-fill"></i> Trang chủ</a></li>
                <?php if ($primary_cat): ?>
                    <li class="breadcrumb-item active" aria-current="page"><a href="<?php echo categoryUrl($primary_cat['slug']); ?>"><?php echo e($primary_cat['name']); ?></a></li>
                <?php endif; ?>
            </ol>
        </nav>

        <div class="row justify-content-center g-4 g-lg-5">
            <div class="col-lg-8<?php echo $sb_left ? ' order-lg-2' : ''; ?>">
                <article>
                    <header class="mb-4">
                        <?php if (!empty($categories)): ?>
                            <div class="single-cats mb-2">
                                <?php foreach ($categories as $c): ?>
                                    <a href="<?php echo categoryUrl($c['slug']); ?>" class="single-cat-chip"><?php echo e($c['name']); ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <h1 class="single-title"><?php echo e($post['title']); ?></h1>
                        <div class="single-meta">
                            <span><i class="bi bi-person-circle"></i> <?php echo e($post['author_name'] ?? 'Admin'); ?></span>
                            <span><i class="bi bi-clock"></i> <time datetime="<?php echo date('c', strtotime($post['created_at'])); ?>"><?php echo date('d/m/Y', strtotime($post['created_at'])); ?></time></span>
                            <span><i class="bi bi-eye"></i> <?php echo number_format((int) $post['views']); ?> lượt xem</span>
                        </div>
                    </header>

                    <?php if ($post_above_html !== ''): ?>
                        <?php echo $post_above_html; ?>
                    <?php elseif (!empty($post['thumbnail'])): ?>
                        <img src="<?php echo e(blog_thumb($post)); ?>" class="single-thumb mb-4"
                             alt="<?php echo e($post['thumbnail_alt'] ?? $post['title']); ?>" fetchpriority="high" decoding="async">
                    <?php endif; ?>

                    <?php if ($toc_html): echo $toc_html; endif; ?>

                    <div class="article-content">
                        <?php echo $post_content; ?>
                    </div>

                    <?php require __DIR__ . '/includes/blocks/post_cta.php'; ?>

                    <?php if ($post_below_html !== ''): ?>
                        <?php echo $post_below_html; ?>
                    <?php endif; ?>

                    <?php if ($has_document): require __DIR__ . '/includes/blocks/document_form.php'; endif; ?>

                    <?php if (!empty($tags)): ?>
                        <div class="single-tags mt-4">
                            <i class="bi bi-tags"></i>
                            <?php foreach ($tags as $t): ?>
                                <a href="<?php echo tagUrl($t['slug']); ?>" class="tag-chip"><?php echo e($t['name']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <section class="post-rating" aria-labelledby="postRatingTitle" data-post-rating>
                        <form class="post-rating__form" data-post-rating-form>
                            <span id="postRatingTitle" class="post-rating__title">Đánh giá bài viết:</span>
                            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                            <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                            <input type="hidden" name="form_ts" value="<?php echo time(); ?>">
                            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="display:none!important;position:absolute!important;left:-9999px!important;">
                            <div class="post-rating__choices" role="radiogroup" aria-label="Chọn số sao">
                                <?php for ($i = 5; $i >= 1; $i--):
                                    $checked = ((int) round((float) $rating_summary['average']) === $i) ? ' checked' : '';
                                ?>
                                    <input type="radio" id="post-rating-<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" required<?php echo $checked; ?>>
                                    <label for="post-rating-<?php echo $i; ?>" title="<?php echo $i; ?> sao" aria-label="<?php echo $i; ?> sao">★</label>
                                <?php endfor; ?>
                            </div>
                            <div class="post-rating__msg" data-post-rating-message role="status" aria-live="polite"></div>
                        </form>
                    </section>

                    <div class="single-share mt-4 pt-4 border-top">
                        <span class="fw-bold me-2">Chia sẻ:</span>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(postUrl($post['slug'], true)); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-facebook"></i> Facebook</a>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(postUrl($post['slug'], true)); ?>&text=<?php echo urlencode($post['title']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info"><i class="bi bi-twitter-x"></i> X</a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(location.href);this.innerHTML='<i class=\'bi bi-check\'></i> Đã copy'"><i class="bi bi-link-45deg"></i> Copy link</button>
                    </div>
                </article>

                <?php if (!empty($related)): ?>
                <section class="mt-5">
                    <div class="blog-section-head"><h2>Bài viết liên quan</h2></div>
                    <div class="row g-4">
                        <?php foreach ($related as $r): ?>
                            <div class="col-sm-4"><?php echo blog_card($r); ?></div>
                        <?php endforeach; ?>
                    </div>
                </section>
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

<script>
(function () {
    var form = document.querySelector('[data-post-rating-form]');
    if (!form) return;

    var box = document.querySelector('[data-post-rating]');
    var msg = form.querySelector('[data-post-rating-message]');

    function setMessage(text, ok) {
        if (!msg) return;
        msg.textContent = text || '';
        msg.classList.toggle('is-success', !!ok);
        msg.classList.toggle('is-error', !ok && !!text);
    }

    function updateSummary(summary) {
        if (!box || !summary) return;
        var avg = box.querySelector('[data-rating-average]');
        var count = box.querySelector('[data-rating-count]');
        var stars = box.querySelector('[data-rating-stars]');
        if (avg) avg.textContent = summary.average_text || '0.0';
        if (count) count.textContent = Number(summary.count || 0).toLocaleString('vi-VN');
        if (stars) stars.style.width = String(summary.percent || 0) + '%';
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        setMessage('', true);
        form.classList.add('is-submitting');

        var fd = new FormData(form);
        var token = (window.CSRF_TOKEN || (document.querySelector('meta[name="csrf-token"]') || {}).content || '').trim();
        if (token) fd.set('csrf_token', token);

        fetch('<?php echo BASE_URL; ?>api/post-rating.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: token ? { 'X-CSRF-Token': token } : {}
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.success) {
                    updateSummary(data.summary);
                    setMessage(data.message || 'Cảm ơn bạn đã đánh giá.', true);
                    return;
                }
                setMessage((data && data.message) || 'Chưa thể gửi đánh giá lúc này.', false);
            })
            .catch(function () {
                setMessage('Lỗi kết nối, vui lòng thử lại.', false);
            })
            .finally(function () {
                form.classList.remove('is-submitting');
            });
    });

    form.querySelectorAll('input[name="rating"]').forEach(function (input) {
        input.addEventListener('change', function () {
            if (!form.classList.contains('is-submitting')) {
                form.requestSubmit();
            }
        });
    });
})();
</script>

<?php
require_once 'includes/footer.php';
if ($slug) { PageCache::save(); }
