<?php
/**
 * admin/ajax/category-ai.php — AI cho danh mục (xử lý từng danh mục).
 * POST: action = rewrite | seo, id = category_id, save = 1|0, csrf_token
 *   - rewrite: viết nội dung SEO/GEO cho trang danh mục (lưu vào categories.content).
 *   - seo: sinh meta_title/description/keywords/focus_keyword.
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/blog.php';
require_once '../../includes/page-cache.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) { echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
require_valid_csrf_token(true);

if (trim((string) get_setting('llm_api_key', '')) === '') {
    echo json_encode(['success' => false, 'message' => 'Chưa cấu hình LLM. Vào Cấu hình → tab AI / LLM.']);
    exit;
}

$action = $_POST['action'] ?? '';
$id = (int) ($_POST['id'] ?? 0);
$save = ($_POST['save'] ?? '1') !== '0';

$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$cat = $stmt->fetch();
if (!$cat) { echo json_encode(['success' => false, 'message' => 'Không tìm thấy danh mục.', 'id' => $id]); exit; }

$hasContentCol = has_table_column($pdo, 'categories', 'content');

try {
    if ($action === 'rewrite') {
        $seed = trim(strip_tags((string) ($cat['content'] ?? ''))) ?: (string) ($cat['description'] ?? '');
        $r = ai_rewrite_blog_post('Chuyên mục: ' . $cat['name'], $seed);
        if (empty($r['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi: ' . ($r['error'] ?? '?'), 'id' => $id]); exit; }
        if ($save && $hasContentCol) {
            $pdo->prepare("UPDATE categories SET content = ? WHERE id = ?")->execute([$r['content'], $id]);
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_rewrite', 'category', $id, 'AI viết nội dung danh mục: ' . $cat['name']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã viết nội dung danh mục.', 'id' => $id, 'title' => $cat['name'],
            'content' => $r['content'], 'saved' => ($save && $hasContentCol)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'all') {
        $seed = trim(strip_tags((string) ($cat['content'] ?? ''))) ?: (string) ($cat['description'] ?? '');
        $r = ai_rewrite_blog_post('Chuyên mục: ' . $cat['name'], $seed);
        if (empty($r['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi (nội dung): ' . ($r['error'] ?? '?'), 'id' => $id]); exit; }
        $newContent = $r['content'];
        $seo = ai_generate_seo((string) $cat['name'], (string) ($cat['description'] ?? ''), strip_tags($newContent));
        $seoOk = !empty($seo['ok']);
        $kw = $seoOk ? (is_array($seo['meta_keywords']) ? implode(', ', $seo['meta_keywords']) : (string) $seo['meta_keywords']) : '';
        if ($save) {
            if ($hasContentCol) $pdo->prepare("UPDATE categories SET content = ? WHERE id = ?")->execute([$newContent, $id]);
            if ($seoOk) {
                $pdo->prepare("UPDATE categories SET meta_title=?, meta_description=?, meta_keywords=?, focus_keyword=? WHERE id=?")
                    ->execute([$seo['meta_title'], $seo['meta_description'], $kw, $seo['focus_keyword'], $id]);
            }
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_all', 'category', $id, 'AI viết nội dung + SEO danh mục: ' . $cat['name']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã viết nội dung + SEO' . ($seoOk ? '.' : ' (SEO lỗi).'),
            'id' => $id, 'title' => $cat['name'], 'content' => $newContent,
            'meta_title' => $seoOk ? $seo['meta_title'] : '', 'meta_description' => $seoOk ? $seo['meta_description'] : '',
            'meta_keywords' => $kw, 'focus_keyword' => $seoOk ? $seo['focus_keyword'] : '', 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'seo') {
        $seo = ai_generate_seo((string) $cat['name'], (string) ($cat['description'] ?? ''), strip_tags((string) ($cat['content'] ?? '')));
        if (empty($seo['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi: ' . ($seo['error'] ?? '?'), 'id' => $id]); exit; }
        $kw = is_array($seo['meta_keywords']) ? implode(', ', $seo['meta_keywords']) : (string) $seo['meta_keywords'];
        if ($save) {
            $pdo->prepare("UPDATE categories SET meta_title = ?, meta_description = ?, meta_keywords = ?, focus_keyword = ? WHERE id = ?")
                ->execute([$seo['meta_title'], $seo['meta_description'], $kw, $seo['focus_keyword'], $id]);
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_seo', 'category', $id, 'AI SEO danh mục: ' . $cat['name']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã tạo SEO.', 'id' => $id, 'title' => $cat['name'],
            'meta_title' => $seo['meta_title'], 'meta_description' => $seo['meta_description'],
            'meta_keywords' => $kw, 'focus_keyword' => $seo['focus_keyword'], 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.', 'id' => $id]);
} catch (Throwable $e) {
    error_log('category-ai error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống.', 'id' => $id]);
}
