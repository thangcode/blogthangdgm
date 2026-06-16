<?php
// admin/login.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/totp.php';

// Redirect if already logged in
if (is_admin_logged_in()) {
    redirect('index.php');
}

ensure_admin_security_columns($pdo);

$error = '';

$ip = get_client_ip_address();
$loginNow = time();
$loginWindow = 900;
$loginMaxAttempts = 5;
$loginRate = admin_login_rate_limit_status($pdo, $ip, $loginWindow, $loginMaxAttempts);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Phiên làm việc hết hạn. Vui lòng tải lại trang và thử lại.';
    } elseif (!empty($loginRate['blocked'])) {
        $remaining = max(1, (int) ceil(((int) ($loginRate['remaining_seconds'] ?? 0)) / 60));
        $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$remaining} phút.";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $rememberMe = !empty($_POST['remember_me']);
        $acctRate = admin_account_rate_limit_status($pdo, $username, $loginWindow, 10);

        if (empty($username) || empty($password)) {
            $error = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
        } elseif (!empty($acctRate['blocked'])) {
            $remaining = max(1, (int) ceil(((int) ($acctRate['remaining_seconds'] ?? 0)) / 60));
            $error = "Tài khoản này tạm thời bị khóa do nhập sai quá nhiều lần. Vui lòng thử lại sau {$remaining} phút.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, username, password, role, full_name, is_active, totp_enabled, totp_secret FROM users WHERE username = ? AND role = 'admin'");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && verify_password($password, $user['password'])) {
                    if ((int) ($user['is_active'] ?? 1) === 0) {
                        $error = 'Tài khoản đã bị vô hiệu hóa. Vui lòng liên hệ quản trị viên.';
                    } elseif (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                        // Mật khẩu đúng → chuyển sang bước xác thực 2 lớp (2FA).
                        admin_clear_login_attempts($pdo, $ip);
                        admin_account_clear($pdo, $username);
                        session_regenerate_id(true);
                        $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
                        $_SESSION['pending_2fa_remember'] = $rememberMe ? 1 : 0;
                        $_SESSION['pending_2fa_time'] = time();
                        redirect('login-2fa.php');
                    } else {
                        // Login Success (không bật 2FA)
                        session_regenerate_id(true);
                        admin_set_login_session($user);
                        admin_clear_login_attempts($pdo, $ip);
                        admin_account_clear($pdo, $username);
                        $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int) $user['id']]);

                        admin_clear_remember_me($pdo, (int) $user['id']);
                        if ($rememberMe) {
                            admin_issue_remember_me($pdo, $user);
                        }
                        if (function_exists('log_activity')) {
                            log_activity('login', 'user', $user['id'], 'Đăng nhập thành công');
                        }
                        redirect('index.php');
                    }
                } else {
                    admin_record_failed_login_attempt($pdo, $ip, $username, $loginWindow, $loginMaxAttempts);
                    admin_account_record_failed($pdo, $username, $loginWindow, 10);
                    $loginRate = admin_login_rate_limit_status($pdo, $ip, $loginWindow, $loginMaxAttempts);
                    if (!empty($loginRate['blocked'])) {
                        $remaining = max(1, (int) ceil(((int) ($loginRate['remaining_seconds'] ?? 0)) / 60));
                        $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$remaining} phút.";
                    } else {
                        $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
                    }
                }
            } catch (PDOException $e) {
                error_log('Admin login error: ' . $e->getMessage());
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
    <title>Đăng nhập quản trị -
        <?php echo SITE_NAME; ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            font-family: 'Inter', sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 2.5rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header h3 {
            color: #1e1b4b;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .btn-premium {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            color: white;
            font-weight: 700;
            padding: 12px;
            border-radius: 12px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .btn-premium:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
            color: white;
        }

        .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-header">
            <h3>
                <?php echo SITE_NAME; ?> Admin
            </h3>
            <p class="text-muted">Đăng nhập để vào trang quản trị</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 12px;">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
        </div>
        <?php
endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold">Tên đăng nhập</label>
                <input type="text" class="form-control form-control-lg" id="username" name="username" required autofocus style="border-radius: 12px; font-size: 1rem;">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label fw-semibold">Mật khẩu</label>
                <input type="password" class="form-control form-control-lg" id="password" name="password" required style="border-radius: 12px; font-size: 1rem;">
            </div>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" value="1" id="remember_me" name="remember_me" checked>
                <label class="form-check-label" for="remember_me">Giữ đăng nhập trong 7 ngày</label>
            </div>
            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-premium btn-lg">Đăng nhập</button>
            </div>
            <div class="text-center mt-3">
                <a href="forgot-password.php" class="text-muted text-decoration-none" style="font-size:0.875rem;">
                    <i class="bi bi-key me-1"></i>Quên mật khẩu?
                </a>
            </div>
        </form>

        <div class="text-center mt-3">
            <a href="../index.php" class="text-decoration-none text-muted">&larr; Quay về trang chủ</a>
        </div>
    </div>
    <script src="<?php echo BASE_URL; ?>assets/js/password-toggle.js?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/js/password-toggle.js') ?: '1'; ?>"></script>
</body>

</html>
