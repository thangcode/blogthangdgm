<?php
// admin/posts/edit.php — Sửa bài viết (blog)
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
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Không tìm thấy bài viết.</div></div>';
    require_once '../includes/footer.php';
    exit;
}

try { $all_categories = $pdo->query("SELECT id, name, parent_id FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll(); }
catch (Throwable $e) { $all_categories = []; }
try { $all_users = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name ASC")->fetchAll(); }
catch (Throwable $e) { $all_users = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $old_slug = $post['slug'];
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

    $thumbnail = trim($_POST['thumbnail'] ?? '');
    if ($thumbnail === '' && isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == 0) {
        $uploaded = upload_file($_FILES['thumbnail'], ROOT_PATH . 'assets/uploads/');
        if ($uploaded) {
            $thumbnail = 'assets/uploads/' . $uploaded;
            if (function_exists('register_media_file')) register_media_file($pdo, ROOT_PATH . $thumbnail, $thumbnail, $_FILES['thumbnail']['name'] ?? '');
        }
    }
    $thumbnail_alt = trim($_POST['thumbnail_alt'] ?? '');

    // Tài liệu
    $document_path = $post['document_path'];
    $document_name = $post['document_name'];
    if (!empty($_POST['remove_document'])) {
        $document_path = null; $document_name = null;
    }
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        [$dp, $dn] = blog_upload_document($_FILES['document']);
        if ($dp === null) { $error = 'File tài liệu không hợp lệ.'; }
        else { $document_path = $dp; $document_name = $dn; }
    }

    if ($title === '' && $error === '') $error = 'Vui lòng nhập tiêu đề.';
    if ($slug !== '' && $slug !== $old_slug && $error === '') {
        $c = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE slug = ? AND id <> ?");
        $c->execute([$slug, $id]);
        if ((int) $c->fetchColumn() > 0) $slug .= '-' . substr(uniqid(), -4);
    }

    if ($error === '') {
        try {
            $pdo->prepare("UPDATE posts SET title=?, slug=?, summary=?, content=?, status=?, schema_type=?, thumbnail=?, thumbnail_alt=?, author_name=?, document_path=?, document_name=?, meta_title=?, meta_description=?, meta_keywords=?, focus_keyword=?, sidebar_mode=?, sidebar_position=?, updated_at=NOW() WHERE id=?")
                ->execute([$title, $slug, $summary, $content, $status, $schema_type, $thumbnail, $thumbnail_alt, $author_name, $document_path, $document_name, $meta_title, $meta_description, $meta_keywords, $focus_keyword, $sidebar_mode, $sidebar_position, $id]);

            $primary = blog_sync_post_categories($pdo, $id, $cat_ids);
            $pdo->prepare("UPDATE posts SET primary_category_id = ? WHERE id = ?")->execute([$primary, $id]);
            blog_sync_post_tags($pdo, $id, $tags_csv);

            if ($slug !== $old_slug) blog_record_slug_redirect($pdo, $old_slug, $slug, $id);

            if (function_exists('log_activity')) log_activity('update', 'post', $id, "Sửa bài viết: $title");
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }

            // reload
            $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $post = $stmt->fetch();
            $success = 'Cập nhật thành công! <a href="' . e(postUrl($slug, true)) . '" target="_blank" class="alert-link ms-2"><i class="bi bi-box-arrow-up-right me-1"></i>Xem bài viết</a>';
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
}

// Dữ liệu hiện tại cho form
$post_cat_ids = array_map('intval', array_column(blog_post_categories($pdo, $id), 'id'));
$post_tags_csv = implode(', ', array_column(blog_post_tags($pdo, $id), 'name'));

$seo_data = [
    'meta_title' => $post['meta_title'] ?? '',
    'meta_description' => $post['meta_description'] ?? '',
    'meta_keywords' => $post['meta_keywords'] ?? '',
    'focus_keyword' => $post['focus_keyword'] ?? '',
    'preview_title' => $post['title'] ?? '',
    'preview_url' => postUrl($post['slug'] ?? '', true),
];

include __DIR__ . '/_form.php';
require_once '../includes/footer.php';
