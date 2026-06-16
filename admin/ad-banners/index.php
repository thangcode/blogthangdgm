<?php
// admin/ad-banners/index.php — Danh sách banner quảng cáo
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/page-cache.php';
require_admin_login();

$current_page = 'ad-banners';
ad_banners_ensure_schema($pdo);

// ===== AJAX: toggle trạng thái / lưu thứ tự / xóa =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $resp = ['success' => false, 'message' => ''];
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF token không hợp lệ']);
        exit;
    }
    try {
        switch ($_POST['ajax']) {
            case 'toggle':
                $id = (int) ($_POST['id'] ?? 0);
                $status = (int) ($_POST['status'] ?? 0);
                $pdo->prepare("UPDATE ad_banners SET status = ? WHERE id = ?")->execute([$status ? 1 : 0, $id]);
                $resp = ['success' => true, 'message' => $status ? 'Đã bật' : 'Đã tắt'];
                break;
            case 'sort':
                $id = (int) ($_POST['id'] ?? 0);
                $val = (int) ($_POST['sort_order'] ?? 0);
                $pdo->prepare("UPDATE ad_banners SET sort_order = ? WHERE id = ?")->execute([$val, $id]);
                $resp = ['success' => true, 'message' => 'Đã lưu thứ tự'];
                break;
            case 'delete':
                $id = (int) ($_POST['id'] ?? 0);
                $pdo->prepare("DELETE FROM ad_banners WHERE id = ?")->execute([$id]);
                $resp = ['success' => true, 'message' => 'Đã xóa banner'];
                break;
        }
    } catch (Throwable $e) {
        error_log('ad-banners ajax error: ' . $e->getMessage());
        $resp = ['success' => false, 'message' => 'Không thể xử lý yêu cầu lúc này.'];
    }
    // Xóa cache trang để thay đổi banner hiện ngay ngoài site.
    if (!empty($resp['success']) && class_exists('PageCache')) { PageCache::flush(); }
    echo json_encode($resp);
    exit;
}

$slots = ad_slots();
$f_slot = trim((string) ($_GET['slot'] ?? ''));
$f_status = $_GET['status'] ?? '';

$cond = [];
$params = [];
if ($f_slot !== '' && isset($slots[$f_slot])) { $cond[] = 'slot = ?'; $params[] = $f_slot; }
if ($f_status === '1' || $f_status === '0') { $cond[] = 'status = ?'; $params[] = (int) $f_status; }
$where = $cond ? ('WHERE ' . implode(' AND ', $cond)) : '';

try {
    $stmt = $pdo->prepare("SELECT * FROM ad_banners $where ORDER BY slot ASC, sort_order ASC, id DESC");
    $stmt->execute($params);
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $banners = [];
}

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom flex-wrap gap-2">
        <h1 class="h2"><i class="bi bi-badge-ad me-2"></i>Banner Quảng cáo</h1>
        <a href="form.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Thêm banner</a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?php echo e($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="alert alert-info d-flex align-items-start"><i class="bi bi-info-circle-fill me-2 fs-5 mt-1"></i>
        <div>Banner quảng cáo hiển thị ở các vị trí cố định trên site (độc lập với Banner Slider trang chủ). Bật/tắt nhanh, đặt lịch chạy, và theo dõi lượt hiển thị/click ngay tại đây.</div>
    </div>

    <!-- Bộ lọc -->
    <form method="GET" class="card shadow-sm mb-3">
        <div class="card-body py-3 d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label small fw-bold mb-1">Vị trí</label>
                <select class="form-select form-select-sm" name="slot" style="min-width:200px;">
                    <option value="">Tất cả vị trí</option>
                    <?php foreach ($slots as $key => $info): ?>
                        <option value="<?php echo e($key); ?>" <?php echo $f_slot === $key ? 'selected' : ''; ?>><?php echo e($info['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small fw-bold mb-1">Trạng thái</label>
                <select class="form-select form-select-sm" name="status" style="min-width:140px;">
                    <option value="">Tất cả</option>
                    <option value="1" <?php echo $f_status === '1' ? 'selected' : ''; ?>>Đang bật</option>
                    <option value="0" <?php echo $f_status === '0' ? 'selected' : ''; ?>>Đã tắt</option>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Lọc</button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">Xóa lọc</a>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($banners)): ?>
                <div class="text-center text-muted py-5"><i class="bi bi-badge-ad fs-1 d-block mb-2 opacity-25"></i>Chưa có banner quảng cáo nào.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="90">Ảnh</th>
                            <th>Tiêu đề / Vị trí</th>
                            <th width="160">Lịch chạy</th>
                            <th width="110" class="text-center">Trạng thái</th>
                            <th width="80" class="text-center">Thứ tự</th>
                            <th width="150" class="text-center">Hiển thị / Click</th>
                            <th width="110" class="text-end">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($banners as $b):
                            $st = ad_banner_status_label($b);
                            $img = $b['image_path'] ? (strpos($b['image_path'], 'http') === 0 ? $b['image_path'] : BASE_URL . $b['image_path']) : 'https://placehold.co/80x40?text=%20';
                            $imp = (int) $b['impressions'];
                            $clk = (int) $b['clicks'];
                            $ctr = $imp > 0 ? round($clk / $imp * 100, 1) : 0;
                            $sched = [];
                            if (!empty($b['start_at'])) $sched[] = 'Từ ' . date('d/m/y H:i', strtotime($b['start_at']));
                            if (!empty($b['end_at'])) $sched[] = 'Đến ' . date('d/m/y H:i', strtotime($b['end_at']));
                        ?>
                        <tr data-id="<?php echo (int) $b['id']; ?>">
                            <td><img src="<?php echo e($img); ?>" alt="" style="width:80px;height:42px;object-fit:cover;border-radius:6px;"></td>
                            <td>
                                <div class="fw-semibold"><?php echo e($b['title']); ?></div>
                                <span class="badge bg-light text-dark"><?php echo e($slots[$b['slot']]['label'] ?? $b['slot']); ?></span>
                            </td>
                            <td><small class="text-muted"><?php echo $sched ? e(implode('<br>', $sched)) : 'Không giới hạn'; ?></small></td>
                            <td class="text-center"><span class="badge <?php echo $st['class']; ?>"><?php echo e($st['label']); ?></span></td>
                            <td class="text-center">
                                <input type="number" class="form-control form-control-sm text-center ad-sort-input" value="<?php echo (int) $b['sort_order']; ?>" data-id="<?php echo (int) $b['id']; ?>" style="width:64px;margin:auto;">
                            </td>
                            <td class="text-center">
                                <span title="Lượt hiển thị"><i class="bi bi-eye text-muted"></i> <?php echo number_format($imp); ?></span>
                                <span class="ms-2" title="Lượt click"><i class="bi bi-cursor-fill text-danger"></i> <?php echo number_format($clk); ?></span>
                                <div class="small text-muted">CTR <?php echo $ctr; ?>%</div>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input ad-toggle" type="checkbox" role="switch" data-id="<?php echo (int) $b['id']; ?>" <?php echo $b['status'] ? 'checked' : ''; ?> style="cursor:pointer;">
                                    </div>
                                    <a href="form.php?id=<?php echo (int) $b['id']; ?>" class="btn btn-sm btn-outline-primary" title="Sửa"><i class="bi bi-pencil"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-danger ad-delete" data-id="<?php echo (int) $b['id']; ?>" title="Xóa"><i class="bi bi-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var CSRF = (window.AdminSecurity && AdminSecurity.csrfToken) ? AdminSecurity.csrfToken() : ((document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    function post(body, cb) {
        body.csrf_token = CSRF;
        fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body).toString()
        }).then(r => r.json()).then(cb).catch(function () { alert('Lỗi kết nối'); });
    }
    document.querySelectorAll('.ad-toggle').forEach(function (t) {
        t.addEventListener('change', function () {
            var self = this;
            post({ ajax: 'toggle', id: self.dataset.id, status: self.checked ? 1 : 0 }, function (d) {
                if (!d.success) { self.checked = !self.checked; alert(d.message || 'Lỗi'); }
            });
        });
    });
    document.querySelectorAll('.ad-sort-input').forEach(function (inp) {
        inp.addEventListener('change', function () {
            post({ ajax: 'sort', id: this.dataset.id, sort_order: this.value }, function () {});
        });
    });
    document.querySelectorAll('.ad-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Xóa banner này?')) return;
            var row = this.closest('tr');
            post({ ajax: 'delete', id: this.dataset.id }, function (d) {
                if (d.success) { row.remove(); } else { alert(d.message || 'Lỗi'); }
            });
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
