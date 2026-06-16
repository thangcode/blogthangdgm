<?php
// admin/users/add.php - Thêm Admin mới
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'users';
require_once '../includes/header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($full_name) || empty($password)) {
        $error = 'Vui lòng điền đầy đủ: Tên đăng nhập, Họ tên và Mật khẩu.';
    }
    elseif (strlen($password) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự.';
    }
    elseif ($password !== $confirm_password) {
        $error = 'Mật khẩu xác nhận không khớp.';
    }
    else {
        try {
            // Check duplicate username
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                $error = 'Tên đăng nhập <strong>' . e($username) . '</strong> đã tồn tại.';
            }
            else {
                $hashed = secure_password($password);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, full_name, email, created_at) VALUES (?, ?, 'admin', ?, ?, NOW())");
                $stmt->execute([$username, $hashed, $full_name, $email]);

                if (function_exists('log_activity')) {
                    log_activity('create', 'user', $pdo->lastInsertId(), "Tạo admin: $username");
                }

                header('Location: ' . BASE_URL . 'admin/users/index.php?success=added');
                exit;
            }
        }
        catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
}
?>

<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-person-plus me-2"></i>Thêm Admin mới</h1>
        <a href="<?php echo BASE_URL; ?>admin/users/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Quay lại
        </a>
    </div>

    <div class="row">
        <div class="col-md-7 col-lg-6 mx-auto">
            <div class="card shadow-sm">
                <div class="card-body">
                    <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <?php echo $error; ?>
                    </div>
                    <?php
endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label">Tên đăng nhập <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username"
                                value="<?php echo e($_POST['username'] ?? ''); ?>" required autofocus
                                pattern="[a-zA-Z0-9_]+" title="Chỉ dùng chữ cái, số và dấu gạch dưới">
                            <div class="form-text">Chỉ dùng chữ cái, số, dấu gạch dưới. Không thể thay đổi sau khi tạo.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Họ và tên <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                value="<?php echo e($_POST['full_name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="admin@example.com">
                            <div class="form-text">Dùng để nhận email reset mật khẩu.</div>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label for="password" class="form-label">Mật khẩu <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" required
                                minlength="6">
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Xác nhận mật khẩu <span
                                    class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-person-check me-1"></i> Tạo tài khoản
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
