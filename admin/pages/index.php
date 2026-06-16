<?php
// admin/pages/index.php — Quản lý trang tĩnh
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';

$current_page = 'pages';
require_once '../includes/header.php';

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_valid_csrf_token();
    try {
        $pdo->prepare("DELETE FROM pages WHERE id = ?")->execute([(int) $_POST['delete_id']]);
        if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
        $success = 'Đã xóa trang.';
    } catch (PDOException $e) { $success = 'Lỗi: ' . $e->getMessage(); }
}

$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$where = '1=1'; $params = [];
if ($q !== '') { $where .= ' AND (title LIKE ? OR slug LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
try {
    $cs = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE $where"); $cs->execute($params);
    $total = (int) $cs->fetchColumn();
} catch (Throwable $e) { $total = 0; }
$total_pages = max(1, (int) ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
try {
    $st = $pdo->prepare("SELECT * FROM pages WHERE $where ORDER BY title ASC LIMIT $per_page OFFSET $offset");
    $st->execute($params);
    $pages = $st->fetchAll();
} catch (Throwable $e) { $pages = []; }
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Trang tĩnh (Pages) <span class="badge bg-secondary"><?php echo number_format($total); ?></span></h1>
        <a href="edit.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Thêm trang</a>
    </div>
    <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-5"><div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Tìm theo tiêu đề / slug..." value="<?php echo e($q); ?>">
        </div></div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-outline-primary">Tìm</button>
            <?php if ($q!==''): ?><a href="index.php" class="btn btn-outline-secondary">Xóa</a><?php endif; ?>
        </div>
    </form>
    <div class="table-responsive bg-white rounded shadow-sm p-3">
        <table class="table table-hover align-middle">
            <thead><tr><th>Tiêu đề</th><th>Đường dẫn</th><th>Trạng thái</th><th class="text-end">Hành động</th></tr></thead>
            <tbody>
            <?php foreach ($pages as $p): ?>
                <tr>
                    <td><strong><?php echo e($p['title']); ?></strong></td>
                    <td><a href="<?php echo BASE_URL . ltrim($p['slug'],'/'); ?>/" target="_blank" class="small text-muted">/<?php echo e($p['slug']); ?>/</a></td>
                    <td><?php echo ((int)$p['status']===1) ? '<span class="badge bg-success">Hiện</span>' : '<span class="badge bg-secondary">Ẩn</span>'; ?></td>
                    <td class="text-end">
                        <a href="edit.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Xóa trang này?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                            <input type="hidden" name="delete_id" value="<?php echo (int)$p['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($pages)): ?><tr><td colspan="4" class="text-center text-muted">Chưa có trang nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav class="mt-3"><ul class="pagination justify-content-center flex-wrap gap-1">
        <?php $lk = fn($p) => 'index.php?' . http_build_query(array_filter(['q'=>$q ?: null,'page'=>$p>1?$p:null])); ?>
        <li class="page-item <?php echo $page<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo e($lk($page-1)); ?>">&laquo;</a></li>
        <?php for($p=1;$p<=$total_pages;$p++): ?>
            <li class="page-item <?php echo $p===$page?'active':''; ?>"><a class="page-link" href="<?php echo e($lk($p)); ?>"><?php echo $p; ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page>=$total_pages?'disabled':''; ?>"><a class="page-link" href="<?php echo e($lk($page+1)); ?>">&raquo;</a></li>
    </ul></nav>
    <?php endif; ?>
</div>
<?php require_once '../includes/footer.php'; ?>
