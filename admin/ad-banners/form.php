<?php
// admin/ad-banners/form.php — Thêm/Sửa banner quảng cáo
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/page-cache.php';
require_admin_login();

$current_page = 'ad-banners';
ad_banners_ensure_schema($pdo);

$slots = ad_slots();
$errors = [];
$is_edit = false;

$banner = [
    'id' => 0, 'title' => '', 'image_path' => '', 'mobile_image_path' => '',
    'link_url' => '', 'slot' => '', 'sort_order' => 0, 'status' => 1,
    'start_at' => '', 'end_at' => '',
];

// Chuẩn hóa datetime-local (Y-m-dTH:i) -> DATETIME (Y-m-d H:i:s) hoặc null
function ad_parse_datetime(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') return null;
    $ts = strtotime(str_replace('T', ' ', $v));
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
// DATETIME -> giá trị cho input datetime-local
function ad_dt_local(?string $v): string
{
    $v = trim((string) $v);
    if ($v === '') return '';
    $ts = strtotime($v);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}
function ad_valid_image_path(string $p): bool
{
    if ($p === '') return false;
    if (strpos($p, '..') !== false) return false;
    return strpos($p, 'assets/') === 0 || strpos($p, 'uploads/') === 0;
}
function ad_valid_link(string $u): bool
{
    if ($u === '' || $u === '#') return true;
    return (bool) preg_match('~^(https?://|/|#)~i', $u);
}

// Load bản ghi khi sửa
$edit_id = (int) ($_GET['id'] ?? 0);
if ($edit_id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT * FROM ad_banners WHERE id = ?");
    $stmt->execute([$edit_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $banner = $row;
        $is_edit = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $post_id = (int) ($_POST['id'] ?? 0);
    $is_edit = $post_id > 0;

    $title    = trim((string) ($_POST['title'] ?? ''));
    $slot     = trim((string) ($_POST['slot'] ?? ''));
    $image    = trim((string) ($_POST['image'] ?? ''));
    $mobile   = trim((string) ($_POST['mobile_image'] ?? ''));
    $link_url = trim((string) ($_POST['link_url'] ?? ''));
    $sort     = (int) ($_POST['sort_order'] ?? 0);
    $status   = isset($_POST['status']) ? 1 : 0;
    $start_at = ad_parse_datetime($_POST['start_at'] ?? '');
    $end_at   = ad_parse_datetime($_POST['end_at'] ?? '');

    $banner = array_merge($banner, [
        'id' => $post_id, 'title' => $title, 'image_path' => $image, 'mobile_image_path' => $mobile,
        'link_url' => $link_url, 'slot' => $slot, 'sort_order' => $sort, 'status' => $status,
        'start_at' => $start_at, 'end_at' => $end_at,
    ]);

    if ($title === '') $errors[] = 'Vui lòng nhập tiêu đề banner.';
    if (!is_valid_ad_slot($slot)) $errors[] = 'Vui lòng chọn vị trí hợp lệ.';
    if (!ad_valid_image_path($image)) $errors[] = 'Vui lòng chọn ảnh desktop từ thư viện.';
    if ($mobile !== '' && !ad_valid_image_path($mobile)) { $mobile = ''; $banner['mobile_image_path'] = ''; }
    if (!ad_valid_link($link_url)) $errors[] = 'URL đích không hợp lệ (phải là http(s)://, đường dẫn nội bộ, hoặc #).';

    if (empty($errors)) {
        try {
            if ($is_edit) {
                $stmt = $pdo->prepare("UPDATE ad_banners SET title=?, image_path=?, mobile_image_path=?, link_url=?, slot=?, sort_order=?, status=?, start_at=?, end_at=? WHERE id=?");
                $stmt->execute([$title, $image, $mobile, $link_url, $slot, $sort, $status, $start_at, $end_at, $post_id]);
                if (function_exists('log_activity')) log_activity('update', 'ad_banner', $post_id, "Cập nhật banner QC: $title");
                $_SESSION['flash_success'] = 'Đã cập nhật banner quảng cáo.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO ad_banners (title, image_path, mobile_image_path, link_url, slot, sort_order, status, start_at, end_at) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$title, $image, $mobile, $link_url, $slot, $sort, $status, $start_at, $end_at]);
                $new_id = (int) $pdo->lastInsertId();
                if (function_exists('log_activity')) log_activity('create', 'ad_banner', $new_id, "Thêm banner QC: $title");
                $_SESSION['flash_success'] = 'Đã thêm banner quảng cáo.';
            }
            // Xóa cache trang để banner xuất hiện ngay (trang chủ/sản phẩm/bài viết được cache).
            if (class_exists('PageCache')) { PageCache::flush(); }
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Lỗi database: ' . $e->getMessage();
        }
    }
}

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-<?php echo $is_edit ? 'pencil-square' : 'plus-circle'; ?> me-2"></i><?php echo $is_edit ? 'Sửa Banner Quảng cáo' : 'Thêm Banner Quảng cáo'; ?></h1>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Quay lại</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?php echo e($er); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $banner['id']; ?>">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Tiêu đề <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" value="<?php echo e($banner['title']); ?>" required placeholder="VD: Khuyến mãi Shopee 9.9">
                            <div class="form-text">Dùng để quản lý và làm thẻ alt cho ảnh.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">URL đích</label>
                            <input type="text" class="form-control" name="link_url" value="<?php echo e($banner['link_url']); ?>" placeholder="https://s.shopee.vn/... hoặc /danh-muc/...">
                            <div class="form-text">Liên kết ngoài sẽ tự gắn <code>rel="nofollow sponsored"</code> và mở tab mới.</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Hình ảnh Desktop <span class="text-danger">*</span></label>
                                <div class="input-group mb-2">
                                    <input type="text" class="form-control" id="image" name="image" readonly placeholder="Chọn ảnh..." value="<?php echo e($banner['image_path']); ?>">
                                    <button type="button" class="btn btn-primary init-media-selector" data-input="image" data-preview="preview-desktop"><i class="bi bi-images me-1"></i>Chọn</button>
                                </div>
                                <div class="text-center bg-light rounded p-2" style="min-height:90px;display:flex;align-items:center;justify-content:center;">
                                    <img id="preview-desktop" src="<?php echo $banner['image_path'] ? e(BASE_URL . $banner['image_path']) : '#'; ?>" alt="" style="max-width:100%;max-height:140px;<?php echo $banner['image_path'] ? '' : 'display:none;'; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Hình ảnh Mobile (tùy chọn)</label>
                                <div class="input-group mb-2">
                                    <input type="text" class="form-control" id="mobile_image" name="mobile_image" readonly placeholder="Chọn ảnh..." value="<?php echo e($banner['mobile_image_path']); ?>">
                                    <button type="button" class="btn btn-primary init-media-selector" data-input="mobile_image" data-preview="preview-mobile"><i class="bi bi-images me-1"></i>Chọn</button>
                                </div>
                                <div class="text-center bg-light rounded p-2" style="min-height:90px;display:flex;align-items:center;justify-content:center;">
                                    <img id="preview-mobile" src="<?php echo $banner['mobile_image_path'] ? e(BASE_URL . $banner['mobile_image_path']) : '#'; ?>" alt="" style="max-width:100%;max-height:140px;<?php echo $banner['mobile_image_path'] ? '' : 'display:none;'; ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Vị trí <span class="text-danger">*</span></label>
                            <select class="form-select" name="slot" id="adSlotSelect" required>
                                <option value="">-- Chọn vị trí --</option>
                                <?php foreach ($slots as $key => $info): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo $banner['slot'] === $key ? 'selected' : ''; ?> data-desc="<?php echo e($info['desc']); ?>" data-size="<?php echo e($info['size'] ?? ''); ?>"><?php echo e($info['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="adSlotDesc"><?php echo e($slots[$banner['slot']]['desc'] ?? 'Vị trí banner hiển thị trên site.'); ?></div>
                            <div class="alert alert-info py-2 px-3 mt-2 mb-0 small" id="adSlotSize" style="<?php echo empty($slots[$banner['slot']]['size']) ? 'display:none;' : ''; ?>">
                                <i class="bi bi-aspect-ratio me-1"></i><strong>Kích thước khuyên dùng:</strong> <span id="adSlotSizeText"><?php echo e($slots[$banner['slot']]['size'] ?? ''); ?></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Thứ tự</label>
                            <input type="number" class="form-control" name="sort_order" value="<?php echo (int) $banner['sort_order']; ?>" min="0">
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label fw-bold">Bắt đầu</label>
                                <input type="datetime-local" class="form-control" name="start_at" value="<?php echo e(ad_dt_local($banner['start_at'] ?? '')); ?>">
                            </div>
                            <div class="col-12 mt-2">
                                <label class="form-label fw-bold">Kết thúc</label>
                                <input type="datetime-local" class="form-control" name="end_at" value="<?php echo e(ad_dt_local($banner['end_at'] ?? '')); ?>">
                            </div>
                            <div class="form-text">Để trống = chạy không giới hạn thời gian.</div>
                        </div>
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" name="status" id="status" <?php echo $banner['status'] ? 'checked' : ''; ?> style="width:3em;height:1.5em;">
                            <label class="form-check-label fw-bold" for="status">Bật hiển thị</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-3 py-2"><i class="bi bi-save me-1"></i><?php echo $is_edit ? 'Cập nhật' : 'Tạo banner'; ?></button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
['image', 'mobile_image'].forEach(function (id) {
    var input = document.getElementById(id);
    if (!input) return;
    input.addEventListener('change', function () {
        var prev = document.getElementById(id === 'image' ? 'preview-desktop' : 'preview-mobile');
        if (prev && this.value) {
            prev.src = '<?php echo BASE_URL; ?>' + this.value;
            prev.style.display = 'block';
        }
    });
});

// Cập nhật gợi ý kích thước + mô tả theo vị trí được chọn
(function () {
    var sel = document.getElementById('adSlotSelect');
    if (!sel) return;
    sel.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var desc = opt.getAttribute('data-desc') || '';
        var size = opt.getAttribute('data-size') || '';
        var descEl = document.getElementById('adSlotDesc');
        var sizeBox = document.getElementById('adSlotSize');
        var sizeText = document.getElementById('adSlotSizeText');
        if (descEl) descEl.textContent = desc || 'Vị trí banner hiển thị trên site.';
        if (sizeBox && sizeText) {
            if (size) { sizeText.textContent = size; sizeBox.style.display = ''; }
            else { sizeBox.style.display = 'none'; }
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
