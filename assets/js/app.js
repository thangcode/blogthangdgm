/**
 * FPTStore — Core Application JS
 * Toast notifications, preloader, dropdown submenus, smooth scroll,
 * floating contact, back-to-top button.
 */

/* ===== Toast Notifications ===== */
function showToast(title, message, type = 'success', duration = 5000) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `custom-toast ${type}`;

    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';

    const iconWrap = document.createElement('div');
    iconWrap.className = 'toast-icon';
    const iconEl = document.createElement('i');
    iconEl.className = `bi ${icon}`;
    iconWrap.appendChild(iconEl);

    const content = document.createElement('div');
    content.className = 'toast-content';
    const titleEl = document.createElement('div');
    titleEl.className = 'toast-title';
    titleEl.textContent = String(title || '');
    const messageEl = document.createElement('div');
    messageEl.className = 'toast-message';
    messageEl.textContent = String(message || '');
    content.appendChild(titleEl);
    content.appendChild(messageEl);

    const progress = document.createElement('div');
    progress.className = 'toast-progress';
    const progressBar = document.createElement('div');
    progressBar.className = 'toast-progress-bar';
    progressBar.style.animation = `progress ${duration}ms linear forwards`;
    progress.appendChild(progressBar);

    toast.appendChild(iconWrap);
    toast.appendChild(content);
    toast.appendChild(progress);

    container.appendChild(toast);

    setTimeout(() => toast.classList.add('show'), 100);

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 600);
    }, duration);
}

/* ===== Preloader ===== */
document.addEventListener('DOMContentLoaded', function () {
    const preloader = document.getElementById('preloader');
    const loaderBar = document.querySelector('.loader-bar');
    const wrapper = document.getElementById('page-wrapper');

    if (!preloader) {
        if (wrapper) {
            wrapper.style.transform = 'none';
            wrapper.style.opacity = '1';
            wrapper.classList.remove('content-hidden', 'content-visible');
        }
        return;
    }

    if (loaderBar) loaderBar.style.width = '100%';

    preloader.classList.add('fade-out');

    if (wrapper) {
        wrapper.classList.add('content-visible');

        setTimeout(() => {
            wrapper.style.transform = 'none';
            wrapper.style.opacity = '1';
            wrapper.classList.remove('content-hidden', 'content-visible');
        }, 0);
    }

    preloader.style.display = 'none';
});

/* ===== Blur-Up Lazy Image Loader ===== */
(function () {
    function loadImg(img) {
        const src = img.dataset.src;
        if (!src) return;
        const tmp = new Image();
        tmp.onload = function () {
            img.src = src;
            img.classList.add('lazy-loaded');
        };
        tmp.src = src;
    }

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadImg(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '150px 0px' });

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('img.lazy-img[data-src]').forEach(function (img) {
                observer.observe(img);
            });
        });
    } else {
        // Fallback for old browsers
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('img.lazy-img[data-src]').forEach(loadImg);
        });
    }
}());

/* ===== Dropdown Submenus ===== */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.dropdown-menu .dropdown-toggle').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            const mobileView = window.innerWidth < 992;
            if (!mobileView) {
                event.preventDefault();
            }

            const nextMenu = this.nextElementSibling;
            if (!nextMenu || !nextMenu.classList.contains('dropdown-menu')) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const siblingMenus = this.closest('.dropdown-menu').querySelectorAll(':scope > .dropdown-submenu > .dropdown-menu.show');
            siblingMenus.forEach(function (menu) {
                if (menu !== nextMenu) {
                    menu.classList.remove('show');
                }
            });

            nextMenu.classList.toggle('show');
        });
    });

    document.querySelectorAll('.dropdown').forEach(function (dropdown) {
        dropdown.addEventListener('hidden.bs.dropdown', function () {
            this.querySelectorAll('.dropdown-menu.show').forEach(function (submenu) {
                submenu.classList.remove('show');
            });
        });
    });
});

/* ===== Smooth Scroll ===== */
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (!href || href === '#') return;
        const target = document.querySelector(href);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth' });
    });
});

/* ===== Floating Contact Menu Toggle ===== */
const CONTACT_ANIMATION_DELAY = 55;

function toggleContactMenu(forceOpen = null) {
    const menu = document.getElementById('contactOptions');
    const toggleBtn = document.querySelector('.contact-toggle');
    if (!menu || !toggleBtn) return;

    const next = forceOpen === null ? !menu.classList.contains('active') : forceOpen;
    menu.classList.toggle('active', next);
    toggleBtn.classList.toggle('open', next);

    if (next) {
        Array.from(menu.children).forEach((btn, index) => {
            btn.style.setProperty('--btn-delay', `${(index + 1) * CONTACT_ANIMATION_DELAY}ms`);
        });
    }
}

document.addEventListener('click', function (e) {
    if (e.target.closest('.floating-contact')) return;
    const menu = document.getElementById('contactOptions');
    if (!menu) return;
    if (!menu.classList.contains('active')) return;

    menu.classList.remove('active');
    const toggleBtn = document.querySelector('.contact-toggle');
    if (toggleBtn) {
        toggleBtn.classList.remove('open');
    }
});

/* ===== Back to Top ===== */
function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.addEventListener('DOMContentLoaded', function () {
    const backToTop = document.getElementById('backToTop');
    if (!backToTop) return;

    window.addEventListener('scroll', function () {
        if (window.scrollY > 300) {
            backToTop.classList.add('show');
        } else {
            backToTop.classList.remove('show');
        }
    });
});

/* ===== CSRF token hydration =====
 * Khi Page Cache bật, HTML (dùng chung) KHÔNG chứa token thật của phiên hiện tại.
 * Trang đánh dấu meta[name="csrf-token"] rỗng + data-csrf-hydrate="1". Ở đây ta gọi
 * api/csrf-token.php (cùng cookie phiên) để lấy token đúng rồi điền vào meta + mọi input ẩn.
 * Khi cache tắt: meta đã có token thật -> bỏ qua, không phát sinh request thừa.
 */
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;

    var needHydrate = meta.getAttribute('data-csrf-hydrate') === '1'
        || !(meta.getAttribute('content') || '').trim();
    if (!needHydrate) {
        window.CSRF_TOKEN = (meta.getAttribute('content') || '').trim();
        return;
    }

    var base = window.BASE_URL || '/';
    fetch(base + 'api/csrf-token.php', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var token = (data && data.token) ? String(data.token) : '';
            if (!token) return;
            meta.setAttribute('content', token);
            document.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
                input.value = token;
            });
            window.CSRF_TOKEN = token;
            document.dispatchEvent(new CustomEvent('csrf:ready', { detail: { token: token } }));
        })
        .catch(function () { /* im lặng: CSRF mềm + tracking vẫn hoạt động */ });
})();
