<?php
// includes/footer.php
$col1 = get_setting('footer_col1');
$footer_text_color = trim((string) get_setting('footer_text_color', '#adb5bd'));
if (!preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $footer_text_color)) {
    $footer_text_color = '#adb5bd';
}
// Auto-load Active Categories (Children preferred) for Footer Col 2
$categories = get_footer_categories();
$col2_auto = '';
if (!empty($categories)) {
    // Header removed to avoid double title with Admin Content
    $col2_auto .= '<ul class="list-unstyled">';
    foreach ($categories as $cat) {
        $cat_url = categoryUrl($cat['slug']);
        $col2_auto .= '<li class="mb-2"><a href="' . $cat_url . '" class="text-decoration-none">' . e($cat['name']) . '</a></li>';
    }
    $col2_auto .= '</ul>';
}
// Admin content (title) appears first, auto categories below
$col2 = get_setting('footer_col2', '') . $col2_auto;

$col3 = get_setting('footer_col3');
$col4 = get_setting('footer_col4');
$site_logo = trim((string) get_setting('site_logo', ''));
$site_name = (string) get_setting('site_name', '');
$col1_with_logo = $col1;
if ($site_logo !== '') {
    $footer_logo_src = (strpos($site_logo, 'http') === 0 || strpos($site_logo, '//') === 0)
        ? $site_logo
        : BASE_URL . ltrim($site_logo, '/');
    $col1_with_logo = '<div class="footer-about-logo mb-3"><img src="' . e($footer_logo_src) . '" alt="' . e($site_name) . '" loading="lazy" decoding="async"></div>' . $col1;
}
$contact_phone = get_setting('contact_phone', '');
$contact_address = get_setting('contact_address', '');
$contact_zalo = get_setting('contact_zalo', '');
$contact_messenger = get_setting('contact_messenger', '');
$gtm_event_hotline = get_setting('gtm_event_hotline', 'click_hotline');
$gtm_event_zalo = get_setting('gtm_event_zalo', 'click_zalo');
$gtm_event_messenger = get_setting('gtm_event_messenger', 'click_messenger');
$custom_script_footer = filter_public_custom_script_markup(get_setting('custom_script_footer', ''));
?>
<!-- Footer -->
<footer class="bg-dark text-white pt-5 pb-3 mt-auto">
    <div class="container">
        <div class="row">
            <div class="col-md-3 mb-4 footer-col"><?php echo $col1_with_logo; ?></div>
            <div class="col-md-3 mb-4 footer-col"><?php echo $col2; ?></div>
            <div class="col-md-3 mb-4 footer-col"><?php echo $col3; ?></div>
            <div class="col-md-3 mb-4 footer-col"><?php echo $col4; ?></div>
        </div>
        <hr class="border-secondary">

        <?php 
        $ecosystem_raw = get_setting('footer_ecosystem', '');
        if (!empty(trim((string)$ecosystem_raw))):
            $lines = explode("\n", trim((string)$ecosystem_raw));
            $links = [];
            foreach($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                // Support format: "Label|URL" or auto-detect if line contains a URL
                if (strpos($line, '|') !== false) {
                    $parts = explode('|', $line, 2);
                    $links[] = ['text' => trim($parts[0]), 'url' => trim($parts[1])];
                } else {
                    // Fallback: find URL in line (looks for http/https)
                    if (preg_match('#(https?://\S+)#i', $line, $m)) {
                        $url = $m[1];
                        $label = trim(str_replace($url, '', $line), ' :|,-');
                        if ($label === '') {
                            $label = parse_url($url, PHP_URL_HOST);
                        }
                        $links[] = ['text' => $label, 'url' => $url];
                    }
                }
            }
            if (!empty($links)):
        ?>
        <div class="footer-ecosystem-section mb-4">
            <div class="ecosystem-inner">
                <div class="ecosystem-label">
                    <span class="ecosystem-icon-wrap"><i class="bi bi-grid-3x3-gap-fill"></i></span>
                    <span>Hệ sinh thái</span>
                </div>
                <div class="ecosystem-links-wrap">
                    <?php foreach($links as $i => $link): ?>
                        <a href="<?php echo e($link['url']); ?>" target="_blank" rel="noopener noreferrer" class="eco-pill" style="--i:<?php echo $i; ?>">
                            <span><?php echo e($link['text']); ?></span>
                            <i class="bi bi-arrow-up-right eco-arrow"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <hr class="border-secondary">
        <?php 
            endif;
        endif; 
        ?>

        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-start mb-2 mb-md-0">
                <div class="text-white-50 small">
                    &copy; <?php echo date('Y'); ?> <?php echo e(get_setting('site_name')); ?>. All rights reserved.
                </div>
                <div class="text-white-50 small mt-2" style="opacity:.85;line-height:1.55;">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php echo e(get_setting('site_name', 'Thắng Digital Marketing')); ?> — blog chia sẻ kiến thức Facebook Ads, Google Ads,
                    kinh doanh online, AI &amp; automation. Nội dung mang tính tham khảo, vui lòng cân nhắc khi áp dụng.
                </div>
            </div>
            <?php if ($contact_address): ?>
                <div class="col-md-6 text-center text-md-end">
                    <div class="text-white-50 small">
                        <i class="bi bi-geo-alt-fill me-1"></i> <?php echo e($contact_address); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</footer>

<!-- Floating Contact -->
<div class="floating-contact">
    <div class="contact-toggle" onclick="toggleContactMenu()">
        <i class="bi bi-headset"></i>
        <span class="pulse-ring"></span>
    </div>

    <div class="contact-options" id="contactOptions">
        <?php if ($contact_phone): ?>
            <a href="#" class="contact-btn phone" data-url="tel:<?php echo e($contact_phone); ?>"
                data-event="<?php echo e($gtm_event_hotline); ?>" data-type="phone"
                data-label="<?php echo e($contact_phone); ?>" data-confirm="Gọi ngay cho tư vấn viên?"
                onclick="handleContact(event, this)" title="Gọi ngay">
                <span class="contact-icon-wrap">
                    <i class="bi bi-telephone-fill"></i>
                </span>
                <span class="contact-text-wrap">
                    <span class="contact-title">Hotline</span>
                    <span class="contact-subtitle"><?php echo e($contact_phone); ?></span>
                </span>
                <span class="contact-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </a>
        <?php endif; ?>

        <?php if ($contact_zalo): ?>
            <a href="#" class="contact-btn zalo" data-url="https://zalo.me/<?php echo e($contact_zalo); ?>"
                data-event="<?php echo e($gtm_event_zalo); ?>" data-type="zalo" data-label="<?php echo e($contact_zalo); ?>"
                data-confirm="Chat với tư vấn viên qua Zalo?" onclick="handleContact(event, this)" title="Chat Zalo">
                <span class="contact-icon-wrap">
                    <span class="zalo-icon">Z</span>
                </span>
                <span class="contact-text-wrap">
                    <span class="contact-title">Zalo</span>
                    <span class="contact-subtitle"><?php echo e($contact_zalo); ?></span>
                </span>
                <span class="contact-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </a>
        <?php endif; ?>

        <?php if ($contact_messenger): ?>
            <a href="#" class="contact-btn messenger" data-url="https://m.me/<?php echo e($contact_messenger); ?>"
                data-event="<?php echo e($gtm_event_messenger); ?>" data-type="messenger"
                data-label="<?php echo e($contact_messenger); ?>" data-confirm="Nhắn tin qua Messenger?"
                onclick="handleContact(event, this)" title="Messenger">
                <span class="contact-icon-wrap">
                    <i class="bi bi-messenger"></i>
                </span>
                <span class="contact-text-wrap">
                    <span class="contact-title">Messenger</span>
                    <span class="contact-subtitle"><?php echo e($contact_messenger); ?></span>
                </span>
                <span class="contact-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Contact Confirm Modal -->
<div class="contact-confirm-overlay" id="contactConfirmOverlay" onclick="closeContactConfirm(event)">
    <div class="contact-confirm-card" onclick="event.stopPropagation()">
        <div class="confirm-header">
            <div class="confirm-icon-wrap" id="confirmIcon"></div>
            <h5 id="confirmTitle"></h5>
            <p id="confirmDesc"></p>
        </div>
        <div class="confirm-body">
            <div class="confirm-info" id="confirmInfo">
                <div class="confirm-info-icon" id="confirmInfoIcon"></div>
                <div>
                    <div class="confirm-info-text" id="confirmInfoText"></div>
                    <div class="confirm-info-sub" id="confirmInfoSub"></div>
                </div>
            </div>
            <div class="confirm-actions">
                <button class="confirm-btn confirm-btn-cancel" onclick="closeContactConfirm()">Để sau</button>
                <button class="confirm-btn confirm-btn-ok" id="confirmOkBtn" onclick="confirmContactAction()">
                    <i class="bi bi-check-lg"></i> <span id="confirmOkText">Đồng ý</span>
                </button>
            </div>
            <div class="confirm-trust">
                <i class="bi bi-shield-check"></i> Miễn phí tư vấn · Hỗ trợ 24/7
            </div>
        </div>
    </div>
</div>
<!-- Back to Top Button -->
<div id="backToTop" class="back-to-top" onclick="scrollToTop()">
    <i class="bi bi-chevron-up"></i>
</div>

<!-- (Đã gỡ modal đăng ký/tư vấn sản phẩm — blog không dùng) -->

<!-- Bootstrap Bundle (defer = không block render) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<?php
$script_name = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$needs_swiper_js = in_array($script_name, ['index.php', 'product.php'], true);
$needs_cart_js = false;
$needs_register_js = !empty($GLOBALS['require_register_js']);
$needs_price_js = !empty($GLOBALS['require_price_js']);
$needs_locations_js = !empty($GLOBALS['require_locations_js']);
?>
<?php if ($needs_swiper_js): ?>
    <!-- Swiper JS (defer = không block render) -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js" defer></script>
<?php endif; ?>

<!-- Local JS -->
<!-- Affiliate click tracking (beacon, không cản trở điều hướng) -->
<script src="<?php echo asset_url('assets/js/affiliate-track.js'); ?>" defer></script>
<?php if ($needs_cart_js): ?>
    <script src="<?php echo asset_url('assets/js/cart.js'); ?>" defer></script>
<?php endif; ?>
<?php if ($needs_register_js): ?>
    <script src="<?php echo asset_url('assets/js/register.js'); ?>" defer></script>
<?php endif; ?>
<?php if ($needs_price_js): ?>
    <script src="<?php echo asset_url('assets/js/price.js'); ?>" defer></script>
<?php endif; ?>
<?php if ($needs_locations_js): ?>
    <script src="<?php echo asset_url('assets/js/locations.js'); ?>" defer></script>
<?php endif; ?>

<?php if ($custom_script_footer): ?>
    <!-- Custom Footer Scripts -->
    <?php echo $custom_script_footer; ?>
<?php endif; ?>

<style>
    .footer-about-logo img {
        max-width: 130px;
        width: 100%;
        height: auto;
        display: block;
        object-fit: contain;
        filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.25));
    }

    .footer-col h3 {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 1.2rem;
        color: #fff;
        text-transform: uppercase;
    }

    .footer-col ul {
        list-style: none;
        padding: 0;
    }

    .footer-col ul li {
        margin-bottom: 0.5rem;
    }

    footer a {
        transition: color 0.2s !important;
    }

    footer a:not(.eco-pill):hover {
        color: var(--primary-color) !important;
        text-decoration: none !important;
        padding-left: 0 !important;
    }

    footer a::before,
    footer a:hover::before {
        display: none !important;
        content: none !important;
    }

    .footer-col ul,
    .footer-col li {
        list-style: none !important;
        padding: 0 !important;
        margin-left: 0 !important;
    }

    .footer-col a,
    .footer-col p,
    .footer-col li,
    .footer-col span {
        color: <?php echo e($footer_text_color); ?>;
        text-decoration: none;
    }

    /* ── Ecosystem Section ─────────────────────────────── */
    .footer-ecosystem-section {
        padding: 2px 0 6px;
    }
    .ecosystem-inner {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }
    .ecosystem-label {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        color: rgba(255,255,255,0.45);
        white-space: nowrap;
        flex-shrink: 0;
    }
    .ecosystem-icon-wrap {
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: linear-gradient(135deg, rgba(99,102,241,0.5) 0%, rgba(139,92,246,0.5) 100%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        color: #fff;
        flex-shrink: 0;
    }
    .ecosystem-links-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .eco-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 14px 5px 12px;
        border-radius: 99px;
        font-size: 0.8rem;
        font-weight: 500;
        color: rgba(255,255,255,0.75) !important;
        text-decoration: none !important;
        background: #2e85ff;
        border: 1px solid rgba(255,255,255,0.1);
        transition: all 0.22s cubic-bezier(0.4,0,0.2,1);
        position: relative;
        overflow: hidden;
        animation: ecoFadeIn 0.4s ease both;
        animation-delay: calc(var(--i, 0) * 60ms);
    }
    .eco-pill::before { display: none !important; }
    .eco-pill::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(120deg, transparent 0%, rgba(255,255,255,0.07) 50%, transparent 100%);
        transform: translateX(-100%);
        transition: transform 0.45s ease;
        pointer-events: none;
    }
    .eco-pill:hover {
        background: rgba(99,102,241,0.25) !important;
        border-color: rgba(99,102,241,0.55);
        color: #fff !important;
        transform: translateY(-2px);
        box-shadow: 0 4px 18px rgba(99,102,241,0.3);
    }
    .eco-pill:hover::after {
        transform: translateX(100%);
    }
    .eco-arrow {
        font-size: 0.65rem;
        opacity: 0.55;
        transition: transform 0.2s ease, opacity 0.2s ease;
    }
    .eco-pill:hover .eco-arrow {
        transform: translate(2px, -2px);
        opacity: 1;
    }
    @keyframes ecoFadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .region-option.active {
        background-color: var(--primary-color) !important;
        color: #fff !important;
    }

    .region-option:hover {
        opacity: 0.9;
    }

    .price-amount.price-bump,
    #detail-price-display.price-bump {
        animation: priceBump .28s ease;
    }

    @keyframes priceBump {
        0% {
            transform: translateY(2px);
            opacity: .35;
        }

        100% {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .floating-contact {
        position: fixed;
        bottom: 100px;
        right: 25px;
        z-index: 9999;
        pointer-events: none;
        touch-action: none;
    }

    .back-to-top {
        position: fixed;
        bottom: 30px;
        right: 25px;
        width: 50px;
        height: 50px;
        background: #fff;
        color: var(--primary-color);
        border: 2px solid var(--primary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 9998;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .back-to-top.show {
        opacity: 1;
        visibility: visible;
    }

    .back-to-top:hover {
        background: var(--primary-color);
        color: #fff;
        transform: translateY(-5px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }

    .back-to-top i {
        font-size: 1.4rem;
        font-weight: bold;
    }

    .contact-toggle {
        pointer-events: auto;
        width: 62px;
        height: 62px;
        border-radius: 99px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        background: var(--primary-gradient);
        box-shadow: 0 14px 30px rgba(79, 70, 229, 0.35);
        transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        transform: translateY(0);
        border: 1px solid rgba(255, 255, 255, 0.35);
        user-select: none;
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
        will-change: transform;
    }

    .contact-toggle i {
        position: relative;
        z-index: 2;
        font-size: 1.5rem;
        color: #fff;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .contact-toggle:hover {
        transform: translateY(-2px) scale(1.02);
        box-shadow: 0 18px 35px rgba(79, 70, 229, 0.45);
    }

    .contact-toggle.open i {
        transform: rotate(45deg);
    }

    .pulse-ring {
        pointer-events: none;
        position: absolute;
        width: 100%;
        height: 100%;
        border: 2px solid rgba(79, 70, 229, 0.45);
        border-radius: 50%;
        animation: pulse-ring 1.8s ease-out infinite;
        z-index: 0;
    }

    .contact-toggle.open .pulse-ring {
        animation: none;
        opacity: 0;
    }

    @keyframes pulse-ring {
        0% {
            transform: scale(1);
            opacity: 1;
        }

        100% {
            transform: scale(1.5);
            opacity: 0;
        }
    }

    .contact-options {
        pointer-events: none;
        position: absolute;
        bottom: 78px;
        right: 0;
        display: flex;
        flex-direction: column;
        gap: 10px;
        opacity: 0;
        visibility: hidden;
        transform: translateY(20px) scale(0.95);
        transition: all 0.33s cubic-bezier(0.4, 0, 0.2, 1);
        min-width: 258px;
        filter: drop-shadow(0 12px 30px rgba(0, 0, 0, 0.2));
        transform-origin: 90% 100%;
    }

    .contact-options.active {
        pointer-events: auto;
        opacity: 1;
        visibility: visible;
        transform: translateY(0) scale(1);
    }

    .contact-btn {
        pointer-events: auto;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 11px 13px;
        border-radius: 16px;
        width: 100%;
        text-decoration: none;
        color: #fff;
        font-weight: 500;
        font-size: 0.9rem;
        line-height: 1.25;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.16);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.24s ease;
        white-space: nowrap;
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.28);
        overflow: hidden;
        position: relative;
        opacity: 0;
        transform: translateX(14px);
        will-change: transform, opacity;
        -webkit-tap-highlight-color: transparent;
        touch-action: manipulation;
    }

    .contact-options.active .contact-btn {
        opacity: 1;
        transform: translateX(0);
    }

    .contact-btn:hover {
        color: #fff;
    }

    @media (hover: hover) and (pointer: fine) {
        .contact-toggle:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 18px 35px rgba(79, 70, 229, 0.45);
        }

        .contact-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22);
            color: #fff;
        }
    }

    .contact-btn::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(130deg, transparent, rgba(255, 255, 255, 0.22), transparent);
        transform: translateX(-120%);
        transition: transform 0.6s ease;
        pointer-events: none;
    }

    .contact-btn:hover::before {
        transform: translateX(120%);
    }

    .contact-btn:nth-child(1) {
        transition-delay: 0.04s;
    }

    .contact-btn:nth-child(2) {
        transition-delay: 0.08s;
    }

    .contact-btn:nth-child(3) {
        transition-delay: 0.12s;
    }

    .contact-options .contact-btn {
        transition-delay: var(--btn-delay, 0s);
    }

    .contact-btn.phone {
        background: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
    }

    .contact-btn.zalo {
        background: linear-gradient(135deg, #0068ff 0%, #00a2ff 100%);
    }

    .contact-btn.messenger {
        background: linear-gradient(135deg, #0084ff 0%, #a855f7 100%);
    }

    .contact-icon-wrap {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.22);
        flex: 0 0 34px;
    }

    .contact-text-wrap {
        display: flex;
        flex-direction: column;
        min-width: 0;
        color: inherit;
        flex: 1;
    }

    .contact-title {
        font-weight: 700;
        font-size: 0.78rem;
        letter-spacing: 0.01em;
        text-transform: uppercase;
    }

    .contact-subtitle {
        font-size: 0.7rem;
        opacity: 0.82;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .contact-arrow {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.2);
        font-size: 0.86rem;
        flex: 0 0 24px;
    }

    .contact-btn i,
    .contact-arrow i {
        font-size: 1.2rem;
    }

    .zalo-icon {
        width: 24px;
        height: 24px;
        background: #fff;
        color: #0068ff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.85rem;
    }

    .service-reg-zalo-btn {
        border-width: 2px;
    }

    .service-reg-zalo-btn.service-zalo-highlight {
        position: relative;
        overflow: hidden;
        color: #fff !important;
        border-color: #fff !important;
        background: linear-gradient(135deg, #0075ff 0%, #00a2ff 100%) !important;
        animation: serviceZaloPulse 1.1s ease-in-out;
    }

    .service-reg-zalo-btn.service-zalo-highlight::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        box-shadow: 0 0 0 0.45rem rgba(0, 132, 255, 0.35);
        animation: serviceZaloGlow 1.1s ease-in-out;
        pointer-events: none;
    }

    @keyframes serviceZaloPulse {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.02);
        }

        100% {
            transform: scale(1);
        }
    }

    @keyframes serviceZaloGlow {
        0% {
            opacity: 0.6;
            transform: scale(0.96);
        }

        100% {
            opacity: 0;
            transform: scale(1.25);
        }
    }

    @media (max-width: 576px) {
        .floating-contact {
            bottom: 90px;
            right: 15px;
        }

        .back-to-top {
            bottom: 24px;
            right: 15px;
            width: 45px;
            height: 45px;
        }

        .contact-toggle {
            width: 55px;
            height: 55px;
        }

        .contact-options {
            min-width: 248px;
        }

        .contact-btn {
            padding: 9px 10px;
            border-radius: 14px;
        }

        .contact-subtitle {
            font-size: 0.63rem;
        }

        .contact-toggle {
            transition: transform 0.16s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.16s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .contact-toggle:hover,
        .contact-btn:hover {
            transform: none !important;
            box-shadow: none !important;
        }

        .contact-toggle:active {
            transform: scale(0.97);
        }

        .contact-options {
            filter: none;
        }

        .contact-btn:active {
            transform: translateX(4px);
        }

        .contact-btn {
            transition-duration: 0.16s;
        }
    }

    /* Contact Confirm Overlay */
    .contact-confirm-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(4px);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .contact-confirm-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .contact-confirm-card {
        background: #fff;
        width: 90%;
        max-width: 400px;
        border-radius: 20px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        transform: scale(0.9) translateY(20px);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        overflow: hidden;
        position: relative;
    }

    .contact-confirm-overlay.active .contact-confirm-card {
        transform: scale(1) translateY(0);
    }

    .confirm-header {
        padding: 25px 25px 15px;
        text-align: center;
    }

    .confirm-icon-wrap {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        margin: 0 auto 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        color: #fff;
        background: var(--primary-gradient);
        box-shadow: 0 8px 20px rgba(243, 112, 33, 0.25);
        position: relative;
    }

    .confirm-icon-wrap::after {
        content: '';
        position: absolute;
        top: -6px;
        left: -6px;
        right: -6px;
        bottom: -6px;
        border-radius: 50%;
        border: 2px dashed rgba(243, 112, 33, 0.3);
        animation: spinSlow 10s linear infinite;
    }

    @keyframes spinSlow {
        to {
            transform: rotate(360deg);
        }
    }

    .confirm-header h5 {
        font-weight: 700;
        margin-bottom: 8px;
        color: #333;
        font-size: 1.25rem;
    }

    .confirm-header p {
        color: #666;
        font-size: 0.95rem;
        margin-bottom: 0;
        line-height: 1.4;
    }

    .confirm-body {
        padding: 0 25px 25px;
    }

    .confirm-info {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 15px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        border: 1px dashed #dee2e6;
    }

    .confirm-info-icon {
        width: 42px;
        height: 42px;
        background: #fff;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: var(--primary-color);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        flex-shrink: 0;
    }

    .confirm-info-text {
        font-weight: 700;
        color: #333;
        font-size: 1.05rem;
        word-break: break-all;
    }

    .confirm-info-sub {
        font-size: 0.85rem;
        color: #888;
        margin-top: 2px;
    }

    .confirm-actions {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
    }

    .confirm-btn {
        flex: 1;
        padding: 12px;
        border-radius: 12px;
        border: none;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .confirm-btn-cancel {
        background: #f1f3f5;
        color: #495057;
    }

    .confirm-btn-cancel:hover {
        background: #e9ecef;
        color: #212529;
    }

    .confirm-btn-ok {
        background: var(--primary-gradient);
        color: #fff;
        box-shadow: 0 4px 12px rgba(243, 112, 33, 0.2);
    }

    .confirm-btn-ok:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(243, 112, 33, 0.3);
    }

    .confirm-trust {
        text-align: center;
        font-size: 0.8rem;
        color: #adb5bd;
        border-top: 1px solid #f1f1f1;
        padding-top: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }
</style>

<script>
    // --- XỬ LÝ MODAL CONFIRM CONTACT ---
    let pendingAction = null;

    function closeContactMenu() {
        const menu = document.getElementById('contactOptions');
        const toggleBtn = document.querySelector('.contact-toggle');

        if (!menu) return;

        menu.classList.remove('active');
        if (toggleBtn) {
            toggleBtn.classList.remove('open');
        }
    }

    document.addEventListener('click', function (e) {
        const floatingContact = document.querySelector('.floating-contact');
        const menu = document.getElementById('contactOptions');

        if (!floatingContact || !menu || !menu.classList.contains('active')) {
            return;
        }

        if (!floatingContact.contains(e.target)) {
            closeContactMenu();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeContactMenu();
            closeContactConfirm();
        }
    });

    function handleContact(e, btn) {
        e.preventDefault();
        const url = btn.getAttribute('data-url');
        const type = btn.getAttribute('data-type');
        const label = btn.getAttribute('data-label');
        const eventName = btn.getAttribute('data-event');
        pendingAction = { url, type, label, eventName };

        const overlay = document.getElementById('contactConfirmOverlay');
        const iconWrap = document.getElementById('confirmIcon');
        const title = document.getElementById('confirmTitle');
        const desc = document.getElementById('confirmDesc');
        const infoIcon = document.getElementById('confirmInfoIcon');
        const infoText = document.getElementById('confirmInfoText');
        const infoSub = document.getElementById('confirmInfoSub');
        const okBtn = document.getElementById('confirmOkBtn');
        const okText = document.getElementById('confirmOkText');

        // Reset styles
        iconWrap.style.cssText = '';
        okBtn.style.cssText = '';
        infoIcon.style.cssText = '';

        if (type === 'phone') {
            iconWrap.className = 'confirm-icon-wrap';
            iconWrap.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
            iconWrap.innerHTML = '<i class="bi bi-telephone-fill"></i>';
            title.textContent = 'Gọi ngay hotline?';
            desc.textContent = 'Cuộc gọi sẽ được chuyển hướng đến ứng dụng điện thoại của bạn.';
            infoIcon.innerHTML = '<i class="bi bi-telephone"></i>';
            infoIcon.style.color = '#28a745';
            infoText.textContent = label;
            infoSub.textContent = 'Hotline hỗ trợ 24/7';
            okBtn.style.background = 'linear-gradient(135deg, #28a745, #20c997)';
            okBtn.style.boxShadow = '0 4px 12px rgba(40, 167, 69, 0.3)';
            okText.textContent = 'Gọi ngay';
        } else if (type === 'zalo') {
            iconWrap.className = 'confirm-icon-wrap';
            iconWrap.style.background = 'linear-gradient(135deg, #0068ff, #00a2ff)';
            iconWrap.style.fontFamily = 'Arial, sans-serif';
            iconWrap.style.fontWeight = '900';
            iconWrap.innerHTML = 'Z';
            title.textContent = 'Chat qua Zalo?';
            desc.textContent = 'Bạn sẽ được chuyển đến ứng dụng Zalo để chat với nhân viên tư vấn.';
            infoIcon.style.color = '#0068ff';
            infoIcon.style.fontWeight = 'bold';
            infoIcon.innerHTML = 'Z';
            infoText.textContent = 'Zalo Support';
            infoSub.textContent = label;
            okBtn.style.background = 'linear-gradient(135deg, #0068ff, #00a2ff)';
            okBtn.style.boxShadow = '0 4px 12px rgba(0, 104, 255, 0.3)';
            okText.textContent = 'Mở Zalo';
        } else {
            iconWrap.className = 'confirm-icon-wrap';
            iconWrap.style.background = 'linear-gradient(135deg, #0084ff, #a855f7)';
            iconWrap.innerHTML = '<i class="bi bi-messenger"></i>';
            title.textContent = 'Chat qua Messenger?';
            desc.textContent = 'Bạn sẽ được chuyển đến Facebook Messenger để được hỗ trợ.';
            infoIcon.innerHTML = '<i class="bi bi-messenger"></i>';
            infoIcon.style.color = '#0084ff';
            infoText.textContent = 'Facebook Support';
            infoSub.textContent = 'Trực tuyến ngay';
            okBtn.style.background = 'linear-gradient(135deg, #0084ff, #a855f7)';
            okBtn.style.boxShadow = '0 4px 12px rgba(0, 132, 255, 0.3)';
            okText.textContent = 'Mở Messenger';
        }

        // Close floating menu rồi show overlay
        closeContactMenu();
        overlay.classList.add('active');
    }

    function closeContactConfirm(e) {
        if (e && e.target !== e.currentTarget) return;
        document.getElementById('contactConfirmOverlay').classList.remove('active');
        pendingAction = null;
    }

    function sendContactLog(logType, label, eventName) {
        if (!logType) return;

        const payload = new URLSearchParams();
        payload.append('type', logType);
        payload.append('label', label || '');
        payload.append('event', eventName || '');
        payload.append('page_url', window.location.href);
        const csrfToken = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').trim();
        if (csrfToken) {
            payload.append('csrf_token', csrfToken);
        }

        const endpoint = (window.BASE_URL || '/') + 'api/log-contact.php';
        if (navigator.sendBeacon) {
            try {
                const blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
                navigator.sendBeacon(endpoint, blob);
                return;
            } catch (e) {
            }
        }

        fetch(endpoint, {
            method: 'POST',
            body: payload.toString(),
            keepalive: true,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {})
            }
        }).catch(() => { });
    }

    function confirmContactAction() {
        if (!pendingAction) return;
        const { url, type, label, eventName } = pendingAction;
        let popup = null;

        // GTM dataLayer push đầy đủ thông tin
        window.dataLayer = window.dataLayer || [];
        if (eventName) {
            window.dataLayer.push({
                event: eventName,
                contact_type: type,
                contact_value: label,
                page_url: window.location.href,
                page_title: document.title
            });
        }

        // Server-side log
        const logType = { phone: 'contact_hotline', zalo: 'contact_zalo', messenger: 'contact_messenger' }[type] || '';
        sendContactLog(logType, label, eventName);

        // Đóng modal, điều hướng
        closeContactConfirm();
        if (type !== 'phone') {
            popup = window.open('about:blank', '_blank', 'noopener');
        }

        window.setTimeout(function () {
            if (type === 'phone') {
                window.location.href = url;
                return;
            }

            if (popup && !popup.closed) {
                popup.location.href = url;
                return;
            }

            window.open(url, '_blank', 'noopener');
        }, 180);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const serviceRegZaloBtn = document.getElementById('serviceRegZaloBtn');
        if (!serviceRegZaloBtn) return;

        serviceRegZaloBtn.addEventListener('click', function (event) {
            event.preventDefault();
            const eventName = this.getAttribute('data-event') || '';
            const label = this.getAttribute('data-label') || '';
            const url = this.getAttribute('href') || '';
            let popup = null;

            window.dataLayer = window.dataLayer || [];
            if (eventName) {
                window.dataLayer.push({
                    event: eventName,
                    contact_type: 'zalo',
                    contact_value: label,
                    page_url: window.location.href,
                    page_title: document.title
                });
            }

            popup = window.open('about:blank', '_blank', 'noopener');
            sendContactLog('contact_zalo', label, eventName);

            window.setTimeout(function () {
                if (popup && !popup.closed) {
                    popup.location.href = url;
                    return;
                }

                window.open(url, '_blank', 'noopener');
            }, 180);
        });
    });
</script>

<script>
    (function () {
        const endpoint = '<?php echo BASE_URL; ?>api/traffic-event.php';
        const startTime = Date.now();
        let pageExitTracked = false;
        let exitSeconds = 0;

        function sendTrafficEvent(eventName, eventLabel, metadata) {
            try {
                const payload = new URLSearchParams();
                payload.append('event_name', eventName || '');
                payload.append('event_label', eventLabel || '');
                payload.append('page_url', window.location.href);
                payload.append('metadata_json', JSON.stringify(metadata || {}));

                if (navigator.sendBeacon) {
                    const blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
                    navigator.sendBeacon(endpoint, blob);
                    return;
                }

                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: payload.toString(),
                    keepalive: true
                }).catch(() => {});
            } catch (err) {
            }
        }

        window.StoreTraffic = {
            track: sendTrafficEvent
        };

        document.addEventListener('click', function (event) {
            const link = event.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href') || '';
            if (href.startsWith('tel:')) {
                sendTrafficEvent('click_tel', href.replace('tel:', ''), { text: (link.textContent || '').trim() });
                return;
            }
            if (href.startsWith('mailto:')) {
                sendTrafficEvent('click_mailto', href.replace('mailto:', ''), { text: (link.textContent || '').trim() });
                return;
            }
            if (link.hasAttribute('data-event')) {
                sendTrafficEvent('custom_click', link.getAttribute('data-event') || '', {
                    href: href,
                    text: (link.textContent || '').trim()
                });
            }
        });

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            const formId = form.id || form.getAttribute('name') || form.getAttribute('action') || 'form_submit';
            sendTrafficEvent('form_submit', formId, {
                action: form.getAttribute('action') || '',
                method: form.getAttribute('method') || 'GET'
            });
        }, true);

        function trackExit() {
            if (pageExitTracked) return;
            pageExitTracked = true;
            exitSeconds = Math.max(1, Math.round((Date.now() - startTime) / 1000));
            if (exitSeconds >= 10) {
                sendTrafficEvent('time_on_page', document.title, { seconds: exitSeconds });
            }
        }

        window.addEventListener('beforeunload', trackExit);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                trackExit();
            }
        });
    })();
</script>

<script>
    (function () {
        function extractYouTubeId(url) {
            if (!url) return '';
            const patterns = [
                /youtube\.com\/embed\/([A-Za-z0-9_-]{6,})/i,
                /youtube\.com\/watch\?v=([A-Za-z0-9_-]{6,})/i,
                /youtu\.be\/([A-Za-z0-9_-]{6,})/i
            ];
            for (const pattern of patterns) {
                const match = url.match(pattern);
                if (match && match[1]) return match[1];
            }
            return '';
        }

        function createYouTubePlaceholder(iframe) {
            const src = iframe.getAttribute('src') || '';
            const videoId = extractYouTubeId(src);
            if (!videoId) return;

            const wrapper = document.createElement('button');
            wrapper.type = 'button';
            wrapper.className = 'yt-lite-embed';
            wrapper.setAttribute('aria-label', 'Phát video YouTube');
            wrapper.innerHTML = '<img loading=\"lazy\" src=\"https://i.ytimg.com/vi/' + videoId + '/hqdefault.jpg\" alt=\"YouTube thumbnail\"><span class=\"yt-lite-play\"></span>';

            wrapper.addEventListener('click', function () {
                const player = document.createElement('iframe');
                player.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
                player.title = iframe.getAttribute('title') || 'YouTube video';
                player.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                player.allowFullscreen = true;
                player.loading = 'lazy';
                player.referrerPolicy = 'strict-origin-when-cross-origin';
                player.className = iframe.className;
                player.style.cssText = iframe.style.cssText;
                wrapper.replaceWith(player);
            });

            iframe.replaceWith(wrapper);
        }

        window.addEventListener('load', function () {
            document.querySelectorAll('iframe[src*=\"youtube.com\"], iframe[src*=\"youtu.be\"]').forEach(createYouTubePlaceholder);
        });
    })();
</script>

<style>
    .yt-lite-embed {
        position: relative;
        display: block;
        width: 100%;
        border: 0;
        padding: 0;
        background: #000;
        border-radius: 14px;
        overflow: hidden;
        aspect-ratio: 16 / 9;
        cursor: pointer;
    }
    .yt-lite-embed img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        opacity: 0.92;
    }
    .yt-lite-play {
        position: absolute;
        inset: 50% auto auto 50%;
        width: 72px;
        height: 52px;
        transform: translate(-50%, -50%);
        border-radius: 14px;
        background: rgba(255, 0, 0, 0.92);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.28);
    }
    .yt-lite-play::before {
        content: '';
        position: absolute;
        inset: 50% auto auto 50%;
        transform: translate(-35%, -50%);
        border-style: solid;
        border-width: 10px 0 10px 18px;
        border-color: transparent transparent transparent #fff;
    }
</style>

</div><!-- #page-wrapper -->
<?php
// Banner quảng cáo dính đáy màn hình + cập nhật lượt hiển thị (impression) cuối request.
if (isset($pdo) && function_exists('render_ad_slot')) {
    try {
        echo render_ad_slot($pdo, 'sticky_bottom');
        if (function_exists('flush_ad_impressions')) {
            flush_ad_impressions($pdo);
        }
    } catch (Throwable $e) { /* không để quảng cáo làm vỡ trang */ }
}
?>
</body>

</html>
