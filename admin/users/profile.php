<?php
// admin/users/profile.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'profile';
require_once '../includes/header.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Get current user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($full_name) || empty($email)) {
        $error = 'Vui lòng nhập họ tên và email.';
    } else {
        try {
            // Update Info
            $sql = "UPDATE users SET full_name = ?, email = ?";
            $params = [$full_name, $email];

            // Update Password if provided
            if (!empty($password)) {
                if ($password !== $confirm_password) {
                    $error = 'Mật khẩu xác nhận không khớp.';
                } else {
                    $sql .= ", password = ?";
                    $params[] = secure_password($password);
                }
            }

            if (empty($error)) {
                $sql .= " WHERE id = ?";
                $params[] = $user_id;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // Update Session
                $_SESSION['full_name'] = $full_name;

                $success = 'Cập nhật hồ sơ thành công!';

                if (function_exists('log_activity')) {
                    $logMsg = "Cập nhật hồ sơ cá nhân";
                    if (!empty($password)) {
                        $logMsg .= " và đổi mật khẩu";
                    }
                    log_activity('update', 'profile', $user_id, $logMsg);
                }

                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            }
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
}
?>

<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Hồ sơ cá nhân</h1>
    </div>

    <div class="row">
        <div class="col-md-6 mx-auto">
            <div class="card shadow-sm">
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                        <div class="mb-3">
                            <label class="form-label">Tên đăng nhập</label>
                            <input type="text" class="form-control" value="<?php echo e($user['username']); ?>"
                                disabled>
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Họ và tên</label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                value="<?php echo e($user['full_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?php echo e($user['email']); ?>" required>
                        </div>

                        <hr>
                        <h5 class="mb-3">Đổi mật khẩu (Để trống nếu không đổi)</h5>

                        <div class="mb-3">
                            <label for="password" class="form-label">Mật khẩu mới</label>
                            <input type="password" class="form-control" id="password" name="password">
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Xác nhận mật khẩu mới</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Cập nhật hồ sơ</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
