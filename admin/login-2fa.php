<?php
// admin/login-2fa.php — Bước xác thực 2 lớp (TOTP) sau khi mật khẩu đúng.
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/totp.php';

if (is_admin_logged_in()) {
    redirect('index.php');
}

// Phải có phiên chờ 2FA hợp lệ (đặt bởi login.php), hết hạn sau 5 phút.
$pendingId = (int) ($_SESSION['pending_2fa_user_id'] ?? 0);
$pendingTime = (int) ($_SESSION['pending_2fa_time'] ?? 0);
if ($pendingId <= 0 || $pendingTime <= 0 || (time() - $pendingTime) > 300) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_remember'], $_SESSION['pending_2fa_time']);
    redirect('login.php');
}

ensure_admin_security_columns($pdo);

$stmt = $pdo->prepare("SELECT id, username, full_name, role, totp_secret, totp_enabled, is_active FROM users WHERE id = ? AND role = 'admin'");
$stmt->execute([$pendingId]);
$user = $stmt->fetch();

if (!$user || empty($user['totp_enabled']) || empty($user['totp_secret'])) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_remember'], $_SESSION['pending_2fa_time']);
    redirect('login.php');
}

$error = '';
$ip = get_client_ip_address();
$loginWindow = 900;
$loginMaxAttempts = 5;
$rate = admin_login_rate_limit_status($pdo, $ip, $loginWindow, $loginMaxAttempts);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Phiên làm việc hết hạn. Vui lòng thử lại.';
    } elseif (!empty($rate['blocked'])) {
        $remaining = max(1, (int) ceil(((int) ($rate['remaining_seconds'] ?? 0)) / 60));
        $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$remaining} phút.";
    } else {
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        if (totp_verify((string) $user['totp_secret'], $code, 1)) {
            // 2FA OK → hoàn tất đăng nhập.
            $rememberMe = !empty($_SESSION['pending_2fa_remember']);
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_remember'], $_SESSION['pending_2fa_time']);
            session_regenerate_id(true);
            admin_set_login_session($user);
            admin_clear_login_attempts($pdo, $ip);
            $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int) $user['id']]);
            admin_clear_remember_me($pdo, (int) $user['id']);
            if ($rememberMe) {
                admin_issue_remember_me($pdo, $user);
            }
            if (function_exists('log_activity')) {
                log_activity('login', 'user', $user['id'], 'Đăng nhập thành công (2FA)');
            }
            redirect('index.php');
        } else {
            admin_record_failed_login_attempt($pdo, $ip, (string) $user['username'], $loginWindow, $loginMaxAttempts);
            $rate = admin_login_rate_limit_status($pdo, $ip, $loginWindow, $loginMaxAttempts);
            if (!empty($rate['blocked'])) {
                $remaining = max(1, (int) ceil(((int) ($rate['remaining_seconds'] ?? 0)) / 60));
                $error = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$remaining} phút.";
            } else {
                $error = 'Mã xác thực không đúng. Vui lòng thử lại.';
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
    <title>Xác thực 2 lớp - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>assets/css/fonts.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            font-family: 'Montserrat', sans-serif;
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
            margin-bottom: 1.75rem;
        }

        .login-header h3 {
            color: #1e1b4b;
            font-weight: 800;
        }

        .otp-input {
            letter-spacing: 0.5em;
            text-align: center;
            font-size: 1.6rem;
            font-weight: 700;
        }

        .btn-premium {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            color: white;
            font-weight: 700;
            padding: 12px;
            border-radius: 12px;
            transition: all 0.3s;
        }

        .btn-premium:hover {
            transform: translateY(-2px);
            color: white;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="login-header">
            <div style="font-size:2.4rem;color:#6366f1;"><i class="bi bi-shield-lock"></i></div>
            <h3>Xác thực 2 lớp</h3>
            <p class="text-muted mb-0">Nhập mã 6 số từ ứng dụng Google Authenticator / Authy</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger border-0 shadow-sm" style="border-radius:12px;">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
            <div class="mb-3">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                    class="form-control form-control-lg otp-input" name="code" placeholder="------" required autofocus
                    style="border-radius:12px;">
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-premium btn-lg">Xác nhận</button>
            </div>
        </form>

        <div class="text-center mt-3">
            <a href="login.php" class="text-decoration-none text-muted" style="font-size:0.875rem;">&larr; Quay lại đăng nhập</a>
        </div>
    </div>
</body>

</html>
