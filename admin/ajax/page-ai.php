<?php
/**
 * admin/ajax/page-ai.php — AI cho trang (xử lý TỪNG trang).
 * POST: action = rewrite | seo | all, id = page_id, save = 1|0, csrf_token
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

$stmt = $pdo->prepare("SELECT * FROM pages WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$page = $stmt->fetch();
if (!$page) { echo json_encode(['success' => false, 'message' => 'Không tìm thấy trang.', 'id' => $id]); exit; }

try {
    if ($action === 'rewrite') {
        $yt = blog_extract_youtube_id((string) ($page['content'] ?? '')) ?? '';
        $seed = trim(strip_tags((string) ($page['content'] ?? '')));
        if ($seed === '') $seed = (string) ($page['summary'] ?? '');
        $r = ai_rewrite_blog_post((string) $page['title'], $seed, $yt);
        if (empty($r['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi: ' . ($r['error'] ?? '?'), 'id' => $id]); exit; }

        if ($save) {
            $summary = trim((string) ($page['summary'] ?? ''));
            if ($summary === '' && !empty($r['description'])) $summary = $r['description'];
            $pdo->prepare("UPDATE pages SET content = ?, summary = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$r['content'], $summary, $id]);
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_rewrite', 'page', $id, 'AI viết lại trang: ' . $page['title']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã viết lại nội dung.', 'id' => $id, 'title' => $page['title'],
            'content' => $r['content'], 'description' => $r['description'], 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'all') {
        // 1) Viết lại nội dung
        $yt = blog_extract_youtube_id((string) ($page['content'] ?? '')) ?? '';
        $seed = trim(strip_tags((string) ($page['content'] ?? '')));
        if ($seed === '') $seed = (string) ($page['summary'] ?? '');
        $r = ai_rewrite_blog_post((string) $page['title'], $seed, $yt);
        if (empty($r['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi (nội dung): ' . ($r['error'] ?? '?'), 'id' => $id]); exit; }
        $newContent = $r['content'];
        $summary = trim((string) ($page['summary'] ?? ''));
        if ($summary === '' && !empty($r['description'])) $summary = $r['description'];

        // 2) Sinh SEO từ nội dung MỚI
        $seo = ai_generate_seo((string) $page['title'], $summary, strip_tags($newContent));
        $seoOk = !empty($seo['ok']);
        $kw = $seoOk ? (is_array($seo['meta_keywords']) ? implode(', ', $seo['meta_keywords']) : (string) $seo['meta_keywords']) : '';

        if ($save) {
            if ($seoOk) {
                $pdo->prepare("UPDATE pages SET content=?, summary=?, meta_title=?, meta_description=?, meta_keywords=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newContent, $summary, $seo['meta_title'], $seo['meta_description'], $kw, $id]);
            } else {
                $pdo->prepare("UPDATE pages SET content=?, summary=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newContent, $summary, $id]);
            }
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_all', 'page', $id, 'AI viết trang + SEO: ' . $page['title']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã viết trang + SEO' . ($seoOk ? '.' : ' (SEO lỗi, đã lưu nội dung).'),
            'id' => $id, 'title' => $page['title'], 'content' => $newContent, 'description' => $r['description'],
            'meta_title' => $seoOk ? $seo['meta_title'] : '', 'meta_description' => $seoOk ? $seo['meta_description'] : '',
            'meta_keywords' => $kw, 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'seo') {
        $seo = ai_generate_seo((string) $page['title'], (string) ($page['summary'] ?? ''), strip_tags((string) ($page['content'] ?? '')));
        if (empty($seo['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi: ' . ($seo['error'] ?? '?'), 'id' => $id]); exit; }
        $kw = is_array($seo['meta_keywords']) ? implode(', ', $seo['meta_keywords']) : (string) $seo['meta_keywords'];

        if ($save) {
            $pdo->prepare("UPDATE pages SET meta_title = ?, meta_description = ?, meta_keywords = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$seo['meta_title'], $seo['meta_description'], $kw, $id]);
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_seo', 'page', $id, 'AI SEO trang: ' . $page['title']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã tạo SEO.', 'id' => $id, 'title' => $page['title'],
            'meta_title' => $seo['meta_title'], 'meta_description' => $seo['meta_description'],
            'meta_keywords' => $kw, 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.', 'id' => $id]);
} catch (Throwable $e) {
    error_log('page-ai error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống.', 'id' => $id]);
}
