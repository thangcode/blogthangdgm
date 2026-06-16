<?php
/**
 * admin/ajax/ai-rewrite.php
 * Viết lại tiêu đề / mô tả / nội dung sản phẩm bằng LLM dùng chung.
 * POST: text, mode = title|description|content
 * Trả: { success, text }
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
require_valid_csrf_token(true);

$text = trim((string) ($_POST['text'] ?? ''));
$mode = trim((string) ($_POST['mode'] ?? 'description'));
$name = trim((string) ($_POST['name'] ?? ''));
$images_in = (isset($_POST['images']) && is_array($_POST['images'])) ? $_POST['images'] : [];

if ($text === '' && $name === '') {
    echo json_encode(['success' => false, 'message' => 'Chưa có nội dung để viết lại.']);
    exit;
}

// Quy tắc chung: tránh copy nguyên Shopee, không hứa hẹn quá đà, không dùng "chính hãng/chính thức"
$rules = "Quy tắc bắt buộc:\n"
    . "- Viết tiếng Việt tự nhiên, không sao chép nguyên văn nguồn.\n"
    . "- Giữ đúng thông số kỹ thuật, không bịa thông tin.\n"
    . "- Không dùng từ 'chính hãng', 'chính thức', 'ủy quyền' trừ khi nguồn nêu rõ.\n"
    . "- Không hứa hẹn quá đà, không cam kết giá vì giá có thể đổi.\n"
    . "- Không nhắc tên sàn TMĐT cụ thể.";

if ($mode === 'all') {
    $res = ai_generate_article($name, $text, $images_in);
    if (empty($res['ok'])) {
        echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Lỗi gọi AI.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success'     => true,
        'title'       => $res['title'],
        'description' => $res['description'],
        'content'     => $res['content'],
        'model'       => $res['model'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === 'title') {
    $system = "Bạn là biên tập viên SEO. Viết lại TÊN sản phẩm ngắn gọn, tự nhiên, dễ đọc, chuẩn SEO (~50-65 ký tự). Giữ thương hiệu và thông tin nhận diện chính; bỏ từ thừa, lặp và từ ngoại ngữ vô nghĩa. Chỉ trả về 1 dòng tiêu đề, không giải thích.\n$rules";
    $user = "Tên gốc: " . ($text !== '' ? $text : $name);
    $opts = ['max_tokens' => 80, 'temperature' => 0.7];
} elseif ($mode === 'content') {
    $system = "Bạn là copywriter + SEO. Viết lại NỘI DUNG chi tiết bằng HTML thuần (KHÔNG markdown). PHẢI bắt đầu bằng thẻ <h2>, TUYỆT ĐỐI KHÔNG dùng <h1> (H1 tiêu đề đã render tự động). Cấu trúc: <h2> mục chính, <h3> mục con nếu cần, <p>, <ul><li>, <strong>. Chuẩn SEO, nội dung hữu ích: giới thiệu, đặc điểm nổi bật, lợi ích. Chỉ trả về HTML, không giải thích.\n$rules";
    $user = "Tên sản phẩm: $name\nNội dung gốc:\n$text";
    $opts = ['max_tokens' => 2000, 'temperature' => 0.7];
} else { // description
    $system = "Bạn là copywriter SEO. Viết lại MÔ TẢ NGẮN sản phẩm 2-3 câu súc tích, chuẩn SEO, có chứa tên/từ khóa sản phẩm. Chỉ trả về đoạn văn, không giải thích.\n$rules";
    $user = "Tên sản phẩm: $name\nMô tả gốc: $text";
    $opts = ['max_tokens' => 300, 'temperature' => 0.7];
}

$res = llm_chat([
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $user],
], $opts);

if (!empty($res['ok'])) {
    $out = trim($res['text']);
    // Bỏ rào markdown code fence nếu model lỡ trả
    $out = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $out);
    if ($mode === 'content') {
        $out = preg_replace('#<(/?)h1(\s[^>]*)?>#i', '<$1h2$2>', $out);
    }
    echo json_encode(['success' => true, 'text' => $out, 'model' => $res['model_used'] ?? ''], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => $res['error'] ?? 'Lỗi gọi AI.'], JSON_UNESCAPED_UNICODE);
}
