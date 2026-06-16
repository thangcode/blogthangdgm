<?php
// includes/mailer.php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function is_smtp_enabled()
{
    $enabled = strtolower(trim((string) get_setting('smtp_enabled', '0')));
    return in_array($enabled, ['1', 'true', 'yes', 'on'], true);
}

function load_phpmailer()
{
    if (class_exists(PHPMailer::class)) {
        return true;
    }

    $autoload = ROOT_PATH . 'vendor/autoload.php';
    if (!file_exists($autoload)) {
        return false;
    }

    require_once $autoload;
    return class_exists(PHPMailer::class);
}

function mail_vi($text)
{
    return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function mail_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalize_mail_utf8($value)
{
    if (!is_string($value) || $value === '') {
        return $value;
    }

    $current = $value;
    for ($i = 0; $i < 3; $i++) {
        if (strpos($current, chr(0xC3)) === false && strpos($current, chr(0xC2)) === false) {
            break;
        }
        $candidate = @iconv('Windows-1252', 'UTF-8//IGNORE', $current);
        if (!is_string($candidate) || $candidate === '' || $candidate === $current) {
            break;
        }
        $current = $candidate;
    }

    return $current;
}

function send_smtp_email($subject, $htmlBody, $textBody = '', $toEmail = '', $toName = '', array $options = [])
{
    $debugEnabled = !empty($options['debug']);
    $debugBuffer = '';

    if (!is_smtp_enabled()) {
        return ['success' => false, 'message' => 'SMTP disabled', 'debug' => $debugBuffer];
    }

    if (!load_phpmailer()) {
        return ['success' => false, 'message' => 'PHPMailer not installed (vendor/autoload.php missing)', 'debug' => $debugBuffer];
    }

    $host = trim((string) get_setting('smtp_host', ''));
    $port = (int) get_setting('smtp_port', '587');
    $user = trim((string) get_setting('smtp_user', ''));
    $pass = (string) get_setting('smtp_pass', '');
    $secure = strtolower(trim((string) get_setting('smtp_secure', 'tls')));

    $fromEmail = trim((string) get_setting('smtp_from_email', get_setting('contact_email', '')));
    $fromName = trim((string) get_setting('smtp_from_name', get_setting('site_name', 'Thắng Digital Marketing')));
    $recipient = trim((string) ($toEmail !== '' ? $toEmail : get_setting('smtp_to_email', get_setting('contact_email', ''))));
    $recipient2 = trim((string) get_setting('smtp_to_email_2', ''));
    $recipient3 = trim((string) get_setting('smtp_to_email_3', ''));
    $replyTo = trim((string) get_setting('contact_email', ''));

    if ($host === '' || $user === '' || $pass === '' || $recipient === '' || $fromEmail === '') {
        return ['success' => false, 'message' => 'SMTP settings incomplete', 'debug' => $debugBuffer];
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'SMTP from email invalid', 'debug' => $debugBuffer];
    }

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'SMTP recipient email invalid', 'debug' => $debugBuffer];
    }

    if ($port <= 0) {
        $port = 587;
    }


    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->ContentType = 'text/html; charset=UTF-8';

        if ($debugEnabled) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function ($str, $level) use (&$debugBuffer) {
                $debugBuffer .= '[L' . (int) $level . '] ' . trim((string) $str) . "\n";
            };
        }

        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'none') {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : 'ShopSieuSale');
        $mail->addAddress($recipient, $toName);
        $noAdminCc = !empty($options['no_admin_cc']);
        if (!$noAdminCc && $recipient2 !== '' && filter_var($recipient2, FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($recipient2);
        }
        if (!$noAdminCc && $recipient3 !== '' && filter_var($recipient3, FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($recipient3);
        }
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo);
        }

        // Đính kèm file (nếu có) — chỉ nhận đường dẫn file tồn tại.
        if (!empty($options['attachments']) && is_array($options['attachments'])) {
            foreach ($options['attachments'] as $att) {
                $attPath = is_array($att) ? ($att['path'] ?? '') : (string) $att;
                if ($attPath !== '' && is_file($attPath)) {
                    $attName = is_array($att) ? ($att['name'] ?? basename($attPath)) : basename($attPath);
                    $mail->addAttachment($attPath, $attName);
                }
            }
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));
        $mail->send();

        return ['success' => true, 'message' => 'sent', 'debug' => $debugBuffer];
    } catch (Exception $e) {
        $errorInfo = isset($mail) ? trim((string) $mail->ErrorInfo) : '';
        $message = trim((string) $e->getMessage());
        if ($errorInfo !== '' && stripos($message, $errorInfo) === false) {
            $message .= ' | ' . $errorInfo;
        }
        return ['success' => false, 'message' => $message, 'debug' => $debugBuffer];
    }
}

function send_registration_notification(array $payload)
{
    $registrationId = (int) ($payload['registration_id'] ?? 0);

    $sourceRaw = trim((string) ($payload['source'] ?? 'Form đăng ký'));
    $serviceNameRaw = trim((string) ($payload['service_name'] ?? ''));
    $fullnameRaw = trim((string) ($payload['fullname'] ?? ''));
    $phoneRaw = trim((string) ($payload['phone'] ?? ''));
    $emailRaw = trim((string) ($payload['email'] ?? ''));
    $messageRaw = trim((string) ($payload['message'] ?? ''));
    $provinceRaw = trim((string) ($payload['province'] ?? ''));
    $districtRaw = trim((string) ($payload['district'] ?? ''));
    $addressRaw = trim((string) ($payload['address'] ?? ''));
    $createdAtRaw = trim((string) ($payload['created_at'] ?? date('Y-m-d H:i:s')));

    $normalizedSource = function_exists('mb_strtolower') ? mb_strtolower($sourceRaw, 'UTF-8') : strtolower($sourceRaw);
    $isContact = !empty($payload['is_contact'])
        || mb_strpos($normalizedSource, 'liên hệ') !== false
        || strpos($normalizedSource, 'lien he') !== false;
    $isService = !$isContact && (
        mb_strpos($normalizedSource, 'tư vấn') !== false
        || strpos($normalizedSource, 'tu van') !== false
        || mb_strpos($normalizedSource, 'dịch vụ') !== false
        || strpos($normalizedSource, 'dich vu') !== false
    );

    if ($isService) {
        $subject = 'Liên hệ tư vấn dịch vụ mới #' . $registrationId;
    } elseif ($isContact) {
        $subject = 'Liên hệ mới #' . $registrationId;
    } else {
        $subject = 'Đăng ký mới #' . $registrationId;
    }

    $source = mail_h($sourceRaw !== '' ? $sourceRaw : 'Form đăng ký');
    $serviceName = mail_h($serviceNameRaw !== '' ? $serviceNameRaw : 'Chưa có thông tin');
    $fullname = mail_h($fullnameRaw !== '' ? $fullnameRaw : 'Chưa có thông tin');
    $phone = mail_h($phoneRaw !== '' ? $phoneRaw : 'Chưa có thông tin');
    $email = mail_h($emailRaw !== '' ? $emailRaw : 'Chưa có thông tin');
    $message = nl2br(mail_h($messageRaw !== '' ? $messageRaw : 'Không có nội dung'));
    $province = mail_h($provinceRaw !== '' ? $provinceRaw : 'Chưa có thông tin');
    $district = mail_h($districtRaw !== '' ? $districtRaw : 'Chưa có thông tin');
    $address = mail_h($addressRaw !== '' ? $addressRaw : 'Chưa có thông tin');
    $createdAt = mail_h($createdAtRaw);

    $siteName = mail_h((string) get_setting('site_name', 'Thắng Digital Marketing'));
    $baseUrl = mail_h((string) BASE_URL);
    $adminUrl = mail_h((string) (BASE_URL . ($isContact ? 'admin/contacts/' : 'admin/registrations/')));

    $rows = '';
    $rowMap = [
        'Nguồn gửi' => $source,
        'Dịch vụ'   => $serviceName,
        'Khách hàng' => $fullname,
        'Điện thoại' => $phone,
        'Email'      => $email,
        'Tin nhắn'   => $message,
        'Tỉnh/Thành' => $province,
        'Quận/Huyện' => $district,
        'Địa chỉ'   => $address,
        'Thời gian'  => $createdAt,
    ];
    foreach ($rowMap as $label => $value) {
        $rows .= '<tr>'
            . '<td style="padding:10px 14px;background:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;font-weight:600;width:170px;">' . mail_h($label) . '</td>'
            . '<td style="padding:10px 14px;border:1px solid #e5e7eb;color:#111827;">' . $value . '</td>'
            . '</tr>';
    }

    $html = '<!doctype html><html lang="vi"><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="680" style="width:100%;max-width:680px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:22px 24px;color:#fff;">'
        . '<div style="font-size:13px;opacity:.9;">Thông báo từ hệ thống</div>'
        . '<h1 style="margin:8px 0 0;font-size:24px;">' . mail_h($subject) . '</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:22px 24px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">' . $rows . '</table>'
        . '<div style="margin-top:18px;"><a href="' . $adminUrl . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 16px;border-radius:999px;font-weight:700;">'
        . 'Mở trang quản lý</a></div>'
        . '</td></tr>'
        . '<tr><td style="padding:14px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;">'
        . 'Email tự động từ <strong style="color:#111827;">' . $siteName . '</strong>. '
        . 'Website: <a href="' . $baseUrl . '" style="color:#2563eb;text-decoration:none;">' . $baseUrl . '</a>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    $text = $subject . "\n\n"
        . 'Khách hàng: ' . strip_tags($fullname) . "\n"
        . 'Điện thoại: ' . strip_tags($phone) . "\n"
        . 'Dịch vụ: ' . strip_tags($serviceName);

    return send_smtp_email($subject, $html, $text);
}
