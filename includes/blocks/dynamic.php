<?php
// includes/blocks/dynamic.php — Block động BÀI VIẾT: nhiều bố cục (grid/slide/list/overlay/magazine)
// + style nền (9 kiểu) + chế độ boxed (khi trang chủ bật sidebar).
// Yêu cầu: $dynamic_block, $pdo. Tùy chọn: $GLOBALS['block_context'] = 'full'|'boxed'.
if (empty($dynamic_block) || !is_array($dynamic_block)) return;

$ctx = ($GLOBALS['block_context'] ?? 'full') === 'boxed' ? 'boxed' : 'full';

// ── Block NỘI DUNG / QUẢNG CÁO (tự soạn nội dung) ───────────────────────────
if ((string) ($dynamic_block['block_type'] ?? 'posts') === 'content') {
    $c_title   = trim((string) ($dynamic_block['title'] ?? ''));
    $c_sub     = trim((string) ($dynamic_block['subtitle'] ?? ''));
    $c_html    = (string) ($dynamic_block['content'] ?? '');
    $c_layout  = (string) ($dynamic_block['layout_style'] ?? 'simple');
    $c_allowed = ['simple','wave','gradient','glass','aurora','sunset','minimal','neon','editorial'];
    if (!in_array($c_layout, $c_allowed, true)) $c_layout = 'simple';
    $c_dark    = in_array($c_layout, ['wave','gradient','glass','aurora','sunset','neon'], true);
    $c_waveTop = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($dynamic_block['wave_top_color'] ?? '')) ? $dynamic_block['wave_top_color'] : '#f8f9fa';
    $c_waveBot = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($dynamic_block['wave_bottom_color'] ?? '')) ? $dynamic_block['wave_bottom_color'] : '#ffffff';
    if (trim(strip_tags($c_html)) === '' && $c_title === '') return;

    ob_start(); ?>
    <div class="cblk-card">
        <?php if ($c_title !== ''): ?>
            <div class="cblk-head">
                <h2 class="cblk-title"><?php echo e($c_title); ?></h2>
                <?php if ($c_sub !== ''): ?><p class="cblk-sub"><?php echo e($c_sub); ?></p><?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="cblk-body article-content"><?php echo $c_html; ?></div>
    </div>
    <?php $cinner = ob_get_clean();

    if ($ctx === 'boxed') {
        echo '<div class="dynblk-box cblk dynblk--' . $c_layout . ' ' . ($c_dark ? 'dynblk--dark' : '') . '">' . $cinner . '</div>';
    } else {
        $spStyle = block_spacing_style($c_layout === 'wave' ? 88 : 44, $c_layout === 'wave' ? 88 : 44);
        echo '<section class="dynblk cblk dynblk--' . $c_layout . ' ' . ($c_dark ? 'dynblk--dark' : '') . ' position-relative overflow-hidden" style="' . $spStyle . '">';
        if ($c_layout === 'wave') {
            echo '<div class="dynblk-wave dynblk-wave-top"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="' . e($c_waveTop) . '" d="M0,60 C360,120 720,0 1440,60 L1440,0 L0,0 Z"></path></svg></div>';
            echo '<div class="dynblk-wave dynblk-wave-bottom"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="' . e($c_waveBot) . '" d="M0,60 C360,0 720,120 1440,60 L1440,120 L0,120 Z"></path></svg></div>';
        }
        echo '<div class="container position-relative" style="z-index:2;"><div class="cblk-wrap">' . $cinner . '</div></div></section>';
    }
    return;
}


$db_title    = trim((string) ($dynamic_block['title'] ?? ''));
$db_subtitle = trim((string) ($dynamic_block['subtitle'] ?? ''));
$db_layout   = (string) ($dynamic_block['layout_style'] ?? 'simple');
$db_allowed  = ['simple','wave','gradient','glass','aurora','sunset','minimal','neon','editorial'];
if (!in_array($db_layout, $db_allowed, true)) $db_layout = 'simple';
$db_dark     = in_array($db_layout, ['wave','gradient','glass','aurora','sunset','neon'], true);

$db_card     = (string) ($dynamic_block['card_layout'] ?? '');
if ($db_card === '') {
    // tương thích bản cũ: display_mode slide -> slide, mặc định grid
    $db_card = (($dynamic_block['display_mode'] ?? 'row') === 'slide') ? 'slide' : 'grid';
}
if (!in_array($db_card, ['grid','slide','list','overlay','magazine'], true)) $db_card = 'grid';

$db_perrow   = max(1, min(6, (int) ($dynamic_block['items_per_row'] ?? 4)));
if ($ctx === 'boxed' && $db_perrow > 2) $db_perrow = 2; // cột hẹp khi có sidebar
$db_count    = max(1, min(50, (int) ($dynamic_block['items_count'] ?? 8)));
$db_order    = (string) ($dynamic_block['order_by'] ?? 'newest');
$db_catRaw   = trim((string) ($dynamic_block['category_id'] ?? ''));
$db_waveTop  = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($dynamic_block['wave_top_color'] ?? '')) ? $dynamic_block['wave_top_color'] : '#f8f9fa';
$db_waveBot  = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($dynamic_block['wave_bottom_color'] ?? '')) ? $dynamic_block['wave_bottom_color'] : '#ffffff';
$db_showMore = !empty($dynamic_block['show_view_more']);
$db_moreText = trim((string) ($dynamic_block['view_more_text'] ?? 'Xem tất cả')) ?: 'Xem tất cả';
$db_moreUrl  = trim((string) ($dynamic_block['view_more_url'] ?? ''));

// ── Truy vấn bài ────────────────────────────────────────────────────────────
$catIds = array_values(array_filter(array_map('intval', explode(',', $db_catRaw)), fn($v) => $v > 0));
switch ($db_order) {
    case 'oldest':  $orderSql = 'p.created_at ASC'; break;
    case 'random':  $orderSql = 'RAND()'; break;
    case 'featured':
    case 'popular': $orderSql = 'p.views DESC, p.created_at DESC'; break;
    default:        $orderSql = 'p.created_at DESC'; break;
}
$db_posts = [];
try {
    if (!empty($catIds)) {
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $st = $pdo->prepare("SELECT DISTINCT p.id, p.title, p.slug, p.summary, p.thumbnail, p.thumbnail_alt, p.created_at
            FROM posts p JOIN post_categories pc ON pc.post_id = p.id
            WHERE p.status = 1 AND pc.category_id IN ($ph) ORDER BY $orderSql LIMIT $db_count");
        $st->execute($catIds);
    } else {
        $st = $pdo->prepare("SELECT p.id, p.title, p.slug, p.summary, p.thumbnail, p.thumbnail_alt, p.created_at
            FROM posts p WHERE p.status = 1 ORDER BY $orderSql LIMIT $db_count");
        $st->execute();
    }
    $db_posts = $st->fetchAll();
} catch (Throwable $e) { $db_posts = []; }
if (empty($db_posts)) return;

if ($db_showMore && $db_moreUrl === '' && !empty($catIds)) {
    try { $cs = $pdo->prepare("SELECT slug FROM categories WHERE id=? LIMIT 1"); $cs->execute([$catIds[0]]); $cslug = $cs->fetchColumn(); if ($cslug) $db_moreUrl = categoryUrl($cslug); } catch (Throwable $e) {}
}

$colN = [1=>12,2=>6,3=>4,4=>3,5=>'5ths',6=>2][$db_perrow] ?? 3;
$colCls = $colN === '5ths' ? 'col-sm-6 col-5ths' : ('col-sm-6 col-lg-' . $colN);
$uid = 'dynblk_' . substr(md5(($dynamic_block['block_key'] ?? '') . $ctx), 0, 8);

// ── Render phần thân (header + items) ───────────────────────────────────────
ob_start();
if ($db_title !== ''): ?>
    <div class="dynblk-head <?php echo $ctx === 'boxed' ? 'dynblk-head--box text-start' : 'text-center'; ?> mb-4">
        <h2 class="fw-bold dynblk__title"><?php echo e($db_title); ?></h2>
        <?php if ($db_subtitle !== ''): ?><p class="dynblk__subtitle"><?php echo e($db_subtitle); ?></p><?php endif; ?>
        <?php if ($ctx !== 'boxed'): ?><div class="dynblk__rule"></div><?php endif; ?>
    </div>
<?php endif;

if ($db_card === 'slide'): ?>
    <div class="swiper dynblk-swiper" id="<?php echo $uid; ?>">
        <div class="swiper-wrapper">
            <?php foreach ($db_posts as $p): ?><div class="swiper-slide"><?php echo blog_card($p); ?></div><?php endforeach; ?>
        </div>
        <div class="swiper-pagination"></div>
    </div>
    <script>document.addEventListener('DOMContentLoaded',function(){if(window.Swiper){new Swiper('#<?php echo $uid; ?>',{slidesPerView:1.15,spaceBetween:16,grabCursor:true,pagination:{el:'#<?php echo $uid; ?> .swiper-pagination',clickable:true},breakpoints:{576:{slidesPerView:2},768:{slidesPerView:3},992:{slidesPerView:<?php echo $db_perrow; ?>}}});}});</script>
<?php elseif ($db_card === 'list'): ?>
    <div class="dynblk-list">
        <?php foreach ($db_posts as $p): ?><?php echo blog_card_list($p); ?><?php endforeach; ?>
    </div>
<?php elseif ($db_card === 'overlay'): ?>
    <div class="row g-3 g-md-4">
        <?php foreach ($db_posts as $p): ?><div class="<?php echo $colCls; ?>"><?php echo blog_card_overlay($p); ?></div><?php endforeach; ?>
    </div>
<?php elseif ($db_card === 'magazine'):
    $feat = $db_posts[0]; $rest = array_slice($db_posts, 1, $ctx === 'boxed' ? 4 : 6); ?>
    <div class="row g-4 dynblk-magazine">
        <div class="col-lg-<?php echo $ctx === 'boxed' ? 12 : 6; ?>"><?php echo blog_card_overlay($feat, true); ?></div>
        <div class="col-lg-<?php echo $ctx === 'boxed' ? 12 : 6; ?>">
            <div class="dynblk-list dynblk-list--compact">
                <?php foreach ($rest as $p): ?><?php echo blog_card_list($p); ?><?php endforeach; ?>
            </div>
        </div>
    </div>
<?php else: /* grid */ ?>
    <div class="row g-3 g-md-4">
        <?php foreach ($db_posts as $p): ?><div class="<?php echo $colCls; ?>"><?php echo blog_card($p); ?></div><?php endforeach; ?>
    </div>
<?php endif;

if ($db_showMore && $db_moreUrl !== ''): ?>
    <div class="text-center mt-4"><a href="<?php echo e($db_moreUrl); ?>" class="btn btn-outline-primary rounded-pill px-4"><?php echo e($db_moreText); ?> <i class="bi bi-arrow-right ms-1"></i></a></div>
<?php endif;
$inner = ob_get_clean();

// ── Bọc theo ngữ cảnh ───────────────────────────────────────────────────────
if ($ctx === 'boxed') {
    echo '<div class="dynblk-box dynblk--' . $db_layout . ' ' . ($db_dark ? 'dynblk--dark' : '') . '">' . $inner . '</div>';
} else {
    $spStyle = block_spacing_style($db_layout === 'wave' ? 88 : 44, $db_layout === 'wave' ? 88 : 44);
    echo '<section class="dynblk dynblk--' . $db_layout . ' ' . ($db_dark ? 'dynblk--dark' : '') . ' position-relative overflow-hidden" style="' . $spStyle . '">';
    if ($db_layout === 'wave') {
        echo '<div class="dynblk-wave dynblk-wave-top"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="' . e($db_waveTop) . '" d="M0,60 C360,120 720,0 1440,60 L1440,0 L0,0 Z"></path></svg></div>';
        echo '<div class="dynblk-wave dynblk-wave-bottom"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="' . e($db_waveBot) . '" d="M0,60 C360,0 720,120 1440,60 L1440,120 L0,120 Z"></path></svg></div>';
    }
    echo '<div class="container position-relative" style="z-index:2;">' . $inner . '</div></section>';
}
