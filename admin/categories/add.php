<?php
// admin/categories/add.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'categories';
require_once '../includes/header.php';

$error = '';
$success = '';
$name = '';
$slug = '';
$description = '';
$content = '';
$sort_order = 0;

// Ensure categories.content exists for long-form SEO content
if (!has_table_column($pdo, 'categories', 'content')) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN content LONGTEXT NULL AFTER description");
    } catch (Exception $e) {
        // Keep page usable even if migration fails
    }
}
$has_category_content = has_table_column($pdo, 'categories', 'content');
if (!has_table_column($pdo, 'categories', 'sort_order')) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN sort_order INT(11) DEFAULT 0 AFTER parent_id");
    } catch (Exception $e) {
        // Keep page usable even if migration fails
    }
}
$has_category_sort_order = has_table_column($pdo, 'categories', 'sort_order');
if ($has_category_content) {
    // One-way data move for old records that stored HTML inside short description
    $pdo->exec("UPDATE categories SET content = description WHERE (content IS NULL OR content = '') AND description LIKE '%<%'");
}

if (!has_table_column($pdo, 'categories', 'focus_keyword')) {
    try { $pdo->exec("ALTER TABLE categories ADD COLUMN focus_keyword VARCHAR(255) DEFAULT NULL AFTER meta_keywords"); } catch (Exception $e) {}
}
if (!has_table_column($pdo, 'categories', 'og_image')) {
    try { $pdo->exec("ALTER TABLE categories ADD COLUMN og_image VARCHAR(500) DEFAULT NULL AFTER focus_keyword"); } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $content = $_POST['content'] ?? '';
    $status = isset($_POST['status']) ? 1 : 0;
    $parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
    $sort_order = (int) ($_POST['sort_order'] ?? 0);

    // SEO fields
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $focus_keyword = trim($_POST['focus_keyword'] ?? '');
    $og_image = trim($_POST['og_image'] ?? '');

    // Auto generate slug if empty
    $slug = !empty($_POST['slug']) ? create_slug($_POST['slug']) : create_slug($name);

    if (empty($name)) {
        $error = 'Vui lòng nhập tên danh mục.';
    } else {
        try {
            // Check if slug exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE slug = ?");
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Slug này đã tồn tại, vui lòng chọn tên khác.';
            } else {
                if ($has_category_content) {
                    if ($has_category_sort_order) {
                        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, content, parent_id, status, sort_order, meta_title, meta_description, meta_keywords, focus_keyword, og_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $slug, $description, $content, $parent_id, $status, $sort_order, $meta_title, $meta_description, $meta_keywords, $focus_keyword, $og_image]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, content, parent_id, status, meta_title, meta_description, meta_keywords, focus_keyword, og_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $slug, $description, $content, $parent_id, $status, $meta_title, $meta_description, $meta_keywords, $focus_keyword, $og_image]);
                    }
                } else {
                    if ($has_category_sort_order) {
                        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, parent_id, status, sort_order, meta_title, meta_description, meta_keywords, focus_keyword, og_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $slug, $description, $parent_id, $status, $sort_order, $meta_title, $meta_description, $meta_keywords, $focus_keyword, $og_image]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, description, parent_id, status, meta_title, meta_description, meta_keywords, focus_keyword, og_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $slug, $description, $parent_id, $status, $meta_title, $meta_description, $meta_keywords, $focus_keyword, $og_image]);
                    }
                }

                $new_id = $pdo->lastInsertId();
                if (function_exists('log_activity')) {
                    log_activity('create', 'category', $new_id, "Thêm danh mục: $name");
                }

                redirect('edit.php?id=' . (int) $new_id . '&created=1');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }
}

// SEO data for component (empty for new category)
$seo_data = [
    'meta_title' => $_POST['meta_title'] ?? '',
    'meta_description' => $_POST['meta_description'] ?? '',
    'meta_keywords' => $_POST['meta_keywords'] ?? '',
    'focus_keyword' => $_POST['focus_keyword'] ?? '',
    'og_image' => $_POST['og_image'] ?? '',
    'preview_title' => 'Tên danh mục',
    'preview_url' => BASE_URL . 'danh-muc/...'
];
?>

<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Thêm Danh mục mới</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Quay lại
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card shadow-sm">
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="name" class="form-label">Tên danh mục <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                value="<?php echo e($name); ?>" required
                                onkeyup="document.getElementById('slug').value = createSlug(this.value)">
                        </div>

                        <div class="mb-3">
                            <label for="slug" class="form-label">Slug (URL)</label>
                            <input type="text" class="form-control" id="slug" name="slug"
                                value="<?php echo e($slug); ?>">
                            <div class="form-text">Để trống sẽ tự động tạo từ tên.</div>
                        </div>

                        <div class="mb-3">
                            <label for="parent_id" class="form-label">Danh mục cha</label>
                            <select class="form-select" id="parent_id" name="parent_id">
                                <option value="">-- Không có (Danh mục gốc) --</option>
                                <?php echo render_category_options($pdo, null, null, false); ?>
                            </select>
                        </div>

                        <?php if ($has_category_sort_order): ?>
                            <div class="mb-3">
                                <label for="sort_order" class="form-label">Thứ tự hiển thị</label>
                                <input type="number" class="form-control" id="sort_order" name="sort_order" min="0"
                                    value="<?php echo (int) $sort_order; ?>">
                                <div class="form-text">Số nhỏ hơn sẽ hiển thị trước ở trang chủ.</div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="description" class="form-label">Mô tả ngắn</label>
                            <textarea class="form-control" id="description" name="description"
                                rows="3"><?php echo e($description); ?></textarea>
                            <div class="form-text">Hiển thị ở card danh mục ngoài trang chủ.</div>
                        </div>

                        <?php if ($has_category_content): ?>
                            <div class="mb-3">
                                <label for="content" class="form-label">Nội dung SEO (content)</label>
                                <textarea class="form-control" id="content" name="content"
                                    rows="10"><?php echo e($content); ?></textarea>
                                <div class="form-text">Hiển thị phía dưới danh sách dịch vụ trong trang danh mục.</div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="status" name="status" checked>
                            <label class="form-check-label" for="status">Kích hoạt</label>
                        </div>

                        <?php include '../includes/seo-fields.php'; ?>

                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-lg me-2"></i>Lưu danh mục
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    window.addEventListener('load', function () {
        <?php if ($has_category_content): ?>
            tinymce.init({
                selector: '#content',
                height: 420,
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
        <?php endif; ?>
    });


</script>

<?php require_once '../includes/footer.php'; ?>

