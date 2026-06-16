<?php
// admin/users/edit.php - Sửa tài khoản Admin
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'users';
ensure_admin_security_columns($pdo);
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

    // Tắt/đặt lại 2FA cho tài khoản này (dùng để khôi phục khi chủ tài khoản mất thiết bị).
    if (($_POST['action'] ?? '') === 'disable_2fa') {
        $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?")->execute([$id]);
        if (function_exists('log_activity')) {
            log_activity('update', 'user', $id, 'Tắt 2FA cho admin: ' . $user['username']);
        }
        $success = 'Đã tắt 2FA cho tài khoản này.';
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
    } else {
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
    } // end else (xử lý cập nhật hồ sơ)
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

            <?php
            $is2faOn = !empty($user['totp_enabled']);
            $isSelf = ($id === (int) $_SESSION['user_id']);
            ?>
            <div class="card shadow-sm mt-4">
                <div class="card-body">
                    <h5 class="mb-1"><i class="bi bi-shield-lock me-2"></i>Xác thực 2 lớp (2FA)</h5>
                    <p class="text-muted small mb-3">Mã 6 số từ Google Authenticator / Authy khi đăng nhập.</p>

                    <?php if ($is2faOn): ?>
                        <div class="alert alert-success d-flex align-items-center mb-3">
                            <i class="bi bi-check-circle-fill me-2"></i> 2FA đang <strong class="mx-1">BẬT</strong>.
                        </div>
                        <form method="POST" action="" onsubmit="return confirm('Tắt/đặt lại 2FA cho tài khoản này? Dùng khi chủ tài khoản mất thiết bị.');">
                            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="disable_2fa">
                            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Tắt / Đặt lại 2FA</button>
                        </form>
                        <?php if ($isSelf): ?>
                            <div class="form-text mt-2">Bạn cũng có thể quản lý 2FA của mình trong <a href="<?php echo BASE_URL; ?>admin/users/profile.php">Hồ sơ</a>.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary d-flex align-items-center mb-2">
                            <i class="bi bi-shield-x me-2"></i> 2FA đang <strong class="mx-1">TẮT</strong>.
                        </div>
                        <?php if ($isSelf): ?>
                            <a href="<?php echo BASE_URL; ?>admin/users/profile.php" class="btn btn-primary"><i class="bi bi-shield-plus me-1"></i>Thiết lập 2FA</a>
                        <?php else: ?>
                            <div class="form-text">Chỉ chủ tài khoản mới bật được 2FA (cần quét mã QR bằng điện thoại của họ) — trong trang <strong>Hồ sơ</strong> của họ.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
