<?php
// ajax_contact.php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/mailer.php';

header('Content-Type: application/json; charset=UTF-8');

function cleanInput($data)
{
    if (is_array($data)) {
        return $data;
    }
    return htmlspecialchars(strip_tags(trim((string) $data)), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Thao tác không hợp lệ.']);
    exit;
}

check_bot_submission(3);

$csrf_token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token)) {
    echo json_encode(['status' => 'error', 'message' => 'Phiên làm việc hết hạn. Vui lòng tải lại trang và thử lại.']);
    exit;
}

$clientPhone = cleanInput($_POST['phone'] ?? '');
$service_id = isset($_POST['service_id']) && $_POST['service_id'] !== ''
    ? (int) $_POST['service_id']
    : (isset($_POST['product_id']) && $_POST['product_id'] !== '' ? (int) $_POST['product_id'] : null);
$service_name = cleanInput($_POST['service_name'] ?? ($_POST['product_name'] ?? ''));
$isProductRegistration = $service_id !== null || $service_name !== '';

if ($service_id !== null) {
    try {
        $p_stmt = $pdo->prepare('SELECT name FROM products WHERE id = ? AND status = 1 AND deleted_at IS NULL');
        $p_stmt->execute([$service_id]);
        $productRecord = $p_stmt->fetch();
        if (!$productRecord) {
            echo json_encode(['status' => 'error', 'message' => 'Dịch vụ không tồn tại hoặc đã bị xóa.', 'field' => 'service_id']);
            exit;
        }
        $service_name = $productRecord['name'];
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Không thể xác thực dịch vụ. Vui lòng thử lại.', 'field' => 'service_id']);
        exit;
    }
}

try {
    $rate_stmt = $pdo->prepare("SELECT COUNT(*) FROM " . ($isProductRegistration ? "product_registrations" : "contacts") . " WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND phone = ?");
    $rate_stmt->execute([$clientPhone]);
    if ($rate_stmt->fetchColumn() >= 1) {
        echo json_encode(['status' => 'error', 'message' => 'Bạn đã gửi yêu cầu gần đây. Vui lòng chờ ít nhất 1 phút trước khi gửi lại.']);
        exit;
    }
} catch (Exception $e) {
}

$name = cleanInput($_POST['name'] ?? '');
$phone = $clientPhone;
$email = cleanInput($_POST['email'] ?? '');
$message = cleanInput($_POST['message'] ?? '');
$province = '';
$district = '';

if (mb_strlen($name) > 100) {
    echo json_encode(['status' => 'error', 'message' => 'Họ tên không được vượt quá 100 ký tự.', 'field' => 'name']);
    exit;
}
if (mb_strlen($phone) > 15) {
    echo json_encode(['status' => 'error', 'message' => 'Số điện thoại không hợp lệ.', 'field' => 'phone']);
    exit;
}
if (mb_strlen($email) > 190) {
    echo json_encode(['status' => 'error', 'message' => 'Email không được vượt quá 190 ký tự.', 'field' => 'email']);
    exit;
}
if (mb_strlen($message) > 1000) {
    echo json_encode(['status' => 'error', 'message' => 'Nội dung không được vượt quá 1000 ký tự.', 'field' => 'message']);
    exit;
}

if ($name === '') {
    echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập họ tên.', 'field' => 'name']);
    exit;
}
if (mb_strlen($name) < 2) {
    echo json_encode(['status' => 'error', 'message' => 'Họ tên phải có ít nhất 2 ký tự.', 'field' => 'name']);
    exit;
}
if (!preg_match('/^[\p{L}\s.\-]+$/u', $name)) {
    echo json_encode(['status' => 'error', 'message' => 'Họ tên không được chứa ký tự đặc biệt.', 'field' => 'name']);
    exit;
}

if ($phone === '') {
    echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập số điện thoại.', 'field' => 'phone']);
    exit;
}
$phoneNormalized = preg_replace('/\s+/', '', $phone);
if (!preg_match('/^(0|\+84)[0-9]{9,10}$/', $phoneNormalized)) {
    echo json_encode(['status' => 'error', 'message' => 'Số điện thoại không hợp lệ (VD: 0362360364).', 'field' => 'phone']);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Email không hợp lệ.', 'field' => 'email']);
    exit;
}

if ($message !== '' && !preg_match('/^[\p{L}0-9\s.,!?;:\/\-()@"\'&]+$/u', $message)) {
    echo json_encode(['status' => 'error', 'message' => 'Nội dung chứa ký tự không hợp lệ.', 'field' => 'message']);
    exit;
}

if ($isProductRegistration && $service_id === null) {
    echo json_encode(['status' => 'error', 'message' => 'Thiếu thông tin dịch vụ. Vui lòng tải lại trang và thử lại.', 'field' => 'service_id']);
    exit;
}

try {
    if ($isProductRegistration) {
        $sql = "INSERT INTO product_registrations (service_id, service_name, fullname, phone, province, district, address, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";

        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute([$service_id, $service_name ?: 'Tư vấn dịch vụ', $name, $phone, $province, $district, ''])) {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi hệ thống. Vui lòng thử lại sau.']);
            exit;
        }
    } else {
        $contactMessage = $message;
        if ($email !== '') {
            $contactMessage = ($contactMessage !== '' ? $contactMessage . "\n" : '') . 'Email: ' . $email;
        }

        $sql = "INSERT INTO contacts (name, phone, city, product_id, message, status, created_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())";

        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute([$name, $phone, $email !== '' ? $email : null, null, $contactMessage !== '' ? $contactMessage : null])) {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi hệ thống. Vui lòng thử lại sau.']);
            exit;
        }

        $service_name = 'Liên hệ chung';
    }

    $registration_id = (int) $pdo->lastInsertId();

    $mailResult = send_registration_notification([
        'registration_id' => $registration_id,
        'is_contact' => !$isProductRegistration,
        'source' => $isProductRegistration ? 'Form tư vấn dịch vụ' : 'Form liên hệ',
        'service_name' => $service_name,
        'fullname' => $name,
        'phone' => $phone,
        'email' => $email,
        'message' => $message,
        'province' => '',
        'district' => '',
        'address' => '',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    if (!$mailResult['success'] && $mailResult['message'] !== 'SMTP disabled') {
        error_log('Contact mail warning: ' . $mailResult['message']);
    }

    log_conversion($isProductRegistration ? 'consultation' : 'contact', [
        'fullname' => $name,
        'phone' => $phone,
        'email' => $email,
        'message' => $message,
        'service_name' => $service_name,
        'registration_id' => $registration_id
    ], get_setting('gtm_event_form_consultation', 'submit_consultation'));

    echo json_encode([
        'status' => 'success',
        'message' => 'Gửi yêu cầu thành công! Chúng tôi sẽ liên hệ sớm.',
        'mail_sent' => (bool) $mailResult['success'],
        'mail_error' => $mailResult['success'] ? '' : (string) ($mailResult['message'] ?? '')
    ]);
} catch (PDOException $e) {
    error_log('Contact DB Error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu. Vui lòng thử lại sau.']);
}
?>
