/**
 * FPTStore — Registration Modal
 * Modal open, form validation, AJAX submission, province/district cascade.
 */

/* ===== Open Modal ===== */
function openRegisterModal(productName, productSlug) {
    const nameEl = document.getElementById('registerProductName');
    if (nameEl) nameEl.textContent = productName || '';

    const slugEl = document.getElementById('productSlug');
    if (slugEl) slugEl.value = productSlug || '';

    clearAllValidation();

    const provSelect = document.getElementById('provinceSelect');
    if (provSelect && provSelect.options.length <= 1 && typeof initProvinceSelect === 'function') {
        initProvinceSelect('provinceSelect');
    }

    new bootstrap.Modal(document.getElementById('registerModal')).show();
}

/* ===== Validation Helpers ===== */
function setFieldError(fieldName, message) {
    const form = document.getElementById('registerForm');
    if (!form) return;
    const field = form.querySelector('[name="' + fieldName + '"]');
    if (!field) return;

    field.classList.add('is-invalid');
    field.classList.remove('is-valid');

    let feedback = field.parentElement.querySelector('.invalid-feedback');
    if (!feedback) {
        feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        field.parentElement.appendChild(feedback);
    }
    feedback.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>' + message;
    feedback.style.display = 'block';

    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function clearFieldError(field) {
    field.classList.remove('is-invalid');
    const feedback = field.parentElement.querySelector('.invalid-feedback');
    if (feedback) feedback.style.display = 'none';
}

function clearAllValidation() {
    const form = document.getElementById('registerForm');
    if (!form) return;
    form.querySelectorAll('.is-invalid').forEach(f => f.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach(f => f.style.display = 'none');
}

/* ===== Client-side Validation ===== */
function validateRegisterForm() {
    const form = document.getElementById('registerForm');
    let isValid = true;
    let firstError = null;

    clearAllValidation();

    // Fullname
    const fullname = form.querySelector('[name="fullname"]');
    const nameVal = fullname.value.trim();
    const nameRegex = /^[\p{L}\s.\-]+$/u;
    if (!nameVal) {
        setFieldError('fullname', 'Vui lòng nhập họ tên');
        isValid = false;
        if (!firstError) firstError = fullname;
    } else if (nameVal.length < 2) {
        setFieldError('fullname', 'Họ tên phải có ít nhất 2 ký tự');
        isValid = false;
        if (!firstError) firstError = fullname;
    } else if (!nameRegex.test(nameVal)) {
        setFieldError('fullname', 'Họ tên không được chứa ký tự đặc biệt (@#$!...)');
        isValid = false;
        if (!firstError) firstError = fullname;
    }

    // Phone
    const phone = form.querySelector('[name="phone"]');
    const phoneVal = phone.value.trim().replace(/\s+/g, '');
    if (!phoneVal) {
        setFieldError('phone', 'Vui lòng nhập số điện thoại');
        isValid = false;
        if (!firstError) firstError = phone;
    } else if (!/^(0|\+84)[0-9]{9,10}$/.test(phoneVal)) {
        setFieldError('phone', 'Số điện thoại không hợp lệ (VD: 0362360364)');
        isValid = false;
        if (!firstError) firstError = phone;
    }

    // Province
    const province = form.querySelector('[name="province"]');
    if (!province.value) {
        setFieldError('province', 'Vui lòng chọn tỉnh/thành phố');
        isValid = false;
        if (!firstError) firstError = province;
    }

    // District
    const district = form.querySelector('[name="district"]');
    if (!district.value) {
        setFieldError('district', 'Vui lòng chọn quận/huyện');
        isValid = false;
        if (!firstError) firstError = district;
    }

    // Address (optional)
    const address = form.querySelector('[name="address"]');
    const addrVal = address ? address.value.trim() : '';
    const addrRegex = /^[\p{L}0-9\s.,\/\-()]*$/u;
    if (addrVal && !addrRegex.test(addrVal)) {
        setFieldError('address', 'Địa chỉ không được chứa ký tự đặc biệt (@#$!...)');
        isValid = false;
        if (!firstError) firstError = address;
    }

    if (firstError) firstError.focus();
    return isValid;
}

/* ===== Form Init & Submission ===== */
document.addEventListener('DOMContentLoaded', function () {
    const provSelect = document.getElementById('provinceSelect');
    if (provSelect) {
        provSelect.addEventListener('change', function () {
            clearFieldError(this);
            if (typeof updateDistrictSelect === 'function') {
                updateDistrictSelect(this, 'districtSelect');
            }
        });
    }

    const regForm = document.getElementById('registerForm');
    if (regForm) {
        regForm.querySelectorAll('.form-control, .form-select').forEach(field => {
            field.addEventListener('input', function () { clearFieldError(this); });
            field.addEventListener('change', function () { clearFieldError(this); });
        });

        regForm.addEventListener('submit', function (e) {
            e.preventDefault();

            if (!validateRegisterForm()) return;

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang gửi...';

            fetch(this.action, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        clearAllValidation();
                        // GTM: registration event
                        const gtmEvent = regForm.dataset.gtmEvent;
                        if (gtmEvent) {
                            window.dataLayer = window.dataLayer || [];
                            window.dataLayer.push({
                                event: gtmEvent,
                                form_type: 'registration',
                                product_name: document.getElementById('registerProductName')?.textContent?.trim() || '',
                                page_url: window.location.href,
                                page_title: document.title
                            });
                        }
                        bootstrap.Modal.getInstance(document.getElementById('registerModal')).hide();
                        showToast('Đăng ký thành công', 'Chúng tôi sẽ liên hệ với quý khách trong thời gian sớm nhất.', 'success');
                        this.reset();
                    } else {
                        if (data.field) {
                            setFieldError(data.field, data.message);
                        } else {
                            showToast('Đăng ký thất bại', data.message || 'Vui lòng thử lại sau.', 'error');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Lỗi kết nối', 'Đã xảy ra lỗi trong quá trình gửi thông tin.', 'error');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
        });
    }
});
