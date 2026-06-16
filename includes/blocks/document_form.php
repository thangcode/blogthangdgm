<?php
// includes/blocks/document_form.php — Form nhận tài liệu của bài viết (gửi file qua email).
// Yêu cầu biến $post (có id, document_name).
if (empty($post['document_path'])) return;
$doc_label = $post['document_name'] ?? 'tài liệu';
?>
<section class="document-form" id="documentForm" aria-labelledby="docFormTitle">
    <div class="document-form__inner">
        <div class="document-form__icon"><i class="bi bi-file-earmark-arrow-down-fill"></i></div>
        <div class="document-form__head">
            <h3 id="docFormTitle">Nhận tài liệu miễn phí</h3>
            <p>Nhập họ tên &amp; email, hệ thống sẽ gửi <strong><?php echo e($doc_label); ?></strong> về hộp thư của bạn ngay.</p>
        </div>
        <form id="docRequestForm" class="document-form__form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
            <input type="hidden" name="form_ts" value="<?php echo time(); ?>">
            <input type="hidden" name="source_url" value="<?php echo e(postUrl($post['slug'], true)); ?>">
            <!-- honeypot -->
            <div class="d-none" aria-hidden="true">
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>
            <div class="document-form__row">
                <input type="text" name="fullname" class="form-control" placeholder="Họ và tên" required maxlength="150">
                <input type="email" name="email" class="form-control" placeholder="Email nhận tài liệu *" required maxlength="191">
                <button type="submit" class="btn btn-primary" id="docSubmitBtn">
                    <i class="bi bi-send-fill me-1"></i> Nhận ngay
                </button>
            </div>
            <div class="document-form__msg" id="docFormMsg" role="status" aria-live="polite"></div>
        </form>
    </div>
</section>

<script>
(function () {
    var form = document.getElementById('docRequestForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('docSubmitBtn');
        var msg = document.getElementById('docFormMsg');
        msg.className = 'document-form__msg';
        msg.textContent = '';
        var email = form.email.value.trim();
        if (!form.fullname.value.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
            msg.classList.add('is-error');
            msg.textContent = 'Vui lòng nhập họ tên và email hợp lệ.';
            return;
        }
        btn.disabled = true;
        var old = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Đang gửi...';
        fetch((window.BASE_URL || '/') + 'api/request-document.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.success) {
                msg.classList.add('is-success');
                msg.textContent = d.message || 'Đã gửi! Vui lòng kiểm tra email của bạn.';
                form.reset();
            } else {
                msg.classList.add('is-error');
                msg.textContent = (d && d.message) ? d.message : 'Có lỗi xảy ra, vui lòng thử lại.';
            }
        })
        .catch(function () {
            msg.classList.add('is-error');
            msg.textContent = 'Lỗi kết nối, vui lòng thử lại.';
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = old;
        });
    });
})();
</script>
