<?php
// admin/reset-password.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Redirect if already logged in
if (is_admin_logged_in()) {
    redirect('index.php');
}

$token = trim($_GET['token'] ?? '');
$error = '';
$success = false;
$user = null;

if (empty($token)) {
    $error = 'Link không hợp lệ.';
}
else {
    try {
        $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE reset_token = ? AND reset_token_expires > NOW() AND role = 'admin'");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Link đã hết hạn hoặc không hợp lệ. Vui lòng yêu cầu link mới.';
        }
    }
    catch (PDOException $e) {
        $error = 'Lỗi hệ thống. Vui lòng thử lại.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Phiên làm việc hết hạn. Vui lòng tải lại trang và thử lại.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($password) || strlen($password) < 8) {
            $error = 'Mật khẩu phải có ít nhất 8 ký tự.';
        }
        elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Mật khẩu phải chứa ít nhất 1 chữ cái và 1 chữ số.';
        }
        elseif ($password !== $confirm_password) {
            $error = 'Mật khẩu xác nhận không khớp.';
        }
        else {
            try {
                $hashed = secure_password($password);
                $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?")
                    ->execute([$hashed, $user['id']]);

                if (function_exists('log_activity')) {
                    log_activity('update', 'user', $user['id'], 'Đặt lại mật khẩu qua link reset');
                }

                $success = true;
            }
            catch (PDOException $e) {
                error_log('Reset password error: ' . $e->getMessage());
                $error = 'Lỗi hệ thống. Vui lòng thử lại sau.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt lại mật khẩu -
        <?php echo SITE_NAME; ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/fonts.css">
    <style>
        body{font-family:'Montserrat',sans-serif;}
        body {
            background-color: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .card {
            width: 100%;
            max-width: 440px;
            border-radius: 16px;
            border: none;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .1);
        }

        .card-header {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            border-radius: 16px 16px 0 0 !important;
            padding: 1.5rem;
        }

        .strength-bar {
            height: 4px;
            border-radius: 2px;
            transition: all .3s;
            background: #e5e7eb;
            margin-top: 6px;
        }

        .strength-bar div {
            height: 100%;
            border-radius: 2px;
            transition: all .3s;
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-1"><i class="bi bi-shield-lock me-2"></i>Đặt lại mật khẩu</h5>
            <?php if ($user): ?>
            <small style="opacity:.85">Tài khoản: <strong>
                    <?php echo e($user['username']); ?>
                </strong></small>
            <?php
endif; ?>
        </div>
        <div class="card-body p-4">
            <?php if ($success): ?>
            <div class="text-center py-2">
                <div class="mb-3" style="font-size:3rem;">✅</div>
                <h5>Đặt lại mật khẩu thành công!</h5>
                <p class="text-muted">Bạn có thể đăng nhập với mật khẩu mới.</p>
                <a href="login.php" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Đăng nhập ngay
                </a>
            </div>

            <?php
elseif ($error && !$user): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo $error; ?>
            </div>
            <div class="text-center">
                <a href="forgot-password.php" class="btn btn-outline-primary">
                    <i class="bi bi-key me-1"></i> Yêu cầu link mới
                </a>
            </div>

            <?php
else: ?>
            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo $error; ?>
            </div>
            <?php
    endif; ?>
            <form method="POST" action="?token=<?php echo urlencode($token); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                <div class="mb-3">
                    <label for="password" class="form-label">Mật khẩu mới <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="password" name="password" required minlength="6"
                        oninput="checkStrength(this.value)">
                    <div class="strength-bar">
                        <div id="strengthBar" style="width:0;background:#ef4444;"></div>
                    </div>
                    <div class="form-text" id="strengthText"></div>
                </div>
                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Xác nhận mật khẩu mới <span
                            class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Xác nhận đặt lại mật khẩu
                    </button>
                </div>
                <div class="text-center">
                    <a href="login.php" class="text-muted text-decoration-none small">
                        <i class="bi bi-arrow-left me-1"></i>Quay lại đăng nhập
                    </a>
                </div>
            </form>
            <script>
                function checkStrength(v) {
                    var bar = document.getElementById('strengthBar');
                    var txt = document.getElementById('strengthText');
                    var score = 0;
                    if (v.length >= 6) score++;
                    if (v.length >= 10) score++;
                    if (/[A-Z]/.test(v)) score++;
                    if (/[0-9]/.test(v)) score++;
                    if (/[^a-zA-Z0-9]/.test(v)) score++;
                    var colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#16a34a'];
                    var labels = ['', 'Yếu', 'Trung bình', 'Khá', 'Mạnh', 'Rất mạnh'];
                    bar.style.width = (score * 20) + '%';
                    bar.style.background = colors[Math.max(0, score - 1)] || '#e5e7eb';
                    txt.textContent = score > 0 ? 'Độ mạnh: ' + labels[score] : '';
                }
            </script>
            <?php
endif; ?>
        </div>
    </div>
    <script src="<?php echo BASE_URL; ?>assets/js/password-toggle.js?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/js/password-toggle.js') ?: '1'; ?>"></script>
</body>

</html>
