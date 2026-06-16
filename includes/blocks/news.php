<?php
// includes/blocks/news.php
// Latest News Section Block - tôn trọng layout_style cấu hình trong Admin.

$nw_layout = $block['layout_style'] ?? 'simple';
$nw_allowed = ['simple', 'wave', 'gradient', 'glass', 'aurora', 'sunset', 'minimal', 'neon', 'editorial'];
if (!in_array($nw_layout, $nw_allowed, true)) {
    $nw_layout = 'simple';
}
$nw_dark = in_array($nw_layout, ['wave', 'gradient', 'glass', 'aurora', 'sunset', 'neon'], true);

$nw_wave_top = $block['wave_top_color'] ?? '#f8f9fa';
$nw_wave_bottom = $block['wave_bottom_color'] ?? '#ffffff';
if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string) $nw_wave_top)) {
    $nw_wave_top = '#f8f9fa';
}
if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string) $nw_wave_bottom)) {
    $nw_wave_bottom = '#ffffff';
}
$nw_section_class = 'news-section news-section--' . $nw_layout . ($nw_dark ? ' news-section--dark' : '');
$__news_boxed = ($GLOBALS['block_context'] ?? 'full') === 'boxed';
if ($__news_boxed) { $nw_section_class = 'news-box'; $nw_layout = 'simple'; $nw_dark = false; }
?>
<!-- Latest News -->
<section class="position-relative overflow-hidden <?php echo $nw_section_class; ?>" style="<?php echo function_exists('block_spacing_style') ? block_spacing_style($nw_layout === 'wave' ? 88 : 44, $nw_layout === 'wave' ? 88 : 44) : 'padding:44px 0;'; ?>">
    <?php if ($nw_layout === 'wave'): ?>
        <div class="news-wave news-wave-top"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="<?php echo e($nw_wave_top); ?>" d="M0,60 C360,120 720,0 1440,60 L1440,0 L0,0 Z"></path></svg></div>
        <div class="news-wave news-wave-bottom"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="<?php echo e($nw_wave_bottom); ?>" d="M0,60 C360,0 720,120 1440,60 L1440,120 L0,120 Z"></path></svg></div>
    <?php endif; ?>
    <div class="container position-relative" style="z-index:2;">
        <div class="text-center mb-5">
            <h2 class="fw-bold news-section__title">Tin Tức Mới Nhất</h2>
            <div class="d-inline-block rounded-pill" style="height: 4px; width: 60px; background: var(--primary-gradient);"></div>
        </div>
        <div class="row g-4">
            <?php foreach ($news as $item): ?>
                <div class="col-md-4"><?php echo blog_card($item); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<style>
.news-section--simple    { background: #ffffff; }
.news-section--minimal   { background: #f8fafc; background-image: linear-gradient(rgba(15,23,42,.05) 1px, transparent 1px), linear-gradient(to right, rgba(15,23,42,.04) 1px, transparent 1px); background-size: 48px 48px; }
.news-section--editorial { background: #f8fafc; background-image: linear-gradient(rgba(15,23,42,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(15,23,42,.04) 1px, transparent 1px); background-size: 52px 52px; }
.news-section--gradient  { background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 40%, #312e81 100%); }
.news-section--glass     { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.news-section--aurora    { background: linear-gradient(135deg, #050a30 0%, #3c1666 40%, #0ea5e9 100%); }
.news-section--sunset    { background: linear-gradient(135deg, #f97316 0%, #ec4899 45%, #7c2d12 100%); }
.news-section--neon      { background: #050816; box-shadow: inset 0 0 120px rgba(34,211,238,.18); }
.news-section--wave      { background: linear-gradient(135deg, #6366f1 0%, #7c3aed 50%, #8b5cf6 100%); }
.news-wave { position: absolute; left: 0; width: 100%; height: 80px; z-index: 1; }
.news-wave-top { top: 0; }
.news-wave-bottom { bottom: 0; }
.news-wave svg { width: 100%; height: 100%; }
.news-section--dark .news-section__title { color: #ffffff; }
</style>
