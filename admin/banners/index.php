<?php
// admin/banners/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$current_page = 'banners';

// Handle Action
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';

if ($action === 'toggle_status' && $id > 0) {
    if (!verify_csrf_token($csrfToken)) {
        $_SESSION['error'] = 'Invalid security token.';
        header("Location: index.php");
        exit;
    }
    try {
        $stmt = $pdo->prepare("UPDATE banners SET status = NOT status WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success'] = "Đã thay đổi trạng thái banner.";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Lỗi: " . $e->getMessage();
    }
    header("Location: index.php");
    exit;
}

if ($action === 'delete' && $id > 0) {
    header("Location: delete.php?id=$id&csrf_token=" . urlencode($csrfToken));
    exit;
}


// Fetch Banners
try {
    $stmt = $pdo->query("SELECT * FROM banners ORDER BY sort_order ASC, id DESC");
    $banners = $stmt->fetchAll();
} catch (PDOException $e) {
    $banners = [];
    $error = "Lỗi tải dữ liệu: " . $e->getMessage();
}

require_once '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2 class="fw-bold text-primary"><i class="bi bi-images me-2"></i> Quản lý Banner Slider</h2>
        <p class="text-muted">Quản lý các banner hiển thị trên trang chủ.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="add.php" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i> Thêm Banner Mới</a>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <?php echo $_SESSION['success'];
        unset($_SESSION['success']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?php echo $_SESSION['error'];
        unset($_SESSION['error']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <?php if (empty($banners)): ?>
            <div class="text-center py-5">
                <img src="https://cdni.iconscout.com/illustration/premium/thumb/empty-state-2130362-1800926.png" alt="Empty"
                    style="max-width: 200px; opacity: 0.5;">
                <p class="text-muted mt-3">Chưa có banner nào. Hãy thêm banner mới ngay!</p>
                <a href="add.php" class="btn btn-primary mt-2">Thêm Mới Ngay</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="bannersTable">
                    <thead class="table-light">
                        <tr>
                            <th width="40"></th>
                            <th width="50">#</th>
                            <th width="150">Hình ảnh</th>
                            <th>Tiêu đề / Link</th>
                            <th width="100" class="text-center">Thứ tự</th>
                            <th width="100" class="text-center">Trạng thái</th>
                            <th width="150" class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="bannersSortable">
                        <?php foreach ($banners as $index => $banner): ?>
                            <tr data-id="<?php echo $banner['id']; ?>">
                                <td class="drag-handle" style="cursor: grab; color: #aaa;"><i class="bi bi-grip-vertical fs-5"></i></td>
                                <td>
                                    <?php echo $index + 1; ?>
                                </td>
                                <td>
                                    <?php if (!empty($banner['image_path'])): ?>
                                        <?php 
                                            $banner_src = (strpos($banner['image_path'], 'http') === 0 || strpos($banner['image_path'], '//') === 0) ? $banner['image_path'] : BASE_URL . $banner['image_path'];
                                        ?>
                                        <img src="<?php echo $banner_src; ?>" class="img-thumbnail"
                                            style="height: 60px; object-fit: cover;" alt="Banner">
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">Không có ảnh</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <?php echo e($banner['title']); ?>
                                    </div>
                                    <?php if (!empty($banner['link_url'])): ?>
                                        <small class="text-muted"><i class="bi bi-link-45deg"></i>
                                            <?php echo e($banner['link_url']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary rounded-pill px-3">
                                        <?php echo $banner['sort_order']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($banner['status'] == 1): ?>
                                        <a href="index.php?action=toggle_status&id=<?php echo $banner['id']; ?>&csrf_token=<?php echo urlencode(generate_csrf_token()); ?>"
                                            class="badge bg-success text-decoration-none" title="Nhấn để ẩn">Hiển thị</a>
                                    <?php else: ?>
                                        <a href="index.php?action=toggle_status&id=<?php echo $banner['id']; ?>&csrf_token=<?php echo urlencode(generate_csrf_token()); ?>"
                                            class="badge bg-secondary text-decoration-none" title="Nhấn để hiện">Đang ẩn</a>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="edit.php?id=<?php echo $banner['id']; ?>" class="btn btn-outline-primary"
                                            title="Sửa">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="delete.php?id=<?php echo $banner['id']; ?>&csrf_token=<?php echo urlencode(generate_csrf_token()); ?>" class="btn btn-outline-danger"
                                            data-confirm-link="&#66;&#7841;n c&#243; ch&#7855;c ch&#7855;n mu&#7889;n x&#243;a banner n&#224;y?" data-confirm-title="X&#243;a banner" data-confirm-ok="X&#243;a ngay" data-confirm-class="btn-danger" title="Xóa">
                                            <i class="bi bi-trash"></i>
                                        </a>
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

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('bannersSortable');
    if (!tbody) return;

    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 200,
        ghostClass: 'table-warning',
        onEnd: function() {
            const rows = tbody.querySelectorAll('tr[data-id]');
            const order = Array.from(rows).map(r => r.getAttribute('data-id'));

            // Update visual numbering
            rows.forEach((row, i) => {
                row.children[1].textContent = i + 1;
                row.querySelector('.badge').textContent = i + 1;
            });

            fetch('../ajax/sort-banners.php', {
                method: 'POST',
                headers: AdminSecurity.headers({'Content-Type': 'application/json'}),
                body: JSON.stringify({order: order, csrf_token: AdminSecurity.csrfToken()})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (window.AdminPopup) {
                        AdminPopup.success(data.message);
                    }
                } else {
                    if (window.AdminPopup) {
                        AdminPopup.error(data.message || 'Lỗi cập nhật');
                    } else {
                        alert(data.message || 'Lỗi cập nhật');
                    }
                }
            })
            .catch(() => {
                if (window.AdminPopup) {
                    AdminPopup.error('Lỗi kết nối server');
                } else {
                    alert('Lỗi kết nối server');
                }
            });
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
