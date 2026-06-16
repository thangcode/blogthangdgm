<?php
// admin/posts/add.php — Thêm bài viết (blog)
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';
require_once '../../includes/page-cache.php';
require_once '../../includes/blog.php';

$current_page = 'posts';
require_once '../includes/header.php';

$error = '';
$success = '';

// Danh sách category để chọn (kèm parent_id để hiển thị phân cấp)
try { $all_categories = $pdo->query("SELECT id, name, parent_id FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll(); }
catch (Throwable $e) { $all_categories = []; }
try { $all_users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name ASC")->fetchAll(); }
catch (Throwable $e) { $all_users = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $content = $_POST['content'] ?? '';
    $status = (int) ($_POST['status'] ?? 1);
    if (!in_array($status, [0, 1, 2])) $status = 0;
    $schema_type = in_array($_POST['schema_type'] ?? '', ['Article','BlogPosting','NewsArticle'], true) ? $_POST['schema_type'] : 'BlogPosting';
    $cat_ids = array_map('intval', (array) ($_POST['categories'] ?? []));
    $tags_csv = trim($_POST['tags'] ?? '');
    $author_name = trim($_POST['author_name'] ?? 'Admin');
    $sidebar_mode = in_array($_POST['sidebar_mode'] ?? '', ['default','show','hide'], true) ? $_POST['sidebar_mode'] : 'default';
    $sidebar_position = in_array($_POST['sidebar_position'] ?? '', ['default','left','right'], true) ? $_POST['sidebar_position'] : 'default';

    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $focus_keyword = trim($_POST['focus_keyword'] ?? '');

    $slug = !empty($_POST['slug']) ? create_slug($_POST['slug']) : create_slug($title);

    // Ảnh đại diện
    $thumbnail = trim($_POST['thumbnail'] ?? '');
    if ($thumbnail === '' && isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == 0) {
        $uploaded = upload_file($_FILES['thumbnail'], ROOT_PATH . 'assets/uploads/');
        if ($uploaded) {
            $thumbnail = 'assets/uploads/' . $uploaded;
            if (function_exists('register_media_file')) register_media_file($pdo, ROOT_PATH . $thumbnail, $thumbnail, $_FILES['thumbnail']['name'] ?? '');
        }
    }
    $thumbnail_alt = trim($_POST['thumbnail_alt'] ?? '');

    // Tài liệu đính kèm
    $document_path = null; $document_name = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        [$document_path, $document_name] = blog_upload_document($_FILES['document']);
        if ($document_path === null) $error = 'File tài liệu không hợp lệ (chỉ pdf, doc, docx, xls, xlsx, ppt, pptx, zip).';
    }

    if ($title === '' && $error === '') $error = 'Vui lòng nhập tiêu đề.';
    if ($slug !== '' && $error === '') {
        // đảm bảo slug unique
        $c = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE slug = ?");
        $c->execute([$slug]);
        if ((int) $c->fetchColumn() > 0) $slug .= '-' . substr(uniqid(), -4);
    }

    if ($error === '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO posts (title, slug, summary, content, status, schema_type, thumbnail, thumbnail_alt, author_name, document_path, document_name, meta_title, meta_description, meta_keywords, focus_keyword, sidebar_mode, sidebar_position, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $stmt->execute([$title, $slug, $summary, $content, $status, $schema_type, $thumbnail, $thumbnail_alt, $author_name, $document_path, $document_name, $meta_title, $meta_description, $meta_keywords, $focus_keyword, $sidebar_mode, $sidebar_position]);
            $new_id = (int) $pdo->lastInsertId();

            $primary = blog_sync_post_categories($pdo, $new_id, $cat_ids);
            if ($primary) $pdo->prepare("UPDATE posts SET primary_category_id = ? WHERE id = ?")->execute([$primary, $new_id]);
            blog_sync_post_tags($pdo, $new_id, $tags_csv);

            if (function_exists('log_activity')) log_activity('create', 'post', $new_id, "Thêm bài viết: $title");
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }

            $post_url = postUrl($slug, true);
            $success = 'Thêm bài viết thành công! <a href="' . e($post_url) . '" target="_blank" class="alert-link ms-2"><i class="bi bi-box-arrow-up-right me-1"></i>Xem bài viết</a>';
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
}

$seo_data = [
    'meta_title' => $_POST['meta_title'] ?? '',
    'meta_description' => $_POST['meta_description'] ?? '',
    'meta_keywords' => $_POST['meta_keywords'] ?? '',
    'focus_keyword' => $_POST['focus_keyword'] ?? '',
    'preview_title' => 'Tiêu đề bài viết',
    'preview_url' => BASE_URL . '...'
];
$post = []; // để header admin-bar không lỗi
include __DIR__ . '/_form.php';
require_once '../includes/footer.php';
