<?php
/**
 * admin/ai/test.php — Test tính năng: chạy thử CẢ model chính VÀ dự phòng (2 lần gọi riêng).
 * POST: feature = write|seo|vision|image, csrf_token
 * Trả JSON: { success, feature, results: [ {role, model_id, model_name, provider, ok, latency_ms, snippet|error, image_url?} ] }
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

$feature = (string) ($_POST['feature'] ?? '');
if (!in_array($feature, ['write', 'seo', 'vision', 'image'], true)) {
    echo json_encode(['success' => false, 'message' => 'Tính năng không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ảnh mẫu 64x64 PNG (đỏ) dạng data URI cho test vision.
// Dùng ảnh đủ lớn vì một số model vision từ chối ảnh 1x1 (invalid image).
$sampleImage = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAYklEQVRoge3PMQ0AIADAMEAN/vUgBhEcDcmqYJtn7/GzpQNeNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaBdzZgBrEjENv4AAAAASUVORK5CYII=';

// Chuẩn bị messages + opts theo từng tính năng.
function build_test_call(string $feature, string $sampleImage): array {
    switch ($feature) {
        case 'image':
            return [[['role' => 'user', 'content' => 'A simple red circle on white background, minimalist']], ['max_tokens' => 16]];
        case 'vision':
            return [[[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Ảnh này màu gì? Trả lời 1 từ.'],
                    ['type' => 'image_url', 'image_url' => ['url' => $sampleImage]],
                ],
            ]], ['max_tokens' => 20, 'temperature' => 0]];
        case 'seo':
            return [[['role' => 'user', 'content' => 'Trả lời đúng 1 từ: OK']], ['max_tokens' => 16, 'temperature' => 0]];
        default: // write
            return [[['role' => 'user', 'content' => 'Trả lời đúng 1 từ: OK']], ['max_tokens' => 16, 'temperature' => 0]];
    }
}

[$messages, $opts] = build_test_call($feature, $sampleImage);
if ($feature === 'image') { $opts['size'] = (string) get_setting('ai_image_size', '1024x1024'); }

$resolved = llm_resolve_feature($pdo, $feature);
$results = [];

foreach (['primary' => 'Model chính', 'fallback' => 'Model dự phòng'] as $slot => $roleLabel) {
    $model = $resolved[$slot] ?? null;
    if (!$model) {
        $results[] = ['role' => $roleLabel, 'slot' => $slot, 'assigned' => false,
            'ok' => false, 'error' => 'Chưa gán model.'];
        continue;
    }
    $res = llm_call_model($model, $messages, $opts);
    $entry = [
        'role'       => $roleLabel,
        'slot'       => $slot,
        'assigned'   => true,
        'model_id'   => (int) $model['id'],
        'model_name' => (string) $model['model_name'],
        'provider'   => (string) ($model['provider']['name'] ?? ''),
        'ok'         => !empty($res['ok']),
        'latency_ms' => (int) ($res['latency_ms'] ?? 0),
    ];
    if (!empty($res['ok'])) {
        if ($feature === 'image') {
            $entry['image_url'] = (string) ($res['image_url'] ?? '');
            $entry['snippet'] = 'Đã tạo ảnh.';
        } else {
            $entry['snippet'] = mb_substr((string) ($res['text'] ?? ''), 0, 120);
        }
    } else {
        $entry['error'] = (string) ($res['error'] ?? 'Lỗi không rõ.');
    }
    $results[] = $entry;
}

echo json_encode(['success' => true, 'feature' => $feature, 'results' => $results], JSON_UNESCAPED_UNICODE);
