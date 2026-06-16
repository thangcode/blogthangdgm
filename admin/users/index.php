<?php
// admin/users/index.php - Quản lý tài khoản admin
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'users';
require_once '../includes/header.php';

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Fetch all admin users
$stmt = $pdo->query("SELECT id, username, full_name, email, created_at FROM users WHERE role = 'admin' ORDER BY id ASC");
$users = $stmt->fetchAll();
?>

<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-people me-2"></i>Quản lý Admin</h1>
        <a href="<?php echo BASE_URL; ?>admin/users/add.php" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i> Thêm Admin mới
        </a>
    </div>

    <?php if ($success === 'added'): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Tạo tài khoản
        admin mới thành công! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php
elseif ($success === 'updated'): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Cập nhật tài
        khoản thành công! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php
elseif ($success === 'deleted'): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>Đã xóa tài
        khoản! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php
endif; ?>

    <?php if ($error === 'cannot_delete_self'): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i>Không thể
        xóa tài khoản đang đăng nhập! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php
elseif ($error === 'last_admin'): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i>Không thể
        xóa — hệ thống phải có ít nhất 1 admin! <button type="button" class="btn-close"
            data-bs-dismiss="alert"></button></div>
    <?php
endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>Tên đăng nhập</th>
                        <th>Họ và tên</th>
                        <th>Email</th>
                        <th>Ngày tạo</th>
                        <th style="width:160px">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr <?php if ($u['id']==$_SESSION['user_id'])
        echo 'class="table-active"' ; ?>>
                        <td class="text-muted">#
                            <?php echo $u['id']; ?>
                        </td>
                        <td>
                            <strong>
                                <?php echo e($u['username']); ?>
                            </strong>
                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                            <span class="badge bg-primary ms-1" style="font-size:0.7rem;">Bạn</span>
                            <?php
    endif; ?>
                        </td>
                        <td>
                            <?php echo e($u['full_name'] ?? '—'); ?>
                        </td>
                        <td>
                            <?php echo e($u['email'] ?? '—'); ?>
                        </td>
                        <td class="text-muted" style="font-size:0.85rem;">
                            <?php echo $u['created_at'] ? date('d/m/Y H:i', strtotime($u['created_at'])) : '—'; ?>
                        </td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>admin/users/edit.php?id=<?php echo $u['id']; ?>"
                                class="btn btn-sm btn-outline-primary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                onclick="confirmDelete(<?php echo $u['id']; ?>, '<?php echo e($u['username']); ?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php
    endif; ?>
                        </td>
                    </tr>
                    <?php
endforeach; ?>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Chưa có tài khoản admin nào.</td>
                    </tr>
                    <?php
endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal xác nhận xóa -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Xác nhận xóa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Bạn có chắc muốn xóa tài khoản <strong id="deleteUsername"></strong>? Hành động này không thể hoàn tác.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <form method="POST" action="<?php echo BASE_URL; ?>admin/users/delete.php">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                    <input type="hidden" name="id" id="deleteId">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Xóa</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmDelete(id, username) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteUsername').textContent = username;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>

<?php require_once '../includes/footer.php'; ?>
