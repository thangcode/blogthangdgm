<?php
/**
 * admin/ajax/post-ai.php — AI cho bài viết (xử lý TỪNG bài; gọi lặp để chạy hàng loạt + tiến trình).
 * POST: action = rewrite | seo, id = post_id, save = 1|0 (mặc định 1), csrf_token
 *   - rewrite: viết lại nội dung chuẩn SEO+GEO (bắt đầu H2, giữ video YouTube).
 *   - seo: sinh meta_title/description/keywords/focus_keyword.
 * Trả JSON: { success, message, id, title, ...data }
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

$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) { echo json_encode(['success' => false, 'message' => 'Không tìm thấy bài viết.', 'id' => $id]); exit; }

try {
    if ($action === 'rewrite') {
        $yt = blog_extract_youtube_id((string) ($post['content'] ?? '')) ?? '';
        $seed = trim(strip_tags((string) ($post['content'] ?? '')));
        if ($seed === '') $seed = (string) ($post['summary'] ?? '');
        $r = ai_rewrite_blog_post((string) $post['title'], $seed, $yt);
        if (empty($r['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi: ' . ($r['error'] ?? '?'), 'id' => $id]); exit; }

        // Ảnh đại diện từ thumbnail video nếu bài chưa có ảnh (giống plugin WordPress)
        $thumbRel = '';
        if ($yt !== '' && trim((string) ($post['thumbnail'] ?? '')) === '') {
            $thumbRel = blog_import_youtube_thumb($pdo, $yt, (string) $post['title']) ?: '';
        }

        if ($save) {
            $summary = trim((string) ($post['summary'] ?? ''));
            if ($summary === '' && !empty($r['description'])) $summary = $r['description'];
            $pdo->prepare("UPDATE posts SET content = ?, summary = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$r['content'], $summary, $id]);
            if ($thumbRel !== '') $pdo->prepare("UPDATE posts SET thumbnail = ? WHERE id = ?")->execute([$thumbRel, $id]);
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_rewrite', 'post', $id, 'AI viết lại bài: ' . $post['title']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã viết lại nội dung.', 'id' => $id, 'title' => $post['title'],
            'content' => $r['content'], 'description' => $r['description'], 'thumbnail' => $thumbRel, 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'all') {
        // 1) Viết lại nội dung
        $yt = blog_extract_youtube_id((string) ($post['content'] ?? '')) ?? '';
        $seed = trim(strip_tags((string) ($post['content'] ?? '')));
        if ($seed === '') $seed = (string) ($post['summary'] ?? '');
        $r = ai_rewrite_blog_post((string) $post['title'], $seed, $yt);
        if (empty($r['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi (nội dung): ' . ($r['error'] ?? '?'), 'id' => $id]); exit; }
        $newContent = $r['content'];
        $summary = trim((string) ($post['summary'] ?? ''));
        if ($summary === '' && !empty($r['description'])) $summary = $r['description'];

        // Ảnh đại diện từ thumbnail video nếu bài chưa có ảnh
        $thumbRel = '';
        if ($yt !== '' && trim((string) ($post['thumbnail'] ?? '')) === '') {
            $thumbRel = blog_import_youtube_thumb($pdo, $yt, (string) $post['title']) ?: '';
        }

        // 2) Sinh SEO từ nội dung MỚI
        $seo = ai_generate_seo((string) $post['title'], $summary, strip_tags($newContent));
        $seoOk = !empty($seo['ok']);
        $kw = $seoOk ? (is_array($seo['meta_keywords']) ? implode(', ', $seo['meta_keywords']) : (string) $seo['meta_keywords']) : '';

        if ($save) {
            if ($seoOk) {
                $pdo->prepare("UPDATE posts SET content=?, summary=?, meta_title=?, meta_description=?, meta_keywords=?, focus_keyword=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newContent, $summary, $seo['meta_title'], $seo['meta_description'], $kw, $seo['focus_keyword'], $id]);
                if ($kw !== '' && function_exists('blog_sync_post_tags')) blog_sync_post_tags($pdo, $id, $kw);
            } else {
                $pdo->prepare("UPDATE posts SET content=?, summary=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newContent, $summary, $id]);
            }
            if ($thumbRel !== '') $pdo->prepare("UPDATE posts SET thumbnail = ? WHERE id = ?")->execute([$thumbRel, $id]);
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_all', 'post', $id, 'AI viết bài + SEO: ' . $post['title']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã viết bài + SEO' . ($seoOk ? ' + tags.' : ' (SEO lỗi, đã lưu nội dung).'),
            'id' => $id, 'title' => $post['title'], 'content' => $newContent, 'description' => $r['description'],
            'meta_title' => $seoOk ? $seo['meta_title'] : '', 'meta_description' => $seoOk ? $seo['meta_description'] : '',
            'meta_keywords' => $kw, 'focus_keyword' => $seoOk ? $seo['focus_keyword'] : '', 'tags' => ($seoOk ? $kw : ''), 'thumbnail' => $thumbRel, 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'seo') {
        $seo = ai_generate_seo((string) $post['title'], (string) ($post['summary'] ?? ''), strip_tags((string) ($post['content'] ?? '')));
        if (empty($seo['ok'])) { echo json_encode(['success' => false, 'message' => 'AI lỗi: ' . ($seo['error'] ?? '?'), 'id' => $id]); exit; }
        $kw = is_array($seo['meta_keywords']) ? implode(', ', $seo['meta_keywords']) : (string) $seo['meta_keywords'];

        $tagsCsv = $kw; // dùng từ khóa AI làm tag
        if ($save) {
            $pdo->prepare("UPDATE posts SET meta_title = ?, meta_description = ?, meta_keywords = ?, focus_keyword = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$seo['meta_title'], $seo['meta_description'], $kw, $seo['focus_keyword'], $id]);
            if ($tagsCsv !== '' && function_exists('blog_sync_post_tags')) blog_sync_post_tags($pdo, $id, $tagsCsv);
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            if (function_exists('log_activity')) log_activity('ai_seo', 'post', $id, 'AI SEO bài: ' . $post['title']);
        }
        echo json_encode(['success' => true, 'message' => 'Đã tạo SEO + tags.', 'id' => $id, 'title' => $post['title'],
            'meta_title' => $seo['meta_title'], 'meta_description' => $seo['meta_description'],
            'meta_keywords' => $kw, 'focus_keyword' => $seo['focus_keyword'], 'tags' => $tagsCsv, 'saved' => $save], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.', 'id' => $id]);
} catch (Throwable $e) {
    error_log('post-ai error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống.', 'id' => $id]);
}
