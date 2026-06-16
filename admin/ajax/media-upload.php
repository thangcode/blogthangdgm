<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();
ensure_media_library_table($pdo);

header('Content-Type: application/json; charset=UTF-8');

function conversion_reason_message(string $reason, string $error = ''): string
{
    $map = [
        'converted' => 'Đã chuyển sang WebP thành công.',
        'converted_imagick' => 'Đã chuyển sang WebP thành công (fallback Imagick).',
        'webp_disabled' => 'Tính năng chuyển WebP đang tắt trong cấu hình.',
        'gd_webp_not_supported' => 'Server GD không hỗ trợ imagewebp.',
        'source_not_found' => 'Không tìm thấy file nguồn sau khi upload.',
        'source_not_readable' => 'File nguồn không đọc được.',
        'unsupported_mime' => 'Định dạng MIME không được hỗ trợ để chuyển WebP.',
        'image_decode_failed' => 'Không giải mã được nội dung ảnh (file lỗi/không hợp lệ).',
        'imagewebp_failed' => 'Hàm imagewebp trả về thất bại.',
        'webp_empty_output' => 'File WebP tạo ra rỗng, đã giữ nguyên file gốc.',
        'imagick_failed' => 'GD thất bại và fallback Imagick cũng thất bại.',
        'imagick_empty_output' => 'Imagick tạo WebP rỗng, đã giữ nguyên file gốc.',
        'duplicate_existing' => 'File trùng nội dung với file đã có, dùng lại file cũ.',
        'unknown' => 'Không rõ nguyên nhân chuyển WebP thất bại.'
    ];

    $msg = $map[$reason] ?? ('Không chuyển được WebP: ' . $reason);
    if ($error !== '') {
        $msg .= ' Chi tiết: ' . $error;
    }
    return $msg;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token không hợp lệ.']);
    exit;
}

if (!isset($_FILES['files'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Không có file upload.']);
    exit;
}

$allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$max_size = 8 * 1024 * 1024; // 8MB/file
$upload_root = ROOT_PATH . 'assets/uploads/media/';

if (!is_dir($upload_root) && !mkdir($upload_root, 0777, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Không thể tạo thư mục upload.']);
    exit;
}

$year_month = date('Y/m');
$target_dir = $upload_root . $year_month . '/';
if (!is_dir($target_dir) && !mkdir($target_dir, 0777, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Không thể tạo thư mục theo tháng.']);
    exit;
}

$names = $_FILES['files']['name'] ?? [];
$tmp_names = $_FILES['files']['tmp_name'] ?? [];
$sizes = $_FILES['files']['size'] ?? [];
$errors = $_FILES['files']['error'] ?? [];

if (!is_array($names)) {
    $names = [$_FILES['files']['name']];
    $tmp_names = [$_FILES['files']['tmp_name']];
    $sizes = [$_FILES['files']['size']];
    $errors = [$_FILES['files']['error']];
}

$uploaded = [];
$failed = [];

for ($i = 0; $i < count($names); $i++) {
    $original_name = basename((string) ($names[$i] ?? ''));
    $tmp_path = $tmp_names[$i] ?? '';
    $size = (int) ($sizes[$i] ?? 0);
    $error_code = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);

    if ($error_code !== UPLOAD_ERR_OK) {
        $failed[] = ['name' => $original_name, 'message' => 'File lỗi upload (' . $error_code . ').'];
        continue;
    }

    if (!is_uploaded_file($tmp_path)) {
        $failed[] = ['name' => $original_name, 'message' => 'File upload không hợp lệ.'];
        continue;
    }

    if ($size <= 0 || $size > $max_size) {
        $failed[] = ['name' => $original_name, 'message' => 'Kích thước tối đa 8MB.'];
        continue;
    }

    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        $failed[] = ['name' => $original_name, 'message' => 'Định dạng không được hỗ trợ.'];
        continue;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp_path);
    if (!in_array($mime, $allowed_mime, true)) {
        $failed[] = ['name' => $original_name, 'message' => 'MIME type không hợp lệ.'];
        continue;
    }

    $img_info = @getimagesize($tmp_path);
    if ($img_info === false || empty($img_info[0]) || empty($img_info[1])) {
        $failed[] = ['name' => $original_name, 'message' => 'Nội dung file không phải ảnh hợp lệ.'];
        continue;
    }

    $hash = hash_file('sha256', $tmp_path);

    $stmt_dupe = $pdo->prepare("SELECT id, file_path, original_name, mime_type, file_size, width, height, created_at
                                FROM media_library
                                WHERE sha256_hash = ?
                                LIMIT 1");
    $stmt_dupe->execute([$hash]);
    $existing = $stmt_dupe->fetch(PDO::FETCH_ASSOC);

    if ($existing && file_exists(ROOT_PATH . $existing['file_path'])) {
        $uploaded[] = [
            'id' => (int) $existing['id'],
            'url' => BASE_URL . $existing['file_path'],
            'file_path' => $existing['file_path'],
            'original_name' => $existing['original_name'],
            'mime_type' => $existing['mime_type'],
            'file_size' => (int) $existing['file_size'],
            'width' => (int) $existing['width'],
            'height' => (int) $existing['height'],
            'created_at' => $existing['created_at'],
            'is_duplicate' => true,
            'conversion' => [
                'attempted' => false,
                'converted' => false,
                'reason' => 'duplicate_existing',
                'message' => conversion_reason_message('duplicate_existing')
            ]
        ];
        continue;
    }

    $safe_base = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($original_name, PATHINFO_FILENAME));
    $safe_base = trim((string) $safe_base, '-');
    if ($safe_base === '') {
        $safe_base = 'image';
    }

    $stored_name = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '-' . $safe_base . '.' . $ext;
    $target_path = $target_dir . $stored_name;
    $relative_path = 'assets/uploads/media/' . $year_month . '/' . $stored_name;

    if (!move_uploaded_file($tmp_path, $target_path)) {
        $failed[] = ['name' => $original_name, 'message' => 'Không thể lưu file lên server.'];
        continue;
    }

    $conversion_reason = 'webp_disabled';
    $conversion_attempted = false;
    $conversion_converted = false;
    $conversion_error = '';

    $webp_enabled = (get_setting('perf_webp_enabled', '1') === '1');
    if ($webp_enabled) {
        $conversion_attempted = true;
        $convert_details = null;
        $webp_path = compress_to_webp($target_path, 82, 1920, $convert_details);

        if ($webp_path !== $target_path && file_exists($webp_path)) {
            $stored_name = basename($webp_path);
            $relative_path = 'assets/uploads/media/' . $year_month . '/' . $stored_name;
            $target_path = $webp_path;
            $ext = 'webp';
            $mime = 'image/webp';
            $size = (int) filesize($webp_path);
            $img_info_new = @getimagesize($webp_path);
            if ($img_info_new) {
                $img_info = $img_info_new;
            }
            $conversion_converted = true;
            $conversion_reason = (string) ($convert_details['reason'] ?? 'converted');
        } else {
            $conversion_reason = (string) ($convert_details['reason'] ?? 'unknown');
            $conversion_error = (string) ($convert_details['error'] ?? '');
        }
    }

    $hash = hash_file('sha256', $target_path);

    try {
        $stmt = $pdo->prepare("INSERT INTO media_library
            (original_name, stored_name, file_path, mime_type, extension, file_size, width, height, sha256_hash, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                file_path = VALUES(file_path),
                stored_name = VALUES(stored_name),
                original_name = VALUES(original_name),
                mime_type = VALUES(mime_type),
                extension = VALUES(extension),
                file_size = VALUES(file_size),
                width = VALUES(width),
                height = VALUES(height),
                created_at = CURRENT_TIMESTAMP");
        $stmt->execute([
            $original_name,
            $stored_name,
            $relative_path,
            $mime,
            $ext,
            $size,
            (int) $img_info[0],
            (int) $img_info[1],
            $hash,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
        ]);
        $new_media_id = (int) $pdo->lastInsertId();
        if ($new_media_id === 0 && $hash) {
            // Trùng sha256 (bản ghi cũ, file đã mất) -> lấy id bản ghi đã được cập nhật lại
            $qid = $pdo->prepare("SELECT id FROM media_library WHERE sha256_hash = ? LIMIT 1");
            $qid->execute([$hash]);
            $new_media_id = (int) $qid->fetchColumn();
        }
    } catch (Exception $e) {
        @unlink($target_path);
        error_log('media-upload DB insert failed: ' . $e->getMessage());
        $failed[] = ['name' => $original_name, 'message' => 'Không thể lưu metadata vào CSDL.'];
        continue;
    }

    $uploaded[] = [
        'id' => $new_media_id,
        'url' => BASE_URL . $relative_path,
        'file_path' => $relative_path,
        'original_name' => $original_name,
        'mime_type' => $mime,
        'file_size' => $size,
        'width' => (int) $img_info[0],
        'height' => (int) $img_info[1],
        'created_at' => date('Y-m-d H:i:s'),
        'is_duplicate' => false,
        'conversion' => [
            'attempted' => $conversion_attempted,
            'converted' => $conversion_converted,
            'reason' => $conversion_reason,
            'error' => $conversion_error,
            'message' => conversion_reason_message($conversion_reason, $conversion_error)
        ]
    ];
}

echo json_encode([
    'success' => true,
    'uploaded' => $uploaded,
    'failed' => $failed
], JSON_UNESCAPED_UNICODE);
exit;
