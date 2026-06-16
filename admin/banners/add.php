<?php
// admin/banners/add.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$current_page = 'banners';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_valid_csrf_token();
    $title = trim($_POST['title']);
    $link_url = trim($_POST['link_url']);
    $sort_order = (int) $_POST['sort_order'];
    $status = isset($_POST['status']) ? 1 : 0;

    // Image from Media Library (path string)
    $image_path = trim($_POST['image'] ?? '');
    $mobile_image_path = trim($_POST['mobile_image'] ?? '') ?: null;

    // Validate paths (security check)
    if (!empty($image_path) && (strpos($image_path, 'assets/') !== 0 || strpos($image_path, '..') !== false)) {
        $error = 'Đường dẫn ảnh không hợp lệ.';
        $image_path = '';
    }
    if (!empty($mobile_image_path) && (strpos($mobile_image_path, 'assets/') !== 0 || strpos($mobile_image_path, '..') !== false)) {
        $mobile_image_path = null;
    }

    if (empty($title)) {
        $error = 'Vui lòng nhập tiêu đề Banner.';
    } elseif (empty($image_path)) {
        if (empty($error)) $error = 'Vui lòng chọn ảnh Banner.';
    }

    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO banners (title, image_path, mobile_image_path, link_url, sort_order, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $image_path, $mobile_image_path, $link_url, $sort_order, $status]);
            $new_id = $pdo->lastInsertId();
            if (function_exists('log_activity')) {
                log_activity('create', 'banner', $new_id, "Thêm banner: $title");
            }
            $_SESSION['success'] = "Thêm banner thành công!";
            header("Location: index.php");
            exit;
        } catch (PDOException $e) {
            $error = "Lỗi database: " . $e->getMessage();
        }
    }
}

require_once '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2 class="fw-bold text-primary"><i class="bi bi-plus-circle me-2"></i> Thêm Banner Mới</h2>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i> Quay lại</a>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Tiêu đề Banner <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" required
                            placeholder="Nhập tiêu đề hoặc mô tả ngắn"
                            value="<?php echo e($_POST['title'] ?? ''); ?>">
                        <div class="form-text">Dùng để quản lý và thẻ alt cho ảnh.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Đường dẫn (Link)</label>
                        <input type="text" class="form-control" name="link_url"
                            placeholder="https://... hoặc #services"
                            value="<?php echo e($_POST['link_url'] ?? ''); ?>">
                        <div class="form-text">Link khi người dùng click vào banner (để trống nếu không cần).</div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Thứ tự hiển thị</label>
                        <input type="number" class="form-control" name="sort_order"
                            value="<?php echo e($_POST['sort_order'] ?? '0'); ?>" min="0">
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch p-0 mt-4">
                            <label class="form-check-label fw-bold ms-5" for="status">Hiển thị</label>
                            <input class="form-check-input ms-0 fs-5" type="checkbox" id="status" name="status" checked>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Hình ảnh PC (Desktop) <span
                                class="text-danger">*</span></label>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control" id="image" name="image" readonly
                                placeholder="Chọn ảnh từ thư viện..."
                                value="<?php echo e($_POST['image'] ?? ''); ?>">
                            <button type="button" class="btn btn-primary init-media-selector"
                                data-input="image" data-preview="preview-desktop">
                                <i class="bi bi-images me-1"></i> Chọn ảnh
                            </button>
                        </div>
                        <div class="form-text text-muted">Kích thước khuyên dùng: 1920x600px. Ảnh sẽ được tự động nén
                            WebP nếu bật trong Cấu hình.</div>
                        <div class="mt-3 text-center bg-light rounded p-3"
                            style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                            <img id="preview-desktop" src="#" alt="Preview Desktop"
                                style="max-width: 100%; max-height: 200px; display: none;">
                            <span id="placeholder-desktop" class="text-muted small">Preview ảnh PC</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Hình ảnh Mobile (Tùy chọn)</label>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control" id="mobile_image" name="mobile_image" readonly
                                placeholder="Chọn ảnh từ thư viện..."
                                value="<?php echo e($_POST['mobile_image'] ?? ''); ?>">
                            <button type="button" class="btn btn-primary init-media-selector"
                                data-input="mobile_image" data-preview="preview-mobile">
                                <i class="bi bi-images me-1"></i> Chọn ảnh
                            </button>
                        </div>
                        <div class="form-text text-muted">Kích thước khuyên dùng: 800x800px hoặc 600x800px. Nếu không
                            chọn sẽ dùng ảnh PC.</div>
                        <div class="mt-3 text-center bg-light rounded p-3"
                            style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                            <img id="preview-mobile" src="#" alt="Preview Mobile"
                                style="max-width: 100%; max-height: 200px; display: none;">
                            <span id="placeholder-mobile" class="text-muted small">Preview ảnh Mobile</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-primary px-4 py-2"><i class="bi bi-save me-2"></i> Lưu
                    Banner</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Show preview when media is selected
    document.getElementById('image')?.addEventListener('change', function () {
        const preview = document.getElementById('preview-desktop');
        const placeholder = document.getElementById('placeholder-desktop');
        if (this.value) {
            preview.src = '<?php echo BASE_URL; ?>' + this.value;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        }
    });

    document.getElementById('mobile_image')?.addEventListener('change', function () {
        const preview = document.getElementById('preview-mobile');
        const placeholder = document.getElementById('placeholder-mobile');
        if (this.value) {
            preview.src = '<?php echo BASE_URL; ?>' + this.value;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
