<?php
// admin/products/edit.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';

$current_page = 'products';
require_once '../includes/header.php';

$has_sort_order = has_table_column($pdo, 'products', 'sort_order');

// Auto-migrate: Add product_type columns if missing
if (!has_table_column($pdo, 'products', 'product_type')) {
    try {
        $pdo->exec("ALTER TABLE products
            ADD COLUMN product_type ENUM('default','digital','service','software','affiliate') NOT NULL DEFAULT 'default' AFTER is_featured,
            ADD COLUMN download_url VARCHAR(500) DEFAULT NULL AFTER product_type,
            ADD COLUMN affiliate_url VARCHAR(500) DEFAULT NULL AFTER download_url,
            ADD COLUMN activation_note TEXT DEFAULT NULL AFTER affiliate_url");
    } catch (PDOException $e) { /* columns may already exist */ }
} else {
    // Normalize legacy 'course' values to 'digital', then remove 'course' from ENUM.
    try {
        $pdo->exec("ALTER TABLE products MODIFY COLUMN product_type ENUM('default','digital','course','service','software','affiliate') NOT NULL DEFAULT 'default'");
        $pdo->exec("UPDATE products SET product_type = 'digital' WHERE product_type = 'course'");
        $pdo->exec("ALTER TABLE products MODIFY COLUMN product_type ENUM('default','digital','service','software','affiliate') NOT NULL DEFAULT 'default'");
    } catch (PDOException $e) { /* handle potential errors */ }
}

// Auto-migrate: Add og_image column if missing
if (!has_table_column($pdo, 'products', 'og_image')) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN og_image VARCHAR(500) DEFAULT NULL COMMENT 'Social thumbnail override' AFTER meta_keywords");
    } catch (PDOException $e) { /* column may already exist */ }
}

// Auto-migrate: Add software_subtype column if missing
if (!has_table_column($pdo, 'products', 'software_subtype')) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN software_subtype ENUM('source','key') DEFAULT NULL AFTER product_type");
    } catch (PDOException $e) { /* column may already exist */ }
}

// Auto-migrate: Add original_url column if missing (link gốc dự phòng cho affiliate)
if (!has_table_column($pdo, 'products', 'original_url')) {
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN original_url VARCHAR(500) DEFAULT NULL AFTER affiliate_url");
    } catch (PDOException $e) { /* column may already exist */ }
}

// Auto-migrate: Create slug_redirects table if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `slug_redirects` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `old_path`    VARCHAR(500) NOT NULL,
        `new_path`    VARCHAR(500) NOT NULL,
        `entity_type` ENUM('product','category','post') NOT NULL DEFAULT 'product',
        `entity_id`   INT UNSIGNED NOT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_old_path` (`old_path`(191)),
        INDEX `idx_entity`   (`entity_type`, `entity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { /* ignore */ }

// Get product ID
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$id) {
    redirect('index.php');
}

// Get product data
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    redirect('index.php');
}

// Map link affiliate hiện có theo platform_id để prefill form (đa nền tảng)
$existing_aff_links = [];
foreach (get_product_affiliate_links($id) as $__l) {
    $existing_aff_links[(int) $__l['platform_id']] = $__l['url'];
}

// Get categories
$categories = $pdo->query("SELECT * FROM categories WHERE status = 1")->fetchAll();

$error = '';
$success = '';
if (isset($_GET['created']) && (int) $_GET['created'] === 1) {
    $success = 'Thêm sản phẩm thành công!';
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $category_id = $_POST['category_id'] ?: null;
    $price_city = str_replace(['.', ','], '', $_POST['price']);
    $price_province = str_replace(['.', ','], '', $_POST['sale_price']);
    $description = trim($_POST['description']);
    $content = $_POST['content'];
    $status = isset($_POST['status']) ? 1 : 0;
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;

    // Product type fields — site thuần Affiliate
    $product_type = $_POST['product_type'] ?? 'affiliate';
    if ($product_type === 'course') {
        $product_type = 'digital';
    }
    $software_subtype = ($product_type === 'software') ? ($_POST['software_subtype'] ?? null) : null;
    $digital_download_url = trim($_POST['digital_download_url'] ?? '');
    $software_download_url = trim($_POST['software_download_url'] ?? '');
    $download_url = $product_type === 'digital' ? $digital_download_url : (($product_type === 'software') ? $software_download_url : '');
    // Multi-platform affiliate links: chỉ ghi đè khi form thực sự submit aff_link (tab affiliate đang mở)
    $aff_links_post = (isset($_POST['aff_link']) && is_array($_POST['aff_link'])) ? $_POST['aff_link'] : null;
    if ($aff_links_post !== null) {
        $affiliate_url = save_product_affiliate_links($id, $aff_links_post);
    } else {
        $affiliate_url = trim($product['affiliate_url'] ?? '');
    }
    if ($affiliate_url === '') {
        $affiliate_url = trim($_POST['affiliate_url'] ?? '');
    }
    $original_url = trim($_POST['original_url'] ?? '');

    $activation_note = trim($_POST['activation_note'] ?? '');
    $sort_order = (int) ($_POST['sort_order'] ?? 0);
    $click_count = max(0, (int) str_replace(['.', ',', ' '], '', (string) ($_POST['click_count'] ?? 0)));
    $views = max(0, (int) str_replace(['.', ',', ' '], '', (string) ($_POST['views'] ?? 0)));

    // SEO fields
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $focus_keyword = trim($_POST['focus_keyword'] ?? '');
    $og_image = trim($_POST['og_image'] ?? '');

    // Features
    $features_raw = trim($_POST['features'] ?? '');
    $features = null;
    if (!empty($features_raw)) {
        $features_arr = array_filter(array_map('trim', explode("\n", $features_raw)));
        if (count($features_arr) > 0) {
            $features = json_encode(array_values($features_arr), JSON_UNESCAPED_UNICODE);
        }
    }

    // Giữ nguyên slug cũ nếu POST slug rỗng; KHÔNG tự sinh slug từ tên
    $old_slug = $product['slug'];
    if (!empty($_POST['slug'])) {
        $slug = create_slug($_POST['slug']);
    } else {
        $slug = $old_slug; // Giữ nguyên nếu bỏ trống
    }

    // Main image
    $image_path = $product['image'];
    if (!empty($_POST['image'])) {
        $image_path = $_POST['image'];
    } elseif (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $upload_dir = ROOT_PATH . 'assets/uploads/';
        $uploaded_file = upload_file($_FILES['image'], $upload_dir);
        if ($uploaded_file) {
            if (!empty($product['image']) && file_exists(ROOT_PATH . $product['image'])) {
                unlink(ROOT_PATH . $product['image']);
            }
            $image_path = 'assets/uploads/' . $uploaded_file;
        }
    }

    // Gallery
    $current_gallery = !empty($product['gallery']) ? json_decode($product['gallery'], true) : [];
    if (!is_array($current_gallery)) $current_gallery = [];
    $submitted_gallery = $_POST['existing_gallery'] ?? [];
    if (!is_array($submitted_gallery)) $submitted_gallery = [];
    $clean_gallery = [];
    foreach ($submitted_gallery as $gpath) {
        $gpath = trim($gpath);
        if ($gpath === '' || strpos($gpath, '..') !== false) {
            continue;
        }
        // Chấp nhận ảnh từ media library (assets/uploads/) lẫn ảnh import (uploads/...)
        if (strpos($gpath, 'assets/uploads/') === 0 || strpos($gpath, 'uploads/') === 0) {
            $clean_gallery[] = $gpath;
        }
    }
    $new_gallery_paths = array_values(array_unique($clean_gallery));
    $gallery_json = !empty($new_gallery_paths) ? json_encode($new_gallery_paths, JSON_UNESCAPED_UNICODE) : null;

    if (empty($name)) {
        $error = 'Vui long nhap ten san pham.';
    } else {
        try {
            $og_val = $og_image ?: null;
            if ($has_sort_order) {
                $stmt = $pdo->prepare("UPDATE products SET category_id=?,name=?,slug=?,description=?,content=?,features=?,price=?,sale_price=?,status=?,sort_order=?,is_featured=?,product_type=?,software_subtype=?,download_url=?,affiliate_url=?,original_url=?,activation_note=?,image=?,gallery=?,meta_title=?,meta_description=?,meta_keywords=?,focus_keyword=?,og_image=?,click_count=?,views=? WHERE id=?");
                $stmt->execute([$category_id,$name,$slug,$description,$content,$features,$price_city,$price_province,$status,$sort_order,$is_featured,$product_type,$software_subtype,$download_url?:null,$affiliate_url?:null,$original_url?:null,$activation_note?:null,$image_path,$gallery_json,$meta_title,$meta_description,$meta_keywords,$focus_keyword,$og_val,$click_count,$views,$id]);
            } else {
                $stmt = $pdo->prepare("UPDATE products SET category_id=?,name=?,slug=?,description=?,content=?,features=?,price=?,sale_price=?,status=?,is_featured=?,product_type=?,software_subtype=?,download_url=?,affiliate_url=?,original_url=?,activation_note=?,image=?,gallery=?,meta_title=?,meta_description=?,meta_keywords=?,focus_keyword=?,og_image=?,click_count=?,views=? WHERE id=?");
                $stmt->execute([$category_id,$name,$slug,$description,$content,$features,$price_city,$price_province,$status,$is_featured,$product_type,$software_subtype,$download_url?:null,$affiliate_url?:null,$original_url?:null,$activation_note?:null,$image_path,$gallery_json,$meta_title,$meta_description,$meta_keywords,$focus_keyword,$og_val,$click_count,$views,$id]);
            }
            if (function_exists('log_activity')) {
                log_activity('update', 'product', $id, "Cập nhật sản phẩm: $name");
            }

            // --- Slug Redirect (giống RankMath) ---
            // Nếu slug thay đổi, tạo redirect 301 từ URL cũ → URL mới
            if ($slug !== $old_slug) {
                // Lấy category_slug để build path
                $cat_slug_row = $pdo->prepare("SELECT slug FROM categories WHERE id = ? LIMIT 1");
                $cat_slug_row->execute([$category_id]);
                $cat_slug_val = (string) ($cat_slug_row->fetchColumn() ?: '');

                $old_path = $cat_slug_val ? '/' . $cat_slug_val . '/' . $old_slug : '/' . $old_slug;
                $new_path = $cat_slug_val ? '/' . $cat_slug_val . '/' . $slug     : '/' . $slug;

                // Upsert: nếu old_path đã có thì cập nhật new_path, tránh chuỗi redirect
                $ins = $pdo->prepare(
                    "INSERT INTO slug_redirects (old_path, new_path, entity_type, entity_id)
                     VALUES (?, ?, 'product', ?)
                     ON DUPLICATE KEY UPDATE new_path = VALUES(new_path), created_at = NOW()"
                );
                // Nếu DB chưa có UNIQUE trên old_path thì dùng INSERT bình thường
                try {
                    $ins->execute([$old_path, $new_path, $id]);
                } catch (PDOException $e) {
                    // Fallback: plain INSERT nếu ON DUPLICATE KEY không áp dụng
                    try {
                        $ins2 = $pdo->prepare(
                            "INSERT INTO slug_redirects (old_path, new_path, entity_type, entity_id) VALUES (?, ?, 'product', ?)"
                        );
                        $ins2->execute([$old_path, $new_path, $id]);
                    } catch (PDOException $e2) { /* ignore */ }
                }

                // Xóa cache trang cũ nếu PageCache đang dùng
                if (class_exists('PageCache')) {
                    PageCache::delete('product_' . preg_replace('/[^a-z0-9-]/', '', strtolower($old_slug)));
                    PageCache::delete('product_' . preg_replace('/[^a-z0-9-]/', '', strtolower($slug)));
                }

                if (function_exists('log_activity')) {
                    log_activity('update', 'product', $id, "Slug redirect: {$old_path} → {$new_path}");
                }
            }
            // --- End Slug Redirect ---

            $success = 'Cập nhật sản phẩm thành công!';
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
}

// SEO data for component
$seo_data = [
    'meta_title'       => $product['meta_title'] ?? '',
    'meta_description' => $product['meta_description'] ?? '',
    'meta_keywords'    => $product['meta_keywords'] ?? '',
    'focus_keyword'    => $product['focus_keyword'] ?? '',
    'og_image'         => $product['og_image'] ?? '',
    'og_image_default' => $product['image'] ?? '',
    'preview_title'    => $product['name'],
    'preview_url'      => BASE_URL . 'san-pham/' . $product['slug']
];
$category_slug_map = [];
foreach ($categories as $cat) {
    $category_slug_map[(string) $cat["id"]] = (string) ($cat["slug"] ?? "");
}
$selected_category_slug = "";
if (!empty($product["category_id"])) {
    $selected_category_slug = $category_slug_map[(string) $product["category_id"]] ?? "";
}
$current_product_url = !empty($selected_category_slug)
    ? productUrl($product["slug"], $selected_category_slug, true)
    : "";
$current_product_url_text = !empty($current_product_url)
    ? $current_product_url
    : "Chon danh muc de hien thi link dung";

?>
<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Sửa Sản phẩm</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Quay lại
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="mb-3">
                                    <label for="name" class="form-label d-flex align-items-center justify-content-between gap-2">
                                        <span>Tên sản phẩm <span class="text-danger">*</span></span>
                                        <span class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-primary" id="aiRewriteAllBtn"><i class="bi bi-stars me-1"></i>Viết lại bằng AI</button>
                                            <button type="button" class="btn btn-sm btn-success" id="aiWriteSeoBtn"><i class="bi bi-magic me-1"></i>Viết bài + SEO</button>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" id="name" name="name" required
                                        value="<?php echo e($product['name']); ?>">
                                    <div class="form-text" id="aiRewriteAllResult">AI sẽ viết lại đồng thời Tên + Mô tả ngắn + Nội dung chi tiết.</div>
                                    <div class="form-text d-none" id="aiWriteSeoResult"></div>
                                </div>
                                <div class="mb-3">
                                    <label for="slug" class="form-label d-flex align-items-center justify-content-between gap-2">
                                        <span>Slug</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="regenSlugBtn"
                                            title="Sinh lại slug từ tên sản phẩm hiện tại">
                                            <i class="bi bi-arrow-repeat"></i> Tạo lại slug từ tên
                                        </button>
                                    </label>
                                    <input type="text" class="form-control" id="slug" name="slug"
                                        value="<?php echo e($product['slug']); ?>">
                                    <div class="form-text mt-1">
                                        Link sản phẩm:
                                        <a id="product-url-preview"
                                            href="<?php echo !empty($selected_category_slug) ? e($current_product_url) : 'javascript:void(0)'; ?>"
                                            target="_blank" class="text-break text-decoration-none <?php echo empty($selected_category_slug) ? 'disabled text-muted' : ''; ?>">
                                            <?php echo e($current_product_url_text); ?>
                                        </a>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="content" class="form-label">Nội dung chi tiết</label>
                                    <textarea class="form-control" id="content" name="content"
                                        rows="10"><?php echo e($product['content']); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="description" class="form-label">Mô tả ngắn</label>
                                    <textarea class="form-control" id="description" name="description"
                                        rows="3"><?php echo e($product['description']); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label for="features" class="form-label">Tính năng <small class="text-muted">(mỗi
                                            dòng 1 tính năng)</small></label>
                                    <textarea class="form-control" id="features" name="features" rows="4" placeholder="VD:
Không giới hạn dung lượng
Miễn phí lắp đặt
Hỗ trợ kỹ thuật 24/7"><?php
// Convert JSON features to multiline text
$features_text = '';
if (!empty($product['features'])) {
    $feat_arr = json_decode($product['features'], true);
    if (is_array($feat_arr)) {
        $features_text = implode("\n", $feat_arr);
    }
}
echo e($features_text);
?></textarea>
                                </div>

                                <!-- SEO Fields Component -->
                                <?php include '../includes/seo-fields.php'; ?>
                            </div>
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label for="category_id" class="form-label">Danh mục</label>
                                    <select class="form-select" id="category_id" name="category_id">
                                        <option value="">-- Chọn danh mục --</option>
                                        <?php echo render_category_options($pdo, $product['category_id'], null, true); ?>
                                    </select>
                                </div>

                                <!-- Site thuần Affiliate -->
                                <input type="hidden" name="product_type" value="affiliate">
                                <div id="field-affiliate" class="mb-3">
                                    <label class="form-label fw-bold"><i class="bi bi-link-45deg me-1"></i>Link Affiliate theo nền tảng</label>
                                    <?php $aff_platforms = get_affiliate_platforms(true); ?>
                                    <?php if (empty($aff_platforms)): ?>
                                        <div class="alert alert-warning small mb-2">Chưa có nền tảng nào đang bật. <a href="../affiliate-platforms/index.php">Thêm / bật nền tảng</a>.</div>
                                    <?php else: foreach ($aff_platforms as $afp):
                                        $afp_id = (int) $afp['id'];
                                        $afp_url = $existing_aff_links[$afp_id] ?? ''; ?>
                                        <div class="input-group mb-2">
                                            <span class="input-group-text text-white" style="background:<?php echo e($afp['color']); ?>;min-width:150px;">
                                                <i class="bi <?php echo e($afp['icon']); ?> me-1"></i><?php echo e($afp['name']); ?>
                                            </span>
                                            <input type="url" class="form-control" name="aff_link[<?php echo $afp_id; ?>]" placeholder="https://..." value="<?php echo e($afp_url); ?>">
                                        </div>
                                    <?php endforeach; endif; ?>
                                    <small class="text-muted d-block">Nhập link cho nền tảng nào thì nền tảng đó hiển thị nút. Nút "Mua ngay" chính dùng nền tảng có thứ tự nhỏ nhất.</small>
                                    <label class="form-label mt-3">Link gốc (dự phòng)</label>
                                    <input type="url" class="form-control" name="original_url" placeholder="https://nhasanxuat.com/san-pham" value="<?php echo e($product['original_url'] ?? ''); ?>">
                                    <small class="text-danger d-block mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Dùng khi không có link affiliate. KHÔNG nhập link sàn đối thủ (Lazada, Tiki, Sendo, TikTok Shop) để tránh vi phạm chính sách Shopee.</small>
                                </div>
                                <?php if ($has_sort_order): ?>
                                    <div class="mb-3">
                                        <label for="sort_order" class="form-label">Thứ tự hiển thị</label>
                                        <input type="number" class="form-control" id="sort_order" name="sort_order" min="0"
                                            value="<?php echo (int) ($product['sort_order'] ?? 0); ?>">
                                        <small class="text-muted">Số nhỏ hơn sẽ hiển thị trước trong cùng danh mục.</small>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label for="click_count" class="form-label fw-bold"><i class="bi bi-cursor-fill me-1 text-danger"></i>Lượt quan tâm (ảo)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="click_count" name="click_count" min="0"
                                            value="<?php echo (int) ($product['click_count'] ?? 0); ?>">
                                        <button type="button" class="btn btn-outline-warning" id="simInterestBtn"
                                            title="Random lượt quan tâm 2.000–10.000">
                                            <i class="bi bi-magic"></i> Giả lập
                                        </button>
                                    </div>
                                    <small class="text-muted">Lượt click vào nút "Mua ngay". Bấm "Giả lập" để random 2.000–10.000; click thật sẽ tự cộng dồn.</small>
                                </div>
                                <div class="mb-3">
                                    <label for="views" class="form-label fw-bold"><i class="bi bi-eye-fill me-1 text-info"></i>Lượt xem (ảo)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="views" name="views" min="0"
                                            value="<?php echo (int) ($product['views'] ?? 0); ?>">
                                        <button type="button" class="btn btn-outline-info" id="simViewsBtn"
                                            title="Random lượt xem 5.000–50.000">
                                            <i class="bi bi-magic"></i> Giả lập
                                        </button>
                                    </div>
                                    <small class="text-muted">Lượt xem hiển thị ngoài site. Bấm "Giả lập" để random 5.000–50.000; lượt xem thật (thống kê) không bị ảnh hưởng.</small>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <label for="price" class="form-label">Giá gốc (VNĐ)</label>
                                        <input type="text" class="form-control price-input" id="price"
                                            name="price"
                                            value="<?php echo number_format((int) $product['price'], 0, ',', '.'); ?>"
                                            placeholder="VD: 200.000">
                                        <small class="text-muted">Giá gốc sản phẩm</small>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label for="sale_price" class="form-label">Giá khuyến mãi (VNĐ)</label>
                                        <input type="text" class="form-control price-input" id="sale_price"
                                            name="sale_price"
                                            value="<?php echo number_format((int) $product['sale_price'], 0, ',', '.'); ?>"
                                            placeholder="VD: 180.000">
                                        <small class="text-muted">Để trống nếu không khuyến mãi</small>
                                    </div>
                                </div>
                                <!-- Main Image Upload -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Hình ảnh đại diện</label>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control" id="image" name="image" readonly
                                            placeholder="Đường dẫn ảnh..." value="<?php echo e($product['image']); ?>">
                                        <button type="button" class="btn btn-primary init-media-selector"
                                            data-input="image" data-preview="main-preview">
                                            <i class="bi bi-images me-1"></i> Chọn ảnh
                                        </button>
                                    </div>
                                    <div class="preview-area <?php echo empty($product['image']) ? 'd-none' : ''; ?>">
                                        <?php 
                                            $img_src = '';
                                            if (!empty($product['image'])) {
                                                $img_src = (strpos($product['image'], 'http') === 0 || strpos($product['image'], '//') === 0) ? $product['image'] : BASE_URL . $product['image'];
                                            }
                                        ?>
                                        <img src="<?php echo $img_src; ?>"
                                            id="main-preview" class="img-fluid rounded shadow-sm"
                                            style="max-height: 200px;">
                                    </div>
                                </div>

                                <!-- Gallery Upload Section -->
                                <div class="mb-4">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <label class="form-label fw-bold mb-0">Thư viện ảnh (Gallery)</label>
                                        <button type="button" class="btn btn-outline-primary btn-sm init-media-selector"
                                            id="add-from-library" data-mode="multiple">
                                            <i class="bi bi-images me-1"></i> Chọn từ thư viện
                                        </button>
                                    </div>

                                    <!-- 1. Upload Box -->
                                    <div class="upload-box text-center p-3 rounded-3 border-2 border-dashed mb-3"
                                        id="gallery-box"
                                        style="border: 2px dashed #dee2e6; background: #f8f9fa; cursor: pointer; transition: all 0.2s;">
                                        <div class="d-flex justify-content-center align-items-center gap-2">
                                            <i class="bi bi-cloud-arrow-up fs-4 text-primary opacity-75"></i>
                                            <span class="fw-bold text-dark">Tải ảnh lên</span>
                                        </div>
                                        <span class="text-muted small">Click hoặc kéo thả (Chọn nhiều ảnh)</span>
                                    </div>
                                    <input class="d-none" type="file" id="gallery" name="gallery[]" accept="image/*"
                                        multiple>
                                    <!-- New Uploads Previews -->
                                    <div id="gallery-preview-container" class="row g-2 mt-2"></div>
                                </div>

                                <!-- 2. Sortable Grid -->
                                <?php
                                $current_gallery = !empty($product['gallery']) ? json_decode($product['gallery'], true) : [];
                                ?>
                                <div class="card bg-light border-0">
                                    <div
                                        class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center px-0 pb-0">
                                        <label class="small text-muted fw-bold mb-0">Ảnh đang có (Kéo thả để sắp
                                            xếp):</label>
                                        <span
                                            class="badge bg-secondary rounded-pill" id="gallery-count-badge"><?php echo is_array($current_gallery) ? count($current_gallery) : 0; ?>
                                            ảnh</span>
                                    </div>
                                    <div class="card-body px-0 pt-2">
                                        <div id="sortable-gallery" class="row g-2">
                                            <?php if (!empty($current_gallery) && is_array($current_gallery)):
                                                foreach ($current_gallery as $g_img): ?>
                                                    <div class="col-4 col-sm-3 col-lg-2 gallery-item handle"
                                                        style="cursor: grab;">
                                                        <div
                                                            class="ratio ratio-1x1 rounded-3 overflow-hidden shadow-sm border bg-white position-relative group-hover">
                                                            <?php 
                                                                $g_img_src = (strpos($g_img, 'http') === 0 || strpos($g_img, '//') === 0) ? $g_img : BASE_URL . $g_img;
                                                            ?>
                                                            <img src="<?php echo $g_img_src; ?>"
                                                                class="object-fit-cover w-100 h-100">

                                                            <!-- Hidden Input for Ordering -->
                                                            <input type="hidden" name="existing_gallery[]"
                                                                value="<?php echo e($g_img); ?>">

                                                            <!-- Remove Button -->
                                                            <button type="button"
                                                                class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 p-0 rounded-circle d-flex justify-content-center align-items-center btn-remove-item"
                                                                style="width: 24px; height: 24px; opacity: 0.9;"
                                                                title="Xóa ảnh này">
                                                                <i class="bi bi-x-lg" style="font-size: 12px;"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; endif; ?>
                                        </div>
                                        <?php if (empty($current_gallery)): ?>
                                            <p class="text-muted small text-center py-3 m-0 fst-italic border rounded-3 bg-white"
                                                id="empty-gallery-msg">
                                                Chưa có ảnh nào trong thư viện.
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">
                                <div class="col-6">
                                    <div
                                        class="form-check form-switch p-3 bg-white rounded shadow-sm border h-100 d-flex align-items-center">
                                        <input class="form-check-input ms-0 me-3 flex-shrink-0" type="checkbox"
                                            id="is_featured" name="is_featured" <?php echo (isset($product['is_featured']) && $product['is_featured']) ? 'checked' : ''; ?> style="float:none;margin-left:0;">
                                        <label class="form-check-label fw-bold mb-0" for="is_featured">
                                            <i class="bi bi-star-fill text-warning me-1"></i> Nổi bật
                                        </label>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div
                                        class="form-check form-switch p-3 bg-white rounded shadow-sm border h-100 d-flex align-items-center">
                                        <input class="form-check-input ms-0 me-3 flex-shrink-0" type="checkbox"
                                            id="status" name="status" <?php echo $product['status'] ? 'checked' : ''; ?>
                                            style="float:none;margin-left:0;">
                                        <label class="form-check-label fw-bold mb-0" for="status">
                                            <i class="bi bi-toggle-on text-success me-1"></i> Kích hoạt
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary btn-lg rounded-pill shadow-sm fw-bold">
                                    <i class="bi bi-check-circle-fill me-2"></i>Cập nhật Sản phẩm
                                </button>
                            </div>
                        </div>
                </div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Elements
        const productSlugInput = document.getElementById('slug');
        const productCategorySelect = document.getElementById('category_id');
        const productUrlPreview = document.getElementById('product-url-preview');
        const siteBaseUrl = <?php echo json_encode(rtrim(BASE_URL, '/')); ?>;
        const categorySlugMap = <?php echo json_encode($category_slug_map, JSON_UNESCAPED_UNICODE); ?>;
        const galleryBox = document.getElementById('gallery-box');
        const galleryInput = document.getElementById('gallery');
        const galleryPreviewContainer = document.getElementById('gallery-preview-container');
        const sortableGallery = document.getElementById('sortable-gallery');
        const addFromLibraryBtn = document.getElementById('add-from-library');
        const galleryCountBadge = document.getElementById('gallery-count-badge');

        function updateGalleryCount() {
            if (!galleryCountBadge || !sortableGallery) return;
            galleryCountBadge.textContent = sortableGallery.querySelectorAll('input[name="existing_gallery[]"]').length + ' ảnh';
        }

        const updateProductUrlPreview = () => {
            if (!productSlugInput || !productCategorySelect || !productUrlPreview) return;

            const slug = (productSlugInput.value || '').trim();
            const categorySlug = categorySlugMap[productCategorySelect.value] || '';

            let fullUrl = 'Chọn danh mục để hiển thị link đúng';
            if (categorySlug && slug) {
                fullUrl = `${siteBaseUrl}/${categorySlug}/${slug}`;
                productUrlPreview.href = fullUrl;
                productUrlPreview.classList.remove('disabled');
                productUrlPreview.classList.remove('text-muted');
            } else {
                productUrlPreview.href = 'javascript:void(0)';
                productUrlPreview.classList.add('disabled');
                productUrlPreview.classList.add('text-muted');
            }
            productUrlPreview.textContent = fullUrl;
        };

        if (productSlugInput && productCategorySelect && productUrlPreview) {
            productSlugInput.addEventListener('input', updateProductUrlPreview);
            productCategorySelect.addEventListener('change', updateProductUrlPreview);
            updateProductUrlPreview();
        }

        // 1. SortableJS Init
        if (sortableGallery) {
            new Sortable(sortableGallery, {
                animation: 150,
                handle: '.handle',
                ghostClass: 'bg-light'
            });
        }

        // 2. Add from Library Handler
        if (addFromLibraryBtn) {
            addFromLibraryBtn.addEventListener('media-selected', function (e) {
                const images = e.detail.images;
                if (!images || images.length === 0) return;

                const emptyMsg = document.getElementById('empty-gallery-msg');
                if (emptyMsg) emptyMsg.remove();

                images.forEach(img => {
                    // Avoid duplicates in visual list (server side should also filter)
                    if (sortableGallery.querySelector(`input[value="${img.path}"]`)) return;

                    const col = document.createElement('div');
                    col.className = 'col-4 col-sm-3 col-lg-2 gallery-item handle';
                    col.style.cursor = 'grab';
                    col.innerHTML = `
                        <div class="ratio ratio-1x1 rounded-3 overflow-hidden shadow-sm border bg-white position-relative group-hover">
                            <img src="${img.url}" class="object-fit-cover w-100 h-100">
                            <input type="hidden" name="existing_gallery[]" value="${img.path}">
                            <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 p-0 rounded-circle d-flex justify-content-center align-items-center btn-remove-item" 
                                    style="width: 24px; height: 24px; opacity: 0.9;" title="Xóa ảnh này">
                                <i class="bi bi-x-lg" style="font-size: 12px;"></i>
                            </button>
                        </div>
                    `;
                    sortableGallery.appendChild(col);
                });
                updateGalleryCount();
            });
        }

        // 3. Delete Handler (Delegated)
        if (sortableGallery) {
            sortableGallery.addEventListener('click', function (e) {
                const btn = e.target.closest('.btn-remove-item');
                if (btn) {
                    const item = btn.closest('.gallery-item');
                    if (item) item.remove();
                    updateGalleryCount();
                }
            });
        }

        // 4. File Upload Preview Logic
        if (galleryBox && galleryInput) {
            galleryBox.addEventListener('click', (e) => {
                // Prevent infinite loop if the input itself is clicked (rare but possible if CSS breaks)
                if (e.target !== galleryInput) {
                    galleryInput.click();
                }
            });

            // 4. File Upload Preview & AJAX Upload
            const apiUploadUrl = '<?php echo BASE_URL; ?>admin/ajax/media-upload.php';

            galleryInput.addEventListener('change', async function (e) {
                if (!this.files || this.files.length === 0) return;

                const files = Array.from(this.files);
                const validFiles = files.filter(file => file.type.startsWith('image/'));

                if (validFiles.length === 0) return;

                // Show loading state
                galleryPreviewContainer.innerHTML = `
                    <div class="col-12 text-center py-3">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted small">Đang tải lên...</p>
                    </div>
                `;

                const formData = new FormData();
                // CSRF Token
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                if (csrfToken) formData.append('csrf_token', csrfToken);

                validFiles.forEach(file => {
                    formData.append('files[]', file);
                });

                try {
                    const res = await fetch(apiUploadUrl, {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    galleryPreviewContainer.innerHTML = ''; // Clear loading

                    if (data.success && data.uploaded.length > 0) {
                        data.uploaded.forEach(img => {
                            // Avoid duplicates in visual list
                            if (sortableGallery.querySelector(`input[value="${img.file_path}"]`)) return;

                            const col = document.createElement('div');
                            col.className = 'col-4 col-sm-3 col-lg-2 gallery-item handle';
                            col.style.cursor = 'grab';
                            col.innerHTML = `
                                <div class="ratio ratio-1x1 rounded-3 overflow-hidden shadow-sm border bg-white position-relative group-hover">
                                    <img src="${img.url}" class="object-fit-cover w-100 h-100">
                                    <input type="hidden" name="existing_gallery[]" value="${img.file_path}">
                                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-1 p-0 rounded-circle d-flex justify-content-center align-items-center btn-remove-item" 
                                            style="width: 24px; height: 24px; opacity: 0.9;" title="Xóa ảnh này">
                                        <i class="bi bi-x-lg" style="font-size: 12px;"></i>
                                    </button>
                                </div>
                            `;
                            sortableGallery.appendChild(col);
                        });
                        updateGalleryCount();

                        // Clear input to allow re-uploading same file if needed
                        galleryInput.value = '';

                        // Show temporary success message
                        const successMsg = document.createElement('div');
                        successMsg.className = 'col-12 text-center text-success small fw-bold fade-out';
                        successMsg.innerText = `Đã tải lên ${data.uploaded.length} ảnh thành công!`;
                        galleryPreviewContainer.appendChild(successMsg);
                        setTimeout(() => successMsg.remove(), 3000);

                    } else {
                        // Show error
                        galleryPreviewContainer.innerHTML = `<div class="col-12 text-center text-danger small">${data.message || 'Lỗi tải lên'}</div>`;
                    }
                } catch (err) {
                    console.error(err);
                    galleryPreviewContainer.innerHTML = `<div class="col-12 text-center text-danger small">Lỗi kết nối server</div>`;
                }
            });

            // Drag effects
            ['dragenter', 'dragover'].forEach(evt => {
                galleryBox.addEventListener(evt, (e) => {
                    e.preventDefault();
                    galleryBox.style.borderColor = '#0d6efd';
                    galleryBox.style.backgroundColor = '#e9ecef';
                });
            });
            ['dragleave', 'drop'].forEach(evt => {
                galleryBox.addEventListener(evt, (e) => {
                    e.preventDefault();
                    galleryBox.style.borderColor = '#dee2e6';
                    galleryBox.style.backgroundColor = '#f8f9fa';
                });
            });
            galleryBox.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                if (dt.files && dt.files.length > 0) {
                    galleryInput.files = dt.files;
                    galleryInput.dispatchEvent(new Event('change'));
                }
            });
        }

        // 5. TinyMCE Init
        tinymce.init({
            selector: '#content',
            height: 600,
            menubar: 'file edit view insert format tools table help',
            plugins: 'advlist autolink lists link image media table code fullscreen preview searchreplace visualblocks charmap help wordcount',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | blockquote | link image media table | removeformat code fullscreen preview | help',
            branding: false,
            promotion: false,
            license_key: 'gpl',
            image_dimensions: false,
            toolbar_mode: 'wrap',
            convert_urls: false,
            content_style: 'body { font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 16px; overflow-x: hidden; } img, video, iframe { max-width: 100% !important; height: auto !important; } figure, figure.image { max-width: 100% !important; width: auto !important; margin: 1rem auto !important; } figure img, figure.image img { display: block; max-width: 100% !important; height: auto !important; } figcaption { max-width: 100%; overflow-wrap: anywhere; } table { max-width: 100%; }',
            setup: (editor) => {
                const normalizeEditorMedia = () => {
                    const body = editor.getBody();
                    if (!body) return;

                    body.querySelectorAll('img, video, iframe').forEach((node) => {
                        node.removeAttribute('width');
                        node.removeAttribute('height');
                        node.style.width = '';
                        node.style.height = '';
                        node.style.maxWidth = '100%';
                        node.style.height = 'auto';
                    });

                    body.querySelectorAll('figure, figure.image').forEach((node) => {
                        node.removeAttribute('width');
                        node.removeAttribute('height');
                        node.style.width = '';
                        node.style.height = '';
                        node.style.maxWidth = '100%';
                    });
                };

                editor.on('init SetContent change Undo Redo Paste PostProcess', normalizeEditorMedia);
            },
            images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '../ajax/summernote-upload.php');
                xhr.setRequestHeader('X-CSRF-Token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) {
                        progress((e.loaded / e.total) * 100);
                    }
                };
                xhr.onload = () => {
                    if (xhr.status < 200 || xhr.status >= 300) {
                        reject('HTTP Error: ' + xhr.status);
                        return;
                    }
                    let json = null;
                    try {
                        json = JSON.parse(xhr.responseText);
                    } catch (err) {
                        reject('Response parse error');
                        return;
                    }
                    if (!json || !json.success || !json.url) {
                        reject((json && json.message) ? json.message : 'Upload failed');
                        return;
                    }
                    resolve(json.url);
                };
                xhr.onerror = () => reject('Image upload failed');
                const formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                xhr.send(formData);
            })
        });
    });
</script>

<script>
// Site thuần Affiliate: không còn các loại sản phẩm khác nên không cần toggle.

// ===== AI rewrite: nút "Viết lại bằng AI" (Tên + Mô tả + Nội dung) + nút "Viết bài + SEO" =====
document.addEventListener('DOMContentLoaded', function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const allBtn = document.getElementById('aiRewriteAllBtn');
    const allResult = document.getElementById('aiRewriteAllResult');
    const seoBtn = document.getElementById('aiWriteSeoBtn');
    const seoResult = document.getElementById('aiWriteSeoResult');
    if (!allBtn && !seoBtn) return;

    // Slugify tiếng Việt (đồng bộ với create_slug phía PHP)
    function aiSlugify(str) {
        str = (str || '').toLowerCase();
        const map = {
            a: 'áàảãạăắằẳẵặâấầẩẫậ', e: 'éèẻẽẹêếềểễệ', i: 'íìỉĩị',
            o: 'óòỏõọôốồổỗộơớờởỡợ', u: 'úùủũụưứừửữự', y: 'ýỳỷỹỵ', d: 'đ'
        };
        for (const k in map) {
            str = str.replace(new RegExp('[' + map[k] + ']', 'g'), k);
        }
        return str.replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    }

    // Chạy AI viết lại Tên + Mô tả + Nội dung. onDone(success) gọi sau khi xong.
    function runAiRewrite(btn, resultEl, onDone) {
        const nameEl = document.getElementById('name');
        const descEl = document.getElementById('description');
        const nameVal = nameEl?.value || '';
        let contentVal = '';
        if (window.tinymce && tinymce.get('content')) {
            contentVal = tinymce.get('content').getContent();
        } else {
            contentVal = document.getElementById('content')?.value || '';
        }
        const seed = (descEl?.value || '') + "\n" + contentVal;
        if (!nameVal.trim() && !seed.trim()) {
            alert('Chưa có nội dung để viết lại.');
            return;
        }
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang viết...';
        if (resultEl) { resultEl.className = 'form-text text-muted'; resultEl.textContent = 'AI đang viết lại, vui lòng đợi...'; }

        const body = new URLSearchParams();
        body.set('mode', 'all');
        body.set('name', nameVal);
        body.set('text', seed);
        // Thu thập URL ảnh (đại diện + gallery) để AI chèn vào nội dung
        const imgUrls = [];
        document.querySelectorAll('#sortable-gallery img').forEach(function (im) {
            if (im.src) imgUrls.push(im.src);
        });
        const mainPrev = document.getElementById('main-preview');
        if (mainPrev && mainPrev.src && mainPrev.src.indexOf('http') === 0) {
            imgUrls.unshift(mainPrev.src);
        }
        imgUrls.forEach(function (u) { body.append('images[]', u); });
        if (window.AdminSecurity) { AdminSecurity.applyCsrf(body); }
        else { body.set('csrf_token', csrf); }

        fetch('../ajax/ai-rewrite.php', {
            method: 'POST',
            headers: window.AdminSecurity
                ? AdminSecurity.headers({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' })
                : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body
        })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = original;
                if (!data.success) {
                    if (resultEl) { resultEl.className = 'form-text text-danger'; resultEl.textContent = 'Lỗi AI: ' + (data.message || 'không rõ'); }
                    if (onDone) onDone(false);
                    return;
                }
                if (data.title && nameEl) {
                    nameEl.value = data.title;
                    // Sinh lại slug theo tiêu đề mới
                    const slugEl = document.getElementById('slug');
                    if (slugEl) {
                        slugEl.value = aiSlugify(data.title);
                        slugEl.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
                if (data.description && descEl) descEl.value = data.description;
                if (data.content) {
                    if (window.tinymce && tinymce.get('content')) {
                        tinymce.get('content').setContent(data.content);
                    } else {
                        const c = document.getElementById('content');
                        if (c) c.value = data.content;
                    }
                }
                if (resultEl) { resultEl.className = 'form-text text-success'; resultEl.textContent = 'Đã viết lại Tên + Mô tả + Nội dung. Kiểm tra lại trước khi lưu.'; }
                if (onDone) onDone(true);
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = original;
                if (resultEl) { resultEl.className = 'form-text text-danger'; resultEl.textContent = 'Không gọi được API AI.'; }
                if (onDone) onDone(false);
            });
    }

    if (allBtn) {
        allBtn.addEventListener('click', function () { runAiRewrite(allBtn, allResult, null); });
    }

    // Nút "Giả lập" lượt quan tâm / lượt xem cho riêng sản phẩm này (random vào ô input, lưu khi bấm Cập nhật)
    const randInt = (min, max) => Math.floor(min + Math.random() * (max - min + 1));
    const simInterestBtn = document.getElementById('simInterestBtn');
    if (simInterestBtn) {
        simInterestBtn.addEventListener('click', function () {
            const el = document.getElementById('click_count');
            if (el) el.value = randInt(2000, 10000);
        });
    }
    const simViewsBtn = document.getElementById('simViewsBtn');
    if (simViewsBtn) {
        simViewsBtn.addEventListener('click', function () {
            const el = document.getElementById('views');
            if (el) el.value = randInt(5000, 50000);
        });
    }

    // Nút "Tạo lại slug từ tên": sinh slug từ tên sản phẩm hiện tại (chủ động đổi URL)
    const regenSlugBtn = document.getElementById('regenSlugBtn');
    if (regenSlugBtn) {
        regenSlugBtn.addEventListener('click', function () {
            const nameEl = document.getElementById('name');
            const slugEl = document.getElementById('slug');
            if (!nameEl || !slugEl) return;
            const newSlug = aiSlugify(nameEl.value);
            if (!newSlug) { alert('Chưa có tên sản phẩm để tạo slug.'); return; }
            slugEl.value = newSlug;
            slugEl.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    // Nút "Viết bài + SEO": viết lại nội dung xong tự động tạo SEO
    if (seoBtn) {
        seoBtn.addEventListener('click', function () {
            runAiRewrite(seoBtn, seoResult, function (ok) {
                if (!ok) return;
                if (typeof autoSEO === 'function') {
                    if (seoResult) { seoResult.className = 'form-text text-muted'; seoResult.textContent = 'Đã viết bài. Đang tạo SEO tự động...'; }
                    autoSEO();
                    if (seoResult) {
                        setTimeout(function () {
                            seoResult.className = 'form-text text-success';
                            seoResult.textContent = 'Đã viết bài + tạo SEO. Kiểm tra lại trước khi lưu.';
                        }, 600);
                    }
                } else if (seoResult) {
                    seoResult.className = 'form-text text-warning';
                    seoResult.textContent = 'Đã viết bài, nhưng không tìm thấy chức năng Auto SEO trên trang.';
                }
            });
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>


