/*!
 * password-toggle.js — Tự thêm nút hiện/ẩn (con mắt) cho mọi input mật khẩu.
 * Dùng SVG nội tuyến nên không phụ thuộc font icon. An toàn với input-group sẵn có.
 */
(function () {
    var EYE = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/></svg>';
    var EYE_SLASH = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="m10.79 12.912-1.614-1.615a3.5 3.5 0 0 1-4.474-4.474l-2.06-2.06C.938 6.278 0 8 0 8s3 5.5 8 5.5a7 7 0 0 0 2.79-.588M5.21 3.088A7 7 0 0 1 8 2.5c5 0 8 5.5 8 5.5s-.939 1.721-2.641 3.238l-2.062-2.062a3.5 3.5 0 0 0-4.474-4.474z"/><path d="M5.525 7.646a2.5 2.5 0 0 0 2.829 2.829zm4.95.708-2.829-2.83a2.5 2.5 0 0 1 2.829 2.829zm3.171 6-12-12 .708-.708 12 12z"/></svg>';

    function addToggle(input) {
        if (!input || input.dataset.pwToggle === '1') return;
        // Bỏ qua input đã nằm trong input-group (thường đã có nút hiện/ẩn riêng).
        if (input.closest('.input-group')) return;
        input.dataset.pwToggle = '1';

        var wrap = document.createElement('div');
        wrap.className = 'pw-toggle-wrap';
        wrap.style.position = 'relative';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        // Chừa chỗ cho nút để không đè lên chữ.
        input.style.paddingRight = '2.6rem';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle-btn';
        btn.tabIndex = -1;
        btn.setAttribute('aria-label', 'Hiện/ẩn mật khẩu');
        btn.title = 'Hiện/ẩn mật khẩu';
        btn.innerHTML = EYE;
        btn.style.cssText = 'position:absolute;top:0;right:0;height:100%;width:2.6rem;border:0;background:transparent;'
            + 'padding:0;margin:0;cursor:pointer;color:#6b7280;display:flex;align-items:center;justify-content:center;z-index:3;';

        btn.addEventListener('click', function () {
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            btn.innerHTML = reveal ? EYE_SLASH : EYE;
            btn.style.color = reveal ? '#4f46e5' : '#6b7280';
        });

        wrap.appendChild(btn);
    }

    function init() {
        document.querySelectorAll('input[type="password"]').forEach(addToggle);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
