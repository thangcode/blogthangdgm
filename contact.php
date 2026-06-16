<?php
// contact.php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/seo.php';
require_once 'includes/url-helper.php';
require_once 'includes/widgets.php';

$page_title = 'Liên hệ';
$page_key = 'contact';

$contact_address = get_setting('contact_address', '');

$seo = new SEO();
$seo->setTitle('Liên hệ');
$seo->setDescription('Liên hệ với chúng tôi để được tư vấn và hỗ trợ nhanh chóng.');
$seo->addBreadcrumb('Trang chủ', BASE_URL)
    ->addBreadcrumb('Liên hệ', BASE_URL . 'lien-he');

// ── Sidebar: cascade mặc định tổng -> override trang 'contact' ──
[$sb_mode, $sb_pos] = sidebar_page_override($pdo, 'contact');
$sb_cfg  = sidebar_resolve($sb_mode, $sb_pos);
$sb_html = $sb_cfg['enabled'] ? sidebar_render($pdo) : '';
$has_sidebar = $sb_cfg['enabled'] && trim($sb_html) !== '';
$sb_left = $has_sidebar && $sb_cfg['position'] === 'left';

require_once 'includes/header.php';
?>

<section class="position-relative overflow-hidden"
    style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 300px;">
    <div class="position-absolute top-0 start-0 w-100 h-100"
        style="background: url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><defs><linearGradient id=%22g%22 x1=%220%25%22 y1=%220%25%22 x2=%22100%25%22 y2=%22100%25%22><stop offset=%220%25%22 stop-color=%22%23ff6b35%22 stop-opacity=%220.1%22/><stop offset=%22100%25%22 stop-color=%22%23f7931e%22 stop-opacity=%220.05%22/></linearGradient></defs><rect fill=%22url(%23g)%22 width=%22100%22 height=%22100%22/></svg>'); opacity: 0.5;">
    </div>
    <div class="container position-relative py-5">
        <div class="row align-items-center py-4">
            <div class="col-lg-7">
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb mb-0" style="--bs-breadcrumb-divider-color: rgba(255,255,255,0.5);">
                        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>"
                                class="text-white-50 text-decoration-none">Trang chủ</a></li>
                        <li class="breadcrumb-item active text-white" aria-current="page">Liên hệ</li>
                    </ol>
                </nav>
                <h1 class="display-5 fw-bold text-white mb-3">Liên Hệ Với Chúng Tôi</h1>
                <p class="lead text-white-50 mb-0">Chúng tôi luôn sẵn sàng hỗ trợ bạn. Hãy liên hệ ngay để được tư vấn
                    miễn phí.</p>
            </div>
            <div class="col-lg-5 d-none d-lg-block text-end">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle"
                    style="width: 150px; height: 150px; background: linear-gradient(135deg, rgba(99,102,241,0.2), rgba(139,92,246,0.2)); border: 2px solid rgba(99,102,241,0.3);">
                    <i class="bi bi-headset" style="font-size: 4rem; color: #6366f1;"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5" style="background: #f8f9fa;">
    <div class="container">
        <div class="row g-5">
            <div class="<?php echo $has_sidebar ? 'col-lg-8' : 'col-12'; ?><?php echo $sb_left ? ' order-lg-2' : ''; ?>">
        <div class="row g-4">
            <div class="col-lg-5">
                <div class="d-flex flex-column gap-4">
                    <?php if (!empty($contact_phone)): ?>
                        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                            <div class="card-body p-4 d-flex align-items-center gap-4">
                                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                                    style="width: 60px; height: 60px; background: var(--primary-gradient);">
                                    <i class="bi bi-telephone-fill text-white fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-muted mb-1 text-uppercase"
                                        style="font-size: 0.75rem; letter-spacing: 1px;">Hotline</h6>
                                    <a href="tel:<?php echo e($contact_phone); ?>" class="fs-5 fw-bold text-dark text-decoration-none">
                                        <?php echo e($contact_phone); ?>
                                    </a>
                                    <p class="text-muted small mb-0 mt-1">Hỗ trợ 24/7</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($contact_email)): ?>
                        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                            <div class="card-body p-4 d-flex align-items-center gap-4">
                                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                                    style="width: 60px; height: 60px; background: linear-gradient(135deg, #0d6efd, #6610f2);">
                                    <i class="bi bi-envelope-fill text-white fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-muted mb-1 text-uppercase"
                                        style="font-size: 0.75rem; letter-spacing: 1px;">Email</h6>
                                    <a href="mailto:<?php echo e($contact_email); ?>"
                                        class="fs-5 fw-bold text-dark text-decoration-none">
                                        <?php echo e($contact_email); ?>
                                    </a>
                                    <p class="text-muted small mb-0 mt-1">Phản hồi trong vòng 24h</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($contact_address)): ?>
                        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                            <div class="card-body p-4 d-flex align-items-center gap-4">
                                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                                    style="width: 60px; height: 60px; background: linear-gradient(135deg, #198754, #20c997);">
                                    <i class="bi bi-geo-alt-fill text-white fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-muted mb-1 text-uppercase"
                                        style="font-size: 0.75rem; letter-spacing: 1px;">Địa chỉ</h6>
                                    <p class="fs-6 fw-bold text-dark mb-0"><?php echo e($contact_address); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                        <div class="card-body p-4">
                            <h6 class="fw-bold text-muted mb-3 text-uppercase"
                                style="font-size: 0.75rem; letter-spacing: 1px;">Kết nối với chúng tôi</h6>
                            <div class="d-flex gap-3">
                                <?php if (!empty($contact_zalo)): ?>
                                    <a href="https://zalo.me/<?php echo e($contact_zalo); ?>" target="_blank"
                                        class="btn btn-outline-primary rounded-circle d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;" title="Zalo">
                                        <i class="bi bi-chat-dots-fill fs-5"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($contact_messenger)): ?>
                                    <a href="https://m.me/<?php echo e($contact_messenger); ?>" target="_blank"
                                        class="btn btn-outline-primary rounded-circle d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;" title="Messenger">
                                        <i class="bi bi-messenger fs-5"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($contact_phone)): ?>
                                    <a href="tel:<?php echo e($contact_phone); ?>"
                                        class="btn btn-outline-success rounded-circle d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;" title="Gọi ngay">
                                        <i class="bi bi-telephone-fill fs-5"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header border-0 p-4 pb-0" style="background: none;">
                        <h3 class="fw-bold mb-1">Gửi Yêu Cầu Tư Vấn</h3>
                        <p class="text-muted mb-0">Điền thông tin bên dưới, chúng tôi sẽ liên hệ bạn sớm nhất</p>
                    </div>
                    <div class="card-body p-4">
                        <form id="contactForm" method="POST" action="<?php echo BASE_URL; ?>ajax_contact.php">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <div style="display:none;position:absolute;left:-9999px;">
                                <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
                            </div>
                            <input type="hidden" name="form_ts" value="<?php echo time(); ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Họ và tên <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-person"></i></span>
                                        <input type="text" class="form-control border-start-0 ps-0" name="name"
                                            placeholder="Nhập họ và tên" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Số điện thoại <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-phone"></i></span>
                                        <input type="tel" class="form-control border-start-0 ps-0" name="phone"
                                            placeholder="Nhập số điện thoại" required>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Email</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope"></i></span>
                                        <input type="email" class="form-control border-start-0 ps-0" name="email"
                                            placeholder="Nhập email (tùy chọn)">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Nội dung</label>
                                    <textarea class="form-control" name="message" rows="4"
                                        placeholder="Mô tả yêu cầu của bạn..."></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-lg px-5 py-3 fw-bold text-white w-100"
                                        id="contactSubmitBtn"
                                        style="background: var(--primary-gradient); border: none; border-radius: 12px; transition: all 0.3s;">
                                        <i class="bi bi-send-fill me-2"></i>Gửi Yêu Cầu Tư Vấn
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
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

<section class="py-5 bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Tại Sao Chọn <?php echo e(get_setting('site_name', 'Website')); ?>?</h2>
            <div class="d-inline-block bg-primary rounded-pill" style="height: 4px; width: 60px;"></div>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="text-center p-4">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                        style="width: 80px; height: 80px; background: linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1));">
                        <i class="bi bi-lightning-charge-fill" style="font-size: 2rem; color: #6366f1;"></i>
                    </div>
                    <h5 class="fw-bold">Tự động hóa</h5>
                    <p class="text-muted mb-0">Các sản phẩm được xử lý tự động, bạn có thể mua mọi lúc mọi nơi.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-4">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                        style="width: 80px; height: 80px; background: linear-gradient(135deg, rgba(13,110,253,0.1), rgba(102,16,242,0.1));">
                        <i class="bi bi-shield-check" style="font-size: 2rem; color: #0d6efd;"></i>
                    </div>
                    <h5 class="fw-bold">Hỗ Trợ 24/7</h5>
                    <p class="text-muted mb-0">Đội ngũ kỹ thuật chuyên nghiệp, hỗ trợ khách hàng mọi lúc mọi nơi.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center p-4">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                        style="width: 80px; height: 80px; background: linear-gradient(135deg, rgba(25,135,84,0.1), rgba(32,201,151,0.1));">
                        <i class="bi bi-cash-coin" style="font-size: 2rem; color: #198754;"></i>
                    </div>
                    <h5 class="fw-bold">Giá Cả Hợp Lý</h5>
                    <p class="text-muted mb-0">Chi phí tốt nhất, nhiều hỗ trợ ưu đãi cho anh em học từ cơ bản đến nâng cao.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.card.rounded-4 {
    border-radius: 1rem !important;
}

.card.rounded-4:hover {
    transform: translateY(-2px);
    transition: all 0.3s ease;
}

#contactSubmitBtn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.3);
}

.input-group .form-control:focus {
    box-shadow: none;
    border-color: #6366f1;
}

.input-group .input-group-text {
    border-color: #dee2e6;
}

.input-group .form-control:focus+.input-group-text,
.input-group:focus-within .input-group-text {
    border-color: #6366f1;
}

#contactForm .form-control.is-invalid,
#contactForm .form-select.is-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15) !important;
    animation: contact-shake 0.4s ease;
}

#contactForm .input-group:has(.is-invalid) .input-group-text {
    border-color: #dc3545 !important;
}

#contactForm .invalid-feedback {
    display: none;
    color: #dc3545;
    font-size: 0.8rem;
    margin-top: 4px;
    font-weight: 500;
}

#contactForm .invalid-feedback.show {
    display: block;
    animation: contact-fade-in 0.3s ease;
}

@keyframes contact-shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-5px); }
    40% { transform: translateX(5px); }
    60% { transform: translateX(-3px); }
    80% { transform: translateX(3px); }
}

@keyframes contact-fade-in {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
function ctSetError(fieldName, message) {
    const form = document.getElementById('contactForm');
    if (!form) return;
    const field = form.querySelector('[name="' + fieldName + '"]');
    if (!field) return;
    field.classList.add('is-invalid');
    const wrapper = field.closest('.col-md-6, .col-12');
    if (!wrapper) return;
    let fb = wrapper.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        wrapper.appendChild(fb);
    }
    fb.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>' + message;
    fb.classList.add('show');
}

function ctClearError(field) {
    field.classList.remove('is-invalid');
    const wrapper = field.closest('.col-md-6, .col-12');
    if (!wrapper) return;
    const fb = wrapper.querySelector('.invalid-feedback');
    if (fb) fb.classList.remove('show');
}

function ctClearAll() {
    const form = document.getElementById('contactForm');
    if (!form) return;
    form.querySelectorAll('.is-invalid').forEach(f => f.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach(f => f.classList.remove('show'));
}

(function () {
    const form = document.getElementById('contactForm');
    if (!form) return;
    form.querySelectorAll('.form-control, .form-select').forEach(field => {
        field.addEventListener('input', function () { ctClearError(this); });
        field.addEventListener('change', function () { ctClearError(this); });
    });
})();

function validateContactForm() {
    const form = document.getElementById('contactForm');
    let isValid = true;
    let firstErr = null;
    ctClearAll();

    const name = form.querySelector('[name="name"]');
    const nameVal = name ? name.value.trim() : '';
    const nameRegex = /^[\p{L}\s.\-]+$/u;
    if (!nameVal) {
        ctSetError('name', 'Vui lòng nhập họ tên');
        isValid = false; if (!firstErr) firstErr = name;
    } else if (nameVal.length < 2) {
        ctSetError('name', 'Họ tên phải có ít nhất 2 ký tự');
        isValid = false; if (!firstErr) firstErr = name;
    } else if (!nameRegex.test(nameVal)) {
        ctSetError('name', 'Họ tên không được chứa ký tự đặc biệt');
        isValid = false; if (!firstErr) firstErr = name;
    }

    const phone = form.querySelector('[name="phone"]');
    const phoneVal = phone ? phone.value.trim().replace(/\s+/g, '') : '';
    if (!phoneVal) {
        ctSetError('phone', 'Vui lòng nhập số điện thoại');
        isValid = false; if (!firstErr) firstErr = phone;
    } else if (!/^(0|\+84)[0-9]{9,10}$/.test(phoneVal)) {
        ctSetError('phone', 'Số điện thoại không hợp lệ (VD: 0362360364)');
        isValid = false; if (!firstErr) firstErr = phone;
    }

    const email = form.querySelector('[name="email"]');
    const emailVal = email ? email.value.trim() : '';
    if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
        ctSetError('email', 'Email không hợp lệ');
        isValid = false; if (!firstErr) firstErr = email;
    }

    const message = form.querySelector('[name="message"]');
    const msgVal = message ? message.value.trim() : '';
    const msgRegex = /^[\p{L}0-9\s.,!?;:\/\-()@"'&]+$/u;
    if (msgVal && !msgRegex.test(msgVal)) {
        ctSetError('message', 'Nội dung chứa ký tự không hợp lệ');
        isValid = false; if (!firstErr) firstErr = message;
    } else if (msgVal.length > 1000) {
        ctSetError('message', 'Nội dung không được vượt quá 1000 ký tự');
        isValid = false; if (!firstErr) firstErr = message;
    }

    if (firstErr) firstErr.focus();
    return isValid;
}

document.getElementById('contactForm').addEventListener('submit', function (e) {
    e.preventDefault();

    if (!validateContactForm()) return;

    const btn = document.getElementById('contactSubmitBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang gửi...';
    btn.disabled = true;

    fetch(this.action, {
        method: 'POST',
        body: new FormData(this)
    })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                ctClearAll();
                if (typeof showToast === 'function') {
                    showToast('Gửi thành công!', 'Chúng tôi sẽ liên hệ bạn sớm nhất.', 'success');
                } else {
                    alert('Gửi thành công! Chúng tôi sẽ liên hệ bạn sớm nhất.');
                }
                this.reset();
            } else {
                if (data.field) {
                    ctSetError(data.field, data.message);
                } else if (typeof showToast === 'function') {
                    showToast('Lỗi', data.message || 'Có lỗi xảy ra, vui lòng thử lại.', 'error');
                } else {
                    alert(data.message || 'Có lỗi xảy ra, vui lòng thử lại.');
                }
            }
        })
        .catch(() => {
            if (typeof showToast === 'function') {
                showToast('Lỗi kết nối', 'Vui lòng thử lại.', 'error');
            } else {
                alert('Lỗi kết nối. Vui lòng thử lại.');
            }
        })
        .finally(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
});
</script>

<?php require_once 'includes/footer.php'; ?>
