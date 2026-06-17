<?php
// includes/functions.php

/**
 * Secure file upload function
 */
function upload_file($file, $target_dir, $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
{
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // Check for errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);

    if (!in_array($mime_type, $allowed_types)) {
        return false;
    }

    // Validate extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed_exts)) {
        return false;
    }

    // Generate safe unique filename (ngẫu nhiên, khó đoán)
    $filename = 'img_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target_file = $target_dir . $filename;

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        // Re-encode ảnh để loại bỏ payload có thể nhúng sau dữ liệu ảnh (defense-in-depth chống RCE).
        // Nếu có GD mà re-encode thất bại (ảnh hỏng/đáng ngờ) -> loại bỏ file.
        if (function_exists('imagecreatefromstring') && !sanitize_uploaded_image($target_file, $mime_type)) {
            @unlink($target_file);
            return false;
        }
        return $filename;
    }

    return false;
}

/**
 * Re-encode ảnh tại chỗ bằng GD để loại bỏ metadata/payload nhúng.
 * Trả về true nếu ghi lại thành công, false nếu không xử lý được (caller sẽ loại file).
 */
function sanitize_uploaded_image(string $path, string $mime): bool
{
    $data = @file_get_contents($path);
    if ($data === false || $data === '') {
        return false;
    }
    $img = @imagecreatefromstring($data);
    if (!$img) {
        return false;
    }
    if (function_exists('imagealphablending')) {
        @imagealphablending($img, false);
        @imagesavealpha($img, true);
    }
    $ok = false;
    switch ($mime) {
        case 'image/jpeg':
            $ok = @imagejpeg($img, $path, 90);
            break;
        case 'image/png':
            $ok = @imagepng($img, $path, 6);
            break;
        case 'image/gif':
            $ok = @imagegif($img, $path);
            break;
        case 'image/webp':
            $ok = function_exists('imagewebp') ? @imagewebp($img, $path, 90) : true; // không có imagewebp -> giữ file gốc
            break;
        default:
            $ok = false;
    }
    imagedestroy($img);
    return $ok;
}

// Include Core Functions (License Protected)
require_once __DIR__ . '/core.php';

// Lop tuong lua nhe (chan UA xau + auto-ban theo nguong). An toan: tu nuot loi.
require_once __DIR__ . '/security-firewall.php';

/**
 * Helper to get image URL with fallback to production URL if local file is missing, or default placeholder if empty.
 */
function get_image_url($image_path, $type = 'product')
{
    $image_path = trim((string)$image_path);
    if ($image_path === '') {
        return get_default_placeholder($type);
    }

    // Absolute URL -> giữ nguyên
    if (preg_match('#^(https?:)?//#i', $image_path)) {
        return $image_path;
    }

    // Ảnh nội bộ -> dựng URL theo domain hiện tại (không phụ thuộc domain cũ, không stat đĩa mỗi ảnh)
    return BASE_URL . ltrim($image_path, '/');
}

function get_default_placeholder($type)
{
    if ($type === 'news' || $type === 'post') {
        return 'https://placehold.co/800x450/1e293b/a78bfa?text=Tin+Tuc';
    }
    if ($type === 'banner') {
        return 'https://placehold.co/1920x560/1e293b/6366f1?text=Banner';
    }
    return 'https://placehold.co/600x600/1e293b/6366f1?text=San+Pham';
}

/**
 * Redirect helper
 */
function redirect($url)
{
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    }

    $safe_url_html = htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    echo '<script>window.location.href = ' . json_encode((string) $url, JSON_UNESCAPED_UNICODE) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe_url_html . '"></noscript>';
    exit;
}

/**
 * Clean data for output
 */
function e($string)
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// (da go cac ham affiliate/san pham cu)

function traffic_clean_tracking_value($value, $maxLength = 150)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, (int) $maxLength, 'UTF-8');
    }

    return substr($value, 0, (int) $maxLength);
}

function traffic_safe_page_label($pagePath = '', $pageUrl = '', $maxLength = 255)
{
    $pagePath = trim((string) $pagePath);
    $pageUrl = trim((string) $pageUrl);

    $candidate = $pagePath !== '' ? $pagePath : $pageUrl;
    if ($candidate === '') {
        return '/';
    }

    $parsedPath = '';
    if ($pageUrl !== '') {
        $parsedPath = (string) parse_url($pageUrl, PHP_URL_PATH);
    }

    if ($parsedPath === '' && preg_match('#^[a-z]+://#i', $candidate)) {
        $parsedPath = (string) parse_url($candidate, PHP_URL_PATH);
    }

    if ($parsedPath !== '') {
        $candidate = $parsedPath;
    }

    $candidate = preg_replace('/[?#].*$/', '', $candidate);
    $candidate = html_entity_decode((string) $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $candidate = strip_tags((string) $candidate);
    $candidate = preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $candidate);
    $candidate = trim((string) $candidate);

    if ($candidate === '') {
        return '/';
    }

    if ($candidate[0] !== '/' && !preg_match('#^[a-z0-9._-]+$#i', $candidate)) {
        $candidate = '/' . ltrim($candidate, '/');
    }

    if (function_exists('mb_substr')) {
        return mb_substr($candidate, 0, (int) $maxLength, 'UTF-8');
    }

    return substr($candidate, 0, (int) $maxLength);
}

function custom_script_trusted_host_patterns()
{
    return [
        'googletagmanager.com',
        'google-analytics.com',
        'analytics.google.com',
        'googlesyndication.com',
        'googleadservices.com',
        'doubleclick.net',
        'connect.facebook.net',
        'facebook.com',
        'analytics.tiktok.com',
        'business-api.tiktok.com',
        'clarity.ms',
        'bing.com',
        'bat.bing.com',
        'hotjar.com',
        'static.hotjar.com',
        'script.hotjar.com',
        'tawk.to',
        'embed.tawk.to',
        'v2.tawk.to',
        'zdassets.com',
        'zopim.com',
        'linkedin.com',
        'licdn.com',
        'twitter.com',
        'ads-twitter.com',
    ];
}

function custom_script_host_is_trusted($host)
{
    $host = strtolower(trim((string) $host));
    if ($host === '') {
        return true;
    }

    foreach (custom_script_trusted_host_patterns() as $trustedHost) {
        $trustedHost = strtolower((string) $trustedHost);
        if ($host === $trustedHost || substr($host, -strlen('.' . $trustedHost)) === '.' . $trustedHost) {
            return true;
        }
    }

    return false;
}

function validate_public_custom_script_markup($markup, &$reason = '')
{
    $markup = trim((string) $markup);
    if ($markup === '') {
        return true;
    }

    if (strlen($markup) > 20000) {
        $reason = 'Độ dài đoạn mã vượt quá giới hạn an toàn.';
        return false;
    }

    if (preg_match('/<(?:object|embed|applet|form|svg|math)\b/i', $markup)) {
        $reason = 'Đoạn mã chứa thẻ không được phép.';
        return false;
    }

    if (preg_match('/\bon[a-z]+\s*=/i', $markup)) {
        $reason = 'Đoạn mã chứa inline event handler không an toàn.';
        return false;
    }

    if (preg_match('/(?:javascript:|data:text\/html|document\.cookie|localStorage|sessionStorage|indexedDB|eval\s*\(|new\s+Function|XMLHttpRequest|fetch\s*\(|WebSocket|navigator\.sendBeacon)/i', $markup)) {
        $reason = 'Đoạn mã chứa API/script pattern không nằm trong nhóm tracking tin cậy.';
        return false;
    }

    if (preg_match_all('#(?:https?:)?//([^/"\'\s>]+)#i', $markup, $urlMatches)) {
        foreach ($urlMatches[1] as $host) {
            if (!custom_script_host_is_trusted($host)) {
                $reason = 'Đoạn mã gọi tới domain không nằm trong danh sách tin cậy: ' . $host;
                return false;
            }
        }
    }

    if (preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $markup, $scriptMatches, PREG_SET_ORDER)) {
        $trustedInlineMarkers = [
            'googletagmanager.com',
            'google-analytics.com',
            'gtag(',
            'gtm.start',
            'dataLayer',
            'fbq(',
            'connect.facebook.net',
            'ttq.',
            'analytics.tiktok.com',
            'clarity(',
            'clarity.ms',
            'hotjar',
            'hj(',
            'tawk',
        ];

        foreach ($scriptMatches as $scriptMatch) {
            $attrs = (string) ($scriptMatch[1] ?? '');
            $content = trim((string) ($scriptMatch[2] ?? ''));
            $hasSrc = preg_match('/\bsrc\s*=/i', $attrs) === 1;

            if ($hasSrc || $content === '') {
                continue;
            }

            $trustedInline = false;
            foreach ($trustedInlineMarkers as $marker) {
                if (stripos($content, $marker) !== false) {
                    $trustedInline = true;
                    break;
                }
            }

            if (!$trustedInline) {
                $reason = 'Inline script không khớp nhóm tracking marketing tin cậy.';
                return false;
            }
        }
    }

    return true;
}

function filter_public_custom_script_markup($markup)
{
    $reason = '';
    $markup = trim((string) $markup);
    if ($markup === '') {
        return '';
    }

    if (!validate_public_custom_script_markup($markup, $reason)) {
        error_log('Blocked public custom script markup: ' . ($reason !== '' ? $reason : 'invalid markup'));
        return '';
    }

    return $markup;
}

/**
 * Generate a slug from a string (Vietnamese friendly)
 */
function create_slug($string)
{
    $string = trim((string) $string);
    if ($string === '') {
        return '';
    }

    // Chuyển tiếng Việt có dấu -> không dấu (ổn định, không phụ thuộc iconv/intl)
    $vn = [
        'a' => ['á','à','ả','ã','ạ','ă','ắ','ằ','ẳ','ẵ','ặ','â','ấ','ầ','ẩ','ẫ','ậ'],
        'e' => ['é','è','ẻ','ẽ','ẹ','ê','ế','ề','ể','ễ','ệ'],
        'i' => ['í','ì','ỉ','ĩ','ị'],
        'o' => ['ó','ò','ỏ','õ','ọ','ô','ố','ồ','ổ','ỗ','ộ','ơ','ớ','ờ','ở','ỡ','ợ'],
        'u' => ['ú','ù','ủ','ũ','ụ','ư','ứ','ừ','ử','ữ','ự'],
        'y' => ['ý','ỳ','ỷ','ỹ','ỵ'],
        'd' => ['đ'],
    ];
    $lower = function_exists('mb_strtolower') ? mb_strtolower($string, 'UTF-8') : strtolower($string);
    foreach ($vn as $ascii => $chars) {
        $lower = str_replace($chars, $ascii, $lower);
    }
    $string = $lower;

    // Fallback cho ký tự ngoại lai còn sót (vd tiếng nước khác)
    if (function_exists('iconv')) {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
        if ($normalized !== false && $normalized !== '') {
            $string = $normalized;
        }
    }

    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9]+/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

/**
 * Check if user is logged in as admin
 */
function is_admin_logged_in()
{
    if (isset($_SESSION['user_id'], $_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        // Hết hạn do không hoạt động (idle timeout 2 giờ): hủy phiên,
        // vẫn cho phép remember-me khôi phục bên dưới nếu người dùng đã chọn.
        $idleLimit = 7200;
        $now = time();
        $last = (int) ($_SESSION['admin_last_activity'] ?? 0);
        if ($last > 0 && ($now - $last) > $idleLimit) {
            unset(
                $_SESSION['user_id'],
                $_SESSION['username'],
                $_SESSION['full_name'],
                $_SESSION['user_role'],
                $_SESSION['admin_last_activity']
            );
        } else {
            $_SESSION['admin_last_activity'] = $now;
            return true;
        }
    }

    static $rememberAttempted = false;
    if ($rememberAttempted) {
        return false;
    }

    $rememberAttempted = true;
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        return admin_restore_from_remember_cookie($pdo);
    }

    return false;
}

/**
 * Require admin login or redirect
 */
function require_admin_login()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!is_admin_logged_in()) {
        redirect(BASE_URL . 'admin/login.php');
    }
}

function admin_remember_cookie_name()
{
    return 'store_admin_remember';
}

function admin_remember_duration()
{
    return 7 * 24 * 60 * 60;
}

function admin_cookie_options($expires)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function admin_set_login_session(array $user)
{
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['admin_last_activity'] = time();
}

function ensure_admin_remember_columns($pdo)
{
    static $checked = false;
    if ($checked) {
        return;
    }

    if (!has_table_column($pdo, 'users', 'remember_selector')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN remember_selector VARCHAR(64) DEFAULT NULL");
    }
    if (!has_table_column($pdo, 'users', 'remember_token_hash')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN remember_token_hash CHAR(64) DEFAULT NULL");
    }
    if (!has_table_column($pdo, 'users', 'remember_expires_at')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN remember_expires_at DATETIME DEFAULT NULL");
    }

    $checked = true;
}

/**
 * Bổ sung cột bảo mật cho bảng users (2FA + trạng thái + lần đăng nhập cuối).
 * Idempotent — chỉ ALTER khi thiếu cột.
 */
function ensure_admin_security_columns($pdo)
{
    static $checked = false;
    if ($checked || !($pdo instanceof PDO)) {
        return;
    }
    if (!has_table_column($pdo, 'users', 'totp_secret')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL");
    }
    if (!has_table_column($pdo, 'users', 'totp_enabled')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!has_table_column($pdo, 'users', 'is_active')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
    if (!has_table_column($pdo, 'users', 'last_login_at')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME DEFAULT NULL");
    }
    $checked = true;
}

function admin_clear_remember_me($pdo = null, $userId = null)
{
    $cookieName = admin_remember_cookie_name();
    $cookieRaw = $_COOKIE[$cookieName] ?? '';

    if ($pdo instanceof PDO) {
        ensure_admin_remember_columns($pdo);

        if ($userId) {
            $stmt = $pdo->prepare("UPDATE users SET remember_selector = NULL, remember_token_hash = NULL, remember_expires_at = NULL WHERE id = ?");
            $stmt->execute([(int) $userId]);
        } elseif ($cookieRaw !== '' && strpos($cookieRaw, ':') !== false) {
            [$selector] = explode(':', $cookieRaw, 2);
            $stmt = $pdo->prepare("UPDATE users SET remember_selector = NULL, remember_token_hash = NULL, remember_expires_at = NULL WHERE remember_selector = ?");
            $stmt->execute([$selector]);
        }
    }

    setcookie($cookieName, '', admin_cookie_options(time() - 3600));
    unset($_COOKIE[$cookieName]);
}

function admin_issue_remember_me($pdo, array $user)
{
    ensure_admin_remember_columns($pdo);

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + admin_remember_duration());

    $stmt = $pdo->prepare("UPDATE users SET remember_selector = ?, remember_token_hash = ?, remember_expires_at = ? WHERE id = ?");
    $stmt->execute([$selector, $tokenHash, $expiresAt, (int) $user['id']]);

    setcookie(
        admin_remember_cookie_name(),
        $selector . ':' . $validator,
        admin_cookie_options(time() + admin_remember_duration())
    );
}

function ensure_admin_login_rate_limit_table($pdo)
{
    static $checked = false;
    if ($checked || !($pdo instanceof PDO)) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_login_attempts (
            ip_address VARCHAR(64) NOT NULL PRIMARY KEY,
            username_last VARCHAR(191) DEFAULT '',
            attempts INT NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            last_attempt_at DATETIME NOT NULL,
            locked_until DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $checked = true;
}

function admin_login_rate_limit_status($pdo, $ipAddress, $windowSeconds = 900, $maxAttempts = 5)
{
    ensure_admin_login_rate_limit_table($pdo);

    $ipAddress = traffic_normalize_ip_address($ipAddress);
    $status = [
        'blocked' => false,
        'attempts' => 0,
        'remaining_seconds' => 0,
        'reset_at' => time() + (int) $windowSeconds,
        'max_attempts' => (int) $maxAttempts,
    ];

    if ($ipAddress === '') {
        return $status;
    }

    $stmt = $pdo->prepare("SELECT attempts, window_started_at, locked_until FROM admin_login_attempts WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$ipAddress]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return $status;
    }

    $now = time();
    $windowStartedAt = strtotime((string) ($row['window_started_at'] ?? ''));
    $lockedUntil = strtotime((string) ($row['locked_until'] ?? ''));
    $attempts = (int) ($row['attempts'] ?? 0);

    if ($lockedUntil > $now) {
        $status['blocked'] = true;
        $status['attempts'] = $attempts;
        $status['remaining_seconds'] = $lockedUntil - $now;
        $status['reset_at'] = $lockedUntil;
        return $status;
    }

    if ($windowStartedAt <= 0 || ($now - $windowStartedAt) >= (int) $windowSeconds) {
        return $status;
    }

    $status['attempts'] = $attempts;
    $status['reset_at'] = $windowStartedAt + (int) $windowSeconds;
    $status['remaining_seconds'] = max(0, $status['reset_at'] - $now);
    return $status;
}

function admin_record_failed_login_attempt($pdo, $ipAddress, $username = '', $windowSeconds = 900, $maxAttempts = 5)
{
    ensure_admin_login_rate_limit_table($pdo);

    $ipAddress = traffic_normalize_ip_address($ipAddress);
    if ($ipAddress === '') {
        return;
    }

    $username = trim((string) $username);
    $stmt = $pdo->prepare("SELECT attempts, window_started_at, locked_until FROM admin_login_attempts WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$ipAddress]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $nowTs = time();
    $now = date('Y-m-d H:i:s', $nowTs);
    $lockedUntil = null;
    $attempts = 1;
    $windowStartedAt = $now;

    if ($row) {
        $rowWindowStartedAt = strtotime((string) ($row['window_started_at'] ?? ''));
        $rowLockedUntil = strtotime((string) ($row['locked_until'] ?? ''));
        $rowAttempts = (int) ($row['attempts'] ?? 0);

        if ($rowLockedUntil > $nowTs) {
            $attempts = $rowAttempts;
            $lockedUntil = date('Y-m-d H:i:s', $rowLockedUntil);
            $windowStartedAt = $row['window_started_at'] ?? $now;
        } elseif ($rowWindowStartedAt > 0 && ($nowTs - $rowWindowStartedAt) < (int) $windowSeconds) {
            $attempts = $rowAttempts + 1;
            $windowStartedAt = $row['window_started_at'] ?? $now;
        }
    }

    if ($attempts >= (int) $maxAttempts && $lockedUntil === null) {
        $lockedUntil = date('Y-m-d H:i:s', $nowTs + (int) $windowSeconds);
    }

    $sql = "INSERT INTO admin_login_attempts (ip_address, username_last, attempts, window_started_at, last_attempt_at, locked_until)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                username_last = VALUES(username_last),
                attempts = VALUES(attempts),
                window_started_at = VALUES(window_started_at),
                last_attempt_at = VALUES(last_attempt_at),
                locked_until = VALUES(locked_until)";
    $update = $pdo->prepare($sql);
    $update->execute([$ipAddress, $username, $attempts, $windowStartedAt, $now, $lockedUntil]);
}

function admin_clear_login_attempts($pdo, $ipAddress)
{
    ensure_admin_login_rate_limit_table($pdo);

    $ipAddress = traffic_normalize_ip_address($ipAddress);
    if ($ipAddress === '') {
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM admin_login_attempts WHERE ip_address = ?");
    $stmt->execute([$ipAddress]);
}

/**
 * Throttle theo TÀI KHOẢN (chống brute-force đổi IP nhắm vào 1 username).
 * Dùng chung bảng admin_login_attempts nhưng khóa bằng 'acct:'+hash(username),
 * nên không bị traffic_normalize_ip_address (chỉ nhận IP thật) chặn.
 */
function admin_account_lock_key($username): string
{
    return 'acct:' . substr(hash('sha256', strtolower(trim((string) $username))), 0, 56);
}

function admin_account_rate_limit_status($pdo, $username, $windowSeconds = 900, $maxAttempts = 10)
{
    ensure_admin_login_rate_limit_table($pdo);
    $status = ['blocked' => false, 'attempts' => 0, 'remaining_seconds' => 0];
    $username = trim((string) $username);
    if ($username === '') {
        return $status;
    }
    $key = admin_account_lock_key($username);
    $stmt = $pdo->prepare("SELECT attempts, window_started_at, locked_until FROM admin_login_attempts WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $status;
    }
    $now = time();
    $lockedUntil = strtotime((string) ($row['locked_until'] ?? ''));
    $windowStartedAt = strtotime((string) ($row['window_started_at'] ?? ''));
    $attempts = (int) ($row['attempts'] ?? 0);
    if ($lockedUntil > $now) {
        $status['blocked'] = true;
        $status['attempts'] = $attempts;
        $status['remaining_seconds'] = $lockedUntil - $now;
        return $status;
    }
    if ($windowStartedAt > 0 && ($now - $windowStartedAt) < (int) $windowSeconds) {
        $status['attempts'] = $attempts;
    }
    return $status;
}

function admin_account_record_failed($pdo, $username, $windowSeconds = 900, $maxAttempts = 10)
{
    ensure_admin_login_rate_limit_table($pdo);
    $username = trim((string) $username);
    if ($username === '') {
        return;
    }
    $key = admin_account_lock_key($username);
    $stmt = $pdo->prepare("SELECT attempts, window_started_at, locked_until FROM admin_login_attempts WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $nowTs = time();
    $now = date('Y-m-d H:i:s', $nowTs);
    $lockedUntil = null;
    $attempts = 1;
    $windowStartedAt = $now;

    if ($row) {
        $rowWindowStartedAt = strtotime((string) ($row['window_started_at'] ?? ''));
        $rowLockedUntil = strtotime((string) ($row['locked_until'] ?? ''));
        $rowAttempts = (int) ($row['attempts'] ?? 0);
        if ($rowLockedUntil > $nowTs) {
            $attempts = $rowAttempts;
            $lockedUntil = date('Y-m-d H:i:s', $rowLockedUntil);
            $windowStartedAt = $row['window_started_at'] ?? $now;
        } elseif ($rowWindowStartedAt > 0 && ($nowTs - $rowWindowStartedAt) < (int) $windowSeconds) {
            $attempts = $rowAttempts + 1;
            $windowStartedAt = $row['window_started_at'] ?? $now;
        }
    }
    if ($attempts >= (int) $maxAttempts && $lockedUntil === null) {
        $lockedUntil = date('Y-m-d H:i:s', $nowTs + (int) $windowSeconds);
    }
    $sql = "INSERT INTO admin_login_attempts (ip_address, username_last, attempts, window_started_at, last_attempt_at, locked_until)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                username_last = VALUES(username_last),
                attempts = VALUES(attempts),
                window_started_at = VALUES(window_started_at),
                last_attempt_at = VALUES(last_attempt_at),
                locked_until = VALUES(locked_until)";
    $pdo->prepare($sql)->execute([$key, $username, $attempts, $windowStartedAt, $now, $lockedUntil]);
}

function admin_account_clear($pdo, $username)
{
    ensure_admin_login_rate_limit_table($pdo);
    $username = trim((string) $username);
    if ($username === '') {
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM admin_login_attempts WHERE ip_address = ?");
    $stmt->execute([admin_account_lock_key($username)]);
}

function admin_restore_from_remember_cookie($pdo)
{
    if (isset($_SESSION['user_id'], $_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        return true;
    }

    $cookieRaw = $_COOKIE[admin_remember_cookie_name()] ?? '';
    if ($cookieRaw === '' || strpos($cookieRaw, ':') === false) {
        return false;
    }

    ensure_admin_remember_columns($pdo);
    [$selector, $validator] = explode(':', $cookieRaw, 2);

    if ($selector === '' || $validator === '') {
        admin_clear_remember_me($pdo);
        return false;
    }

    $stmt = $pdo->prepare("SELECT id, username, full_name, role, remember_token_hash, remember_expires_at FROM users WHERE remember_selector = ? AND role = 'admin' LIMIT 1");
    $stmt->execute([$selector]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['remember_token_hash']) || empty($user['remember_expires_at'])) {
        admin_clear_remember_me($pdo);
        return false;
    }

    if (strtotime((string) $user['remember_expires_at']) < time()) {
        admin_clear_remember_me($pdo, (int) $user['id']);
        return false;
    }

    $expectedHash = (string) $user['remember_token_hash'];
    $actualHash = hash('sha256', $validator);
    if (!hash_equals($expectedHash, $actualHash)) {
        admin_clear_remember_me($pdo, (int) $user['id']);
        return false;
    }

    session_regenerate_id(true);
    admin_set_login_session($user);
    admin_issue_remember_me($pdo, $user);
    return true;
}

function get_client_ip_address()
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $parts = explode(',', $candidate);
        foreach ($parts as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '';
}

function traffic_normalize_ip_address($ipAddress)
{
    $ipAddress = trim((string) $ipAddress);
    if ($ipAddress === '' || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        return '';
    }

    return $ipAddress;
}

/**
 * Kiểm tra IP có nằm trong dải CIDR không (hỗ trợ cả IPv4 lẫn IPv6).
 */
function ip_in_cidr(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int) $bits;
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false; // khác họ địa chỉ (v4 vs v6) hoặc không hợp lệ
    }
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($rem > 0) {
        $mask = chr(0xff << (8 - $rem) & 0xff);
        if ((($ipBin[$bytes] ^ $subnetBin[$bytes]) & $mask) !== "\0") {
            return false;
        }
    }
    return true;
}

/**
 * IP có thuộc Cloudflare hoặc proxy nội bộ tin cậy (loopback/private) không.
 * Dùng để quyết định có nên tin header CF-Connecting-IP / X-Forwarded-For hay không.
 */
function is_trusted_proxy_ip(string $ip): bool
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    // Loopback + private (reverse proxy chung host: nginx/apache trước PHP)
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return true;
    }
    static $cf = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($cf as $range) {
        if (ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * Lấy IP client một cách AN TOÀN: chỉ tin header CF-Connecting-IP / X-Forwarded-For
 * khi REMOTE_ADDR là proxy tin cậy (Cloudflare hoặc proxy nội bộ). Ngược lại dùng REMOTE_ADDR.
 * Tránh việc client trực tiếp giả mạo header để vượt rate-limit / dedup.
 */
function get_trusted_client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote !== '' && is_trusted_proxy_ip($remote)) {
        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}

/**
 * Tùy chọn SSL cho cURL: bật xác thực chứng chỉ khi tìm được CA bundle (hoặc trên Linux/production
 * dùng CA store hệ thống). Chỉ tắt khi là Windows local không có CA bundle (tránh vỡ môi trường dev).
 * @return array<int,mixed> mảng option để áp thêm vào curl_setopt
 */
function app_curl_ssl_opts(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $ca = '';
    foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $p) {
        if (is_string($p) && $p !== '' && @is_file($p)) {
            $ca = $p;
            break;
        }
    }
    if ($ca === '') {
        foreach ([
            'F:/Xamp/php/extras/ssl/cacert.pem',
            'F:/Xamp/apache/bin/curl-ca-bundle.crt',
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
            'C:/xampp/php/extras/ssl/cacert.pem',
        ] as $p) {
            if (@is_file($p)) {
                $ca = $p;
                break;
            }
        }
    }
    if ($ca !== '') {
        $cached = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => $ca];
    } elseif (stripos(PHP_OS, 'WIN') === 0) {
        // Windows dev không có CA store mặc định -> giữ verify off để không vỡ local.
        $cached = [CURLOPT_SSL_VERIFYPEER => false];
    } else {
        // Linux/production dùng CA store hệ thống.
        $cached = [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    }
    return $cached;
}

/**
 * Host của URL có khớp một trong các hậu tố tên miền cho phép không (chống SSRF).
 * Ví dụ: url_host_in_suffixes('https://down-vn.img.susercontent.com/x', ['susercontent.com']) === true
 */
function url_host_in_suffixes(string $url, array $suffixes): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }
    foreach ($suffixes as $sfx) {
        $sfx = strtolower(trim($sfx));
        if ($sfx === '') {
            continue;
        }
        if ($host === $sfx || substr($host, -(strlen($sfx) + 1)) === '.' . $sfx) {
            return true;
        }
    }
    return false;
}

// (da go search_products - blog tim bai viet)

function traffic_cookie_options($expires)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function traffic_detect_device_type($ua)
{
    $ua = strtolower((string) $ua);
    if ($ua === '') {
        return 'unknown';
    }
    if (preg_match('/ipad|tablet|playbook|silk/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/mobile|iphone|ipod|android|opera mini|iemobile|wpdesktop/i', $ua)) {
        return 'mobile';
    }
    return 'desktop';
}

function traffic_detect_browser($ua)
{
    $map = [
        'Edge' => '/edg\//i',
        'Chrome' => '/chrome\//i',
        'Firefox' => '/firefox\//i',
        'Safari' => '/safari\//i',
        'Opera' => '/opr\//i',
        'Internet Explorer' => '/msie|trident/i',
    ];

    foreach ($map as $label => $pattern) {
        if (preg_match($pattern, (string) $ua)) {
            return $label;
        }
    }

    return 'Other';
}

function traffic_detect_os($ua)
{
    $map = [
        'Windows' => '/windows nt/i',
        'macOS' => '/macintosh|mac os x/i',
        'iOS' => '/iphone|ipad|ipod/i',
        'Android' => '/android/i',
        'Linux' => '/linux/i',
        'Chrome OS' => '/cros/i',
    ];

    foreach ($map as $label => $pattern) {
        if (preg_match($pattern, (string) $ua)) {
            return $label;
        }
    }

    return 'Other';
}

function traffic_is_google_automation_ip($ipAddress)
{
    $ipAddress = trim((string) $ipAddress);
    if ($ipAddress === '') {
        return false;
    }

    $prefixes = [
        '66.102.',
        '66.249.',
        '64.233.',
        '72.14.',
        '74.125.',
        '209.85.',
        '216.239.',
    ];

    foreach ($prefixes as $prefix) {
        if (strpos($ipAddress, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

function traffic_is_bot($ua, $ipAddress = '', array $profile = [])
{
    if (preg_match('/googlebot|adsbot|apis-google|mediapartners-google|bingbot|bingpreview|yandex|baiduspider|duckduckbot|slurp|facebot|facebookexternalhit|twitterbot|linkedinbot|petalbot|applebot|semrushbot|ahrefsbot|mj12bot|dotbot|seekport|seznambot|coccocbot|bytespider|sogou|crawler|crawl|spider|headless|preview|wget|curl/i', (string) $ua) === 1) {
        return true;
    }

    $providerText = strtolower(trim((string) (($profile['org_name'] ?? '') . ' ' . ($profile['isp_name'] ?? ''))));
    if ($providerText !== '' && strpos($providerText, 'google') !== false && traffic_is_google_automation_ip($ipAddress)) {
        return true;
    }

    return false;
}

function traffic_normalize_url($url)
{
    return substr(trim((string) $url), 0, 1000);
}

function traffic_build_current_url()
{
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443)) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return traffic_normalize_url($scheme . '://' . $host . $uri);
}

function traffic_detect_source($referrer, array $query)
{
    $referrer = trim((string) $referrer);
    $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));

    $source = [
        'source_type' => 'direct',
        'source_name' => 'Direct',
        'source_host' => $host,
        'utm_source' => traffic_clean_tracking_value($query['utm_source'] ?? '', 150),
        'utm_medium' => traffic_clean_tracking_value($query['utm_medium'] ?? '', 150),
        'utm_campaign' => traffic_clean_tracking_value($query['utm_campaign'] ?? '', 150),
        'utm_term' => traffic_clean_tracking_value($query['utm_term'] ?? '', 150),
        'utm_content' => traffic_clean_tracking_value($query['utm_content'] ?? '', 150),
    ];

    if ($source['utm_source'] !== '' || $source['utm_medium'] !== '' || $source['utm_campaign'] !== '') {
        $source['source_type'] = 'campaign';
        $source['source_name'] = $source['utm_source'] !== '' ? $source['utm_source'] : 'Campaign';
        return $source;
    }

    if ($host === '') {
        return $source;
    }

    $searchHosts = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'coccoc.'];
    foreach ($searchHosts as $needle) {
        if (strpos($host, $needle) !== false) {
            $source['source_type'] = 'search';
            $source['source_name'] = $host;
            return $source;
        }
    }

    $socialHosts = ['facebook.com', 'm.facebook.com', 'l.facebook.com', 'instagram.com', 'zalo.me', 't.co', 'twitter.com', 'youtube.com', 'linkedin.com', 'threads.net', 'tiktok.com'];
    foreach ($socialHosts as $needle) {
        if (strpos($host, $needle) !== false) {
            $source['source_type'] = 'social';
            $source['source_name'] = $host;
            return $source;
        }
    }

    $source['source_type'] = 'referral';
    $source['source_name'] = $host;
    return $source;
}

function ensure_traffic_tables($pdo)
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `traffic_ip_profiles` (
        `ip_address` VARCHAR(45) PRIMARY KEY,
        `country_code` VARCHAR(8) DEFAULT NULL,
        `country_name` VARCHAR(100) DEFAULT NULL,
        `region_name` VARCHAR(150) DEFAULT NULL,
        `city_name` VARCHAR(150) DEFAULT NULL,
        `isp_name` VARCHAR(150) DEFAULT NULL,
        `org_name` VARCHAR(150) DEFAULT NULL,
        `asn` VARCHAR(50) DEFAULT NULL,
        `network_type` VARCHAR(50) DEFAULT NULL,
        `latitude` DECIMAL(10,6) DEFAULT NULL,
        `longitude` DECIMAL(10,6) DEFAULT NULL,
        `raw_json` MEDIUMTEXT DEFAULT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `traffic_sessions` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `session_key` CHAR(40) NOT NULL,
        `visitor_key` CHAR(40) NOT NULL,
        `ip_address` VARCHAR(45) DEFAULT '',
        `user_agent` TEXT,
        `referrer_url` VARCHAR(1000) DEFAULT '',
        `source_type` VARCHAR(50) DEFAULT '',
        `source_name` VARCHAR(255) DEFAULT '',
        `source_host` VARCHAR(255) DEFAULT '',
        `utm_source` VARCHAR(150) DEFAULT '',
        `utm_medium` VARCHAR(150) DEFAULT '',
        `utm_campaign` VARCHAR(150) DEFAULT '',
        `utm_term` VARCHAR(150) DEFAULT '',
        `utm_content` VARCHAR(150) DEFAULT '',
        `landing_url` VARCHAR(1000) DEFAULT '',
        `landing_path` VARCHAR(500) DEFAULT '',
        `exit_url` VARCHAR(1000) DEFAULT '',
        `exit_path` VARCHAR(500) DEFAULT '',
        `device_type` VARCHAR(30) DEFAULT '',
        `browser_name` VARCHAR(80) DEFAULT '',
        `os_name` VARCHAR(80) DEFAULT '',
        `country_code` VARCHAR(8) DEFAULT NULL,
        `country_name` VARCHAR(100) DEFAULT NULL,
        `region_name` VARCHAR(150) DEFAULT NULL,
        `city_name` VARCHAR(150) DEFAULT NULL,
        `isp_name` VARCHAR(150) DEFAULT NULL,
        `org_name` VARCHAR(150) DEFAULT NULL,
        `asn` VARCHAR(50) DEFAULT NULL,
        `network_type` VARCHAR(50) DEFAULT NULL,
        `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
        `is_new_visitor` TINYINT(1) NOT NULL DEFAULT 1,
        `pageviews_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_session_key` (`session_key`),
        KEY `idx_started_at` (`started_at`),
        KEY `idx_visitor_key` (`visitor_key`),
        KEY `idx_source_type` (`source_type`),
        KEY `idx_device_type` (`device_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `traffic_pageviews` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `session_key` CHAR(40) NOT NULL,
        `visitor_key` CHAR(40) NOT NULL,
        `ip_address` VARCHAR(45) DEFAULT '',
        `page_url` VARCHAR(1000) DEFAULT '',
        `page_path` VARCHAR(500) DEFAULT '',
        `referrer_url` VARCHAR(1000) DEFAULT '',
        `page_title` VARCHAR(255) DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_created_at` (`created_at`),
        KEY `idx_session_key` (`session_key`),
        KEY `idx_page_path` (`page_path`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `traffic_events` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `session_key` CHAR(40) NOT NULL,
        `visitor_key` CHAR(40) NOT NULL,
        `ip_address` VARCHAR(45) DEFAULT '',
        `event_name` VARCHAR(120) NOT NULL,
        `event_label` VARCHAR(255) DEFAULT '',
        `page_url` VARCHAR(1000) DEFAULT '',
        `page_path` VARCHAR(500) DEFAULT '',
        `source_type` VARCHAR(50) DEFAULT '',
        `device_type` VARCHAR(30) DEFAULT '',
        `browser_name` VARCHAR(80) DEFAULT '',
        `os_name` VARCHAR(80) DEFAULT '',
        `country_name` VARCHAR(100) DEFAULT NULL,
        `region_name` VARCHAR(150) DEFAULT NULL,
        `city_name` VARCHAR(150) DEFAULT NULL,
        `isp_name` VARCHAR(150) DEFAULT NULL,
        `metadata_json` MEDIUMTEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_created_at` (`created_at`),
        KEY `idx_event_name` (`event_name`),
        KEY `idx_session_key` (`session_key`),
        KEY `idx_page_path` (`page_path`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `traffic_blocked_ips` (
        `ip_address` VARCHAR(45) PRIMARY KEY,
        `block_reason` VARCHAR(255) DEFAULT '',
        `blocked_by` VARCHAR(100) DEFAULT '',
        `attempts_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_attempt_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $checked = true;
}

function traffic_get_blocked_ip($pdo, $ipAddress)
{
    if (!$pdo instanceof PDO) {
        return [];
    }

    $ipAddress = traffic_normalize_ip_address($ipAddress);
    if ($ipAddress === '') {
        return [];
    }

    ensure_traffic_tables($pdo);
    $stmt = $pdo->prepare("SELECT * FROM traffic_blocked_ips WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$ipAddress]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

function traffic_block_ip($pdo, $ipAddress, $reason = '', $blockedBy = '')
{
    if (!$pdo instanceof PDO) {
        return false;
    }

    $ipAddress = traffic_normalize_ip_address($ipAddress);
    if ($ipAddress === '') {
        return false;
    }

    ensure_traffic_tables($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO traffic_blocked_ips (ip_address, block_reason, blocked_by, created_at, updated_at)
         VALUES (?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            block_reason = VALUES(block_reason),
            blocked_by = VALUES(blocked_by),
            updated_at = NOW()"
    );

    return $stmt->execute([
        $ipAddress,
        substr(trim((string) $reason), 0, 255),
        substr(trim((string) $blockedBy), 0, 100),
    ]);
}

function traffic_unblock_ip($pdo, $ipAddress)
{
    if (!$pdo instanceof PDO) {
        return false;
    }

    $ipAddress = traffic_normalize_ip_address($ipAddress);
    if ($ipAddress === '') {
        return false;
    }

    ensure_traffic_tables($pdo);
    $stmt = $pdo->prepare("DELETE FROM traffic_blocked_ips WHERE ip_address = ?");
    return $stmt->execute([$ipAddress]);
}

function enforce_traffic_ip_block($pdo, array $options = [])
{
    if (!$pdo instanceof PDO || PHP_SAPI === 'cli') {
        return false;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if (!empty($options['skip_admin']) && strpos($requestUri, '/admin/') !== false) {
        return false;
    }

    $ipAddress = traffic_normalize_ip_address(get_client_ip_address());
    if ($ipAddress === '') {
        return false;
    }

    $blockedRow = traffic_get_blocked_ip($pdo, $ipAddress);
    if (empty($blockedRow)) {
        return false;
    }

    // Ban tam thoi (auto-firewall): het han thi tu dong mo, khong chan nua.
    if (!empty($blockedRow['expires_at'])) {
        $expireTs = strtotime((string) $blockedRow['expires_at']);
        if ($expireTs !== false && $expireTs <= time()) {
            try {
                traffic_unblock_ip($pdo, $ipAddress);
            } catch (Throwable $e) {
            }
            return false;
        }
    }

    try {
        $stmt = $pdo->prepare("UPDATE traffic_blocked_ips SET attempts_count = attempts_count + 1, last_attempt_at = NOW() WHERE ip_address = ?");
        $stmt->execute([$ipAddress]);
    } catch (Throwable $e) {
    }

    $statusCode = max(403, (int) ($options['status_code'] ?? 403));
    if (empty($options['message'])) {
        $options['message'] = 'IP của bạn đã bị chặn truy cập website.';
    }
    $message = trim((string) ($options['message'] ?? 'IP của bạn đã bị chặn truy cập website.'));
    $acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $wantsJson = !empty($options['json'])
        || $isAjax
        || strpos($requestUri, '/api/') !== false
        || strpos($acceptHeader, 'application/json') !== false;

    http_response_code($statusCode);

    if (!$wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $fontsHref = htmlspecialchars(rtrim((string) BASE_URL, '/') . '/assets/css/fonts.css', ENT_QUOTES, 'UTF-8');
        if ($safeMessage === '' || strpos($safeMessage, 'Ã') !== false || strpos($safeMessage, 'á»') !== false || strpos($safeMessage, 'Ä') !== false || strpos($safeMessage, 'Â') !== false) {
            $safeMessage = 'IP c&#7911;a b&#7841;n &#273;&#227; b&#7883; ch&#7863;n truy c&#7853;p website.';
        }
        echo '<!doctype html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Truy c&#7853;p b&#7883; ch&#7863;n</title><link rel="stylesheet" href="' . $fontsHref . '"><style>:root{color-scheme:light;--bg:#f4f7fb;--bg-deep:#e7eef8;--line:rgba(148,163,184,.24);--text:#020617;--muted:#475569;--navy:#0f172a;--blue:#1e3a8a;--gold:#ca8a04;--gold-soft:rgba(202,138,4,.14);--shadow:0 32px 90px rgba(15,23,42,.16)}*{box-sizing:border-box}html,body{margin:0;min-height:100%}body{font-family:\'Montserrat\',sans-serif;background:radial-gradient(circle at top left,rgba(30,58,138,.16),transparent 32%),radial-gradient(circle at top right,rgba(202,138,4,.16),transparent 24%),linear-gradient(180deg,var(--bg) 0%,#eef4fb 48%,var(--bg-deep) 100%);color:var(--text);display:flex;align-items:center;justify-content:center;padding:24px;overflow:hidden}.traffic-block-shell{position:relative;width:min(100%,1100px)}.traffic-orb{position:absolute;border-radius:999px;filter:blur(18px);opacity:.7;pointer-events:none}.traffic-orb.one{width:240px;height:240px;background:rgba(30,58,138,.14);top:-72px;left:-54px}.traffic-orb.two{width:180px;height:180px;background:rgba(202,138,4,.14);right:8%;bottom:-36px}.traffic-panel{position:relative;overflow:hidden;border:1px solid var(--line);border-radius:32px;background:linear-gradient(135deg,rgba(255,255,255,.9) 0%,rgba(255,255,255,.72) 100%);backdrop-filter:blur(18px);box-shadow:var(--shadow)}.traffic-panel::before{content:\"\";position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.6),transparent 46%,rgba(30,58,138,.06));pointer-events:none}.traffic-grid{position:relative;display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr);gap:0}.traffic-main{padding:38px 38px 34px}.traffic-side{padding:38px 32px;background:linear-gradient(180deg,rgba(15,23,42,.96),rgba(15,23,42,.9));color:#e2e8f0;border-left:1px solid rgba(255,255,255,.08)}.traffic-badge{display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.82);border:1px solid rgba(148,163,184,.22);box-shadow:0 14px 30px rgba(15,23,42,.08);font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--navy)}.traffic-badge-dot{width:10px;height:10px;border-radius:999px;background:linear-gradient(135deg,var(--gold),#facc15);box-shadow:0 0 0 6px var(--gold-soft)}.traffic-icon-wrap{width:74px;height:74px;border-radius:24px;display:grid;place-items:center;margin:24px 0 22px;background:linear-gradient(135deg,var(--navy),var(--blue));box-shadow:0 24px 40px rgba(30,58,138,.28)}.traffic-icon-wrap svg{width:34px;height:34px;color:#fff}.traffic-title{margin:0;font-size:clamp(40px,6vw,72px);line-height:1.08;letter-spacing:-.03em;font-weight:700;max-width:8ch;word-break:break-word}.traffic-subtitle{margin:18px 0 0;max-width:34rem;font-size:clamp(18px,2vw,22px);line-height:1.7;color:var(--muted)}.traffic-note{margin:26px 0 0;padding:18px 20px;border-radius:22px;background:rgba(255,255,255,.72);border:1px solid rgba(148,163,184,.2);display:flex;gap:14px;align-items:flex-start}.traffic-note strong{display:block;margin-bottom:4px;font-size:14px;color:var(--navy)}.traffic-note span{font-size:14px;line-height:1.7;color:var(--muted)}.traffic-note svg{width:20px;height:20px;flex:0 0 auto;color:var(--gold);margin-top:2px}.traffic-meta-label{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:rgba(226,232,240,.62);font-weight:700}.traffic-status{margin-top:18px;font-size:28px;font-weight:700;line-height:1.25;color:#fff}.traffic-side p{margin:12px 0 0;font-size:15px;line-height:1.75;color:rgba(226,232,240,.78)}.traffic-stats{margin-top:24px;display:grid;gap:14px}.traffic-stat{padding:16px 18px;border-radius:20px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08)}.traffic-stat-label{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:rgba(226,232,240,.58)}.traffic-stat-value{margin-top:8px;font-size:20px;font-weight:700;color:#fff}.traffic-footer{margin-top:20px;font-size:13px;color:rgba(226,232,240,.55)}@media (prefers-reduced-motion:no-preference){.traffic-panel{animation:trafficFade .55s ease-out both}.traffic-badge,.traffic-icon-wrap,.traffic-note,.traffic-stat{animation:trafficRise .65s ease-out both}.traffic-icon-wrap{animation-delay:.08s}.traffic-note{animation-delay:.14s}.traffic-stat:nth-child(1){animation-delay:.18s}.traffic-stat:nth-child(2){animation-delay:.24s}}@keyframes trafficFade{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}@keyframes trafficRise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}@media (max-width:900px){body{padding:18px}.traffic-grid{grid-template-columns:1fr}.traffic-side{border-left:0;border-top:1px solid rgba(255,255,255,.08)}.traffic-main,.traffic-side{padding:28px}.traffic-title{max-width:none}}@media (max-width:560px){.traffic-panel{border-radius:26px}.traffic-main,.traffic-side{padding:22px}.traffic-badge{font-size:11px;padding:9px 13px}.traffic-icon-wrap{width:62px;height:62px;border-radius:20px;margin:20px 0 18px}.traffic-status{font-size:24px}.traffic-subtitle{font-size:17px}.traffic-note{padding:16px;border-radius:18px}}</style></head><body><div class="traffic-block-shell"><div class="traffic-orb one"></div><div class="traffic-orb two"></div><section class="traffic-panel" aria-labelledby="traffic-block-title"><div class="traffic-grid"><div class="traffic-main"><div class="traffic-badge"><span class="traffic-badge-dot" aria-hidden="true"></span>L&#7899;p b&#7843;o v&#7879; truy c&#7853;p</div><div class="traffic-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3.5V11c0 4.5-2.7 8.6-7 10-4.3-1.4-7-5.5-7-10V6.5L12 3z" stroke="currentColor" stroke-width="1.7"/><path d="M9.25 11.75l1.9 1.9 3.85-4.15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></div><h1 class="traffic-title" id="traffic-block-title">Truy c&#7853;p b&#7883; ch&#7863;n</h1><p class="traffic-subtitle">' . $safeMessage . '</p><div class="traffic-note"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8v5m0 3h.01M10.2 3.86L2.82 16.5A2 2 0 0 0 4.55 19.5h14.9a2 2 0 0 0 1.73-3L13.8 3.86a2 2 0 0 0-3.6 0z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><div><strong>Th&#244;ng b&#225;o b&#7843;o m&#7853;t</strong><span>N&#7871;u b&#7841;n cho r&#7857;ng &#273;&#226;y l&#224; nh&#7847;m l&#7851;n, vui l&#242;ng li&#234;n h&#7879; qu&#7843;n tr&#7883; vi&#234;n ho&#7863;c b&#7897; ph&#7853;n h&#7895; tr&#7907; &#273;&#7875; &#273;&#432;&#7907;c ki&#7875;m tra v&#224; m&#7903; l&#7841;i quy&#7873;n truy c&#7853;p.</span></div></div></div><aside class="traffic-side" aria-label="Tr&#7841;ng th&#225;i truy c&#7853;p"><div class="traffic-meta-label">Tr&#7841;ng th&#225;i b&#7843;o m&#7853;t</div><div class="traffic-status">Truy c&#7853;p b&#7883; gi&#7899;i h&#7841;n</div><p>H&#7879; th&#7889;ng &#273;&#227; k&#237;ch ho&#7841;t l&#7899;p b&#7843;o v&#7879; truy c&#7853;p &#273;&#7875; &#273;&#7843;m b&#7843;o an to&#224;n cho website v&#224; h&#7841; t&#7847;ng v&#7853;n h&#224;nh.</p><div class="traffic-stats"><div class="traffic-stat"><div class="traffic-stat-label">M&#227; ph&#7843;n h&#7891;i</div><div class="traffic-stat-value">' . (int) $statusCode . '</div></div><div class="traffic-stat"><div class="traffic-stat-label">X&#225;c th&#7921;c</div><div class="traffic-stat-value">Quy t&#7855;c ch&#7863;n IP</div></div></div><div class="traffic-footer">C&#7893;ng b&#7843;o m&#7853;t h&#7879; th&#7889;ng</div></aside></div></section></div></body></html>';
        exit;
        echo '<!doctype html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Truy cập bị chặn</title><link rel="stylesheet" href="' . $fontsHref . '"><style>:root{color-scheme:light;--bg:#f4f7fb;--bg-deep:#e7eef8;--line:rgba(148,163,184,.24);--text:#020617;--muted:#475569;--navy:#0f172a;--blue:#1e3a8a;--gold:#ca8a04;--gold-soft:rgba(202,138,4,.14);--shadow:0 32px 90px rgba(15,23,42,.16)}*{box-sizing:border-box}html,body{margin:0;min-height:100%}body{font-family:\'Montserrat\',sans-serif;background:radial-gradient(circle at top left,rgba(30,58,138,.16),transparent 32%),radial-gradient(circle at top right,rgba(202,138,4,.16),transparent 24%),linear-gradient(180deg,var(--bg) 0%,#eef4fb 48%,var(--bg-deep) 100%);color:var(--text);display:flex;align-items:center;justify-content:center;padding:24px;overflow:hidden}.traffic-block-shell{position:relative;width:min(100%,1100px)}.traffic-orb{position:absolute;border-radius:999px;filter:blur(18px);opacity:.7;pointer-events:none}.traffic-orb.one{width:240px;height:240px;background:rgba(30,58,138,.14);top:-72px;left:-54px}.traffic-orb.two{width:180px;height:180px;background:rgba(202,138,4,.14);right:8%;bottom:-36px}.traffic-panel{position:relative;overflow:hidden;border:1px solid var(--line);border-radius:32px;background:linear-gradient(135deg,rgba(255,255,255,.9) 0%,rgba(255,255,255,.72) 100%);backdrop-filter:blur(18px);box-shadow:var(--shadow)}.traffic-panel::before{content:\"\";position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,255,255,.6),transparent 46%,rgba(30,58,138,.06));pointer-events:none}.traffic-grid{position:relative;display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr);gap:0}.traffic-main{padding:38px 38px 34px}.traffic-side{padding:38px 32px;background:linear-gradient(180deg,rgba(15,23,42,.96),rgba(15,23,42,.9));color:#e2e8f0;border-left:1px solid rgba(255,255,255,.08)}.traffic-badge{display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.82);border:1px solid rgba(148,163,184,.22);box-shadow:0 14px 30px rgba(15,23,42,.08);font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--navy)}.traffic-badge-dot{width:10px;height:10px;border-radius:999px;background:linear-gradient(135deg,var(--gold),#facc15);box-shadow:0 0 0 6px var(--gold-soft)}.traffic-icon-wrap{width:74px;height:74px;border-radius:24px;display:grid;place-items:center;margin:24px 0 22px;background:linear-gradient(135deg,var(--navy),var(--blue));box-shadow:0 24px 40px rgba(30,58,138,.28)}.traffic-icon-wrap svg{width:34px;height:34px;color:#fff}.traffic-title{margin:0;font-size:clamp(40px,6vw,72px);line-height:1.08;letter-spacing:-.03em;font-weight:700;max-width:8ch}.traffic-subtitle{margin:18px 0 0;max-width:34rem;font-size:clamp(18px,2vw,22px);line-height:1.7;color:var(--muted)}.traffic-note{margin:26px 0 0;padding:18px 20px;border-radius:22px;background:rgba(255,255,255,.72);border:1px solid rgba(148,163,184,.2);display:flex;gap:14px;align-items:flex-start}.traffic-note strong{display:block;margin-bottom:4px;font-size:14px;color:var(--navy)}.traffic-note span{font-size:14px;line-height:1.7;color:var(--muted)}.traffic-note svg{width:20px;height:20px;flex:0 0 auto;color:var(--gold);margin-top:2px}.traffic-meta-label{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:rgba(226,232,240,.62);font-weight:700}.traffic-status{margin-top:18px;font-size:28px;font-weight:700;line-height:1.25;color:#fff}.traffic-side p{margin:12px 0 0;font-size:15px;line-height:1.75;color:rgba(226,232,240,.78)}.traffic-stats{margin-top:24px;display:grid;gap:14px}.traffic-stat{padding:16px 18px;border-radius:20px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08)}.traffic-stat-label{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:rgba(226,232,240,.58)}.traffic-stat-value{margin-top:8px;font-size:20px;font-weight:700;color:#fff}.traffic-footer{margin-top:20px;font-size:13px;color:rgba(226,232,240,.55)}@media (prefers-reduced-motion:no-preference){.traffic-panel{animation:trafficFade .55s ease-out both}.traffic-badge,.traffic-icon-wrap,.traffic-note,.traffic-stat{animation:trafficRise .65s ease-out both}.traffic-icon-wrap{animation-delay:.08s}.traffic-note{animation-delay:.14s}.traffic-stat:nth-child(1){animation-delay:.18s}.traffic-stat:nth-child(2){animation-delay:.24s}}@keyframes trafficFade{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}@keyframes trafficRise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}@media (max-width:900px){body{padding:18px}.traffic-grid{grid-template-columns:1fr}.traffic-side{border-left:0;border-top:1px solid rgba(255,255,255,.08)}.traffic-main,.traffic-side{padding:28px}.traffic-title{max-width:none}}@media (max-width:560px){.traffic-panel{border-radius:26px}.traffic-main,.traffic-side{padding:22px}.traffic-badge{font-size:11px;padding:9px 13px}.traffic-icon-wrap{width:62px;height:62px;border-radius:20px;margin:20px 0 18px}.traffic-status{font-size:24px}.traffic-subtitle{font-size:17px}.traffic-note{padding:16px;border-radius:18px}}</style></head><body><div class="traffic-block-shell"><div class="traffic-orb one"></div><div class="traffic-orb two"></div><section class="traffic-panel" aria-labelledby="traffic-block-title"><div class="traffic-grid"><div class="traffic-main"><div class="traffic-badge"><span class="traffic-badge-dot" aria-hidden="true"></span>Protected Access Layer</div><div class="traffic-icon-wrap" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3.5V11c0 4.5-2.7 8.6-7 10-4.3-1.4-7-5.5-7-10V6.5L12 3z" stroke="currentColor" stroke-width="1.7"/><path d="M9.25 11.75l1.9 1.9 3.85-4.15" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></div><h1 class="traffic-title" id="traffic-block-title">Truy cập bị chặn</h1><p class="traffic-subtitle">' . $safeMessage . '</p><div class="traffic-note"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8v5m0 3h.01M10.2 3.86L2.82 16.5A2 2 0 0 0 4.55 19.5h14.9a2 2 0 0 0 1.73-3L13.8 3.86a2 2 0 0 0-3.6 0z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><div><strong>Thông báo bảo mật</strong><span>Nếu bạn cho rằng đây là nhầm lẫn, vui lòng liên hệ quản trị viên hoặc bộ phận hỗ trợ để được kiểm tra và mở lại quyền truy cập.</span></div></div></div><aside class="traffic-side" aria-label="Trạng thái truy cập"><div class="traffic-meta-label">Security Status</div><div class="traffic-status">Access Restricted</div><p>Hệ thống đã kích hoạt lớp bảo vệ truy cập để đảm bảo an toàn cho website và hạ tầng vận hành.</p><div class="traffic-stats"><div class="traffic-stat"><div class="traffic-stat-label">Response Code</div><div class="traffic-stat-value">' . (int) $statusCode . '</div></div><div class="traffic-stat"><div class="traffic-stat-label">Verification</div><div class="traffic-stat-value">IP Access Rule</div></div></div><div class="traffic-footer">Store Security Gateway</div></aside></div></section></div></body></html>';
        exit;
    }

    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'success' => false,
            'blocked' => true,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Truy cập bị chặn</title><link rel="stylesheet" href="' . $fontsHref . '"><style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px;display:flex;min-height:100vh;align-items:center;justify-content:center}.traffic-block-wrap{max-width:560px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:28px;box-shadow:0 16px 40px rgba(15,23,42,.08)}h1{margin:0 0 12px;font-size:28px}p{margin:0;color:#475569;line-height:1.6}</style></head><body><div class="traffic-block-wrap"><h1>Truy cập bị chặn</h1><p>' . $safeMessage . '</p></div></body></html>';
    exit;
}

function traffic_fetch_remote_ip_profile($ipAddress)
{
    if ($ipAddress === '' || !filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return [];
    }

    $url = 'https://ipwho.is/' . rawurlencode($ipAddress);
    $body = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'StoreTrafficBot/1.0',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
                'header' => "User-Agent: StoreTrafficBot/1.0\r\n",
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
    }

    if (!is_string($body) || trim($body) === '') {
        return [];
    }

    $json = json_decode($body, true);
    if (!is_array($json) || (isset($json['success']) && $json['success'] === false)) {
        return [];
    }

    $connection = isset($json['connection']) && is_array($json['connection']) ? $json['connection'] : [];
    return [
        'country_code' => substr((string) ($json['country_code'] ?? ''), 0, 8),
        'country_name' => substr((string) ($json['country'] ?? ''), 0, 100),
        'region_name' => substr((string) ($json['region'] ?? ''), 0, 150),
        'city_name' => substr((string) ($json['city'] ?? ''), 0, 150),
        'isp_name' => substr((string) ($connection['isp'] ?? ($json['isp'] ?? '')), 0, 150),
        'org_name' => substr((string) ($connection['org'] ?? ($json['org'] ?? '')), 0, 150),
        'asn' => substr((string) ($connection['asn'] ?? ($json['asn'] ?? '')), 0, 50),
        'network_type' => substr((string) ($connection['type'] ?? ($json['type'] ?? '')), 0, 50),
        'latitude' => isset($json['latitude']) ? (float) $json['latitude'] : null,
        'longitude' => isset($json['longitude']) ? (float) $json['longitude'] : null,
        'raw_json' => json_encode($json, JSON_UNESCAPED_UNICODE),
    ];
}

function traffic_get_ip_profile($pdo, $ipAddress)
{
    if ($ipAddress === '') {
        return [];
    }

    $stmt = $pdo->prepare("SELECT * FROM traffic_ip_profiles WHERE ip_address = ? LIMIT 1");
    $stmt->execute([$ipAddress]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        return $profile;
    }

    $fresh = traffic_fetch_remote_ip_profile($ipAddress);
    if (empty($fresh)) {
        return [];
    }

    $stmt = $pdo->prepare("INSERT INTO traffic_ip_profiles (ip_address, country_code, country_name, region_name, city_name, isp_name, org_name, asn, network_type, latitude, longitude, raw_json, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $ipAddress,
        $fresh['country_code'] ?? null,
        $fresh['country_name'] ?? null,
        $fresh['region_name'] ?? null,
        $fresh['city_name'] ?? null,
        $fresh['isp_name'] ?? null,
        $fresh['org_name'] ?? null,
        $fresh['asn'] ?? null,
        $fresh['network_type'] ?? null,
        $fresh['latitude'] ?? null,
        $fresh['longitude'] ?? null,
        $fresh['raw_json'] ?? null,
    ]);

    $fresh['ip_address'] = $ipAddress;
    return $fresh;
}

function should_track_traffic_request()
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET') {
        return false;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if (strpos($requestUri, '/admin/') !== false || strpos($requestUri, '/api/') !== false) {
        return false;
    }

    if ((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        return false;
    }

    return true;
}

/**
 * Chạy IP-block + tracking server-side cho trường hợp SẮP phục vụ Page Cache HIT.
 *
 * Lý do: khi cache HIT, header.php (nơi gọi enforce_traffic_ip_block + track_frontend_traffic)
 * bị bỏ qua. Phải chạy tại đây — TRƯỚC khi echo cache để setcookie còn hiệu lực và để IP bị
 * chặn không nhận được nội dung từ cache. Khi MISS / cache tắt: không làm gì (header.php lo như cũ).
 */
function frontend_cache_prelude($pdo, $cacheKey, array $context = [])
{
    if (!class_exists('PageCache') || !PageCache::willServeCache((string) $cacheKey)) {
        return;
    }
    if (function_exists('enforce_traffic_ip_block')) {
        enforce_traffic_ip_block($pdo, ['skip_admin' => true]);
    }
    if (function_exists('security_fw_guard')) {
        security_fw_guard($pdo, ['skip_admin' => true]);
    }
    if (function_exists('track_frontend_traffic')) {
        track_frontend_traffic($pdo, $context);
    }
}

function track_frontend_traffic($pdo, array $context = [])
{
    static $tracked = false;
    if ($tracked || !$pdo instanceof PDO || !should_track_traffic_request()) {
        return;
    }
    $tracked = true;

    ensure_traffic_tables($pdo);

    $visitorCookie = $_COOKIE['store_visitor_id'] ?? '';
    $sessionCookie = $_COOKIE['store_session_id'] ?? '';
    $sessionSeenCookie = (int) ($_COOKIE['store_session_seen'] ?? 0);
    $now = time();

    $isNewVisitor = false;
    if (!preg_match('/^[a-f0-9]{40}$/', $visitorCookie)) {
        $visitorCookie = sha1(generate_token(20));
        $isNewVisitor = true;
    }

    $isNewSession = !preg_match('/^[a-f0-9]{40}$/', $sessionCookie) || ($now - $sessionSeenCookie) > 1800;
    if ($isNewSession) {
        $sessionCookie = sha1(generate_token(20));
    }

    setcookie('store_visitor_id', $visitorCookie, traffic_cookie_options($now + 31536000));
    setcookie('store_session_id', $sessionCookie, traffic_cookie_options($now + 1800));
    setcookie('store_session_seen', (string) $now, traffic_cookie_options($now + 1800));

    $ipAddress = get_client_ip_address();
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
    $referrerUrl = traffic_normalize_url($_SERVER['HTTP_REFERER'] ?? '');
    $pageUrl = traffic_build_current_url();
    $pagePath = substr(trim((string) parse_url($pageUrl, PHP_URL_PATH), '/'), 0, 500);
    $pageTitle = substr((string) ($context['page_title'] ?? ''), 0, 255);
    $query = $_GET ?? [];

    $source = traffic_detect_source($referrerUrl, $query);
    $deviceType = traffic_detect_device_type($userAgent);
    $browserName = traffic_detect_browser($userAgent);
    $osName = traffic_detect_os($userAgent);
    $profile = traffic_get_ip_profile($pdo, $ipAddress);
    $isBot = traffic_is_bot($userAgent, $ipAddress, $profile) ? 1 : 0;

    if (!$isNewVisitor) {
        $stmt = $pdo->prepare("SELECT 1 FROM traffic_sessions WHERE visitor_key = ? LIMIT 1");
        $stmt->execute([$visitorCookie]);
        $isNewVisitor = !$stmt->fetchColumn();
    }

    $insertTrafficSession = static function ($sessionKey, $visitorKey, $markNewVisitor) use ($pdo, $ipAddress, $userAgent, $referrerUrl, $source, $pageUrl, $pagePath, $deviceType, $browserName, $osName, $profile, $isBot) {
        $stmt = $pdo->prepare("INSERT INTO traffic_sessions (session_key, visitor_key, ip_address, user_agent, referrer_url, source_type, source_name, source_host, utm_source, utm_medium, utm_campaign, utm_term, utm_content, landing_url, landing_path, exit_url, exit_path, device_type, browser_name, os_name, country_code, country_name, region_name, city_name, isp_name, org_name, asn, network_type, is_bot, is_new_visitor, pageviews_count, started_at, last_activity_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->execute([
            $sessionKey,
            $visitorKey,
            $ipAddress,
            $userAgent,
            $referrerUrl,
            $source['source_type'],
            $source['source_name'],
            $source['source_host'],
            $source['utm_source'],
            $source['utm_medium'],
            $source['utm_campaign'],
            $source['utm_term'],
            $source['utm_content'],
            $pageUrl,
            $pagePath,
            $pageUrl,
            $pagePath,
            $deviceType,
            $browserName,
            $osName,
            $profile['country_code'] ?? null,
            $profile['country_name'] ?? null,
            $profile['region_name'] ?? null,
            $profile['city_name'] ?? null,
            $profile['isp_name'] ?? null,
            $profile['org_name'] ?? null,
            $profile['asn'] ?? null,
            $profile['network_type'] ?? null,
            $isBot,
            $markNewVisitor ? 1 : 0,
        ]);
    };

    if ($isNewSession) {
        $insertTrafficSession($sessionCookie, $visitorCookie, $isNewVisitor);
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM traffic_sessions WHERE session_key = ? LIMIT 1");
        $checkStmt->execute([$sessionCookie]);
        $sessionExists = (bool) $checkStmt->fetchColumn();

        if (!$sessionExists) {
            $insertTrafficSession($sessionCookie, $visitorCookie, $isNewVisitor);
        }

        $stmt = $pdo->prepare("UPDATE traffic_sessions SET exit_url = ?, exit_path = ?, last_activity_at = NOW(), pageviews_count = pageviews_count + 1 WHERE session_key = ?");
        $stmt->execute([$pageUrl, $pagePath, $sessionCookie]);
    }

    $stmt = $pdo->prepare("INSERT INTO traffic_pageviews (session_key, visitor_key, ip_address, page_url, page_path, referrer_url, page_title, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$sessionCookie, $visitorCookie, $ipAddress, $pageUrl, $pagePath, $referrerUrl, $pageTitle]);
}

function get_traffic_identity()
{
    return [
        'visitor_key' => isset($_COOKIE['store_visitor_id']) && preg_match('/^[a-f0-9]{40}$/', (string) $_COOKIE['store_visitor_id']) ? (string) $_COOKIE['store_visitor_id'] : '',
        'session_key' => isset($_COOKIE['store_session_id']) && preg_match('/^[a-f0-9]{40}$/', (string) $_COOKIE['store_session_id']) ? (string) $_COOKIE['store_session_id'] : '',
    ];
}

function track_traffic_event($pdo, $eventName, $eventLabel = '', array $metadata = [])
{
    if (!$pdo instanceof PDO || trim((string) $eventName) === '') {
        return false;
    }

    ensure_traffic_tables($pdo);
    $identity = get_traffic_identity();
    if ($identity['visitor_key'] === '' || $identity['session_key'] === '') {
        return false;
    }

    $ipAddress = get_client_ip_address();
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $pageUrl = traffic_normalize_url((string) ($metadata['page_url'] ?? traffic_build_current_url()));
    $pagePath = substr(trim((string) parse_url($pageUrl, PHP_URL_PATH), '/'), 0, 500);
    $deviceType = traffic_detect_device_type($userAgent);
    $browserName = traffic_detect_browser($userAgent);
    $osName = traffic_detect_os($userAgent);
    $sourceType = '';
    $countryName = null;
    $regionName = null;
    $cityName = null;
    $ispName = null;

    $stmt = $pdo->prepare("SELECT source_type, device_type, browser_name, os_name, country_name, region_name, city_name, isp_name FROM traffic_sessions WHERE session_key = ? LIMIT 1");
    $stmt->execute([$identity['session_key']]);
    $sessionRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sessionRow) {
        $sourceType = (string) ($sessionRow['source_type'] ?? '');
        $deviceType = (string) ($sessionRow['device_type'] ?? $deviceType);
        $browserName = (string) ($sessionRow['browser_name'] ?? $browserName);
        $osName = (string) ($sessionRow['os_name'] ?? $osName);
        $countryName = $sessionRow['country_name'] ?? null;
        $regionName = $sessionRow['region_name'] ?? null;
        $cityName = $sessionRow['city_name'] ?? null;
        $ispName = $sessionRow['isp_name'] ?? null;
    }

    $cleanMetadata = $metadata;
    unset($cleanMetadata['page_url']);

    $stmt = $pdo->prepare("INSERT INTO traffic_events (session_key, visitor_key, ip_address, event_name, event_label, page_url, page_path, source_type, device_type, browser_name, os_name, country_name, region_name, city_name, isp_name, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $identity['session_key'],
        $identity['visitor_key'],
        $ipAddress,
        substr((string) $eventName, 0, 120),
        substr((string) $eventLabel, 0, 255),
        $pageUrl,
        $pagePath,
        $sourceType,
        $deviceType,
        $browserName,
        $osName,
        $countryName,
        $regionName,
        $cityName,
        $ispName,
        !empty($cleanMetadata) ? json_encode($cleanMetadata, JSON_UNESCAPED_UNICODE) : null,
    ]);

    return true;
}

/**
 * Hash password with security key
 */
function secure_password($password)
{
    // Add security key to password before hashing for better security.
    $salted = $password . SECURITY_KEY;
    return password_hash($salted, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password with security key
 */
function verify_password($password, $hash)
{
    $salted = $password . SECURITY_KEY;
    return password_verify($salted, $hash);
}

/**
 * Generate CSRF token
 */
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Read CSRF token from common request locations.
 */
function get_request_csrf_token()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $token = $_POST['csrf_token'] ?? '';
    if ($token !== '') {
        return (string) $token;
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($headerToken !== '') {
        return (string) $headerToken;
    }

    return '';
}

/**
 * Verify CSRF token for JSON/AJAX/admin requests and stop on failure.
 */
function require_valid_csrf_token($jsonResponse = false)
{
    $token = get_request_csrf_token();
    if (verify_csrf_token($token)) {
        return;
    }

    if ($jsonResponse) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'success' => false,
            'error' => 'CSRF token không hợp lệ.',
            'message' => 'CSRF token không hợp lệ.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        http_response_code(403);
    }
    exit('CSRF token không hợp lệ.');
}

/**
 * Generate secure random token (for password reset, remember me, etc.)
 */
function generate_token($length = 32)
{
    return bin2hex(random_bytes($length));
}

/**
 * Get hierarchical category options for select dropdowns
 * Returns array with proper indentation for parent-child display
 * 
 * @param PDO $pdo Database connection
 * @param int|null $selected_id Currently selected category ID
 * @param int|null $exclude_id Category ID to exclude (for edit forms)
 * @param bool $active_only Only include active categories
 * @return array Array of categories with 'id', 'name', 'display_name', 'selected', 'level'
 */
function get_hierarchical_categories($pdo, $selected_id = null, $exclude_id = null, $active_only = true)
{
    $where = $active_only ? "WHERE status = 1" : "";
    $stmt = $pdo->query("SELECT * FROM categories {$where} ORDER BY parent_id ASC, name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    build_category_tree($categories, $result, null, 0, $selected_id, $exclude_id);

    return $result;
}

/**
 * Recursive helper to build category tree
 */
function build_category_tree($categories, &$result, $parent_id = null, $level = 0, $selected_id = null, $exclude_id = null)
{
    foreach ($categories as $cat) {
        if ($cat['parent_id'] == $parent_id) {
            // Skip if this is the excluded category
            if ($exclude_id !== null && $cat['id'] == $exclude_id) {
                continue;
            }

            // Create indentation prefix
            $prefix = $level > 0 ? str_repeat('-- ', $level) : '';
            if ($level > 0) {
                $prefix = '+' . $prefix;
            }

            $result[] = [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'display_name' => $prefix . $cat['name'],
                'selected' => ($selected_id !== null && $cat['id'] == $selected_id),
                'level' => $level,
                'parent_id' => $cat['parent_id']
            ];

            // Recursive call for children
            build_category_tree($categories, $result, $cat['id'], $level + 1, $selected_id, $exclude_id);
        }
    }
}

/**
 * Render category options HTML for select element
 * 
 * @param PDO $pdo Database connection
 * @param int|null $selected_id Currently selected category ID
 * @param int|null $exclude_id Category ID to exclude
 * @param bool $active_only Only include active categories
 * @return string HTML options string
 */
function render_category_options($pdo, $selected_id = null, $exclude_id = null, $active_only = true)
{
    $categories = get_hierarchical_categories($pdo, $selected_id, $exclude_id, $active_only);
    $html = '';

    foreach ($categories as $cat) {
        $selected = $cat['selected'] ? ' selected' : '';
        $html .= '<option value="' . $cat['id'] . '"' . $selected . '>' . e($cat['display_name']) . '</option>' . "\n";
    }

    return $html;
}

/**
 * Check whether a table has a given column
 */
function has_table_column($pdo, $table, $column)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Đảm bảo bảng product_views (log từng lượt xem THẬT kèm thời gian) tồn tại.
 * Cho phép thống kê lượt xem theo khoảng thời gian (giống product_clicks cho click).
 */
function ensure_product_views_table($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_views` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
            `device_type` VARCHAR(30) NOT NULL DEFAULT '',
            `browser_name` VARCHAR(80) NOT NULL DEFAULT '',
            `os_name` VARCHAR(80) NOT NULL DEFAULT '',
            `referrer_url` VARCHAR(1000) NOT NULL DEFAULT '',
            `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_product` (`product_id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_bot_created` (`is_bot`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // ignore
    }
    $done = true;
}

/**
 * Ensure media library table exists.
 */
function ensure_media_library_table($pdo)
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `media_library` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `original_name` VARCHAR(255) NOT NULL,
        `stored_name` VARCHAR(255) NOT NULL,
        `file_path` VARCHAR(500) NOT NULL,
        `mime_type` VARCHAR(120) NOT NULL,
        `extension` VARCHAR(20) NOT NULL,
        `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `width` INT UNSIGNED DEFAULT NULL,
        `height` INT UNSIGNED DEFAULT NULL,
        `sha256_hash` CHAR(64) DEFAULT NULL,
        `uploaded_by` INT UNSIGNED DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_created_at` (`created_at`),
        INDEX `idx_uploaded_by` (`uploaded_by`),
        INDEX `idx_mime_type` (`mime_type`),
        UNIQUE KEY `uk_sha256_hash` (`sha256_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $checked = true;
}

/**
 * Đăng ký 1 file ảnh vào media_library (idempotent theo sha256).
 * @param string $abs_path      đường dẫn tuyệt đối tới file
 * @param string $relative_path đường dẫn tương đối từ web root (lưu vào DB + dựng URL)
 */
function register_media_file($pdo, string $abs_path, string $relative_path, string $original_name = ''): bool
{
    if (!($pdo instanceof PDO) || !is_file($abs_path)) {
        return false;
    }
    ensure_media_library_table($pdo);
    $info = @getimagesize($abs_path);
    $mime = $info['mime'] ?? (function_exists('mime_content_type') ? (@mime_content_type($abs_path) ?: 'image/webp') : 'image/webp');
    $ext = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION)) ?: 'webp';
    $size = (int) @filesize($abs_path);
    $w = isset($info[0]) ? (int) $info[0] : null;
    $h = isset($info[1]) ? (int) $info[1] : null;
    $hash = @hash_file('sha256', $abs_path) ?: null;
    $stored = basename($abs_path);
    if ($original_name === '') {
        $original_name = $stored;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO media_library
            (original_name, stored_name, file_path, mime_type, extension, file_size, width, height, sha256_hash, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE file_path = VALUES(file_path)");
        $stmt->execute([
            $original_name, $stored, $relative_path, $mime, $ext, $size, $w, $h, $hash,
            (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null),
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check whether a media path is inside managed media upload directory.
 */
function is_safe_media_path($relative_path)
{
    if (!is_string($relative_path) || $relative_path === '') {
        return false;
    }
    if (strpos($relative_path, '..') !== false || strpos($relative_path, "\0") !== false) {
        return false;
    }
    return strpos($relative_path, 'assets/uploads/media/') === 0;
}
function site_theme_palettes(): array
{
    return [
        'violet' => [
            'label' => 'Violet',
            'primary' => '#7c3aed',
            'dark' => '#5b21b6',
            'accent' => '#a855f7',
            'soft' => '#f3e8ff',
            'line' => '#ddd6fe',
            'glow' => 'rgba(124,58,237,.22)',
        ],
        'royal' => [
            'label' => 'Royal Purple',
            'primary' => '#6d28d9',
            'dark' => '#4c1d95',
            'accent' => '#8b5cf6',
            'soft' => '#ede9fe',
            'line' => '#c4b5fd',
            'glow' => 'rgba(109,40,217,.24)',
        ],
        'lavender' => [
            'label' => 'Lavender',
            'primary' => '#8b5cf6',
            'dark' => '#6d28d9',
            'accent' => '#c084fc',
            'soft' => '#f5f3ff',
            'line' => '#ddd6fe',
            'glow' => 'rgba(139,92,246,.2)',
        ],
        'plum' => [
            'label' => 'Plum',
            'primary' => '#9333ea',
            'dark' => '#701a75',
            'accent' => '#d946ef',
            'soft' => '#fae8ff',
            'line' => '#f0abfc',
            'glow' => 'rgba(147,51,234,.22)',
        ],
    ];
}

function site_theme_palette(?string $key = null): array
{
    $palettes = site_theme_palettes();
    $key = $key !== null ? trim($key) : trim((string) get_setting('site_theme_palette', 'violet'));
    return $palettes[$key] ?? $palettes['violet'];
}

function site_theme_css_vars(?string $key = null): string
{
    $p = site_theme_palette($key);
    return '--primary-color:' . $p['primary'] . ';'
        . '--primary-color-dark:' . $p['dark'] . ';'
        . '--primary-accent:' . $p['accent'] . ';'
        . '--primary-soft:' . $p['soft'] . ';'
        . '--primary-line:' . $p['line'] . ';'
        . '--primary-glow:' . $p['glow'] . ';'
        . '--secondary-color:' . $p['accent'] . ';'
        . '--hover-color:' . $p['dark'] . ';'
        . '--accent-glow:' . $p['glow'] . ';'
        . '--primary-gradient:linear-gradient(135deg,' . $p['primary'] . ' 0%,' . $p['accent'] . ' 100%);'
        . '--blog-primary:' . $p['primary'] . ';'
        . '--blog-primary-d:' . $p['dark'] . ';'
        . '--blog-accent:' . $p['accent'] . ';'
        . '--blog-soft:' . $p['soft'] . ';'
        . '--blog-line:' . $p['line'] . ';';
}

/**
 * Generate asset URL with cache-busting version parameter.
 * Uses cache_version setting from DB (updated when admin clicks "clear cache").
 * Falls back to file modification time.
 */
function asset_url($path)
{
    static $cache_version = null;
    if ($cache_version === null) {
        $cache_version = get_setting('cache_version', '1');
    }
    return BASE_URL . $path . '?v=' . $cache_version;
}

function app_local_image_path(string $image_path)
{
    $image_path = trim($image_path);
    if ($image_path === '' || preg_match('#^(https?:)?//#i', $image_path)) {
        return false;
    }

    $path = (string) parse_url($image_path, PHP_URL_PATH);
    $base_path = trim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
    $path = ltrim($path, '/');
    if ($base_path !== '' && strpos($path, $base_path . '/') === 0) {
        $path = substr($path, strlen($base_path) + 1);
    }

    $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') : dirname(__DIR__);
    $full = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    $root_real = realpath($root);
    if (!$full || !$root_real || strpos($full, $root_real) !== 0 || !is_file($full)) {
        return false;
    }

    return $full;
}

function app_image_dimensions_attr(string $image_path): string
{
    $full = app_local_image_path($image_path);
    if (!$full) {
        return '';
    }
    $size = @getimagesize($full);
    if (!$size || empty($size[0]) || empty($size[1])) {
        return '';
    }
    return ' width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"';
}

function app_resized_image_dimensions_attr(string $image_path, int $target_width): string
{
    $full = app_local_image_path($image_path);
    if (!$full) {
        return '';
    }
    $size = @getimagesize($full);
    if (!$size || empty($size[0]) || empty($size[1])) {
        return '';
    }

    $source_width = (int) $size[0];
    $source_height = (int) $size[1];
    $width = max(1, min(max(1, $target_width), $source_width));
    $height = max(1, (int) round($source_height * $width / $source_width));
    return ' width="' . $width . '" height="' . $height . '"';
}

function app_resized_image_url(string $image_path, int $target_width, int $quality = 82): string
{
    $target_width = max(80, min(1920, $target_width));
    $full = app_local_image_path($image_path);
    if (!$full) {
        return get_image_url($image_path, 'news');
    }

    $info = @getimagesize($full);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return get_image_url($image_path, 'news');
    }

    $source_width = (int) $info[0];
    $source_height = (int) $info[1];
    $mime = (string) ($info['mime'] ?? '');
    if ($source_width <= 0 || $source_height <= 0) {
        return get_image_url($image_path, 'news');
    }

    if ($source_width <= $target_width && $mime === 'image/webp') {
        return get_image_url($image_path, 'news');
    }

    $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/\\') : dirname(__DIR__);
    $cache_dir = $root . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'images';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
        return get_image_url($image_path, 'news');
    }

    $mtime = (int) @filemtime($full);
    $hash = substr(sha1($full . '|' . $mtime . '|' . $target_width), 0, 18);
    $dest = $cache_dir . DIRECTORY_SEPARATOR . $hash . '-' . $target_width . '.webp';
    if (!is_file($dest) || @filemtime($dest) < $mtime) {
        $new_width = min($target_width, $source_width);
        $new_height = max(1, (int) round($source_height * $new_width / $source_width));
        $ok = false;

        if (function_exists('imagewebp')) {
            $src = null;
            if ($mime === 'image/jpeg') {
                $src = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($full) : null;
            } elseif ($mime === 'image/png') {
                $src = function_exists('imagecreatefrompng') ? @imagecreatefrompng($full) : null;
            } elseif ($mime === 'image/gif') {
                $src = function_exists('imagecreatefromgif') ? @imagecreatefromgif($full) : null;
            } elseif ($mime === 'image/webp') {
                $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($full) : null;
            }

            if ($src) {
                $dst = imagecreatetruecolor($new_width, $new_height);
                if ($dst) {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                    imagefilledrectangle($dst, 0, 0, $new_width, $new_height, $transparent);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $source_width, $source_height);
                    $ok = @imagewebp($dst, $dest, $quality);
                    imagedestroy($dst);
                }
                imagedestroy($src);
            }
        }

        if (!$ok && class_exists('Imagick')) {
            try {
                $im = new Imagick($full);
                if ($source_width > $target_width) {
                    $im->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, 1);
                }
                $im->setImageFormat('webp');
                $im->setImageCompressionQuality($quality);
                $ok = $im->writeImage($dest);
                $im->clear();
                $im->destroy();
            } catch (Throwable $e) {
                $ok = false;
            }
        }

        if (!$ok || !is_file($dest) || filesize($dest) <= 0) {
            if (is_file($dest)) {
                @unlink($dest);
            }
            return get_image_url($image_path, 'news');
        }
    }

    return BASE_URL . 'cache/images/' . basename($dest);
}

function app_image_srcset(string $image_path, array $widths = [320, 640, 960]): string
{
    $parts = [];
    foreach ($widths as $width) {
        $width = (int) $width;
        if ($width <= 0) {
            continue;
        }
        $parts[] = e(app_resized_image_url($image_path, $width)) . ' ' . $width . 'w';
    }
    return implode(', ', array_unique($parts));
}

/**
 * Log a conversion event (form submission, contact click, etc.)
 *
 * @param string $type  conversion type: consultation, registration, contact_hotline, etc.
 * @param array  $form_data  associative array of submitted form fields
 * @param string $gtm_event  GTM event name that was fired
 * @param string|null $page_url  override page URL (default: HTTP_REFERER)
 */
function ensure_conversion_logs_table($pdo)
{
    static $checked = false;
    if ($checked || !$pdo instanceof PDO) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `conversion_logs` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `type` VARCHAR(50) NOT NULL,
        `session_key` CHAR(40) DEFAULT '',
        `visitor_key` CHAR(40) DEFAULT '',
        `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
        `user_agent` TEXT,
        `page_url` VARCHAR(500) DEFAULT '',
        `referrer` VARCHAR(500) DEFAULT '',
        `source_type` VARCHAR(50) DEFAULT '',
        `source_name` VARCHAR(255) DEFAULT '',
        `utm_source` VARCHAR(150) DEFAULT '',
        `utm_medium` VARCHAR(150) DEFAULT '',
        `utm_campaign` VARCHAR(150) DEFAULT '',
        `device_type` VARCHAR(20) DEFAULT '',
        `form_data` JSON DEFAULT NULL,
        `gtm_event` VARCHAR(100) DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_type` (`type`),
        INDEX `idx_created_at` (`created_at`),
        INDEX `idx_ip` (`ip_address`),
        INDEX `idx_session_key` (`session_key`),
        INDEX `idx_visitor_key` (`visitor_key`),
        INDEX `idx_utm_campaign` (`utm_campaign`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
        'session_key' => "ALTER TABLE `conversion_logs` ADD COLUMN `session_key` CHAR(40) DEFAULT '' AFTER `type`",
        'visitor_key' => "ALTER TABLE `conversion_logs` ADD COLUMN `visitor_key` CHAR(40) DEFAULT '' AFTER `session_key`",
        'source_type' => "ALTER TABLE `conversion_logs` ADD COLUMN `source_type` VARCHAR(50) DEFAULT '' AFTER `referrer`",
        'source_name' => "ALTER TABLE `conversion_logs` ADD COLUMN `source_name` VARCHAR(255) DEFAULT '' AFTER `source_type`",
        'utm_source' => "ALTER TABLE `conversion_logs` ADD COLUMN `utm_source` VARCHAR(150) DEFAULT '' AFTER `source_name`",
        'utm_medium' => "ALTER TABLE `conversion_logs` ADD COLUMN `utm_medium` VARCHAR(150) DEFAULT '' AFTER `utm_source`",
        'utm_campaign' => "ALTER TABLE `conversion_logs` ADD COLUMN `utm_campaign` VARCHAR(150) DEFAULT '' AFTER `utm_medium`",
    ];

    foreach ($columns as $column => $sql) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversion_logs' AND COLUMN_NAME = ?");
            $stmt->execute([$column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        } catch (Exception $e) {
        }
    }

    $checked = true;
}

function log_conversion($type, array $form_data = [], $gtm_event = '', $page_url = null)
{
    global $pdo;
    try {
        ensure_conversion_logs_table($pdo);

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $url = $page_url ?: ($_SERVER['HTTP_REFERER'] ?? '');
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        $device = detect_device_type($ua);
        $identity = function_exists('get_traffic_identity') ? get_traffic_identity() : ['session_key' => '', 'visitor_key' => ''];
        $sourceType = '';
        $sourceName = '';
        $utmSource = '';
        $utmMedium = '';
        $utmCampaign = '';

        if (!empty($identity['session_key'])) {
            try {
                $stmtTraffic = $pdo->prepare("SELECT source_type, source_name, utm_source, utm_medium, utm_campaign FROM traffic_sessions WHERE session_key = ? LIMIT 1");
                $stmtTraffic->execute([$identity['session_key']]);
                $trafficRow = $stmtTraffic->fetch(PDO::FETCH_ASSOC);
                if ($trafficRow) {
                    $sourceType = (string) ($trafficRow['source_type'] ?? '');
                    $sourceName = (string) ($trafficRow['source_name'] ?? '');
                    $utmSource = (string) ($trafficRow['utm_source'] ?? '');
                    $utmMedium = (string) ($trafficRow['utm_medium'] ?? '');
                    $utmCampaign = (string) ($trafficRow['utm_campaign'] ?? '');
                }
            } catch (Exception $e) {
            }
        }

        $stmt = $pdo->prepare("INSERT INTO conversion_logs (type, session_key, visitor_key, ip_address, user_agent, page_url, referrer, source_type, source_name, utm_source, utm_medium, utm_campaign, form_data, gtm_event, device_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $type,
            (string) ($identity['session_key'] ?? ''),
            (string) ($identity['visitor_key'] ?? ''),
            $ip,
            $ua,
            $url,
            $referrer,
            $sourceType,
            $sourceName,
            $utmSource,
            $utmMedium,
            $utmCampaign,
            !empty($form_data) ? json_encode($form_data, JSON_UNESCAPED_UNICODE) : null,
            $gtm_event,
            $device
        ]);
    } catch (Exception $e) {
        error_log('Conversion log error: ' . $e->getMessage());
    }
}

/**
 * Detect device type from User-Agent string
 */
function detect_device_type($ua)
{
    $ua = strtolower($ua);
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua))
        return 'tablet';
    if (preg_match('/mobile|android|iphone|ipod|opera mini|iemobile|wpdesktop/i', $ua))
        return 'mobile';
    return 'desktop';
}

/**
 * Get footer categories (If having children, load children; else load parent)
 */
function get_footer_categories()
{
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT id, name, slug, parent_id 
                             FROM categories 
                             WHERE status = 1 
                             ORDER BY name ASC");
        $all_cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $roots = [];
        $children = [];

        // Separate roots and children
        foreach ($all_cats as $cat) {
            if (empty($cat['parent_id'])) {
                $roots[] = $cat;
            } else {
                $children[$cat['parent_id']][] = $cat;
            }
        }

        $result = [];
        foreach ($roots as $root) {
            if (isset($children[$root['id']])) {
                // If has children, add children
                foreach ($children[$root['id']] as $child) {
                    $result[] = $child;
                }
            } else {
                // If no children, add root
                $result[] = $root;
            }
        }

        return $result;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Check for bot submission (Honeypot + Time Check)
 * 
 * @param int $min_time_seconds Minimum seconds required to fill form (default 3s)
 * @return void Dies with error message if bot detected
 */
function check_bot_submission($min_time_seconds = 3)
{
    // 1. Honeypot check (field 'website' should be empty)
    if (!empty($_POST['website'])) {
        error_log('Bot detected: Honeypot filled. IP: ' . $_SERVER['REMOTE_ADDR']);
        // Fake success to fool bot (hybrid response for both form types)
        echo json_encode(['status' => 'success', 'success' => true, 'message' => 'Gửi yêu cầu thành công!']);
        exit;
    }

    // 2. Time check (users can't fill form instantly)
    $submit_time = time();
    $form_time = isset($_POST['form_ts']) ? (int) $_POST['form_ts'] : 0;

    // Allow old timestamps (cached pages), but block super fast (< 3s) or future timestamps
    if ($form_time > $submit_time || ($submit_time - $form_time < $min_time_seconds)) {
        error_log('Bot detected: Time check failed (' . ($submit_time - $form_time) . 's). IP: ' . $_SERVER['REMOTE_ADDR']);
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Thao tác quá nhanh. Vui lòng thử lại chậm hơn.']);
        exit;
    }

    // 3. Rate Limit (Max 5 form submissions / 5 minutes)
    global $pdo;
    try {
        // IP an toàn: chỉ tin CF/X-Forwarded-For khi sau proxy tin cậy, tránh spoof header để vượt rate-limit.
        $ip = function_exists('get_trusted_client_ip') ? get_trusted_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // Ensure table exists (in case log_conversion wasn't called yet)
        if (!has_table_column($pdo, 'conversion_logs', 'id')) {
            return; // Skip if table doesn't exist
        }

        // Only count form submissions (not click events like contact_hotline/zalo/messenger)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM conversion_logs WHERE ip_address = ? AND type IN ('consultation', 'registration', 'contact', 'order') AND created_at >= (NOW() - INTERVAL 5 MINUTE)");
        $stmt->execute([$ip]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= 5) {
            error_log('Bot detected: Rate limit exceeded (' . $count . '/5m). IP: ' . $ip);
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau 5 phút.']);
            exit;
        }
    } catch (Exception $e) {
        // Find silently
    }
}

/**
 * Log admin activity
 * @param string $action Action name (e.g. 'login', 'create', 'update', 'delete')
 * @param string $resource_type Resource type (e.g. 'service', 'post', 'user', 'setting')
 * @param int|string|null $resource_id Resource ID
 * @param mixed $details Additional details (array or string)
 */
function log_activity($action, $resource_type = null, $resource_id = null, $details = null)
{
    global $pdo;

    // Start session if not started, to access user info
    if (session_status() === PHP_SESSION_NONE) {
        // We can't easily start session here if headers sent, but usually it's started.
    }

    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? ($_SESSION['full_name'] ?? 'Guest');

    // Get IP
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (is_array($details) || is_object($details)) {
        $details = json_encode($details, JSON_UNESCAPED_UNICODE);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, username, action, resource_type, resource_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $username, $action, $resource_type, $resource_id, $details, $ip_address, $user_agent]);
    } catch (PDOException $e) {
        // Silently fail to avoid breaking the main flow
    }
}
/**
 * Compress and optionally convert an uploaded image to WebP.
 *
 * - Converts jpg/jpeg/png/gif → .webp (nếu GD hỗ trợ)
 * - Resize nếu chiều rộng vượt quá $max_width (mặc định 1920px)
 * - Xóa file gốc sau khi convert thành công
 *
 * @param string $src        Đường dẫn tuyệt đối tới file ảnh gốc (đã move_uploaded_file)
 * @param int    $quality    Chất lượng WebP 0-100 (mặc định 82)
 * @param int    $max_width  Chiều rộng tối đa tính bằng px (mặc định 1920)
 * @return string|false      Đường dẫn tuyệt đối tới file WebP, hoặc $src nếu không convert được
 */
function compress_to_webp(string $src, int $quality = 82, int $max_width = 1920, &$details = null, bool $allow_imagick_fallback = true): string
{
    $details = [
        'converted' => false,
        'reason' => 'unknown'
    ];

    if (!is_file($src)) {
        $details['reason'] = 'source_not_found';
        return $src;
    }

    if (!is_readable($src)) {
        $details['reason'] = 'source_not_readable';
        return $src;
    }

    $try_imagick = function () use ($src, $quality, $max_width, &$details): string {
        if (!class_exists('Imagick')) {
            $details['reason'] = 'gd_webp_not_supported';
            return $src;
        }

        $webp_dest = preg_replace('/\.[^.]+$/', '.webp', $src);
        if ($webp_dest === null || $webp_dest === '' || $webp_dest === $src) {
            $webp_dest = $src . '.webp';
        }

        try {
            $im = new Imagick($src);
            $w = (int) $im->getImageWidth();
            $h = (int) $im->getImageHeight();
            if ($max_width > 0 && $w > $max_width && $h > 0) {
                $new_h = (int) round($h * $max_width / $w);
                $im->resizeImage($max_width, $new_h, Imagick::FILTER_LANCZOS, 1);
            }

            $im->setImageFormat('webp');
            $im->setImageCompressionQuality($quality);
            $ok = $im->writeImage($webp_dest);
            $im->clear();
            $im->destroy();

            if ($ok && file_exists($webp_dest) && filesize($webp_dest) > 0) {
                if ($src !== $webp_dest && file_exists($src)) {
                    @unlink($src);
                }
                $details['converted'] = true;
                $details['reason'] = 'converted_imagick';
                $details['output'] = $webp_dest;
                return $webp_dest;
            }

            if (file_exists($webp_dest) && $webp_dest !== $src) {
                @unlink($webp_dest);
            }
            $details['reason'] = 'imagick_empty_output';
            return $src;
        } catch (Throwable $e) {
            $details['reason'] = 'imagick_failed';
            $details['error'] = $e->getMessage();
            return $src;
        }
    };

    if (!function_exists('imagewebp') || !function_exists('imagecreatefromjpeg')) {
        if (!$allow_imagick_fallback) {
            $details['reason'] = 'gd_webp_not_supported';
            return $src;
        }
        return $try_imagick();
    }

    $mime = null;
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($src);
    }

    if (!$mime || $mime === 'application/octet-stream') {
        $info = @getimagesize($src);
        if ($info && isset($info['mime'])) {
            $mime = $info['mime'];
        }
    }
    $details['mime'] = (string) $mime;

    $img = null;
    switch ($mime) {
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($src);
            break;
        case 'image/png':
            $img = @imagecreatefrompng($src);
            break;
        case 'image/gif':
            $img = @imagecreatefromgif($src);
            break;
        case 'image/webp':
            $img = @imagecreatefromwebp($src);
            break;
        default:
            $details['reason'] = 'unsupported_mime';
            return $src;
    }

    if (!$img) {
        $details['reason'] = 'image_decode_failed';
        return $src;
    }

    if ($mime === 'image/png') {
        imagealphablending($img, true);
        imagesavealpha($img, true);
    }

    $w = imagesx($img);
    $h = imagesy($img);

    // Some palette PNG/GIF files fail to encode WebP unless promoted to truecolor.
    if (!imageistruecolor($img) && $w > 0 && $h > 0) {
        $truecolor = imagecreatetruecolor($w, $h);
        if ($truecolor !== false) {
            imagealphablending($truecolor, false);
            imagesavealpha($truecolor, true);
            $transparent = imagecolorallocatealpha($truecolor, 0, 0, 0, 127);
            imagefilledrectangle($truecolor, 0, 0, $w, $h, $transparent);
            imagecopy($truecolor, $img, 0, 0, 0, 0, $w, $h);
            imagedestroy($img);
            $img = $truecolor;
        }
    }

    if ($max_width > 0 && $w > $max_width) {
        $new_h  = (int) round($h * $max_width / $w);
        $resized = imagecreatetruecolor($max_width, $new_h);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $max_width, $new_h, $transparent);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $max_width, $new_h, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    $webp_dest = preg_replace('/\.[^.]+$/', '.webp', $src);
    if ($webp_dest === null || $webp_dest === '' || $webp_dest === $src) {
        $webp_dest = $src . '.webp';
    }

    $imagewebp_warning = null;
    set_error_handler(function ($errno, $errstr) use (&$imagewebp_warning) {
        $imagewebp_warning = (string) $errstr;
        return true;
    });
    $ok = imagewebp($img, $webp_dest, $quality);
    restore_error_handler();
    imagedestroy($img);

    if ($ok && file_exists($webp_dest) && filesize($webp_dest) > 0) {
        if ($src !== $webp_dest && file_exists($src)) {
            @unlink($src);
        }
        $details['converted'] = true;
        $details['reason'] = 'converted';
        $details['output'] = $webp_dest;
        return $webp_dest;
    }

    if (file_exists($webp_dest) && $webp_dest !== $src) {
        @unlink($webp_dest);
    }

    $details['reason'] = $ok ? 'webp_empty_output' : 'imagewebp_failed';
    if ($imagewebp_warning) {
        $details['error'] = $imagewebp_warning;
    }
    if ($allow_imagick_fallback) {
        $fallback = $try_imagick();
        if ($fallback !== $src && is_file($fallback)) {
            return $fallback;
        }
    }
    return $src;
}

/**
 * Xóa các file ảnh local của 1 sản phẩm (ảnh đại diện + gallery).
 * Chỉ xóa file nằm trong thư mục uploads/ (bỏ qua URL ngoài, chống path traversal).
 * Dùng khi xóa vĩnh viễn sản phẩm để dọn rác ảnh.
 *
 * @param array $product mảng có key 'image' và 'gallery' (gallery là JSON mảng path)
 * @return int số file đã xóa
 */
function delete_product_local_images($product): int
{
    if (!is_array($product)) {
        return 0;
    }
    $root = realpath(__DIR__ . '/..');
    $uploads_root = $root ? realpath($root . '/uploads') : false;
    if (!$uploads_root) {
        return 0;
    }

    $paths = [];
    if (!empty($product['image'])) {
        $paths[] = (string) $product['image'];
    }
    if (!empty($product['gallery'])) {
        $gallery = json_decode((string) $product['gallery'], true);
        if (is_array($gallery)) {
            foreach ($gallery as $g) {
                if (is_string($g) && $g !== '') {
                    $paths[] = $g;
                }
            }
        }
    }

    $deleted = 0;
    $dirs = [];
    $rel_to_purge = []; // các path tương đối cần xóa khỏi media_library
    foreach ($paths as $p) {
        $p = trim($p);
        if ($p === '' || strpos($p, 'http') === 0 || strpos($p, '//') === 0) {
            continue; // bỏ qua URL ngoài
        }
        $abs = realpath($root . '/' . ltrim($p, '/'));
        if ($abs === false) {
            // file đã mất nhưng vẫn cần dọn bản ghi media_library
            $rel_to_purge[] = ltrim(str_replace('\\', '/', $p), '/');
            continue;
        }
        // Chỉ xóa nếu nằm trong uploads/
        if (strpos($abs, $uploads_root) !== 0) {
            continue;
        }
        $rel_to_purge[] = ltrim(str_replace('\\', '/', $p), '/');
        if (is_file($abs) && @unlink($abs)) {
            $deleted++;
        }
        $dirs[dirname($abs)] = true;
    }

    // Với ảnh import từ Shopee: dọn SẠCH cả thư mục item (uploads/products/shopee/<item_id>/)
    // để không sót ảnh cũ/banner từ các lần crawl trước.
    $shopee_root = realpath($uploads_root . '/products/shopee');
    foreach (array_keys($dirs) as $dir) {
        $dirReal = realpath($dir);
        if ($dirReal === false || $shopee_root === false) {
            continue;
        }
        if (strpos($dirReal, $shopee_root) === 0 && $dirReal !== $shopee_root) {
            foreach (glob($dirReal . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    // path tương đối từ web root để dọn media_library
                    $rel_to_purge[] = ltrim(str_replace('\\', '/', substr($f, strlen($root) + 1)), '/');
                    if (@unlink($f)) {
                        $deleted++;
                    }
                }
            }
        }
    }

    // Xóa bản ghi media_library trỏ tới các ảnh vừa xóa (để Thư viện Media không còn hiển thị).
    $rel_to_purge = array_values(array_unique(array_filter($rel_to_purge)));
    if (!empty($rel_to_purge)) {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $in = implode(',', array_fill(0, count($rel_to_purge), '?'));
                $pdo->prepare("DELETE FROM media_library WHERE file_path IN ($in)")->execute($rel_to_purge);
            } catch (Throwable $e) { /* bảng có thể chưa có -> bỏ qua */ }
        }
    }

    // Dọn thư mục rỗng (vd uploads/products/shopee/<item_id>/)
    foreach (array_keys($dirs) as $dir) {
        if (strpos($dir, $uploads_root) === 0 && $dir !== $uploads_root && is_dir($dir)) {
            $rest = @scandir($dir);
            if ($rest !== false && count(array_diff($rest, ['.', '..'])) === 0) {
                @rmdir($dir);
            }
        }
    }

    return $deleted;
}

/**
 * Trả về thẻ <script> JSON-LD từ mảng dữ liệu schema (dùng inline trong body).
 */
function jsonld_script($data): string
{
    if (empty($data) || !is_array($data)) {
        return '';
    }
    return "\n<script type=\"application/ld+json\">"
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}

/**
 * Xóa dữ liệu traffic/log cũ hơn $days ngày (chống DB phình).
 * Tự dò cột thời gian phù hợp của từng bảng; bỏ qua bảng không có.
 * @return array<string,int> map [table => số dòng đã xóa]
 */
function prune_old_traffic_data(PDO $pdo, int $days = 90): array
{
    $days = max(7, $days); // an toàn: không cho xóa dữ liệu quá mới
    $targets = ['traffic_pageviews', 'traffic_events', 'traffic_sessions', 'traffic_ip_profiles', 'conversion_logs', 'audit_logs'];
    $dateCandidates = ['created_at', 'last_activity_at', 'last_seen_at', 'last_attempt_at', 'started_at', 'updated_at'];
    $result = [];

    foreach ($targets as $t) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            continue; // bảng không tồn tại
        }
        $dateCol = null;
        foreach ($dateCandidates as $cand) {
            if (in_array($cand, $cols, true)) {
                $dateCol = $cand;
                break;
            }
        }
        if ($dateCol === null) {
            continue;
        }
        try {
            // $days đã ép kiểu int + clamp -> an toàn nội suy
            $st = $pdo->prepare("DELETE FROM `$t` WHERE `$dateCol` < (NOW() - INTERVAL " . (int) $days . " DAY)");
            $st->execute();
            $result[$t] = $st->rowCount();
        } catch (Throwable $e) {
            $result[$t] = -1;
        }
    }
    return $result;
}

/**
 * Ghi 1 setting (upsert) — dùng nội bộ cho mốc thời gian dọn dữ liệu.
 */
function app_set_setting(PDO $pdo, string $key, string $value): void
{
    try {
        $chk = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
        $chk->execute([$key]);
        if ($chk->fetchColumn()) {
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
        } else {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'system')")->execute([$key, $value]);
        }
    } catch (Throwable $e) {
        // im lặng
    }
}

/**
 * Ghi lại 1 lần dọn vào lịch sử (lưu trong settings, giữ tối đa 20 lần gần nhất).
 */
function record_traffic_prune_history(PDO $pdo, int $at, int $days, array $result): void
{
    $total = 0;
    foreach ($result as $n) {
        if ($n > 0) {
            $total += $n;
        }
    }
    app_set_setting($pdo, 'last_traffic_prune_at', (string) $at);
    app_set_setting($pdo, 'last_traffic_prune_result', json_encode(['at' => $at, 'days' => $days, 'total' => $total, 'detail' => $result], JSON_UNESCAPED_UNICODE));

    $hist = json_decode((string) get_setting('traffic_prune_history', '[]'), true);
    if (!is_array($hist)) {
        $hist = [];
    }
    array_unshift($hist, ['at' => $at, 'days' => $days, 'total' => $total]);
    $hist = array_slice($hist, 0, 20);
    app_set_setting($pdo, 'traffic_prune_history', json_encode($hist, JSON_UNESCAPED_UNICODE));
}

/**
 * Dọn ngay (bỏ qua throttle) — dùng cho nút "Dọn ngay" trong admin.
 * @return array kết quả [table => số dòng đã xóa]
 */
function run_traffic_prune_now(PDO $pdo): array
{
    $days = (int) get_setting('perf_traffic_prune_days', '90');
    if ($days < 7) {
        $days = 90;
    }
    $result = prune_old_traffic_data($pdo, $days);
    record_traffic_prune_history($pdo, time(), $days, $result);
    return $result;
}

/**
 * Dọn dữ liệu traffic theo cơ chế throttle (mặc định tối đa 1 lần / 24h).
 * Đọc cấu hình từ settings: perf_traffic_prune_enabled, perf_traffic_prune_days.
 * Gắn vào Dashboard admin -> không cần cron. An toàn, không bao giờ làm vỡ trang.
 */
function maybe_prune_traffic_data(PDO $pdo, int $defaultDays = 90, int $throttleHours = 24): void
{
    try {
        if (get_setting('perf_traffic_prune_enabled', '1') !== '1') {
            return; // người dùng đã tắt
        }
        $days = (int) get_setting('perf_traffic_prune_days', (string) $defaultDays);
        if ($days < 7) {
            $days = $defaultDays;
        }
        $last = (int) get_setting('last_traffic_prune_at', '0');
        $now = time();
        if ($last > 0 && ($now - $last) < $throttleHours * 3600) {
            return; // chưa tới hạn dọn
        }
        // Cập nhật mốc TRƯỚC khi dọn để tránh chạy chồng nếu mở nhiều tab cùng lúc.
        app_set_setting($pdo, 'last_traffic_prune_at', (string) $now);
        $result = prune_old_traffic_data($pdo, $days);
        record_traffic_prune_history($pdo, $now, $days, $result);
    } catch (Throwable $e) {
        // không để việc dọn ảnh hưởng tới trang admin
    }
}

// Hệ thống Banner Quảng cáo (ad slot) — helper + schema.
require_once __DIR__ . '/ad-banners.php';
?>

