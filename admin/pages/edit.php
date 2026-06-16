<?php
// admin/pages/edit.php — Thêm/sửa trang tĩnh
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';
require_once '../../includes/page-cache.php';

$current_page = 'pages';
require_once '../includes/header.php';

$error = ''; $success = '';
$id = (int) ($_GET['id'] ?? 0);
$page = ['id'=>0,'title'=>'','slug'=>'','summary'=>'','content'=>'','meta_title'=>'','meta_description'=>'','meta_keywords'=>'','status'=>1];
$is_edit = false;
if ($id > 0) {
    $s = $pdo->prepare("SELECT * FROM pages WHERE id = ?");
    $s->execute([$id]);
    $row = $s->fetch();
    if ($row) { $page = $row; $is_edit = true; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $title = trim($_POST['title'] ?? '');
    $slug = !empty($_POST['slug']) ? create_slug($_POST['slug']) : create_slug($title);
    $summary = trim($_POST['summary'] ?? '');
    $content = $_POST['content'] ?? '';
    $status = (int) ($_POST['status'] ?? 1) === 1 ? 1 : 0;
    $mt = trim($_POST['meta_title'] ?? '');
    $md = trim($_POST['meta_description'] ?? '');
    $mk = trim($_POST['meta_keywords'] ?? '');

    if ($title === '') $error = 'Vui lòng nhập tiêu đề.';
    elseif ($slug === '') $error = 'Slug không hợp lệ.';
    else {
        // unique slug
        $c = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE slug = ? AND id <> ?");
        $c->execute([$slug, $id]);
        if ((int)$c->fetchColumn() > 0) $slug .= '-' . substr(uniqid(), -4);
        try {
            if ($is_edit) {
                $pdo->prepare("UPDATE pages SET title=?, slug=?, summary=?, content=?, meta_title=?, meta_description=?, meta_keywords=?, status=?, updated_at=NOW() WHERE id=?")
                    ->execute([$title,$slug,$summary,$content,$mt,$md,$mk,$status,$id]);
            } else {
                $pdo->prepare("INSERT INTO pages (title,slug,summary,content,meta_title,meta_description,meta_keywords,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
                    ->execute([$title,$slug,$summary,$content,$mt,$md,$mk,$status]);
                $id = (int)$pdo->lastInsertId(); $is_edit = true;
            }
            if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
            $s = $pdo->prepare("SELECT * FROM pages WHERE id=?"); $s->execute([$id]); $page = $s->fetch();
            $success = 'Đã lưu! <a href="'.e(BASE_URL.ltrim($slug,'/')).'/" target="_blank" class="alert-link ms-2"><i class="bi bi-box-arrow-up-right me-1"></i>Xem trang</a>';
        } catch (PDOException $e) { $error = 'Lỗi: ' . $e->getMessage(); }
    }
}

$seo_data = ['meta_title'=>$page['meta_title'] ?? '','meta_description'=>$page['meta_description'] ?? '','meta_keywords'=>$page['meta_keywords'] ?? '','focus_keyword'=>'','preview_title'=>$page['title'] ?? '','preview_url'=>BASE_URL.ltrim($page['slug'] ?? '','/').'/'];
$v = fn($k) => e($_POST[$k] ?? ($page[$k] ?? ''));
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><?php echo $is_edit ? 'Sửa trang' : 'Thêm trang'; ?></h1>
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Quay lại</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
        <div class="row">
            <div class="col-md-9">
                <div class="mb-3"><label class="form-label">Tiêu đề <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" id="title" required value="<?php echo $v('title'); ?>"
                        onkeyup="if(document.getElementById('slug').dataset.t!=='1'){document.getElementById('slug').value=createSlug(this.value)}"></div>
                <div class="mb-3"><label class="form-label">Slug</label>
                    <input type="text" class="form-control" name="slug" id="slug" value="<?php echo $v('slug'); ?>" oninput="this.dataset.t='1'"></div>
                <div class="mb-3"><label class="form-label">Nội dung</label>
                    <textarea class="form-control" name="content" id="content" rows="14"><?php echo e($_POST['content'] ?? ($page['content'] ?? '')); ?></textarea></div>
                <div class="mb-3"><label class="form-label">Tóm tắt</label>
                    <textarea class="form-control" name="summary" rows="2"><?php echo e($_POST['summary'] ?? ($page['summary'] ?? '')); ?></textarea></div>
                <?php include '../includes/seo-fields.php'; ?>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-lg w-100 mb-3"><i class="bi bi-check-lg"></i> Lưu trang</button>
                <div class="mb-3"><label class="form-label fw-bold">Trạng thái</label>
                    <select class="form-select" name="status">
                        <option value="1" <?php echo (int)($page['status']??1)===1?'selected':''; ?>>Hiện</option>
                        <option value="0" <?php echo (int)($page['status']??1)===0?'selected':''; ?>>Ẩn</option>
                    </select></div>
            </div>
        </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
window.addEventListener('load', function(){
    tinymce.init({ selector:'#content', height:480, menubar:'edit view insert format table',
        plugins:'advlist autolink lists link image media table code fullscreen preview searchreplace visualblocks charmap help wordcount',
        toolbar:'undo redo | blocks | bold italic underline forecolor | alignleft aligncenter alignright | bullist numlist | blockquote link image media table | removeformat code fullscreen preview',
        branding:false, promotion:false, license_key:'gpl', convert_urls:false, toolbar_mode:'wrap',
        images_upload_handler:(b,p)=>new Promise((res,rej)=>{const x=new XMLHttpRequest();x.open('POST','../ajax/summernote-upload.php');x.setRequestHeader('X-CSRF-Token',document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'');x.onload=()=>{if(x.status<200||x.status>=300){rej('HTTP '+x.status);return;}let j=null;try{j=JSON.parse(x.responseText);}catch(e){rej('parse');return;}if(!j||!j.success||!j.url){rej((j&&j.message)||'fail');return;}res(j.url);};x.onerror=()=>rej('upload failed');const fd=new FormData();fd.append('file',b.blob(),b.filename());x.send(fd);})
    });
});
</script>
<?php require_once '../includes/footer.php'; ?>
