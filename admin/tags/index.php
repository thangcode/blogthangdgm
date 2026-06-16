<?php
// admin/tags/index.php — Quản lý Tag
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';

$current_page = 'tags';
require_once '../includes/header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $slug = !empty($_POST['slug']) ? create_slug($_POST['slug']) : create_slug($name);
            $desc = trim($_POST['description'] ?? '');
            $mt = trim($_POST['meta_title'] ?? '');
            $md = trim($_POST['meta_description'] ?? '');
            if ($name === '' || $slug === '') {
                $error = 'Vui lòng nhập tên tag.';
            } else {
                if ($id > 0) {
                    $pdo->prepare("UPDATE tags SET name=?, slug=?, description=?, meta_title=?, meta_description=? WHERE id=?")
                        ->execute([$name, $slug, $desc, $mt, $md, $id]);
                    $success = 'Đã cập nhật tag.';
                } else {
                    $pdo->prepare("INSERT INTO tags (name, slug, description, meta_title, meta_description, created_at) VALUES (?,?,?,?,?,NOW())")
                        ->execute([$name, $slug, $desc, $mt, $md]);
                    $success = 'Đã thêm tag.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM post_tags WHERE tag_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM tags WHERE id=?")->execute([$id]);
            $success = 'Đã xóa tag.';
        }
    } catch (PDOException $e) {
        $error = ($e->getCode() === '23000') ? 'Slug đã tồn tại.' : ('Lỗi: ' . $e->getMessage());
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM tags WHERE id=?");
    $s->execute([(int) $_GET['edit']]);
    $edit = $s->fetch();
}

$search = trim($_GET['q'] ?? '');
$where = $search !== '' ? "WHERE t.name LIKE :q" : '';
$sql = "SELECT t.*, (SELECT COUNT(*) FROM post_tags pt WHERE pt.tag_id=t.id) n FROM tags t $where ORDER BY t.name ASC LIMIT 500";
$st = $pdo->prepare($sql);
if ($search !== '') $st->bindValue(':q', '%' . $search . '%');
$st->execute();
$tags = $st->fetchAll();
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Quản lý Tags</h1>
    </div>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm"><div class="card-body">
                <h5 class="card-title"><?php echo $edit ? 'Sửa tag' : 'Thêm tag'; ?></h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
                    <div class="mb-2"><label class="form-label">Tên *</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo e($edit['name'] ?? ''); ?>"></div>
                    <div class="mb-2"><label class="form-label">Slug</label>
                        <input type="text" name="slug" class="form-control" value="<?php echo e($edit['slug'] ?? ''); ?>" placeholder="tự sinh nếu trống"></div>
                    <div class="mb-2"><label class="form-label">Mô tả</label>
                        <textarea name="description" class="form-control" rows="2"><?php echo e($edit['description'] ?? ''); ?></textarea></div>
                    <div class="mb-2"><label class="form-label">Meta title</label>
                        <input type="text" name="meta_title" class="form-control" value="<?php echo e($edit['meta_title'] ?? ''); ?>"></div>
                    <div class="mb-2"><label class="form-label">Meta description</label>
                        <textarea name="meta_description" class="form-control" rows="2"><?php echo e($edit['meta_description'] ?? ''); ?></textarea></div>
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Lưu</button>
                    <?php if ($edit): ?><a href="index.php" class="btn btn-outline-secondary">Hủy</a><?php endif; ?>
                </form>
            </div></div>
        </div>
        <div class="col-md-8">
            <form method="GET" class="mb-3">
                <div class="input-group" style="max-width:360px;">
                    <input type="search" name="q" class="form-control" placeholder="Tìm tag..." value="<?php echo e($search); ?>">
                    <button class="btn btn-outline-primary">Tìm</button>
                </div>
            </form>
            <div class="table-responsive bg-white rounded shadow-sm p-3">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Tên</th><th>Slug</th><th>Bài</th><th class="text-end">Hành động</th></tr></thead>
                    <tbody>
                    <?php foreach ($tags as $t): ?>
                        <tr>
                            <td><?php echo e($t['name']); ?></td>
                            <td><a href="<?php echo e(tagUrl($t['slug'])); ?>" target="_blank" class="small text-muted">/<?php echo e($t['slug']); ?></a></td>
                            <td><span class="badge bg-light text-dark border"><?php echo (int) $t['n']; ?></span></td>
                            <td class="text-end">
                                <a href="?edit=<?php echo (int) $t['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Xóa tag này?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tags)): ?><tr><td colspan="4" class="text-center text-muted">Chưa có tag.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
