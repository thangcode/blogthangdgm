<?php
// admin/forgot-password.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

// Redirect if already logged in
if (is_admin_logged_in()) {
    redirect('index.php');
}

$error = '';
$success = '';

// --- RATE LIMITING: tối đa 3 lần/giờ theo IP ---
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}
$rateKey = 'forgot_pw_' . md5($ip);
$coolKey = 'forgot_pw_cool_' . md5($ip);
$now = time();
$maxTries = 3;
$window = 3600; // 1 giờ
$cooldown = 60; // ít nhất 60 giây giữa 2 lần

// Khởi tạo counter trong session
if (!isset($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['count' => 0, 'reset_at' => $now + $window];
}
// Reset nếu hết window
if ($now >= $_SESSION[$rateKey]['reset_at']) {
    $_SESSION[$rateKey] = ['count' => 0, 'reset_at' => $now + $window];
}

$rateLimited = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Cooldown check
    if (isset($_SESSION[$coolKey]) && ($now - $_SESSION[$coolKey]) < $cooldown) {
        $error = 'Vui lòng chờ ' . ($cooldown - ($now - $_SESSION[$coolKey])) . ' giây trước khi thử lại.';
        $rateLimited = true;
    }
    // Max attempts check
    elseif ($_SESSION[$rateKey]['count'] >= $maxTries) {
        $remaining = ceil(($_SESSION[$rateKey]['reset_at'] - $now) / 60);
        $error = "Quá nhiều yêu cầu. Vui lòng thử lại sau {$remaining} phút.";
        $rateLimited = true;
    }
    else {
        $_SESSION[$rateKey]['count']++;
        $_SESSION[$coolKey] = $now;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rateLimited) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Phiên làm việc hết hạn. Vui lòng tải lại trang và thử lại.';
    } else {

    $username = trim($_POST['username'] ?? '');

    if (empty($username)) {
        $error = 'Vui lòng nhập tên đăng nhập.';
    }
    else {
        try {
            // Ensure reset token columns exist
            $pdo->exec("ALTER TABLE users 
                ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS reset_token_expires DATETIME DEFAULT NULL");
        }
        catch (PDOException $e) { /* ignore if columns exist */
        }

        try {
            $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE username = ? AND role = 'admin'");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate secure token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?")
                    ->execute([$token, $expires, $user['id']]);

                $resetLink = BASE_URL . 'admin/reset-password.php?token=' . $token;

                // Try sending email if user has email
                $emailSent = false;
                if (!empty($user['email'])) {
                    $siteName = get_setting('site_name', 'ShopSieuSale');
                    $subject = '[' . $siteName . '] Đặt lại mật khẩu Admin';
                    $html = '<!doctype html><html lang="vi"><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
  <tr><td style="background:linear-gradient(135deg,#4f46e5,#6366f1);padding:24px;color:#fff;">
    <h1 style="margin:0;font-size:22px;">Đặt lại mật khẩu Admin</h1>
    <p style="margin:8px 0 0;opacity:.9;font-size:14px;">' . htmlspecialchars($siteName) . '</p>
  </td></tr>
  <tr><td style="padding:24px;">
    <p>Xin chào <strong>' . htmlspecialchars($user['full_name'] ?? $username) . '</strong>,</p>
    <p>Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản <strong>' . htmlspecialchars($username) . '</strong>.</p>
    <p>Nhấn nút bên dưới để tạo mật khẩu mới (link có hiệu lực trong <strong>1 giờ</strong>):</p>
    <p style="text-align:center;margin:24px 0;">
      <a href="' . htmlspecialchars($resetLink) . '" style="background:#4f46e5;color:#fff;text-decoration:none;padding:12px 28px;border-radius:999px;font-weight:700;font-size:15px;display:inline-block;">Đặt lại mật khẩu</a>
    </p>
    <p style="color:#6b7280;font-size:13px;">Hoặc copy link này vào trình duyệt:<br><a href="' . htmlspecialchars($resetLink) . '" style="color:#4f46e5;">' . htmlspecialchars($resetLink) . '</a></p>
    <p style="color:#9ca3af;font-size:12px;margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px;">Nếu bạn không yêu cầu đặt lại mật khẩu, hãy bỏ qua email này.</p>
  </td></tr>
</table></body></html>';

                    $result = send_smtp_email($subject, $html, '', $user['email'], $user['full_name'] ?? $username);
                    $emailSent = $result['success'];
                }

                $success = 'generic';
            }
            else {
                // Generic message to avoid username enumeration
                $success = 'generic';
            }
        }
        catch (PDOException $e) {
            error_log('Forgot password error: ' . $e->getMessage());
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
    <title>Quên mật khẩu -
        <?php echo SITE_NAME; ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
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
    </style>
</head>

<body>
    <div class="card">
        <div class="card-header">
            <h5 class="mb-1"><i class="bi bi-key me-2"></i>Quên mật khẩu</h5>
            <small style="opacity:.85">Đặt lại mật khẩu tài khoản Admin</small>
        </div>
        <div class="card-body p-4">
            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo $error; ?>
            </div>
            <?php
endif; ?>

            <?php if ($success === 'generic'): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                Nếu tên đăng nhập tồn tại trong hệ thống, bạn sẽ nhận được hướng dẫn đặt lại mật khẩu.
            </div>
            <div class="text-center">
                <a href="login.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i>Quay lại đăng
                    nhập</a>
            </div>

            <?php
else: ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                <div class="mb-3">
                    <label for="username" class="form-label">Tên đăng nhập</label>
                    <input type="text" class="form-control" id="username" name="username"
                        value="<?php echo e($_POST['username'] ?? ''); ?>" required autofocus>
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i> Gửi link đặt lại mật khẩu
                    </button>
                </div>
                <div class="text-center">
                    <a href="login.php" class="text-muted text-decoration-none small">
                        <i class="bi bi-arrow-left me-1"></i>Quay lại đăng nhập
                    </a>
                </div>
            </form>
            <?php
endif; ?>
        </div>
    </div>
</body>

</html>
