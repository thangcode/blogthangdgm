<?php
// includes/blocks/post_cta.php — Quảng cáo thông tin GỌN ở cuối bài viết (trên banner dưới bài).
// Có thể tắt qua setting 'post_cta_enabled' = '0'.
if (function_exists('get_setting') && (string) get_setting('post_cta_enabled', '1') === '0') {
    return;
}
$cta_phone_raw = function_exists('get_setting') ? trim((string) get_setting('contact_phone', '0362.360.364')) : '0362.360.364';
if ($cta_phone_raw === '') $cta_phone_raw = '0362.360.364';
$cta_tel  = preg_replace('/[^0-9+]/', '', $cta_phone_raw);
$cta_zalo = function_exists('get_setting') ? preg_replace('/[^0-9]/', '', (string) get_setting('contact_zalo', $cta_tel)) : $cta_tel;
if ($cta_zalo === '') $cta_zalo = preg_replace('/[^0-9]/', '', $cta_tel);
$svc_url = function_exists('categoryUrl') ? categoryUrl('dich-vu-digital-marketing') : '/danh-muc/dich-vu-digital-marketing/';
?>
<aside class="post-cta" aria-label="Dịch vụ Thắng Digital Marketing">
    <span class="post-cta__ico">🚀</span>
    <div class="post-cta__text">
        <strong>Cần tư vấn Marketing?</strong>
        <span>Quảng cáo Google/Facebook Ads · Thiết kế web · Đào tạo &amp; Công cụ AI — bởi Thắng Digital Marketing.</span>
    </div>
    <div class="post-cta__actions">
        <a class="cblk-btn cblk-btn--primary" href="tel:<?php echo e($cta_tel); ?>"><i class="bi bi-telephone-fill"></i> <?php echo e($cta_phone_raw); ?></a>
        <a class="cblk-btn cblk-btn--ghost" href="<?php echo e($svc_url); ?>">Xem dịch vụ</a>
    </div>
</aside>
