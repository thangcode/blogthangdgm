<?php
/**
 * includes/widgets.php — Registry + render cho hệ thống widget sidebar.
 *
 * Nguồn DUY NHẤT định nghĩa các loại widget (nhãn, icon, trường settings) dùng chung
 * cho cả admin (sinh form) lẫn frontend (render). Thêm loại widget mới = thêm 1 entry
 * vào widget_registry() + 1 case trong widget_render_body().
 */

/**
 * Định nghĩa các loại widget.
 *  - label/icon: hiển thị ở admin.
 *  - desc: mô tả ngắn ở palette.
 *  - fields: các trường cấu hình (ngoài 'title' luôn có sẵn).
 *      type: text | number | textarea | url
 */
function widget_registry(): array
{
    return [
        'posts' => [
            'label' => 'Danh sách bài viết',
            'icon'  => 'bi-card-list',
            'desc'  => 'Bài mới / xem nhiều / theo danh mục — tùy chỉnh',
            'fields' => [
                'source' => [
                    'label' => 'Nguồn bài', 'type' => 'select', 'default' => 'all',
                    'options' => ['all' => 'Tất cả bài viết', 'category' => 'Theo danh mục'],
                ],
                'category_id' => [
                    'label' => 'Danh mục (khi chọn "Theo danh mục")', 'type' => 'category', 'default' => 0,
                ],
                'sort' => [
                    'label' => 'Sắp xếp', 'type' => 'select', 'default' => 'newest',
                    'options' => ['newest' => 'Mới nhất', 'views' => 'Xem nhiều nhất', 'oldest' => 'Cũ nhất'],
                ],
                'limit' => ['label' => 'Số bài', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 20],
                'show_thumb' => ['label' => 'Hiện ảnh thu nhỏ', 'type' => 'checkbox', 'default' => 1],
            ],
        ],
        'categories' => [
            'label' => 'Danh mục',
            'icon'  => 'bi-folder',
            'desc'  => 'Danh sách chủ đề kèm số bài',
            'fields' => [
                'limit' => ['label' => 'Số danh mục', 'type' => 'number', 'default' => 8, 'min' => 1, 'max' => 50],
            ],
        ],
        'tags' => [
            'label' => 'Tag phổ biến',
            'icon'  => 'bi-tags',
            'desc'  => 'Đám mây tag dùng nhiều nhất',
            'fields' => [
                'limit' => ['label' => 'Số tag', 'type' => 'number', 'default' => 20, 'min' => 1, 'max' => 100],
            ],
        ],
        'html' => [
            'label' => 'HTML / Text tùy biến',
            'icon'  => 'bi-code-square',
            'desc'  => 'Nội dung HTML tự do (banner, lời mời...)',
            'fields' => [
                'content' => ['label' => 'Nội dung HTML', 'type' => 'textarea', 'default' => ''],
            ],
        ],
        'search' => [
            'label' => 'Ô tìm kiếm',
            'icon'  => 'bi-search',
            'desc'  => 'Khung tìm kiếm bài viết',
            'fields' => [
                'placeholder' => ['label' => 'Gợi ý trong ô', 'type' => 'text', 'default' => 'Tìm bài viết...'],
            ],
        ],
        'social' => [
            'label' => 'Mạng xã hội',
            'icon'  => 'bi-share',
            'desc'  => 'Nút liên kết các kênh MXH',
            'fields' => [
                'facebook'  => ['label' => 'Facebook URL', 'type' => 'url', 'default' => ''],
                'youtube'   => ['label' => 'YouTube URL', 'type' => 'url', 'default' => ''],
                'tiktok'    => ['label' => 'TikTok URL', 'type' => 'url', 'default' => ''],
                'instagram' => ['label' => 'Instagram URL', 'type' => 'url', 'default' => ''],
                'telegram'  => ['label' => 'Telegram URL', 'type' => 'url', 'default' => ''],
            ],
        ],
    ];
}

/** Lấy định nghĩa 1 loại widget (hoặc null). */
function widget_def(string $type): ?array
{
    return widget_registry()[$type] ?? null;
}

/** Nhãn hiển thị cho 1 loại widget. */
function widget_type_label(string $type): string
{
    return widget_registry()[$type]['label'] ?? $type;
}

/** Decode settings JSON -> mảng. */
function widget_settings(array $w): array
{
    $s = json_decode((string) ($w['settings'] ?? ''), true);
    return is_array($s) ? $s : [];
}

/** Lấy 1 giá trị setting kèm default từ registry. */
function widget_get(array $settings, string $type, string $key, $fallback = '')
{
    if (array_key_exists($key, $settings) && $settings[$key] !== '') {
        return $settings[$key];
    }
    $def = widget_def($type);
    return $def['fields'][$key]['default'] ?? $fallback;
}

/**
 * Render 1 widget hoàn chỉnh (khung .sidebar-widget + tiêu đề + body).
 * Trả về '' nếu loại không hợp lệ hoặc body rỗng.
 */
function widget_render(PDO $pdo, array $w): string
{
    $type = (string) ($w['type'] ?? '');
    if (widget_def($type) === null) return '';

    $settings = widget_settings($w);
    $body = widget_render_body($pdo, $type, $settings, $w);
    if (trim($body) === '') return '';

    $title = trim((string) ($w['title'] ?? ''));
    ob_start(); ?>
    <div class="sidebar-widget sidebar-widget--<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($title !== ''): ?>
            <h3 class="sidebar-widget__title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
        <?php endif; ?>
        <?php echo $body; ?>
    </div>
    <?php
    return ob_get_clean();
}

/** Render phần thân (không khung) theo loại. */
function widget_render_body(PDO $pdo, string $type, array $settings, array $w): string
{
    switch ($type) {
        case 'posts':
            $limit = (int) widget_get($settings, $type, 'limit', 5);
            $limit = max(1, min(20, $limit));
            $source = (string) widget_get($settings, $type, 'source', 'all');
            $sort   = (string) widget_get($settings, $type, 'sort', 'newest');
            $showThumb = (int) widget_get($settings, $type, 'show_thumb', 1) === 1;
            $catId  = (int) widget_get($settings, $type, 'category_id', 0);

            $orderMap = [
                'views'  => 'p.views DESC, p.created_at DESC',
                'oldest' => 'p.created_at ASC',
                'newest' => 'p.created_at DESC',
            ];
            $order = $orderMap[$sort] ?? $orderMap['newest'];

            try {
                if ($source === 'category' && $catId > 0) {
                    $st = $pdo->prepare("SELECT p.title, p.slug, p.thumbnail, p.thumbnail_alt, p.created_at
                                         FROM posts p
                                         JOIN post_categories pc ON pc.post_id = p.id
                                         WHERE p.status = 1 AND pc.category_id = ?
                                         ORDER BY $order LIMIT $limit");
                    $st->execute([$catId]);
                } else {
                    $st = $pdo->prepare("SELECT p.title, p.slug, p.thumbnail, p.thumbnail_alt, p.created_at
                                         FROM posts p WHERE p.status = 1 ORDER BY $order LIMIT $limit");
                    $st->execute();
                }
                $rows = $st->fetchAll();
            } catch (Throwable $e) { $rows = []; }
            if (empty($rows)) return '';

            ob_start(); ?>
            <ul class="wp-list <?php echo $showThumb ? 'wp-list--thumb' : 'wp-list--plain'; ?>">
                <?php foreach ($rows as $p): ?>
                    <li class="wp-item">
                        <a href="<?php echo postUrl($p['slug']); ?>" class="wp-item__link">
                            <?php if ($showThumb): ?>
                                <span class="wp-item__thumb">
                                    <img src="<?php echo e(get_image_url($p['thumbnail'] ?? '', 'news')); ?>"
                                         alt="<?php echo e($p['thumbnail_alt'] ?? $p['title']); ?>" loading="lazy" decoding="async">
                                </span>
                            <?php endif; ?>
                            <span class="wp-item__body">
                                <span class="wp-item__title"><?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (!empty($p['created_at'])): ?>
                                    <span class="wp-item__date"><i class="bi bi-clock"></i> <?php echo date('d/m/Y', strtotime($p['created_at'])); ?></span>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php return ob_get_clean();

        case 'categories':
            $limit = (int) widget_get($settings, $type, 'limit', 8);
            $limit = max(1, min(50, $limit));
            try {
                $st = $pdo->query("SELECT c.name, c.slug, COUNT(pc.post_id) n
                                   FROM categories c
                                   JOIN post_categories pc ON pc.category_id = c.id
                                   JOIN posts p ON p.id = pc.post_id AND p.status = 1
                                   WHERE c.status = 1
                                   GROUP BY c.id ORDER BY n DESC LIMIT $limit");
                $rows = $st->fetchAll();
            } catch (Throwable $e) { $rows = []; }
            if (empty($rows)) return '';
            ob_start(); ?>
            <ul class="cat-list">
                <?php foreach ($rows as $c): ?>
                    <li>
                        <a href="<?php echo categoryUrl($c['slug']); ?>"><?php echo htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <span class="cat-count"><?php echo (int) $c['n']; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php return ob_get_clean();

        case 'tags':
            $limit = (int) widget_get($settings, $type, 'limit', 20);
            $limit = max(1, min(100, $limit));
            try {
                $st = $pdo->query("SELECT t.name, t.slug, COUNT(pt.post_id) n
                                   FROM tags t
                                   JOIN post_tags pt ON pt.tag_id = t.id
                                   JOIN posts p ON p.id = pt.post_id AND p.status = 1
                                   GROUP BY t.id ORDER BY n DESC LIMIT $limit");
                $rows = $st->fetchAll();
            } catch (Throwable $e) { $rows = []; }
            if (empty($rows)) return '';
            ob_start(); ?>
            <div class="tag-cloud">
                <?php foreach ($rows as $t): ?>
                    <a href="<?php echo tagUrl($t['slug']); ?>" class="tag-chip"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endforeach; ?>
            </div>
            <?php return ob_get_clean();

        case 'html':
            // HTML thô do admin nhập (giống WordPress Text/HTML widget) — không escape.
            return trim((string) widget_get($settings, $type, 'content', ''));

        case 'search':
            $ph = (string) widget_get($settings, $type, 'placeholder', 'Tìm bài viết...');
            ob_start(); ?>
            <form class="sidebar-search" action="<?php echo BASE_URL; ?>search.php" method="get" role="search">
                <input type="search" name="q" class="form-control" placeholder="<?php echo htmlspecialchars($ph, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Tìm kiếm">
                <button type="submit" aria-label="Tìm"><i class="bi bi-search"></i></button>
            </form>
            <?php return ob_get_clean();

        case 'social':
            $nets = [
                'facebook'  => ['bi-facebook', 'Facebook'],
                'youtube'   => ['bi-youtube', 'YouTube'],
                'tiktok'    => ['bi-tiktok', 'TikTok'],
                'instagram' => ['bi-instagram', 'Instagram'],
                'telegram'  => ['bi-telegram', 'Telegram'],
            ];
            $items = [];
            foreach ($nets as $key => $meta) {
                $url = trim((string) widget_get($settings, $type, $key, ''));
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $items[] = [$url, $meta[0], $meta[1]];
                }
            }
            if (empty($items)) return '';
            ob_start(); ?>
            <div class="sidebar-social">
                <?php foreach ($items as $it): ?>
                    <a href="<?php echo htmlspecialchars($it[0], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="social-btn social-btn--<?php echo $it[2]; ?>" title="<?php echo $it[2]; ?>" aria-label="<?php echo $it[2]; ?>">
                        <i class="bi <?php echo $it[1]; ?>"></i>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php return ob_get_clean();
    }
    return '';
}

/**
 * Render toàn bộ sidebar (mọi widget active theo thứ tự). Trả '' nếu rỗng.
 */
function sidebar_render(PDO $pdo): string
{
    try {
        $rows = $pdo->query("SELECT * FROM widgets WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    } catch (Throwable $e) { $rows = []; }
    if (empty($rows)) return '';
    $html = '';
    foreach ($rows as $w) {
        $html .= widget_render($pdo, $w);
    }
    return $html;
}

/**
 * Giải quyết hiển thị sidebar theo cascade: mặc định tổng -> override bài/trang.
 *
 * @param string $mode     override: 'default' | 'show' | 'hide'
 * @param string $position override: 'default' | 'left' | 'right'
 * @return array ['enabled' => bool, 'position' => 'left'|'right']
 */
function sidebar_resolve(string $mode = 'default', string $position = 'default'): array
{
    $globalEnabled  = (string) get_setting('sidebar_enabled', '1') === '1';
    $globalPosition = get_setting('sidebar_position', 'right') === 'left' ? 'left' : 'right';

    switch ($mode) {
        case 'show': $enabled = true; break;
        case 'hide': $enabled = false; break;
        default:     $enabled = $globalEnabled;
    }

    $pos = in_array($position, ['left', 'right'], true) ? $position : $globalPosition;

    return ['enabled' => $enabled, 'position' => $pos];
}

/**
 * Đọc override sidebar của 1 trang file PHP (home/news/category/tag/about/contact/search).
 * Trả ['default','default'] nếu chưa cấu hình.
 */
function sidebar_page_override(PDO $pdo, string $pageKey): array
{
    try {
        $st = $pdo->prepare("SELECT sidebar_mode, sidebar_position FROM page_sidebar_settings WHERE page_key = ? LIMIT 1");
        $st->execute([$pageKey]);
        $row = $st->fetch();
    } catch (Throwable $e) { $row = null; }
    return [
        $row['sidebar_mode'] ?? 'default',
        $row['sidebar_position'] ?? 'default',
    ];
}
