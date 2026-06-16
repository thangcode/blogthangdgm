<?php
/**
 * includes/ad-banners.php
 * Hệ thống Banner Quảng cáo theo vị trí (ad slot) — độc lập Hero Slider.
 *
 * Cung cấp:
 *  - ad_banners_ensure_schema($pdo): tạo bảng ad_banners (idempotent).
 *  - ad_slots(): danh sách vị trí cố định.
 *  - get_active_ad_banners($pdo, $slot, $limit): banner đang hoạt động theo slot + lịch chạy.
 *  - render_ad_slot($pdo, $slot): HTML khối quảng cáo (rỗng nếu không có banner).
 *  - flush_ad_impressions($pdo): gộp 1 UPDATE tăng impressions cho id đã render.
 *  - ad_banner_status_label($banner): nhãn trạng thái thực tế (Đang chạy/Đã hẹn/Hết hạn/Tắt).
 */

if (!function_exists('ad_banners_ensure_schema')) {
    function ad_banners_ensure_schema(PDO $pdo): void
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `ad_banners` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title`             VARCHAR(255) NOT NULL DEFAULT '',
                `image_path`        VARCHAR(500) NOT NULL DEFAULT '',
                `mobile_image_path` VARCHAR(500) NOT NULL DEFAULT '',
                `link_url`          VARCHAR(1000) NOT NULL DEFAULT '',
                `link_follow`       TINYINT(1) NOT NULL DEFAULT 0,
                `banner_type`       VARCHAR(10) NOT NULL DEFAULT 'image',
                `html_content`      LONGTEXT NULL,
                `slot`              VARCHAR(40) NOT NULL DEFAULT '',
                `sort_order`        INT NOT NULL DEFAULT 0,
                `status`            TINYINT(1) NOT NULL DEFAULT 1,
                `start_at`          DATETIME NULL DEFAULT NULL,
                `end_at`            DATETIME NULL DEFAULT NULL,
                `impressions`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `clicks`            BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_slot_status` (`slot`, `status`),
                KEY `idx_schedule` (`start_at`, `end_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Tự vá schema cho bảng đã tồn tại từ trước (idempotent).
            if (!$pdo->query("SHOW COLUMNS FROM ad_banners LIKE 'link_follow'")->fetch()) {
                $pdo->exec("ALTER TABLE ad_banners ADD COLUMN `link_follow` TINYINT(1) NOT NULL DEFAULT 0 AFTER `link_url`");
            }
            if (!$pdo->query("SHOW COLUMNS FROM ad_banners LIKE 'banner_type'")->fetch()) {
                $pdo->exec("ALTER TABLE ad_banners ADD COLUMN `banner_type` VARCHAR(10) NOT NULL DEFAULT 'image' AFTER `link_follow`");
            }
            if (!$pdo->query("SHOW COLUMNS FROM ad_banners LIKE 'html_content'")->fetch()) {
                $pdo->exec("ALTER TABLE ad_banners ADD COLUMN `html_content` LONGTEXT NULL AFTER `banner_type`");
            }
        } catch (Throwable $e) {
            error_log('ad_banners_ensure_schema error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ad_slots')) {
    /**
     * Danh sách vị trí quảng cáo cố định.
     * 'multi' = true: slot cho phép nhiều banner xoay vòng (slider).
     */
    function ad_slots(): array
    {
        return [
            'home_top'              => ['label' => 'Trang chủ - Đầu trang', 'desc' => 'Dải banner ngang ngay dưới Hero trang chủ', 'multi' => false, 'size' => '1200 × 200px (tỉ lệ ~6:1, ngang)'],
            'home_inline'           => ['label' => 'Trang chủ - Giữa các block', 'desc' => 'Chèn giữa các block trang chủ', 'multi' => true, 'size' => '1200 × 250px (ngang)'],
            'home_sidebar'          => ['label' => 'Trang chủ - Cột bên', 'desc' => 'Cột sidebar trang chủ (khi bật sidebar)', 'multi' => true, 'size' => '300 × 250px hoặc 300 × 600px (dọc/vuông)'],
            'product_sidebar'       => ['label' => 'Sản phẩm - Cột bên', 'desc' => 'Cột phải trang chi tiết sản phẩm', 'multi' => true, 'size' => '300 × 600px hoặc 300 × 250px (dọc/vuông)'],
            'product_below_content' => ['label' => 'Sản phẩm - Dưới nội dung', 'desc' => 'Ngay dưới phần mô tả chi tiết sản phẩm', 'multi' => false, 'size' => '728 × 200px (ngang)'],
            'post_above_content'    => ['label' => 'Bài viết - Trên nội dung', 'desc' => 'Hiển thị phía trên nội dung bài viết, sau phần tiêu đề/thumbnail', 'multi' => false, 'size' => '728 × 200px (ngang)'],
            'post_inline'           => ['label' => 'Bài viết - Trong bài', 'desc' => 'Chèn trong/đầu bài viết', 'multi' => false, 'size' => '728 × 200px (ngang)'],
            'post_below_content'    => ['label' => 'Bài viết - Dưới nội dung', 'desc' => 'Hiển thị ngay dưới nội dung chính của bài viết', 'multi' => false, 'size' => '728 × 200px (ngang)'],
            'post_sidebar'          => ['label' => 'Bài viết - Cột bên', 'desc' => 'Cột sidebar trang chi tiết bài viết', 'multi' => true, 'size' => '300 × 250px hoặc 300 × 600px (dọc/vuông) — WP cũ dùng 800×200'],
            'sticky_bottom'         => ['label' => 'Dính đáy màn hình', 'desc' => 'Dải quảng cáo dính đáy, có nút đóng', 'multi' => false, 'size' => '970 × 90px (ngang, mỏng) — mobile nên có ảnh 320 × 100px'],
        ];
    }
}

if (!function_exists('is_valid_ad_slot')) {
    function is_valid_ad_slot(string $slot): bool
    {
        return array_key_exists($slot, ad_slots());
    }
}

if (!function_exists('get_active_ad_banners')) {
    /**
     * Lấy banner đang hoạt động cho 1 slot (status=1 + trong lịch chạy), sắp theo sort_order.
     * @return array danh sách bản ghi banner
     */
    function get_active_ad_banners(PDO $pdo, string $slot, int $limit = 5): array
    {
        if ($slot === '') {
            return [];
        }
        $limit = max(1, min(20, $limit));
        // Dùng GIỜ PHP cho mốc so sánh (đồng nhất với lúc lưu start_at/end_at + nhãn ở admin),
        // tránh lệch khi MySQL chạy timezone khác PHP (vd hosting để MySQL UTC, PHP +7).
        $now = date('Y-m-d H:i:s');
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM ad_banners
                 WHERE slot = ? AND status = 1
                   AND (start_at IS NULL OR start_at <= ?)
                   AND (end_at   IS NULL OR end_at   >= ?)
                 ORDER BY sort_order ASC, id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([$slot, $now, $now]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Bảng có thể chưa tồn tại -> thử tạo rồi trả rỗng (fail an toàn).
            ad_banners_ensure_schema($pdo);
            return [];
        }
    }
}

if (!function_exists('register_ad_impression')) {
    /**
     * Ghi nhận id banner đã render để cuối request flush 1 lần.
     */
    function register_ad_impression(int $banner_id): void
    {
        if ($banner_id <= 0) {
            return;
        }
        if (!isset($GLOBALS['_ad_impression_ids']) || !is_array($GLOBALS['_ad_impression_ids'])) {
            $GLOBALS['_ad_impression_ids'] = [];
        }
        $GLOBALS['_ad_impression_ids'][$banner_id] = true;
    }
}

if (!function_exists('flush_ad_impressions')) {
    /**
     * Tăng impressions cho mọi banner đã render trong request (gộp 1 truy vấn).
     * Gọi 1 lần ở footer. Bot bị loại nếu có helper nhận diện.
     */
    function flush_ad_impressions(PDO $pdo): void
    {
        $ids = array_keys($GLOBALS['_ad_impression_ids'] ?? []);
        if (empty($ids)) {
            return;
        }
        // Không tính bot (nếu có helper).
        if (function_exists('traffic_is_bot') && traffic_is_bot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($ids)) {
            return;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE ad_banners SET impressions = impressions + 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
        } catch (Throwable $e) {
            error_log('flush_ad_impressions error: ' . $e->getMessage());
        }
        $GLOBALS['_ad_impression_ids'] = [];
    }
}

if (!function_exists('ad_banner_status_label')) {
    /**
     * Nhãn trạng thái thực tế của banner dựa trên status + lịch chạy.
     * @return array ['key' => 'running|scheduled|expired|off', 'label' => ..., 'class' => bootstrap class]
     */
    function ad_banner_status_label(array $banner): array
    {
        if (empty($banner['status'])) {
            return ['key' => 'off', 'label' => 'Đã tắt', 'class' => 'bg-secondary'];
        }
        $now = time();
        $start = !empty($banner['start_at']) ? strtotime((string) $banner['start_at']) : null;
        $end   = !empty($banner['end_at']) ? strtotime((string) $banner['end_at']) : null;
        if ($start !== null && $now < $start) {
            return ['key' => 'scheduled', 'label' => 'Đã hẹn', 'class' => 'bg-info text-dark'];
        }
        if ($end !== null && $now > $end) {
            return ['key' => 'expired', 'label' => 'Hết hạn', 'class' => 'bg-warning text-dark'];
        }
        return ['key' => 'running', 'label' => 'Đang chạy', 'class' => 'bg-success'];
    }
}

if (!function_exists('render_ad_slot')) {
    /**
     * Render HTML khối quảng cáo cho 1 slot. Trả '' nếu không có banner hoạt động.
     */
    function render_ad_slot(PDO $pdo, string $slot): string
    {
        if (!is_valid_ad_slot($slot)) {
            return '';
        }
        $slots = ad_slots();
        $multi = !empty($slots[$slot]['multi']);
        $banners = get_active_ad_banners($pdo, $slot, $multi ? 8 : 1);
        if (empty($banners)) {
            return '';
        }
        foreach ($banners as $b) {
            register_ad_impression((int) ($b['id'] ?? 0));
        }
        ob_start();
        $ad_slot = $slot;
        $ad_items = $banners;
        $ad_multi = $multi;
        include __DIR__ . '/blocks/ad_slot.php';
        return (string) ob_get_clean();
    }
}
