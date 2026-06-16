<?php
// admin/users/edit.php - Sửa tài khoản Admin
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'users';
require_once '../includes/header.php';

$id = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

// Fetch user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ' . BASE_URL . 'admin/users/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name)) {
        $error = 'Vui lòng nhập Họ và tên.';
    }
    elseif (!empty($password) && strlen($password) < 6) {
        $error = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    }
    elseif (!empty($password) && $password !== $confirm_password) {
        $error = 'Mật khẩu xác nhận không khớp.';
    }
    elseif ($email !== '' && (function () use ($pdo, $email, $id) {
        $c = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $c->execute([$email, $id]);
        return (bool) $c->fetch();
    })()) {
        $error = 'Email <strong>' . e($email) . '</strong> đã được dùng cho tài khoản khác.';
    }
    else {
        try {
            $sql = "UPDATE users SET full_name = ?, email = ?";
            $params = [$full_name, $email];

            if (!empty($password)) {
                $sql .= ", password = ?";
                $params[] = secure_password($password);
            }

            $sql .= " WHERE id = ?";
            $params[] = $id;

            $pdo->prepare($sql)->execute($params);

            // Update session if editing self
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['full_name'] = $full_name;
            }

            if (function_exists('log_activity')) {
                $logMsg = "Sửa admin: " . $user['username'];
                if (!empty($password))
                    $logMsg .= " (đổi mật khẩu)";
                log_activity('update', 'user', $id, $logMsg);
            }

            $success = 'Cập nhật thành công!';

            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
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
        <h1 class="h2"><i class="bi bi-pencil-square me-2"></i>Sửa Admin:
            <?php echo e($user['username']); ?>
        </h1>
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
                    <?php if ($success): ?>
                    <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>
                        <?php echo $success; ?>
                    </div>
                    <?php
endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                        <div class="mb-3">
                            <label class="form-label">Tên đăng nhập</label>
                            <input type="text" class="form-control" value="<?php echo e($user['username']); ?>"
                                disabled>
                            <div class="form-text">Tên đăng nhập không thể thay đổi.</div>
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Họ và tên <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                value="<?php echo e($user['full_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?php echo e($user['email'] ?? ''); ?>" placeholder="admin@example.com">
                        </div>
                        <hr>
                        <h6 class="mb-3 text-muted">Đổi mật khẩu <small>(để trống nếu không đổi)</small></h6>
                        <div class="mb-3">
                            <label for="password" class="form-label">Mật khẩu mới</label>
                            <input type="password" class="form-control" id="password" name="password" minlength="6">
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Xác nhận mật khẩu mới</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i> Lưu thay đổi
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
