<?php
// admin/banners/edit.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$current_page = 'banners';
$error = '';
$success = '';

$id = $_GET['id'] ?? 0;

// Fetch banner
try {
    $stmt = $pdo->prepare("SELECT * FROM banners WHERE id = ?");
    $stmt->execute([$id]);
    $banner = $stmt->fetch();

    if (!$banner) {
        $_SESSION['error'] = "Banner không tồn tại.";
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    die("Lỗi: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_valid_csrf_token();
    $title = trim($_POST['title']);
    $link_url = trim($_POST['link_url']);
    $sort_order = (int) $_POST['sort_order'];
    $status = isset($_POST['status']) ? 1 : 0;

    // Image from Media Library (keep old if empty)
    $image_path = trim($_POST['image'] ?? '');
    $mobile_image_path = trim($_POST['mobile_image'] ?? '');

    // Fallback to current values if not changed
    if (empty($image_path)) {
        $image_path = $banner['image_path'];
    }
    if (empty($mobile_image_path)) {
        $mobile_image_path = $banner['mobile_image_path'];
    }

    // Validate paths (security check)
    if (!empty($image_path) && strpos($image_path, 'assets/') !== 0) {
        $error = 'Đường dẫn ảnh không hợp lệ.';
        $image_path = $banner['image_path'];
    }
    if (!empty($mobile_image_path) && strpos($mobile_image_path, 'assets/') !== 0) {
        $mobile_image_path = $banner['mobile_image_path'];
    }

    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("UPDATE banners SET title = ?, image_path = ?, mobile_image_path = ?, link_url = ?, sort_order = ?, status = ? WHERE id = ?");
            $stmt->execute([$title, $image_path, $mobile_image_path, $link_url, $sort_order, $status, $id]);
            if (function_exists('log_activity')) {
                log_activity('update', 'banner', $id, "Cập nhật banner: $title");
            }
            $_SESSION['success'] = "Cập nhật banner thành công!";
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
        <h2 class="fw-bold text-primary"><i class="bi bi-pencil-square me-2"></i> Sửa Banner</h2>
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
                            value="<?php echo e($banner['title']); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Đường dẫn (Link)</label>
                        <input type="text" class="form-control" name="link_url"
                            value="<?php echo e($banner['link_url'] ?? ''); ?>">
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Thứ tự hiển thị</label>
                        <input type="number" class="form-control" name="sort_order" min="0"
                            value="<?php echo e($banner['sort_order']); ?>">
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch p-0 mt-4">
                            <label class="form-check-label fw-bold ms-5" for="status">Hiển thị</label>
                            <input class="form-check-input ms-0 fs-5" type="checkbox" id="status" name="status" <?php echo $banner['status'] ? 'checked' : ''; ?>>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Hình ảnh PC (Desktop)</label>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control" id="image" name="image" readonly
                                placeholder="Chọn ảnh mới từ thư viện..."
                                value="">
                            <button type="button" class="btn btn-primary init-media-selector"
                                data-input="image" data-preview="preview-desktop">
                                <i class="bi bi-images me-1"></i> Chọn ảnh
                            </button>
                        </div>
                        <div class="form-text text-muted">Chọn ảnh mới để thay thế. Để trống giữ ảnh hiện tại.</div>
                        <div class="mt-3 text-center bg-light rounded p-3"
                            style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                            <?php 
                                $desktop_preview_src = (strpos($banner['image_path'], 'http') === 0 || strpos($banner['image_path'], '//') === 0) ? $banner['image_path'] : BASE_URL . $banner['image_path'];
                            ?>
                            <img id="preview-desktop" src="<?php echo $desktop_preview_src; ?>"
                                alt="Preview Desktop" style="max-width: 100%; max-height: 200px;">
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Hình ảnh Mobile (Tùy chọn)</label>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control" id="mobile_image" name="mobile_image" readonly
                                placeholder="Chọn ảnh mới từ thư viện..."
                                value="">
                            <button type="button" class="btn btn-primary init-media-selector"
                                data-input="mobile_image" data-preview="preview-mobile">
                                <i class="bi bi-images me-1"></i> Chọn ảnh
                            </button>
                        </div>
                        <div class="form-text text-muted">Chọn ảnh mới để thay thế. Để trống giữ ảnh hiện tại.</div>
                        <div class="mt-3 text-center bg-light rounded p-3"
                            style="min-height: 150px; display: flex; align-items: center; justify-content: center;">
                            <?php if (!empty($banner['mobile_image_path'])): ?>
                                <?php 
                                    $mobile_preview_src = (strpos($banner['mobile_image_path'], 'http') === 0 || strpos($banner['mobile_image_path'], '//') === 0) ? $banner['mobile_image_path'] : BASE_URL . $banner['mobile_image_path'];
                                ?>
                                <img id="preview-mobile" src="<?php echo $mobile_preview_src; ?>"
                                    alt="Preview Mobile" style="max-width: 100%; max-height: 200px;">
                            <?php else: ?>
                                <img id="preview-mobile" src="#" alt="Preview Mobile"
                                    style="max-width: 100%; max-height: 200px; display: none;">
                                <span id="placeholder-mobile" class="text-muted small">Chưa có ảnh mobile</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-primary px-4 py-2"><i class="bi bi-save me-2"></i> Lưu Cập
                    Nhật</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Show preview when media is selected
    document.getElementById('image')?.addEventListener('change', function () {
        const preview = document.getElementById('preview-desktop');
        if (this.value) {
            const isAbsolute = this.value.startsWith('http') || this.value.startsWith('//');
            preview.src = (isAbsolute ? '' : '<?php echo BASE_URL; ?>') + this.value;
            preview.style.display = 'block';
        }
    });

    document.getElementById('mobile_image')?.addEventListener('change', function () {
        const preview = document.getElementById('preview-mobile');
        const placeholder = document.getElementById('placeholder-mobile');
        if (this.value) {
            const isAbsolute = this.value.startsWith('http') || this.value.startsWith('//');
            preview.src = (isAbsolute ? '' : '<?php echo BASE_URL; ?>') + this.value;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
