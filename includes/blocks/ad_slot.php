<?php
/**
 * includes/blocks/ad_slot.php
 * Render khối quảng cáo cho 1 slot.
 * Biến vào: $ad_slot (string), $ad_items (array), $ad_multi (bool).
 * LƯU Ý: tên class/id/data-attr/endpoint dùng tiền tố trung tính "ss-spot" để
 * tránh bị các trình chặn quảng cáo (Kaspersky Anti-Banner, uBlock...) ẩn mất.
 */
if (empty($ad_items) || !is_array($ad_items)) {
    return;
}
$ad_slot = $ad_slot ?? '';
$ad_multi = !empty($ad_multi);
$is_sticky = ($ad_slot === 'sticky_bottom');
$above_fold = in_array($ad_slot, ['home_top', 'home_sidebar', 'post_inline', 'post_top', 'post_above_content', 'post_sidebar', 'category_top']);

$render_one = function (array $b, bool $eager) use ($ad_slot) {
    // Banner kiểu HTML tùy chỉnh: render thẳng, không bọc ảnh/link.
    if ((string) ($b['banner_type'] ?? 'image') === 'html') {
        $html = trim((string) ($b['html_content'] ?? ''));
        if ($html === '') {
            return '';
        }
        $id = (int) ($b['id'] ?? 0);
        return '<div class="ss-spot__html" data-spot-id="' . $id . '">' . $html . '</div>';
    }
    $img = trim((string) ($b['image_path'] ?? ''));
    if ($img === '') {
        return '';
    }
    $desktop_width = in_array($ad_slot ?? '', ['home_sidebar', 'post_sidebar', 'product_sidebar'], true) ? 420 : 1200;
    $desktop = app_resized_image_url($img, $desktop_width);
    $mobile_path = trim((string) ($b['mobile_image_path'] ?? ''));
    $mobile = $mobile_path !== '' ? app_resized_image_url($mobile_path, 420) : '';
    $alt = e((string) ($b['title'] ?? ''));
    $loading = $eager ? 'fetchpriority="high"' : 'loading="lazy" decoding="async"';
    $dims_attr = app_image_dimensions_attr($img);
    $desktop_srcset = app_image_srcset($img, in_array($ad_slot ?? '', ['home_sidebar', 'post_sidebar', 'product_sidebar'], true) ? [300, 420, 600] : [640, 960, 1200]);
    $sizes_attr = in_array($ad_slot ?? '', ['home_sidebar', 'post_sidebar', 'product_sidebar'], true) ? '(max-width: 991px) 100vw, 360px' : '100vw';

    $picture = '<picture>';
    if ($mobile !== '') {
        $mobile_srcset = $mobile_path !== '' ? app_image_srcset($mobile_path, [320, 420, 640]) : e($mobile);
        $picture .= '<source media="(max-width: 768px)" srcset="' . $mobile_srcset . '" sizes="100vw">';
    }
    $picture .= '<img src="' . e($desktop) . '" srcset="' . $desktop_srcset . '" sizes="' . $sizes_attr . '" alt="' . $alt . '" class="ss-spot__media" ' . $loading . $dims_attr . '>';
    $picture .= '</picture>';

    $link = trim((string) ($b['link_url'] ?? ''));
    $id   = (int) ($b['id'] ?? 0);
    if ($link === '' || $link === '#') {
        return '<div class="ss-spot__link">' . $picture . '</div>';
    }
    $is_external = (bool) preg_match('#^https?://#i', $link);
    $attrs = ' href="' . e($link) . '" data-spot-id="' . $id . '"';
    if ($is_external) {
        $attrs .= ' target="_blank" rel="nofollow sponsored noopener noreferrer"';
    }
    return '<a class="ss-spot__link"' . $attrs . '>' . $picture . '</a>';
};

$wrap_class = 'ss-spot ss-spot--' . preg_replace('/[^a-z0-9_-]/', '', $ad_slot);
if ($is_sticky) {
    $wrap_class .= ' ss-spot--sticky';
}

// Ẩn nhãn "Tài trợ" khi tất cả banner trong slot đều là HTML tùy chỉnh.
$only_html = true;
foreach ($ad_items as $_b) {
    if ((string) ($_b['banner_type'] ?? 'image') !== 'html') {
        $only_html = false;
        break;
    }
}
?>
<?php if ($is_sticky): ?>
<div class="<?php echo $wrap_class; ?>" id="ssSpotBar" hidden>
    <div class="container ss-spotbar__inner">
        <button type="button" class="ss-spotbar__close" aria-label="Đóng" onclick="(function(b){b.setAttribute('hidden','');try{localStorage.setItem('ss_spot_closed','1');}catch(e){}})(document.getElementById('ssSpotBar'))">&times;</button>
        <?php echo $render_one($ad_items[0], false); ?>
    </div>
</div>
<script>
(function(){
    try { if (localStorage.getItem('ss_spot_closed') === '1') return; } catch(e){}
    var el = document.getElementById('ssSpotBar');
    if (el) el.removeAttribute('hidden');
})();
</script>
<?php else: ?>
<div class="<?php echo $wrap_class; ?>">
    <?php if (!$only_html): ?><span class="ss-spot__tag">@thangdigitalmarketing</span><?php endif; ?>
    <?php if ($ad_multi && count($ad_items) > 1): ?>
        <div class="ss-spot__list">
            <?php foreach ($ad_items as $i => $b): ?>
                <?php echo $render_one($b, $above_fold && $i === 0); ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?php echo $render_one($ad_items[0], $above_fold); ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($GLOBALS['_ss_spot_css_emitted'])):
$GLOBALS['_ss_spot_css_emitted'] = true; ?>
<style>
.ss-spot { position: relative; margin: 1.25rem 0; }
.ss-spot__tag { position: absolute; top: 6px; right: 8px; z-index: 2; font-size: .58rem; letter-spacing: .04em; text-transform: none; color: #fff; background: rgba(15,23,42,.55); padding: 1px 7px; border-radius: 999px; pointer-events: none; }
.ss-spot__link { display: block; position: relative; border-radius: 12px; overflow: hidden; line-height: 0; box-shadow: 0 4px 16px rgba(0,0,0,.06); isolation: isolate; transform: translateZ(0); transition: box-shadow .35s cubic-bezier(.2,.8,.2,1); }
.ss-spot__link::after { content: ""; position: absolute; inset: 0; pointer-events: none; background: linear-gradient(135deg, rgba(255,255,255,.18), rgba(255,255,255,0) 46%); opacity: 0; transition: opacity .35s ease; }
.ss-spot__link:hover { box-shadow: 0 14px 32px rgba(15,23,42,.16); }
.ss-spot__link:hover::after { opacity: 1; }
.ss-spot__media { width: 100%; height: auto; display: block; transform: scale(1.001); backface-visibility: hidden; will-change: transform, filter; transition: transform .55s cubic-bezier(.2,.8,.2,1), filter .55s cubic-bezier(.2,.8,.2,1); }
.ss-spot__link:hover .ss-spot__media { transform: scale(1.035); filter: saturate(1.04) contrast(1.02); }
.ss-spot__html { line-height: 1.6; }
.ss-spot__html > :first-child { margin-top: 0; }
.ss-spot__html > :last-child { margin-bottom: 0; }
.ss-spot__list { display: flex; flex-direction: column; gap: 14px; }
.ss-spot--product_sidebar { position: sticky; top: 90px; }
.ss-spot--post_sidebar { margin: 0; }
.ss-spot--post_sidebar .ss-spot__link { border-radius: 14px; box-shadow: 0 .35rem 1rem rgba(15,23,42,.05); }
.ss-spot--sticky { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1040; background: rgba(255,255,255,.96); box-shadow: 0 -4px 18px rgba(0,0,0,.12); padding: 8px 0; backdrop-filter: blur(4px); }
.ss-spot--sticky .ss-spotbar__inner { position: relative; display: flex; justify-content: center; }
.ss-spot--sticky .ss-spot__link { box-shadow: none; max-width: 970px; width: 100%; }
.ss-spotbar__close { position: absolute; top: -2px; right: 6px; z-index: 3; width: 26px; height: 26px; border: none; border-radius: 50%; background: #1e293b; color: #fff; font-size: 18px; line-height: 1; cursor: pointer; opacity: .85; }
.ss-spotbar__close:hover { opacity: 1; }
@media (max-width: 575.98px) {
    .ss-spot--sticky { padding: 6px 0; }
    .ss-spotbar__close { width: 22px; height: 22px; font-size: 15px; }
}
</style>
<?php endif; ?>
