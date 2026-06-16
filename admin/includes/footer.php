</div>
</div>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ========================================
    // GLOBAL LOADING EFFECT SYSTEM - DISABLED
    // ========================================
    function showLoader() { }
    function hideLoader() { }



    // ========================================
    // 5. GLOBAL POPUP (TOAST + CONFIRM)
    // ========================================
    const popupStyle = document.createElement('style');
    popupStyle.textContent = `
        .admin-toast-wrap {
            position: fixed;
            top: 6rem;
            right: 1.25rem;
            z-index: 1200;
            display: flex;
            flex-direction: column;
            gap: .75rem;
            max-width: min(460px, calc(100vw - 2rem));
        }
        .admin-toast {
            position: relative;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 16px;
            color: #fff;
            box-shadow: 0 14px 30px rgba(2, 8, 20, 0.22);
            overflow: hidden;
            min-width: 320px;
            backdrop-filter: blur(3px);
            animation: adminToastIn .24s ease-out;
        }
        .admin-toast .toast-body {
            padding: .95rem 1rem;
            display: grid;
            grid-template-columns: 30px 1fr 20px;
            gap: .65rem;
            align-items: start;
        }
        .admin-toast .toast-icon {
            width: 30px;
            height: 30px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            margin-top: .05rem;
        }
        .admin-toast .toast-message {
            line-height: 1.45;
            font-weight: 600;
            letter-spacing: .1px;
            word-break: break-word;
            padding-top: .1rem;
        }
        .admin-toast .btn-close {
            margin-top: .1rem;
            opacity: .72;
            transform: scale(.9);
            transition: opacity .15s ease;
        }
        .admin-toast .btn-close:hover {
            opacity: 1;
        }
        .admin-toast .toast-progress {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.18);
            transform-origin: left center;
            animation-name: adminToastProgress;
            animation-timing-function: linear;
            animation-fill-mode: forwards;
        }
        .admin-toast.success { background: linear-gradient(135deg, #0f8a4b, #0d6d3d); }
        .admin-toast.error { background: linear-gradient(135deg, #c73636, #9f2626); }
        .admin-toast.info { background: linear-gradient(135deg, #0d6efd, #0b57c7); }
        .admin-toast.warning { background: linear-gradient(135deg, #d7930a, #b37500); }
        @keyframes adminToastIn {
            from {
                opacity: 0;
                transform: translate3d(12px, -8px, 0) scale(.98);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0) scale(1);
            }
        }
        @keyframes adminToastProgress {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }
        @media (max-width: 576px) {
            .admin-toast-wrap {
                top: 6rem;
                right: .75rem;
                left: .75rem;
                max-width: none;
            }
            .admin-toast {
                min-width: auto;
            }
        }
    `;
    document.head.appendChild(popupStyle);

    let toastWrap = document.getElementById('adminToastWrap');
    if (!toastWrap) {
        toastWrap = document.createElement('div');
        toastWrap.id = 'adminToastWrap';
        toastWrap.className = 'admin-toast-wrap';
        document.body.appendChild(toastWrap);
    }

    function toastTypeClass(type) {
        if (type === 'danger') return 'error';
        if (type === 'success') return 'success';
        if (type === 'warning') return 'warning';
        return 'info';
    }

    function showPopup(message, type = 'info', delay = 3200) {
        if (!message) return;
        const level = toastTypeClass(type);
        const toastId = 'admin-toast-' + Date.now() + Math.floor(Math.random() * 9999);
        const iconByType = {
            success: 'check-circle-fill',
            error: 'x-octagon-fill',
            warning: 'exclamation-triangle-fill',
            info: 'info-circle-fill'
        };

        const toastEl = document.createElement('div');
        toastEl.id = toastId;
        toastEl.className = `toast admin-toast ${level}`;
        toastEl.role = 'alert';
        toastEl.ariaLive = 'assertive';
        toastEl.ariaAtomic = 'true';
        toastEl.innerHTML = `
            <div class="toast-body">
                <span class="toast-icon"><i class="bi bi-${iconByType[level]}"></i></span>
                <div class="toast-message"></div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-progress"></div>
        `;
        toastEl.querySelector('.toast-message').textContent = message;
        const progress = toastEl.querySelector('.toast-progress');
        if (progress) {
            progress.style.animationDuration = `${Math.max(800, delay)}ms`;
        }
        toastWrap.appendChild(toastEl);
        toastEl.classList.add('show');

        if (window.bootstrap && typeof bootstrap.Toast === 'function') {
            const toast = new bootstrap.Toast(toastEl, { delay: delay });
            toast.show();
            toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
            return;
        }

        // Fallback when Bootstrap Toast is not available for any reason.
        setTimeout(() => {
            toastEl.style.opacity = '0';
            toastEl.style.transform = 'translate3d(8px, -4px, 0) scale(0.98)';
            setTimeout(() => toastEl.remove(), 220);
        }, Math.max(800, delay));
    }

    function ensureConfirmModal() {
        let modalEl = document.getElementById('adminConfirmModal');
        if (!modalEl) {
            modalEl = document.createElement('div');
            modalEl.id = 'adminConfirmModal';
            modalEl.className = 'modal fade';
            modalEl.tabIndex = -1;
            modalEl.setAttribute('aria-hidden', 'true');
            modalEl.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg rounded-4">
                        <div class="modal-header border-0 pb-0">
                            <h5 class="modal-title" id="adminConfirmTitle">\u0058\u00e1c nh\u1eadn thao t\u00e1c</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body pt-2">
                            <p class="mb-0 text-muted" id="adminConfirmMessage">\u0042\u1ea1n c\u00f3 ch\u1eafc mu\u1ed1n ti\u1ebfp t\u1ee5c?</p>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">\u0048\u1ee7y</button>
                            <button type="button" class="btn btn-danger" id="adminConfirmAccept">\u0110\u1ed3ng \u00fd</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modalEl);
        }
        return modalEl;
    }

    function showConfirm(options = {}) {
        const modalEl = ensureConfirmModal();
        const titleEl = modalEl.querySelector('#adminConfirmTitle');
        const messageEl = modalEl.querySelector('#adminConfirmMessage');
        const acceptBtn = modalEl.querySelector('#adminConfirmAccept');
        const cancelBtn = modalEl.querySelector('.modal-footer [data-bs-dismiss="modal"]');

        cancelBtn.textContent = options.cancelText || '\u0048\u1ee7y';
        acceptBtn.textContent = options.confirmText || '\u0110\u1ed3ng \u00fd';
        acceptBtn.className = 'btn ' + (options.confirmClass || 'btn-danger');
        titleEl.textContent = options.title || '\u0058\u00e1c nh\u1eadn thao t\u00e1c';
        messageEl.textContent = options.message || '\u0042\u1ea1n c\u00f3 ch\u1eafc mu\u1ed1n ti\u1ebfp t\u1ee5c?';

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        return new Promise((resolve) => {
            let accepted = false;

            const onAccept = function () {
                accepted = true;
                modal.hide();
            };

            const onHidden = function () {
                acceptBtn.removeEventListener('click', onAccept);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve(accepted);
            };

            acceptBtn.addEventListener('click', onAccept);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    function hydrateAlertsToPopup() {
        const alerts = document.querySelectorAll('.main-content .alert');
        alerts.forEach((alert) => {
            if (alert.dataset.popupHydrated === '1') return;
            const text = (alert.textContent || '').trim();
            if (!text) return;

            // Only hydrate system flash messages.
            // Keep informational/warning blocks (often static content in tabs) in-place.
            let type = null;
            if (alert.classList.contains('alert-success')) type = 'success';
            else if (alert.classList.contains('alert-danger')) type = 'danger';

            if (!type) return;

            try {
                showPopup(text, type);
            } catch (err) {
                console.error('showPopup failed:', err);
            }
            alert.dataset.popupHydrated = '1';
            alert.remove();
        });
    }

    document.addEventListener('submit', async function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        if (form.dataset.confirmed === '1') {
            form.dataset.confirmed = '0';
            return;
        }

        const message = form.getAttribute('data-confirm');
        if (!message) return;

        event.preventDefault();
        const accepted = await showConfirm({
            title: form.getAttribute('data-confirm-title') || '\u0058\u00e1c nh\u1eadn thao t\u00e1c',
            message: message,
            confirmText: form.getAttribute('data-confirm-ok') || '\u0110\u1ed3ng \u00fd',
            cancelText: form.getAttribute('data-confirm-cancel') || '\u0048\u1ee7y',
            confirmClass: form.getAttribute('data-confirm-class') || 'btn-danger'
        });
        if (accepted) {
            form.dataset.confirmed = '1';
            form.submit();
        }
    }, true);

    document.addEventListener('click', async function (event) {
        const trigger = event.target.closest('[data-confirm-link]');
        if (!trigger) return;

        event.preventDefault();
        const message = trigger.getAttribute('data-confirm-link');
        const accepted = await showConfirm({
            title: trigger.getAttribute('data-confirm-title') || '\u0058\u00e1c nh\u1eadn thao t\u00e1c',
            message: message,
            confirmText: trigger.getAttribute('data-confirm-ok') || '\u0110\u1ed3ng \u00fd',
            cancelText: trigger.getAttribute('data-confirm-cancel') || '\u0048\u1ee7y',
            confirmClass: trigger.getAttribute('data-confirm-class') || 'btn-danger'
        });

        if (accepted) {
            const href = trigger.getAttribute('href');
            if (href) {
                window.location.href = href;
            }
        }
    });

    // ========================================
    // 6. GLOBAL HELPER FUNCTIONS
    // ========================================
    window.AdminLoader = {
        show: showLoader,
        hide: hideLoader
    };

    window.AdminPopup = {
        show: showPopup,
        success: (message, delay) => showPopup(message, 'success', delay),
        error: (message, delay) => showPopup(message, 'danger', delay),
        info: (message, delay) => showPopup(message, 'info', delay),
        warning: (message, delay) => showPopup(message, 'warning', delay),
        confirm: showConfirm
    };

    window.AdminSecurity = {
        csrfToken: function () {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? (meta.getAttribute('content') || '') : '';
        },
        applyCsrf: function (target) {
            const token = this.csrfToken();
            if (!token || !target) return target;

            if (target instanceof FormData) {
                if (!target.has('csrf_token')) {
                    target.append('csrf_token', token);
                }
                return target;
            }

            if (target instanceof URLSearchParams) {
                if (!target.has('csrf_token')) {
                    target.append('csrf_token', token);
                }
                return target;
            }

            return target;
        },
        headers: function (headers = {}) {
            return Object.assign({}, headers, {
                'X-CSRF-Token': this.csrfToken()
            });
        }
    };

    /**
     * Create slug from string
     * @param {string} str 
     * @returns {string}
     */
    function createSlug(str) {
        return str.toLowerCase().trim().normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\u0111/g, 'd')
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
    }

    hydrateAlertsToPopup();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hydrateAlertsToPopup, { once: true });
    }
    setTimeout(hydrateAlertsToPopup, 0);
</script>
<!-- Media Selector Modal -->
<?php include 'media-modal.php'; ?>
<script src="<?php echo BASE_URL; ?>assets/js/media-selector.js?v=<?php echo time(); ?>"></script>
</body>

</html>
