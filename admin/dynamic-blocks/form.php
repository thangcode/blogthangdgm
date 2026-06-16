<?php
// admin/dynamic-blocks/form.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_admin_login();

$current_page = 'dynamic-blocks';

// Ensure table exists & migration
// Ensure table exists & migration
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `dynamic_blocks` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `block_key` VARCHAR(50) NOT NULL UNIQUE,
        `title` VARCHAR(255) NOT NULL,
        `subtitle` VARCHAR(255) DEFAULT NULL,
        `type` ENUM('products','news') NOT NULL DEFAULT 'products',
        `display_mode` ENUM('row','slide') NOT NULL DEFAULT 'row',
        `rows_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `items_per_row` TINYINT UNSIGNED NOT NULL DEFAULT 4,
        `items_count` INT UNSIGNED NOT NULL DEFAULT 8,
        `order_by` VARCHAR(50) NOT NULL DEFAULT 'newest',
        `category_id` VARCHAR(255) DEFAULT NULL,
        `layout_style` ENUM('simple','wave','gradient','glass','aurora','sunset','minimal','neon','editorial') NOT NULL DEFAULT 'simple',
        `wave_top_color` VARCHAR(20) DEFAULT '#f8f9fa',
        `wave_bottom_color` VARCHAR(20) DEFAULT '#ffffff',
        `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_status` (`status`),
        INDEX `idx_block_key` (`block_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure enum includes all supported layout styles
    try {
        $layoutType = $pdo->query("SHOW COLUMNS FROM `dynamic_blocks` LIKE 'layout_style'")->fetchColumn();
        if ($layoutType && strpos((string) $layoutType, 'editorial') === false) {
            $pdo->exec("ALTER TABLE `dynamic_blocks`
                MODIFY COLUMN `layout_style` ENUM('simple','wave','gradient','glass','aurora','sunset','minimal','neon','editorial') NOT NULL DEFAULT 'simple'");
        }
    } catch (PDOException $e) { /* ignore */ }

    // Migration: Add wave color columns if missing
    $stmt = $pdo->query("SHOW COLUMNS FROM `dynamic_blocks` LIKE 'wave_top_color'");
    if (!$stmt->fetch()) {
        try {
            $pdo->exec("ALTER TABLE `dynamic_blocks` 
                ADD COLUMN `wave_top_color` VARCHAR(20) DEFAULT '#f8f9fa' AFTER `layout_style`,
                ADD COLUMN `wave_bottom_color` VARCHAR(20) DEFAULT '#ffffff' AFTER `wave_top_color`
            ");
        } catch (PDOException $e) { /* ignore */ }
    }
    
    // Migration: If category_id is still INT, change it to VARCHAR
    $stmt = $pdo->query("SHOW COLUMNS FROM `dynamic_blocks` LIKE 'category_id'");
    $column = $stmt->fetch();
    if ($column && strpos(strtolower($column['Type']), 'int') !== false) {
        try {
            $pdo->exec("ALTER TABLE `dynamic_blocks` MODIFY `category_id` VARCHAR(255) DEFAULT NULL");
        } catch (PDOException $e) { /* ignore */ }
    }

    // Migration: cột nút "Xem thêm"
    $stmt = $pdo->query("SHOW COLUMNS FROM `dynamic_blocks` LIKE 'show_view_more'");
    if (!$stmt->fetch()) {
        try {
            $pdo->exec("ALTER TABLE `dynamic_blocks`
                ADD COLUMN `show_view_more` TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN `view_more_text` VARCHAR(100) DEFAULT 'Xem tất cả',
                ADD COLUMN `view_more_url` VARCHAR(255) DEFAULT NULL");
        } catch (PDOException $e) { /* ignore */ }
    }
    // Migration: cột bố cục card (grid/slide/list/overlay/magazine)
    $stmt = $pdo->query("SHOW COLUMNS FROM `dynamic_blocks` LIKE 'card_layout'");
    if (!$stmt->fetch()) {
        try {
            $pdo->exec("ALTER TABLE `dynamic_blocks` ADD COLUMN `card_layout` VARCHAR(20) NOT NULL DEFAULT 'grid' AFTER `display_mode`");
        } catch (PDOException $e) { /* ignore */ }
    }
    // Migration: block_type (posts|content) + content — cho block Nội dung/Quảng cáo tùy chỉnh
    $stmt = $pdo->query("SHOW COLUMNS FROM `dynamic_blocks` LIKE 'block_type'");
    if (!$stmt->fetch()) {
        try {
            $pdo->exec("ALTER TABLE `dynamic_blocks`
                ADD COLUMN `block_type` VARCHAR(20) NOT NULL DEFAULT 'posts' AFTER `block_key`,
                ADD COLUMN `content` LONGTEXT NULL AFTER `subtitle`");
        } catch (PDOException $e) { /* ignore */ }
    }
} catch (PDOException $e) { /* ignore */ }
$is_edit = false;
$block = [
    'id' => '',
    'block_type' => 'posts',
    'title' => '',
    'subtitle' => '',
    'content' => '',
    'type' => 'news',
    'display_mode' => 'row',
    'card_layout' => 'grid',
    'rows_count' => 1,
    'items_per_row' => 4,
    'items_count' => 8,
    'order_by' => 'newest',
    'category_id' => '',
    'layout_style' => 'simple',
    'wave_top_color' => '#f8f9fa',
    'wave_bottom_color' => '#ffffff',
    'status' => 1,
    'show_view_more' => 0,
    'view_more_text' => 'Xem tất cả',
    'view_more_url' => '',
];

function normalize_hex_color(string $value, string $default = '#ffffff'): string
{
    $value = trim($value);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
        return strtolower($value);
    }
    return strtolower($default);
}

function hex_for_color_picker(string $value, string $default = '#ffffff'): string
{
    $hex = normalize_hex_color($value, $default);
    if (strlen($hex) === 4) {
        return '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
    }
    if (strlen($hex) === 9) {
        return substr($hex, 0, 7);
    }
    return $hex;
}

// Edit mode
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM dynamic_blocks WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $existing = $stmt->fetch();
    if ($existing) {
        $block = $existing;
        $is_edit = true;
    }
}

$block['wave_top_color'] = normalize_hex_color((string) ($block['wave_top_color'] ?? '#f8f9fa'), '#f8f9fa');
$block['wave_bottom_color'] = normalize_hex_color((string) ($block['wave_bottom_color'] ?? '#ffffff'), '#ffffff');

// Handle form submission — MUST be before header include for redirect to work
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token';
    } else {
    $title = trim($_POST['title'] ?? '');
    $block_type = in_array($_POST['block_type'] ?? '', ['posts', 'content'], true) ? $_POST['block_type'] : 'posts';
    $content = (string) ($_POST['content'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '') ?: null;
    $type = in_array($_POST['type'] ?? '', ['products', 'news']) ? $_POST['type'] : 'news';
    $card_layout = in_array($_POST['card_layout'] ?? '', ['grid','slide','list','overlay','magazine'], true) ? $_POST['card_layout'] : 'grid';
    $display_mode = $card_layout === 'slide' ? 'slide' : 'row';
    $rows_count = max(1, min(10, (int)($_POST['rows_count'] ?? 1)));
    $items_per_row = max(1, min(6, (int)($_POST['items_per_row'] ?? 4)));
    $items_count = max(1, min(50, (int)($_POST['items_count'] ?? 8)));
    $order_by = $_POST['order_by'] ?? 'newest';
    
    // Handle multiple category IDs
    $category_ids = $_POST['category_ids'] ?? [];
    $category_id_str = !empty($category_ids) ? implode(',', array_map('intval', $category_ids)) : null;
    $layout_style = in_array($_POST['layout_style'] ?? '', ['simple', 'wave', 'gradient', 'glass', 'aurora', 'sunset', 'minimal', 'neon', 'editorial'], true) ? $_POST['layout_style'] : 'simple';
    $wave_top_color = normalize_hex_color((string) ($_POST['wave_top_color'] ?? '#f8f9fa'), '#f8f9fa');
    $wave_bottom_color = normalize_hex_color((string) ($_POST['wave_bottom_color'] ?? '#ffffff'), '#ffffff');
    $featured_only = isset($_POST['featured_only']) ? 1 : 0;
    $status = isset($_POST['status']) ? 1 : 0;
    $show_view_more = isset($_POST['show_view_more']) ? 1 : 0;
    $view_more_text = trim($_POST['view_more_text'] ?? '') ?: 'Xem tất cả';
    $view_more_url = trim($_POST['view_more_url'] ?? '') ?: null;

    if (empty($title)) {
        $errors[] = 'Tiêu đề không được để trống';
    }

    if (empty($errors)) {
        try {
            if ($is_edit) {
                // Update
                $stmt = $pdo->prepare("UPDATE dynamic_blocks SET
                    block_type = ?, title = ?, subtitle = ?, content = ?, type = ?, display_mode = ?, card_layout = ?,
                    rows_count = ?, items_per_row = ?, items_count = ?,
                    `order_by` = ?, category_id = ?, layout_style = ?, 
                    wave_top_color = ?, wave_bottom_color = ?,
                    featured_only = ?, status = ?,
                    show_view_more = ?, view_more_text = ?, view_more_url = ?
                    WHERE id = ?");
                $stmt->execute([$block_type, $title, $subtitle, $content, $type, $display_mode, $card_layout,
                    $rows_count, $items_per_row, $items_count,
                    $order_by, $category_id_str, $layout_style, 
                    $wave_top_color, $wave_bottom_color,
                    $featured_only, $status,
                    $show_view_more, $view_more_text, $view_more_url, $block['id']]);

                // Also update homepage_blocks name + icon
                $stmt = $pdo->prepare("UPDATE homepage_blocks SET block_name = ?, block_icon = ? WHERE block_key = ?");
                $stmt->execute([$title, ($block_type === 'content' ? 'bi-megaphone' : 'bi-newspaper'), $block['block_key']]);

                log_activity('update', 'dynamic_block', $block['id'], ['title' => $title]);
                $_SESSION['flash_success'] = 'Đã cập nhật block thành công!';
            } else {
                // Create
                $block_key = 'dynamic_' . time();

                $stmt = $pdo->prepare("INSERT INTO dynamic_blocks
                    (block_key, block_type, title, subtitle, content, type, display_mode, card_layout, rows_count, items_per_row,
                     items_count, `order_by`, category_id, layout_style, 
                     wave_top_color, wave_bottom_color,
                     featured_only, status,
                     show_view_more, view_more_text, view_more_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$block_key, $block_type, $title, $subtitle, $content, $type, $display_mode, $card_layout,
                    $rows_count, $items_per_row, $items_count,
                    $order_by, $category_id_str, $layout_style, 
                    $wave_top_color, $wave_bottom_color,
                    $featured_only, $status,
                    $show_view_more, $view_more_text, $view_more_url]);

                $new_id = $pdo->lastInsertId();

                // Get max sort order from homepage_blocks
                $max_sort = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM homepage_blocks")->fetchColumn();

                // Icon mapping
                $icon = $block_type === 'content' ? 'bi-megaphone' : 'bi-newspaper';

                // Insert into homepage_blocks
                $stmt = $pdo->prepare("INSERT INTO homepage_blocks (block_key, block_name, block_icon, sort_order, is_visible)
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$block_key, $title, $icon, $max_sort + 1, $status]);

                log_activity('create', 'dynamic_block', $new_id, ['title' => $title]);
                $_SESSION['flash_success'] = 'Đã tạo block mới thành công!';
            }

            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Lỗi: ' . $e->getMessage();
        }
    }

    // Refill form on error
    $block = array_merge($block, $_POST);
    $block['category_id'] = $category_id_str;
    $block['status'] = $status;
    }
}

// Get categories for dropdown
$selected_ids = !empty($block['category_id']) ? explode(',', $block['category_id']) : [];
$categories = get_hierarchical_categories($pdo, null, null, false);
// Mark selected
foreach ($categories as &$cat) {
    if (in_array($cat['id'], $selected_ids)) {
        $cat['selected'] = true;
    }
}
unset($cat);

// Map id danh mục -> URL để JS gợi ý nút "Xem thêm"
if (!function_exists('categoryUrl')) {
    require_once '../../includes/url-helper.php';
}
$category_url_map = [];
try {
    foreach ($pdo->query("SELECT id, slug FROM categories WHERE slug IS NOT NULL AND slug <> ''") as $crow) {
        $category_url_map[(string) $crow['id']] = categoryUrl($crow['slug']);
    }
} catch (Throwable $e) {
    $category_url_map = [];
}

// ── Now include header (HTML output starts here) ────────────────────────────────
require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">
            <i class="bi bi-<?php echo $is_edit ? 'pencil-square' : 'plus-circle'; ?> me-2"></i>
            <?php echo $is_edit ? 'Sửa Block' : 'Tạo Block Mới'; ?>
        </h1>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Quay lại
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle me-2"></i>
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo e($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="blockForm">
        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
        <div class="row g-4">
            <!-- Main Content -->
            <div class="col-lg-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Loại Block</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2">
                            <label class="card-layout-option" for="bt_posts">
                                <input type="radio" name="block_type" id="bt_posts" value="posts" <?php echo ($block['block_type'] ?? 'posts') === 'posts' ? 'checked' : ''; ?>>
                                <div class="mode-card p-3 text-center rounded-3" style="min-width:170px;">
                                    <i class="bi bi-newspaper d-block mb-1" style="font-size:1.6rem;"></i>
                                    <strong>Danh sách Bài viết</strong>
                                    <small class="d-block text-muted">Tự lấy bài theo danh mục</small>
                                </div>
                            </label>
                            <label class="card-layout-option" for="bt_content">
                                <input type="radio" name="block_type" id="bt_content" value="content" <?php echo ($block['block_type'] ?? '') === 'content' ? 'checked' : ''; ?>>
                                <div class="mode-card p-3 text-center rounded-3" style="min-width:170px;">
                                    <i class="bi bi-megaphone d-block mb-1" style="font-size:1.6rem;"></i>
                                    <strong>Nội dung / Quảng cáo</strong>
                                    <small class="d-block text-muted">Tự soạn nội dung tùy ý</small>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-type me-2"></i>Thông tin cơ bản</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Tiêu đề Block <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="title"
                                   value="<?php echo e($block['title']); ?>" placeholder="VD: Gói Internet Nổi Bật" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Mô tả ngắn</label>
                            <input type="text" class="form-control" name="subtitle"
                                   value="<?php echo e($block['subtitle'] ?? ''); ?>"
                                   placeholder="VD: Kết nối siêu tốc, ổn định 24/7">
                        </div>
                    </div>
                </div>

                <!-- Trình soạn thảo nội dung (chỉ cho block Nội dung/Quảng cáo) -->
                <div class="card shadow-sm mb-4" id="contentCard">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-megaphone me-2"></i>Nội dung Block</h5>
                    </div>
                    <div class="card-body">
                        <textarea id="blockContent" name="content" rows="14"><?php echo e($block['content'] ?? ''); ?></textarea>
                        <small class="text-muted d-block mt-2"><i class="bi bi-info-circle me-1"></i>Soạn nội dung tự do: tiêu đề phụ, danh sách, hotline, liên kết, ảnh... Tự hiển thị đẹp ở full-width, có sidebar và trên mobile.</small>
                    </div>
                </div>

                <div id="postOnlyCards">
                <!-- Content Type & Filter -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Nội dung & Bộ lọc</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Loại nội dung</label>
                                <div class="d-flex gap-3">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="type" id="typeProducts"
                                               value="products" <?php echo ($block['type'] ?? 'products') === 'products' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="typeProducts">
                                            <i class="bi bi-box-seam me-1 text-primary"></i>Sản phẩm
                                        </label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="type" id="typeNews"
                                               value="news" <?php echo ($block['type'] ?? '') === 'news' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="typeNews">
                                            <i class="bi bi-newspaper me-1 text-success"></i>Tin tức
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6" id="categoryWrapper">
                                <label class="form-label fw-bold">Danh mục</label>
                                <select class="form-select" name="category_ids[]" id="categoryMultiSelect" multiple placeholder="Chọn một hoặc nhiều danh mục...">
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>"
                                            <?php echo !empty($cat['selected']) ? 'selected' : ''; ?>>
                                            <?php echo e($cat['display_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Để trống để lấy từ tất cả danh mục.</small>
                            </div>
                            <div class="col-md-6" id="featuredWrapper">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="featured_only" id="featuredOnly"
                                           <?php echo !empty($block['featured_only']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="featuredOnly">
                                        <i class="bi bi-star-fill text-warning me-1"></i>Chỉ sản phẩm nổi bật
                                    </label>
                                    <small class="d-block text-muted">Chỉ hiển thị các sản phẩm được đánh dấu nổi bật</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Sắp xếp theo</label>
                                <select class="form-select" name="order_by">
                                    <option value="newest" <?php echo ($block['order_by'] ?? '') === 'newest' ? 'selected' : ''; ?>>Mới nhất</option>
                                    <option value="oldest" <?php echo ($block['order_by'] ?? '') === 'oldest' ? 'selected' : ''; ?>>Cũ nhất</option>
                                    <option value="price_asc" <?php echo ($block['order_by'] ?? '') === 'price_asc' ? 'selected' : ''; ?>>Giá tăng dần</option>
                                    <option value="price_desc" <?php echo ($block['order_by'] ?? '') === 'price_desc' ? 'selected' : ''; ?>>Giá giảm dần</option>
                                    <option value="featured" <?php echo ($block['order_by'] ?? '') === 'featured' ? 'selected' : ''; ?>>Nổi bật trước</option>
                                    <option value="sort_order" <?php echo ($block['order_by'] ?? '') === 'sort_order' ? 'selected' : ''; ?>>Thứ tự sắp xếp</option>
                                    <option value="random" <?php echo ($block['order_by'] ?? '') === 'random' ? 'selected' : ''; ?>>Ngẫu nhiên</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Số lượng hiển thị</label>
                                <input type="number" class="form-control" name="items_count"
                                       value="<?php echo (int)($block['items_count'] ?? 8); ?>" min="1" max="50">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Display Mode -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-display me-2"></i>Kiểu hiển thị</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-bold">Bố cục hiển thị</label>
                                <div class="d-flex flex-wrap gap-2 layout-pick">
                                    <?php
                                    $cardLayouts = [
                                        'grid'     => ['bi-grid-3x3-gap', 'Lưới', 'Lưới thẻ nhiều cột'],
                                        'slide'    => ['bi-collection-play', 'Trượt', 'Carousel tự trượt'],
                                        'list'     => ['bi-list-ul', 'Danh sách', 'Ảnh trái, nội dung phải'],
                                        'overlay'  => ['bi-images', 'Phủ ảnh', 'Chữ đè trên ảnh nền'],
                                        'magazine' => ['bi-layout-text-window-reverse', 'Tạp chí', '1 bài lớn + danh sách'],
                                    ];
                                    $curCard = $block['card_layout'] ?? 'grid';
                                    foreach ($cardLayouts as $cv => $ci): ?>
                                        <label class="card-layout-option" for="cl_<?php echo $cv; ?>">
                                            <input type="radio" name="card_layout" id="cl_<?php echo $cv; ?>" value="<?php echo $cv; ?>" <?php echo $curCard === $cv ? 'checked' : ''; ?>>
                                            <div class="mode-card p-3 text-center rounded-3" style="min-width:110px;">
                                                <i class="bi <?php echo $ci[0]; ?> d-block mb-1" style="font-size:1.6rem;"></i>
                                                <strong><?php echo $ci[1]; ?></strong>
                                                <small class="d-block text-muted"><?php echo $ci[2]; ?></small>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">Số item / dòng</label>
                                <input type="number" class="form-control" name="items_per_row"
                                       value="<?php echo (int)($block['items_per_row'] ?? 4); ?>" min="1" max="6">
                                <small class="text-muted">Áp dụng cho Lưới / Trượt / Phủ ảnh.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Nút "Xem thêm" -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-arrow-right-circle me-2"></i>Nút "Xem thêm"</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="show_view_more" id="showViewMore"
                                   <?php echo !empty($block['show_view_more']) ? 'checked' : ''; ?>
                                   style="width: 3em; height: 1.5em;">
                            <label class="form-check-label fw-bold" for="showViewMore">Hiện nút "Xem thêm" bên dưới block</label>
                        </div>
                        <div class="row g-3" id="viewMoreFields">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Chữ trên nút</label>
                                <input type="text" class="form-control" name="view_more_text"
                                       value="<?php echo e($block['view_more_text'] ?? 'Xem tất cả'); ?>" placeholder="Xem tất cả">
                            </div>
                            <div class="col-md-7">
                                <label class="form-label fw-bold">URL khi bấm</label>
                                <input type="text" class="form-control" name="view_more_url" id="viewMoreUrl"
                                       value="<?php echo e($block['view_more_url'] ?? ''); ?>"
                                       placeholder="/danh-muc/... (để trống sẽ tự lấy theo danh mục)">
                                <small class="text-muted">Để trống: tự trỏ tới danh mục đã chọn. Block nổi bật (không có danh mục) nên tự nhập URL hoặc tắt nút.</small>
                            </div>
                        </div>
                    </div>
                </div>
                </div><!-- /#postOnlyCards -->
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Status -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0 fw-bold">Trạng thái</h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="status" id="statusToggle"
                                       <?php echo ($block['status'] ?? 1) ? 'checked' : ''; ?>
                                       style="width: 3em; height: 1.5em;">
                                <label class="form-check-label" for="statusToggle" id="statusLabel">
                                    <?php echo ($block['status'] ?? 1) ? 'Hiện' : 'Ẩn'; ?>
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-<?php echo $is_edit ? 'check-lg' : 'plus-lg'; ?> me-2"></i>
                            <?php echo $is_edit ? 'Cập nhật Block' : 'Tạo Block'; ?>
                        </button>
                    </div>
                </div>

                <!-- Layout Style -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-palette me-2"></i>Kiểu giao diện</h5>
                    </div>
                    <div class="card-body p-2">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="layout-option w-100" for="styleSimple">
                                    <input type="radio" name="layout_style" id="styleSimple" value="simple"
                                           <?php echo ($block['layout_style'] ?? 'simple') === 'simple' ? 'checked' : ''; ?>>
                                    <div class="layout-card text-center p-3 rounded-3">
                                        <div class="layout-preview mb-2" style="height: 50px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;"></div>
                                        <small class="fw-bold">Đơn giản</small>
                                    </div>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="layout-option w-100" for="styleWave">
                                    <input type="radio" name="layout_style" id="styleWave" value="wave"
                                           <?php echo ($block['layout_style'] ?? '') === 'wave' ? 'checked' : ''; ?>>
                                    <div class="layout-card text-center p-3 rounded-3">
                                        <div class="layout-preview mb-2" style="height: 50px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 8px; position: relative; overflow: hidden;">
                                            <svg viewBox="0 0 100 20" style="position: absolute; bottom: 0; left: 0; width: 100%;"><path fill="rgba(255,255,255,0.3)" d="M0,10 C25,20 50,0 100,10 L100,20 L0,20 Z"></path></svg>
                                        </div>
                                        <small class="fw-bold">Gợn sóng</small>
                                    </div>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="layout-option w-100" for="styleGradient">
                                    <input type="radio" name="layout_style" id="styleGradient" value="gradient"
                                           <?php echo ($block['layout_style'] ?? '') === 'gradient' ? 'checked' : ''; ?>>
                                    <div class="layout-card text-center p-3 rounded-3">
                                        <div class="layout-preview mb-2" style="height: 50px; background: linear-gradient(135deg, #1e3a5f, #4a00e0, #8e2de2); border-radius: 8px;"></div>
                                        <small class="fw-bold">Gradient</small>
                                    </div>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="layout-option w-100" for="styleGlass">
                                    <input type="radio" name="layout_style" id="styleGlass" value="glass"
                                           <?php echo ($block['layout_style'] ?? '') === 'glass' ? 'checked' : ''; ?>>
                                    <div class="layout-card text-center p-3 rounded-3">
                                        <div class="layout-preview mb-2" style="height: 50px; background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0.3)); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; background-color: #667eea;"></div>
                                        <small class="fw-bold">Glass</small>
                                    </div>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="layout-option w-100" for="styleAurora">
                                    <input type="radio" name="layout_style" id="styleAurora" value="aurora"
                                           <?php echo ($block['layout_style'] ?? '') === 'aurora' ? 'checked' : ''; ?>>
                                    <div class="layout-card text-center p-3 rounded-3">
                                        <div class="layout-preview mb-2" style="height: 50px; background: linear-gradient(140deg, #1e3a8a, #7c3aed, #f43f5e); border-radius: 8px; position: relative; overflow: hidden;">
                                            <span style="position:absolute; inset: 10px; border-radius: 999px; background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.35), rgba(255,255,255,0)); opacity: 0.8;"></span>
                                        </div>
                                        <small class="fw-bold">Aurora</small>
                                    </div>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="layout-option w-100" for="styleSunset">
                                    <input type="radio" name="layout_style" id="styleSunset" value="sunset"
                                           <?php echo ($block['layout_style'] ?? '') === 'sunset' ? 'checked' : ''; ?>>
                                    <div class="layout-card text-center p-3 rounded-3">
                                        <div class="layout-preview mb-2" style="height: 50px; background: linear-gradient(135deg, #f97316, #ec4899); border-radius: 8px;"></div>
                                        <small class="fw-bold">Sunset</small>
                                    </div>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="layout-option w-100" for="styleMinimal">
                                    <input type="radio" name="layout_style" id="styleMinimal" value="minimal"
                                           <?php echo ($block['layout_style'] ?? '') === 'minimal' ? 'checked' : ''; ?>>
                                    <div class="layout-card text-center p-3 rounded-3">
                                        <div class="layout-preview mb-2" style="height: 50px; background: #ffffff; border-radius: 8px; border: 1px dashed #cbd5e1;"></div>
                                    <small class="fw-bold">Minimal</small>
                                </div>
                            </label>
                        </div>
                        <div class="col-6">
                            <label class="layout-option w-100" for="styleNeon">
                                <input type="radio" name="layout_style" id="styleNeon" value="neon"
                                       <?php echo ($block['layout_style'] ?? '') === 'neon' ? 'checked' : ''; ?>>
                                <div class="layout-card text-center p-3 rounded-3">
                                    <div class="layout-preview mb-2" style="height: 50px; background: linear-gradient(135deg, #0f172a, #22d3ee); border-radius: 8px; position: relative; overflow: hidden;">
                                        <span style="position:absolute; inset: 12px; border-radius: 999px; background: radial-gradient(circle at 30% 30%, rgba(34,211,238,0.55), rgba(34,211,238,0)); opacity: 0.85;"></span>
                                    </div>
                                    <small class="fw-bold">Neon</small>
                                </div>
                            </label>
                        </div>
                        <div class="col-6">
                            <label class="layout-option w-100" for="styleEditorial">
                                <input type="radio" name="layout_style" id="styleEditorial" value="editorial"
                                       <?php echo ($block['layout_style'] ?? '') === 'editorial' ? 'checked' : ''; ?>>
                                <div class="layout-card text-center p-3 rounded-3">
                                    <div class="layout-preview mb-2" style="height: 50px; background: linear-gradient(135deg, #f8fafc, #e2e8f0); border-radius: 8px; border: 1px dashed #cbd5e1;"></div>
                                    <small class="fw-bold">Editorial</small>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Wave Colors (Visible ONLY if layout_style is wave) -->
                <div class="card shadow-sm mb-4" id="waveColorsWrapper" style="display: <?php echo ($block['layout_style'] ?? '') === 'wave' ? '' : 'none'; ?>;">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="bi bi-water me-2"></i>Màu gợn sóng</h5>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">Chỉnh màu này trùng với màu nền của block bên trên/dưới để tạo sự liền mạch.</p>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Màu phía trên</label>
                                <div class="input-group">
                                    <input type="color" class="form-control form-control-color"
                                           id="waveTopColorPicker"
                                           value="<?php echo e(hex_for_color_picker((string) ($block['wave_top_color'] ?? '#f8f9fa'), '#f8f9fa')); ?>"
                                           data-hex-target="waveTopColorHex"
                                           title="Chọn màu cho sóng phía trên">
                                    <input type="text" class="form-control" name="wave_top_color" id="waveTopColorHex"
                                           value="<?php echo e($block['wave_top_color'] ?? '#f8f9fa'); ?>"
                                           pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$"
                                           placeholder="#fff, #ffff, #ffffff, #ffffffff">
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Màu phía dưới</label>
                                <div class="input-group">
                                    <input type="color" class="form-control form-control-color"
                                           id="waveBottomColorPicker"
                                           value="<?php echo e(hex_for_color_picker((string) ($block['wave_bottom_color'] ?? '#ffffff'), '#ffffff')); ?>"
                                           data-hex-target="waveBottomColorHex"
                                           title="Chọn màu cho sóng phía dưới">
                                    <input type="text" class="form-control" name="wave_bottom_color" id="waveBottomColorHex"
                                           value="<?php echo e($block['wave_bottom_color'] ?? '#ffffff'); ?>"
                                           pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$"
                                           placeholder="#fff, #ffff, #ffffff, #ffffffff">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
/* Display Mode Selection */
.display-mode-option input[type="radio"] { display: none; }
.card-layout-option input[type="radio"] { display: none; }
.card-layout-option { cursor: pointer; }
.card-layout-option .mode-card { background:#fff; border:2px solid #e9ecef; cursor:pointer; transition:all .2s ease; }
.card-layout-option .mode-card:hover { border-color:#6366f1; background:#f8f7ff; }
.card-layout-option input:checked + .mode-card { border-color:#6366f1; background:#eef2ff; }
.display-mode-option .mode-card {
    border: 2px solid #e9ecef;
    cursor: pointer;
    transition: all 0.2s ease;
}
.display-mode-option .mode-card:hover {
    border-color: #6366f1;
    background: #f8f7ff;
}
.display-mode-option input:checked + .mode-card {
    border-color: #6366f1;
    background: #eef2ff;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}

/* Layout Style Selection */
.layout-option input[type="radio"] { display: none; }
.layout-option .layout-card {
    border: 2px solid #e9ecef;
    cursor: pointer;
    transition: all 0.2s ease;
}
.layout-option .layout-card:hover {
    border-color: #6366f1;
}
.layout-option input:checked + .layout-card {
    border-color: #6366f1;
    background: #eef2ff;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeRadios = document.querySelectorAll('input[name="type"]');
    const categoryWrapper = document.getElementById('categoryWrapper');
    const featuredWrapper = document.getElementById('featuredWrapper');
    const modeRadios = document.querySelectorAll('input[name="display_mode"]');
    const rowOptions = document.querySelectorAll('.row-options');
    const statusToggle = document.getElementById('statusToggle');
    const statusLabel = document.getElementById('statusLabel');

    // ===== Chuyển đổi theo Loại Block (posts | content) =====
    const contentCard = document.getElementById('contentCard');
    const postOnlyCards = document.getElementById('postOnlyCards');
    function toggleBlockType() {
        const bt = (document.querySelector('input[name="block_type"]:checked') || {}).value || 'posts';
        const isContent = bt === 'content';
        if (contentCard) contentCard.style.display = isContent ? '' : 'none';
        if (postOnlyCards) postOnlyCards.style.display = isContent ? 'none' : '';
    }
    document.querySelectorAll('input[name="block_type"]').forEach(r => r.addEventListener('change', toggleBlockType));
    toggleBlockType();

    // Toggle category & featured based on type
    function toggleProductOptions() {
        const selectedType = document.querySelector('input[name="type"]:checked').value;
        const isProducts = selectedType === 'products';
        categoryWrapper.style.display = isProducts ? '' : 'none';
        featuredWrapper.style.display = isProducts ? '' : 'none';
    }
    typeRadios.forEach(r => r.addEventListener('change', toggleProductOptions));
    toggleProductOptions();

    // Toggle wave colors wrapper
    const layoutRadios = document.querySelectorAll('input[name="layout_style"]');
    const waveColorsWrapper = document.getElementById('waveColorsWrapper');
    function toggleWaveColors() {
        const selectedLayout = document.querySelector('input[name="layout_style"]:checked').value;
        waveColorsWrapper.style.display = selectedLayout === 'wave' ? '' : 'none';
    }
    layoutRadios.forEach(r => r.addEventListener('change', toggleWaveColors));

    // Sync color inputs (#rgb/#rgba/#rrggbb/#rrggbbaa)
    function isValidHexColor(v) {
        return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(v || '');
    }
    function toPickerHex(v) {
        if (!isValidHexColor(v)) return null;
        if (v.length === 4) {
            return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
        }
        if (v.length === 9) {
            return v.slice(0, 7);
        }
        return v;
    }

    document.querySelectorAll('.form-control-color[data-hex-target]').forEach(picker => {
        const hexInput = document.getElementById(picker.dataset.hexTarget);
        if (!hexInput) return;

        picker.addEventListener('input', function() {
            hexInput.value = this.value.toLowerCase();
        });

        const syncFromText = () => {
            const val = hexInput.value.trim().toLowerCase();
            if (!isValidHexColor(val)) {
                hexInput.classList.add('is-invalid');
                return;
            }
            hexInput.classList.remove('is-invalid');
            const pickerVal = toPickerHex(val);
            if (pickerVal) picker.value = pickerVal;
        };

        hexInput.addEventListener('input', syncFromText);
        hexInput.addEventListener('blur', syncFromText);
    });

    // Toggle row options based on display mode
    function toggleRowOptions() {
        const selectedMode = document.querySelector('input[name="display_mode"]:checked').value;
        rowOptions.forEach(el => {
            el.style.display = selectedMode === 'row' ? '' : 'none';
        });
    }
    modeRadios.forEach(r => r.addEventListener('change', toggleRowOptions));
    toggleRowOptions();

    // Status label toggle
    statusToggle.addEventListener('change', function() {
        statusLabel.textContent = this.checked ? 'Hiện' : 'Ẩn';
    });

    // ===== Nút "Xem thêm": bật/tắt fields + gợi ý URL theo danh mục =====
    const categoryUrlMap = <?php echo json_encode($category_url_map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const showViewMore = document.getElementById('showViewMore');
    const viewMoreFields = document.getElementById('viewMoreFields');
    const viewMoreUrl = document.getElementById('viewMoreUrl');
    const categorySelect = document.getElementById('categoryMultiSelect');

    function toggleViewMoreFields() {
        if (viewMoreFields) viewMoreFields.style.display = (showViewMore && showViewMore.checked) ? '' : 'none';
    }
    if (showViewMore) {
        showViewMore.addEventListener('change', toggleViewMoreFields);
        toggleViewMoreFields();
    }

    // Khi đổi danh mục: nếu ô URL đang trống thì tự điền URL của danh mục đầu tiên được chọn.
    function suggestViewMoreUrl() {
        if (!viewMoreUrl || !categorySelect) return;
        if (viewMoreUrl.value.trim() !== '') return; // tôn trọng URL admin tự nhập
        const firstId = Array.from(categorySelect.selectedOptions || [])
            .map(o => o.value).filter(Boolean)[0];
        if (firstId && categoryUrlMap[firstId]) {
            viewMoreUrl.value = categoryUrlMap[firstId];
        }
    }
    if (categorySelect) {
        categorySelect.addEventListener('change', suggestViewMoreUrl);
    }

    // Initialize Tom Select for Categories
    if (typeof TomSelect !== 'undefined') {
        new TomSelect('#categoryMultiSelect', {
            plugins: ['remove_button'],
            create: false,
            persist: false,
            placeholder: 'Chọn danh mục...',
            render: {
                no_results: function(data, escape) {
                    return '<div class="no-results">Không tìm thấy danh mục "' + escape(data.input) + '"</div>';
                }
            }
        });
    }
});
</script>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
window.addEventListener('load', function () {
    if (!window.tinymce) return;
    tinymce.init({
        selector: '#blockContent', height: 420, menubar: false, branding: false, promotion: false,
        license_key: 'gpl', convert_urls: false, toolbar_mode: 'wrap',
        plugins: 'advlist autolink lists link image media table code fullscreen preview charmap',
        toolbar: 'undo redo | blocks | bold italic underline forecolor | alignleft aligncenter alignright | bullist numlist | link image media table | removeformat code fullscreen preview',
        content_style: 'body{font-family:Inter,sans-serif;font-size:16px}img{max-width:100%;height:auto}',
        images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '../ajax/summernote-upload.php');
            xhr.setRequestHeader('X-CSRF-Token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
            xhr.onload = () => {
                if (xhr.status < 200 || xhr.status >= 300) { reject('HTTP ' + xhr.status); return; }
                let j = null; try { j = JSON.parse(xhr.responseText); } catch (e) { reject('parse'); return; }
                if (!j || !j.success || !j.url) { reject((j && j.message) || 'fail'); return; }
                resolve(j.url);
            };
            xhr.onerror = () => reject('upload failed');
            const fd = new FormData(); fd.append('file', blobInfo.blob(), blobInfo.filename()); xhr.send(fd);
        })
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>

