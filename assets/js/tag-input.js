/*!
 * tag-input.js — Ô nhập tag dạng chip (gõ từ khóa rồi Enter/dấu phẩy => tạo chip),
 * giống WordPress. Dùng chung cho mọi nơi nhập tag/từ khóa.
 *
 * Cách dùng:
 *   <div data-tag-input data-placeholder="Nhập tag rồi Enter...">
 *       <input type="hidden" name="tags" value="tag1, tag2">
 *   </div>
 * Script tự render chip + ô nhập, đồng bộ giá trị CSV vào input hidden khi submit.
 */
(function () {
    'use strict';

    function injectCss() {
        if (document.getElementById('tgi-style')) return;
        var css = ''
            + '.tgi-box{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 12px;'
            + 'border:1px solid #dee2e6;border-radius:.375rem;background:#fff;min-height:48px;cursor:text;'
            + 'transition:border-color .2s,box-shadow .2s}'
            + '.tgi-box.is-focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}'
            + '.tgi-chip{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#6366f1,#818cf8);'
            + 'color:#fff;border-radius:20px;padding:5px 13px 5px 15px;font-size:.8rem;font-weight:500;animation:tgiIn .18s ease}'
            + '@keyframes tgiIn{from{opacity:0;transform:scale(.8)}to{opacity:1;transform:scale(1)}}'
            + '.tgi-x{background:rgba(255,255,255,.3);border:none;border-radius:50%;width:16px;height:16px;'
            + 'display:flex;align-items:center;justify-content:center;font-size:.65rem;cursor:pointer;padding:0;color:#fff;line-height:1;transition:background .2s}'
            + '.tgi-x:hover{background:rgba(255,255,255,.55)}'
            + '.tgi-input{border:none;outline:none;flex:1;min-width:140px;font-size:.875rem;background:transparent;padding:2px 0}';
        var s = document.createElement('style');
        s.id = 'tgi-style';
        s.textContent = css;
        document.head.appendChild(s);
    }

    function initOne(box) {
        if (box.dataset.tgiReady === '1') return;
        var hidden = box.querySelector('input[type="hidden"]');
        if (!hidden) return;
        box.dataset.tgiReady = '1';

        var placeholder = box.getAttribute('data-placeholder') || 'Nhập rồi nhấn Enter...';
        var tags = (hidden.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);

        var wrap = document.createElement('div');
        wrap.className = 'tgi-box';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'tgi-input';
        input.placeholder = placeholder;
        input.autocomplete = 'off';
        box.appendChild(wrap);
        wrap.appendChild(input);

        function sync() { hidden.value = tags.join(', '); }

        function render() {
            // xóa chip cũ (giữ lại ô input)
            wrap.querySelectorAll('.tgi-chip').forEach(function (c) { c.remove(); });
            tags.forEach(function (tag, i) {
                var chip = document.createElement('span');
                chip.className = 'tgi-chip';
                chip.appendChild(document.createTextNode(tag));
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tgi-x';
                btn.title = 'Xóa';
                btn.textContent = '\u00d7';
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    tags.splice(i, 1);
                    render(); sync();
                });
                chip.appendChild(btn);
                wrap.insertBefore(chip, input);
            });
        }

        function add(val) {
            (val || '').split(',').forEach(function (raw) {
                var t = raw.trim();
                if (!t) return;
                var exists = tags.some(function (x) { return x.toLowerCase() === t.toLowerCase(); });
                if (!exists) tags.push(t);
            });
            input.value = '';
            render(); sync();
        }

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                add(input.value);
            } else if (e.key === 'Backspace' && input.value === '' && tags.length) {
                tags.pop();
                render(); sync();
            }
        });
        input.addEventListener('blur', function () { if (input.value.trim()) add(input.value); });
        input.addEventListener('focus', function () { wrap.classList.add('is-focus'); });
        input.addEventListener('blur', function () { wrap.classList.remove('is-focus'); });
        wrap.addEventListener('click', function () { input.focus(); });
        // submit an toàn: nếu còn chữ trong ô, thêm vào trước khi gửi
        if (box.closest('form')) {
            box.closest('form').addEventListener('submit', function () { if (input.value.trim()) add(input.value); });
        }

        render(); sync();

        // Cho phép code ngoài (vd AI) set danh sách tag động qua sự kiện 'tags:set'
        hidden.addEventListener('tags:set', function (e) {
            tags = (e.detail && e.detail.values ? e.detail.values : []).map(function (s) { return String(s).trim(); }).filter(Boolean);
            render(); sync();
        });
    }

    function initAll() {
        injectCss();
        document.querySelectorAll('[data-tag-input]').forEach(initOne);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    window.TagInput = { init: initAll };
})();
