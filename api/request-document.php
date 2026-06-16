<?php
/**
 * API: Nhận yêu cầu tài liệu -> gửi file đính kèm qua email (PHPMailer) + lưu lead.
 * Đọc file LOCAL theo post_id (không nhận đường dẫn từ client) -> chống path traversal.
 */
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

header('Content-Type: application/json; charset=UTF-8');

function dr_json($ok, $msg)
{
    echo json_encode(['success' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    dr_json(false, 'Phương thức không hợp lệ.');
}

// CSRF
$csrf = get_request_csrf_token();
if (!verify_csrf_token($csrf)) {
    http_response_code(419);
    dr_json(false, 'Phiên đã hết hạn, vui lòng tải lại trang.');
}

// Honeypot + thời gian tối thiểu (chống bot)
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    dr_json(true, 'Đã gửi! Vui lòng kiểm tra email của bạn.'); // im lặng với bot
}
$ts = (int) ($_POST['form_ts'] ?? 0);
if ($ts > 0 && (time() - $ts) < 2) {
    dr_json(false, 'Bạn thao tác quá nhanh, vui lòng thử lại.');
}

$post_id  = (int) ($_POST['post_id'] ?? 0);
$fullname = trim((string) ($_POST['fullname'] ?? ''));
$email    = trim((string) ($_POST['email'] ?? ''));

if ($post_id <= 0 || $fullname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    dr_json(false, 'Vui lòng nhập đầy đủ họ tên và email hợp lệ.');
}
if (mb_strlen($fullname, 'UTF-8') > 150) {
    $fullname = mb_substr($fullname, 0, 150, 'UTF-8');
}

$ip = get_trusted_client_ip();

// Thông tin theo dõi (giống lead WordPress cũ): trình duyệt, thiết bị, trang nguồn.
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$browser = function_exists('traffic_detect_browser') ? traffic_detect_browser($ua) : 'Other';
$deviceType = function_exists('traffic_detect_device_type') ? traffic_detect_device_type($ua) : 'unknown';
$osName = function_exists('traffic_detect_os') ? traffic_detect_os($ua) : 'Other';
$device = mb_substr($deviceType . ' / ' . $osName, 0, 60, 'UTF-8');
$sourceUrl = mb_substr(trim((string) ($_POST['source_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))), 0, 255, 'UTF-8');

// Rate limit: tối đa 5 yêu cầu / IP / 10 phút
try {
    $rl = $pdo->prepare("SELECT COUNT(*) FROM document_requests WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
    $rl->execute([$ip]);
    if ((int) $rl->fetchColumn() >= 5) {
        http_response_code(429);
        dr_json(false, 'Bạn đã gửi quá nhiều yêu cầu. Vui lòng thử lại sau ít phút.');
    }
} catch (Throwable $e) { /* bỏ qua nếu lỗi đếm */ }

// Lấy bài + tài liệu (đường dẫn từ DB, không từ client)
try {
    $s = $pdo->prepare("SELECT id, title, slug, document_path, document_name FROM posts WHERE id = ? AND status = 1 LIMIT 1");
    $s->execute([$post_id]);
    $post = $s->fetch();
} catch (Throwable $e) {
    $post = null;
}
if (!$post || empty($post['document_path'])) {
    dr_json(false, 'Tài liệu không tồn tại hoặc đã bị gỡ.');
}

// Đường dẫn file local an toàn (phải nằm trong thư mục documents)
$docDirReal = realpath(ROOT_PATH . 'assets/uploads/documents');
$fileReal   = realpath(ROOT_PATH . $post['document_path']);
$docDirPrefix = ($docDirReal !== false) ? rtrim($docDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
if ($docDirReal === false || $fileReal === false || strpos($fileReal, $docDirPrefix) !== 0 || !is_file($fileReal)) {
    // vẫn lưu lead thất bại để admin biết
    try {
        $pdo->prepare("INSERT INTO document_requests (post_id, fullname, email, file_name, ip_address, source_url, device, browser, status, error_note) VALUES (?,?,?,?,?,?,?,?, 'failed', 'file_missing')")
            ->execute([$post_id, $fullname, $email, (string) $post['document_name'], $ip, $sourceUrl, $device, $browser]);
    } catch (Throwable $e) {}
    dr_json(false, 'Hệ thống chưa tìm thấy file tài liệu. Vui lòng liên hệ quản trị viên.');
}

$docName = $post['document_name'] ?: basename($fileReal);
$siteName = get_setting('site_name', 'Thắng Digital Marketing');
$postUrlAbs = BASE_URL . ltrim($post['slug'], '/') . '/';

$subject = 'Tài liệu: ' . $post['title'];
$html = '<!doctype html><html lang="vi"><head><meta charset="UTF-8"></head>'
    . '<body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">'
    . '<table role="presentation" width="600" style="width:100%;max-width:600px;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;">'
    . '<tr><td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:24px;color:#fff;">'
    . '<h1 style="margin:0;font-size:20px;">Tài liệu của bạn đã sẵn sàng</h1></td></tr>'
    . '<tr><td style="padding:24px;color:#111827;">'
    . '<p>Xin chào <strong>' . mail_h($fullname) . '</strong>,</p>'
    . '<p>Cảm ơn bạn đã quan tâm. Tài liệu <strong>' . mail_h($docName) . '</strong> được đính kèm trong email này.</p>'
    . '<p>Bài viết liên quan: <a href="' . mail_h($postUrlAbs) . '" style="color:#2563eb;">' . mail_h($post['title']) . '</a></p>'
    . '<p style="color:#6b7280;font-size:13px;margin-top:24px;">Trân trọng,<br>' . mail_h($siteName) . '</p>'
    . '</td></tr></table></td></tr></table></body></html>';
$text = "Xin chao $fullname,\nTai lieu: $docName duoc dinh kem.\nBai viet: $postUrlAbs\n$siteName";

$status = 'failed';
$errNote = null;
$result = send_smtp_email($subject, $html, $text, $email, $fullname, [
    'attachments' => [['path' => $fileReal, 'name' => $docName]],
    'no_admin_cc' => true,
]);
if (!empty($result['success'])) {
    $status = 'sent';
} else {
    $errNote = mb_substr((string) ($result['message'] ?? 'send_failed'), 0, 250, 'UTF-8');
}

// Lưu lead
try {
    $pdo->prepare("INSERT INTO document_requests (post_id, fullname, email, file_name, ip_address, source_url, device, browser, status, error_note) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$post_id, $fullname, $email, $docName, $ip, $sourceUrl, $device, $browser, $status, $errNote]);
} catch (Throwable $e) {}

if ($status === 'sent') {
    dr_json(true, 'Đã gửi! Vui lòng kiểm tra hộp thư (cả mục Spam) của bạn.');
}
dr_json(false, 'Hiện chưa gửi được email. Chúng tôi đã ghi nhận và sẽ gửi lại sớm.');
