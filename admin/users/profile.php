<?php
// admin/users/profile.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';

$current_page = 'profile';
ensure_admin_security_columns($pdo);
require_once '../includes/header.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';
$show_2fa_setup = false;

// Get current user info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['2fa_init', '2fa_enable', '2fa_disable'], true)) {
    require_valid_csrf_token();
    $action = $_POST['action'];

    if ($action === '2fa_init') {
        // Tạo secret tạm, lưu trong session đến khi xác nhận mã.
        $_SESSION['profile_2fa_pending_secret'] = totp_generate_secret();
        $show_2fa_setup = true;
    } elseif ($action === '2fa_enable') {
        $pendingSecret = (string) ($_SESSION['profile_2fa_pending_secret'] ?? '');
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        if ($pendingSecret === '') {
            $error = 'Phiên thiết lập 2FA đã hết hạn. Vui lòng bấm "Bật 2FA" lại.';
        } elseif (!totp_verify($pendingSecret, $code, 1)) {
            $error = 'Mã xác thực không đúng. Vui lòng quét lại mã QR và thử lại.';
            $show_2fa_setup = true;
        } else {
            $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?")
                ->execute([$pendingSecret, $user_id]);
            unset($_SESSION['profile_2fa_pending_secret']);
            $success = 'Đã bật xác thực 2 lớp (2FA) cho tài khoản của bạn.';
            if (function_exists('log_activity')) {
                log_activity('update', 'profile', $user_id, 'Bật 2FA');
            }
        }
    } elseif ($action === '2fa_disable') {
        $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?")->execute([$user_id]);
        unset($_SESSION['profile_2fa_pending_secret']);
        $success = 'Đã tắt xác thực 2 lớp (2FA).';
        if (function_exists('log_activity')) {
            log_activity('update', 'profile', $user_id, 'Tắt 2FA');
        }
    }

    // Nạp lại dữ liệu user sau thay đổi.
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

            <?php
            $is2faOn = !empty($user['totp_enabled']);
            $pendingSecret = (string) ($_SESSION['profile_2fa_pending_secret'] ?? '');
            $otpUri = ($show_2fa_setup && $pendingSecret !== '')
                ? totp_provisioning_uri($pendingSecret, (string) $user['username'], (string) SITE_NAME)
                : '';
            ?>
            <div class="card shadow-sm mt-4">
                <div class="card-body">
                    <h5 class="mb-1"><i class="bi bi-shield-lock me-2"></i>Xác thực 2 lớp (2FA)</h5>
                    <p class="text-muted small mb-3">Tăng bảo mật bằng mã 6 số từ Google Authenticator / Authy khi đăng nhập.</p>

                    <?php if ($is2faOn): ?>
                        <div class="alert alert-success d-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-2"></i> 2FA đang <strong class="mx-1">BẬT</strong> cho tài khoản này.
                        </div>
                        <form method="POST" action="" onsubmit="return confirm('Tắt xác thực 2 lớp? Tài khoản sẽ kém an toàn hơn.');">
                            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="2fa_disable">
                            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Tắt 2FA</button>
                        </form>
                    <?php elseif ($show_2fa_setup && $otpUri !== ''): ?>
                        <ol class="small text-muted ps-3 mb-3">
                            <li>Mở ứng dụng <strong>Google Authenticator</strong> (hoặc Authy).</li>
                            <li>Quét mã QR bên dưới, hoặc nhập thủ công mã khóa.</li>
                            <li>Nhập mã 6 số ứng dụng hiển thị để xác nhận.</li>
                        </ol>
                        <div class="text-center mb-3">
                            <div id="qrcode" class="d-inline-block p-2 border rounded bg-white"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Mã khóa (nhập thủ công nếu không quét được):</label>
                            <input type="text" class="form-control text-center fw-bold" readonly value="<?php echo e($pendingSecret); ?>" style="letter-spacing:2px;">
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="2fa_enable">
                            <label class="form-label">Mã xác thực (6 số)</label>
                            <div class="input-group">
                                <input type="text" inputmode="numeric" maxlength="6" class="form-control" name="code" placeholder="------" required autofocus>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Xác nhận & Bật</button>
                            </div>
                        </form>
                        <div data-otp-uri="<?php echo e($otpUri); ?>" id="otpUriHolder"></div>
                    <?php else: ?>
                        <div class="alert alert-secondary d-flex align-items-center">
                            <i class="bi bi-shield-x me-2"></i> 2FA đang <strong class="mx-1">TẮT</strong>.
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="2fa_init">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-shield-plus me-1"></i>Bật 2FA</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($show_2fa_setup && !empty($otpUri)): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    (function () {
        var holder = document.getElementById('otpUriHolder');
        var target = document.getElementById('qrcode');
        if (holder && target && window.QRCode) {
            new QRCode(target, { text: holder.getAttribute('data-otp-uri'), width: 180, height: 180 });
        }
    })();
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
