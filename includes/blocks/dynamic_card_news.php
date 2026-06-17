<?php
// includes/blocks/dynamic_card_news.php
// News card template for dynamic blocks
// Variables: $item, $card_extra_class, $db_layout

$post_url = postUrl($item['slug'] ?? '');
$post_img_path = (string) ($item['image'] ?? '');
$post_img = $post_img_path !== '' ? app_resized_image_url($post_img_path, 640) : get_image_url('', 'news');
$post_img_srcset = $post_img_path !== '' ? app_image_srcset($post_img_path, [320, 480, 640]) : '';
?>
<div class="dyncard-news h-100 <?php echo $card_extra_class ?? ''; ?>">
    <!-- Thumbnail -->
    <a href="<?php echo $post_url; ?>" class="dyncard-news-thumb">
        <?php if ($post_img): ?>
            <img src="<?php echo e($post_img); ?>"
                 <?php echo $post_img_srcset !== '' ? 'srcset="' . $post_img_srcset . '" sizes="(max-width: 575px) 100vw, 33vw"' : ''; ?>
                 alt="<?php echo e($item['title']); ?>"
                 width="640" height="360" loading="lazy" decoding="async">
        <?php else: ?>
            <div class="dyncard-news-placeholder">
                <i class="bi bi-newspaper"></i>
            </div>
        <?php endif; ?>
    </a>

    <!-- Content -->
    <div class="dyncard-news-body">
        <div class="dyncard-news-meta">
            <i class="bi bi-calendar3 me-1"></i>
            <?php echo date('d/m/Y', strtotime($item['created_at'])); ?>
        </div>
        <h5 class="dyncard-news-title">
            <a href="<?php echo $post_url; ?>" class="text-decoration-none">
                <?php echo e($item['title']); ?>
            </a>
        </h5>
        <?php if (!empty($item['summary'])): ?>
        <p class="dyncard-news-excerpt"><?php echo e($item['summary']); ?></p>
        <?php endif; ?>
        <a href="<?php echo $post_url; ?>" class="dyncard-news-readmore">
            Xem thêm <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
</div>

<?php if (empty($GLOBALS['_dyncard_news_css'])):
$GLOBALS['_dyncard_news_css'] = true; ?>
<style>
.dyncard-news {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(0,0,0,0.08);
    transition: all 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    border: 1px solid rgba(0,0,0,0.06);
    display: flex;
    flex-direction: column;
}
.dyncard-news:hover { transform: translateY(-8px); box-shadow: 0 16px 40px rgba(0,0,0,0.12); }

.dyncard-news-thumb {
    display: block; height: 200px; overflow: hidden; position: relative;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
}
.dyncard-news-thumb img {
    width: 100%; height: 100%; object-fit: cover;
    transition: transform 0.4s ease;
}
.dyncard-news:hover .dyncard-news-thumb img { transform: scale(1.05); }
.dyncard-news-placeholder {
    width: 100%; height: 100%;
    display: grid; place-items: center;
    color: rgba(255,255,255,0.8); font-size: 3.5rem;
}

.dyncard-news-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }

.dyncard-news-meta { font-size: 0.8rem; color: #999; margin-bottom: 8px; }

.dyncard-news-title { font-weight: 700; font-size: 1.05rem; line-height: 1.4; margin-bottom: 8px; }
.dyncard-news-title a { color: #1a1a2e; }
.dyncard-news-title a:hover { color: #6366f1; }

.dyncard-news-excerpt {
    font-size: 0.88rem; color: #6c757d; line-height: 1.5;
    display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
    margin-bottom: 16px;
}

.dyncard-news-readmore {
    margin-top: auto;
    font-size: 0.88rem; font-weight: 600; color: #6366f1;
    text-decoration: none;
    transition: all 0.2s ease;
}
.dyncard-news-readmore:hover { color: #8b5cf6; transform: translateX(4px); display: inline-block; }

/* Glass overrides */
.glass-card.dyncard-news {
    background: rgba(255,255,255,0.13);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,0.2);
}
.glass-card .dyncard-news-title a { color: #fff !important; }
.glass-card .dyncard-news-meta { color: rgba(255,255,255,0.6) !important; }
.glass-card .dyncard-news-excerpt { color: rgba(255,255,255,0.75) !important; }
.glass-card .dyncard-news-readmore { color: #fbbf24; }
</style>
<?php endif; ?>
