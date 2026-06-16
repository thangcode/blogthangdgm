/**
 * affiliate-track.js
 * Đo lường click nút "Mua ngay" mà KHÔNG cản trở điều hướng tới Shopee.
 * - Link giữ nguyên href trỏ thẳng sang Shopee (target="_blank") -> cookie affiliate được bảo toàn.
 * - Khi click, bắn navigator.sendBeacon() về api/track-click.php (fire-and-forget).
 * - Không gọi preventDefault, không chặn, không delay điều hướng.
 */
(function () {
    'use strict';

    var ENDPOINT = (window.BASE_URL || '/') + 'api/track-click.php';
    var AD_ENDPOINT = (window.BASE_URL || '/') + 'api/sp-hit.php';
    var VIEW_ENDPOINT = (window.BASE_URL || '/') + 'api/track-view.php';

    // Đọc token tại thời điểm bắn (không cache lúc load): khi Page Cache bật, token được
    // app.js nạp bất đồng bộ sau khi trang hiển thị, nên không thể cache sẵn ở đây.
    function getCsrf() {
        return (window.CSRF_TOKEN || (document.querySelector('meta[name="csrf-token"]') || {}).content || '').trim();
    }

    function beacon(endpoint, params) {
        var CSRF = getCsrf();
        if (CSRF) {
            params += '&csrf_token=' + encodeURIComponent(CSRF);
        }
        // Ưu tiên sendBeacon (đáng tin cậy nhất, gửi được kể cả khi trang unload).
        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([params], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
                if (navigator.sendBeacon(endpoint, blob)) {
                    return;
                }
            }
        } catch (e) { /* rơi xuống fetch */ }

        // Dự phòng: fetch keepalive (không chờ kết quả).
        try {
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params,
                keepalive: true,
                credentials: 'same-origin'
            });
        } catch (e) { /* bỏ qua, không ảnh hưởng người dùng */ }
    }

    function sendClick(productId) {
        if (!productId) return;
        beacon(ENDPOINT, 'product_id=' + encodeURIComponent(productId));
    }

    function sendAdClick(adId) {
        if (!adId) return;
        beacon(AD_ENDPOINT, 'id=' + encodeURIComponent(adId));
    }

    function handle(e) {
        var target = e.target;
        if (!target || !target.closest) return;

        // 1) Nút "Mua ngay" affiliate (data-aff-id)
        var el = target.closest('[data-aff-id]');
        if (el) {
            var id = el.getAttribute('data-aff-id');
            if (id) {
                var now = Date.now();
                var last = parseInt(el.getAttribute('data-aff-ts') || '0', 10);
                if (now - last >= 1000) {
                    el.setAttribute('data-aff-ts', String(now));
                    sendClick(id);
                }
            }
        }

        // 2) Khối quảng cáo (data-spot-id)
        var adEl = target.closest('[data-spot-id]');
        if (adEl) {
            var adId = adEl.getAttribute('data-spot-id');
            if (adId) {
                var now2 = Date.now();
                var last2 = parseInt(adEl.getAttribute('data-spot-ts') || '0', 10);
                if (now2 - last2 >= 1000) {
                    adEl.setAttribute('data-spot-ts', String(now2));
                    sendAdClick(adId);
                }
            }
        }
        // KHÔNG preventDefault -> trình duyệt vẫn mở link như bình thường.
    }

    // click: chuột trái + bàn phím. auxclick: chuột giữa (mở tab mới).
    document.addEventListener('click', handle, true);
    document.addEventListener('auxclick', handle, true);

    // ─── Đếm lượt xem sản phẩm (bất đồng bộ) ────────────────────────────────
    // Bắn 1 lần khi trang sản phẩm tải xong. product.php đặt window.PRODUCT_VIEW_ID.
    // Đếm được trên cả cache HIT lẫn MISS (vì chạy ở client, không phụ thuộc server render).
    function sendView() {
        var pid = window.PRODUCT_VIEW_ID;
        if (!pid) return;
        beacon(VIEW_ENDPOINT, 'product_id=' + encodeURIComponent(pid));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sendView);
    } else {
        sendView();
    }
})();
