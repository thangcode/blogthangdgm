<?php
// admin/posts/index.php — Quản lý bài viết (tìm kiếm + lọc chuyên mục/trạng thái + phân trang)
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';

$current_page = 'posts';
require_once '../includes/header.php';

$success = '';
if (isset($_POST['delete_id'])) {
    require_valid_csrf_token();
    $id = (int) $_POST['delete_id'];
    try {
        $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM post_categories WHERE post_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM post_tags WHERE post_id = ?")->execute([$id]);
        if (function_exists('log_activity')) log_activity('delete', 'post', $id, "Xóa bài viết ID: $id");
        if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
        $success = "Đã xóa bài viết.";
    } catch (PDOException $e) { $error = "Lỗi khi xóa: " . $e->getMessage(); }
}

// Bộ lọc
$q = trim($_GET['q'] ?? '');
$cat = (int) ($_GET['cat'] ?? 0);
$status = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;

$where = ['1=1'];
$params = [];
$join = '';
if ($q !== '') { $where[] = '(p.title LIKE ? OR p.slug LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($status !== '' && in_array($status, ['0','1','2'], true)) { $where[] = 'p.status = ?'; $params[] = (int)$status; }
if ($cat > 0) { $join = 'JOIN post_categories pc ON pc.post_id = p.id AND pc.category_id = ' . $cat; }
$whereSql = implode(' AND ', $where);

try {
    $cs = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM posts p $join WHERE $whereSql");
    $cs->execute($params);
    $total = (int) $cs->fetchColumn();
} catch (Throwable $e) { $total = 0; }
$total_pages = max(1, (int) ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

try {
    $st = $pdo->prepare("SELECT DISTINCT p.* FROM posts p $join WHERE $whereSql ORDER BY p.created_at DESC LIMIT $per_page OFFSET $offset");
    $st->execute($params);
    $posts = $st->fetchAll();
} catch (Throwable $e) { $posts = []; }

try { $all_cats = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name")->fetchAll(); }
catch (Throwable $e) { $all_cats = []; }

$qs = function($overrides = []) use ($q, $cat, $status) {
    $p = array_merge(['q'=>$q ?: null, 'cat'=>$cat ?: null, 'status'=>$status !== '' ? $status : null], $overrides);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return $p ? '?' . http_build_query($p) : '';
};
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Quản lý Bài viết <span class="badge bg-secondary"><?php echo number_format($total); ?></span></h1>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-youtube"></i> Nhập ý tưởng / YouTube</button>
            <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Thêm mới</a>
        </div>
    </div>

    <script>const POST_CSRF = <?php echo json_encode(generate_csrf_token()); ?>;</script>

    <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Tìm theo tiêu đề / slug..." value="<?php echo e($q); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="cat" class="form-select">
                <option value="">— Tất cả chuyên mục —</option>
                <?php foreach ($all_cats as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $cat===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">— Trạng thái —</option>
                <option value="1" <?php echo $status==='1'?'selected':''; ?>>Công khai</option>
                <option value="0" <?php echo $status==='0'?'selected':''; ?>>Nháp</option>
                <option value="2" <?php echo $status==='2'?'selected':''; ?>>Ẩn</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-outline-primary flex-fill">Lọc</button>
            <?php if ($q!=='' || $cat || $status!==''): ?><a href="index.php" class="btn btn-outline-secondary">Xóa</a><?php endif; ?>
        </div>
    </form>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="badge bg-light text-dark border"><i class="bi bi-check2-square me-1"></i><span id="selCount">0</span> bài đã chọn</span>
        <button type="button" class="btn btn-sm btn-primary" id="btnBulkRewrite"><i class="bi bi-pencil-square me-1"></i>Viết bài chuẩn SEO+GEO</button>
        <button type="button" class="btn btn-sm btn-success" id="btnBulkAll"><i class="bi bi-stars me-1"></i>Viết bài + Tự động SEO</button>
        <span class="text-muted small">Nút 1: chỉ viết nội dung. Nút 2: viết nội dung + điền SEO bên dưới. Chọn nhiều bài để chạy hàng loạt.</span>
    </div>

    <div class="table-responsive bg-white rounded shadow-sm p-3">
        <table class="table table-hover align-middle">
            <thead><tr><th style="width:34px"><input type="checkbox" id="checkAll" class="form-check-input" title="Chọn tất cả"></th><th>ID</th><th>Tiêu đề</th><th>Chuyên mục</th><th>Lượt xem</th><th>Ngày</th><th>Trạng thái</th><th class="text-end">Hành động</th></tr></thead>
            <tbody>
            <?php foreach ($posts as $row):
                $s = (int) $row['status'];
                $sb = $s===1 ? '<span class="badge bg-success">Công khai</span>' : ($s===0 ? '<span class="badge bg-warning text-dark">Nháp</span>' : '<span class="badge bg-secondary">Ẩn</span>');
                $cats = '';
                try { $cq=$pdo->prepare("SELECT c.name FROM post_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.post_id=? LIMIT 3"); $cq->execute([$row['id']]); foreach($cq->fetchAll(PDO::FETCH_COLUMN) as $cn) $cats.='<span class="badge bg-light text-dark border me-1">'.e($cn).'</span>'; } catch(Throwable $e){}
                if($cats==='') $cats='<span class="text-muted small">—</span>';
            ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input row-check" value="<?php echo (int)$row['id']; ?>"></td>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><strong><?php echo e(mb_substr($row['title'],0,60,'UTF-8')); ?></strong><br>
                        <a href="<?php echo e(postUrl($row['slug'])); ?>" target="_blank" class="text-muted small"><i class="bi bi-box-arrow-up-right me-1"></i>/<?php echo e($row['slug']); ?>/</a></td>
                    <td><?php echo $cats; ?></td>
                    <td><small class="text-muted"><?php echo number_format((int)($row['views']??0)); ?></small></td>
                    <td><small><?php echo date('d/m/Y', strtotime($row['created_at'])); ?></small></td>
                    <td><?php echo $sb; ?></td>
                    <td class="text-end">
                        <a href="edit.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Xóa bài viết này?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                            <input type="hidden" name="delete_id" value="<?php echo (int)$row['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($posts)): ?><tr><td colspan="8" class="text-center text-muted py-4">Không có bài viết nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="mt-3"><ul class="pagination justify-content-center flex-wrap gap-1">
        <li class="page-item <?php echo $page<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo e($qs(['page'=>$page-1>1?$page-1:null])); ?>">&laquo;</a></li>
        <?php for($p=1;$p<=$total_pages;$p++): ?>
            <li class="page-item <?php echo $p===$page?'active':''; ?>"><a class="page-link" href="<?php echo e($qs(['page'=>$p>1?$p:null])); ?>"><?php echo $p; ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page>=$total_pages?'disabled':''; ?>"><a class="page-link" href="<?php echo e($qs(['page'=>$page+1])); ?>">&raquo;</a></li>
    </ul></nav>
    <?php endif; ?>
</div>

<!-- Modal: Nhập ý tưởng / YouTube -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-youtube text-danger me-2"></i>Nhập ý tưởng / YouTube</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="text-muted small">Mỗi dòng là một <strong>ý tưởng</strong> (dùng làm tiêu đề) hoặc một <strong>link YouTube</strong> (tự lấy tiêu đề + nhúng video). Bài tạo ra ở dạng <strong>Nháp</strong>. Sau đó chọn bài và bấm "Viết lại AI" để có bài hoàn chỉnh.</p>
        <textarea id="ideasInput" class="form-control" rows="8" placeholder="Cách chạy quảng cáo Facebook hiệu quả&#10;https://www.youtube.com/watch?v=xxxxxxxxxxx&#10;5 mẹo tối ưu Google Ads"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
        <button type="button" class="btn btn-success" id="btnRunImport"><i class="bi bi-download me-1"></i>Tạo bài nháp</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Tiến trình AI -->
<div class="modal fade" id="aiProgressModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="aiProgressTitle">Đang xử lý AI...</h5></div>
      <div class="modal-body">
        <div class="progress mb-2" style="height:22px;">
          <div class="progress-bar progress-bar-striped progress-bar-animated" id="aiProgressBar" style="width:0%">0%</div>
        </div>
        <div class="small text-muted mb-2" id="aiProgressStatus">Chuẩn bị...</div>
        <div id="aiProgressLog" style="max-height:240px;overflow:auto;font-size:.82rem;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" id="aiProgressClose" data-bs-dismiss="modal" disabled>Đóng</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('checkAll');
    const selCount = document.getElementById('selCount');
    function rowChecks() { return Array.from(document.querySelectorAll('.row-check')); }
    function selectedIds() { return rowChecks().filter(c => c.checked).map(c => parseInt(c.value, 10)); }
    function updateCount() { selCount.textContent = selectedIds().length; }
    if (checkAll) checkAll.addEventListener('change', () => { rowChecks().forEach(c => c.checked = checkAll.checked); updateCount(); });
    rowChecks().forEach(c => c.addEventListener('change', updateCount));

    const pm = document.getElementById('aiProgressModal');
    const pmModal = { show() { if (typeof bootstrap !== 'undefined' && pm) bootstrap.Modal.getOrCreateInstance(pm).show(); } };
    const bar = document.getElementById('aiProgressBar');
    const statusEl = document.getElementById('aiProgressStatus');
    const logEl = document.getElementById('aiProgressLog');
    const closeBtn = document.getElementById('aiProgressClose');

    function setBar(done, total) {
        const pct = total ? Math.round(done / total * 100) : 0;
        bar.style.width = pct + '%'; bar.textContent = pct + '%';
    }
    function logLine(id, title, ok, msg) {
        const cls = ok ? 'text-success' : 'text-danger';
        const ico = ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
        logEl.insertAdjacentHTML('beforeend', `<div class="${cls}"><i class="bi ${ico} me-1"></i>#${id} ${title ? '— ' + title : ''}: ${msg}</div>`);
        logEl.scrollTop = logEl.scrollHeight;
    }

    async function runBulk(action, label) {
        const ids = selectedIds();
        if (!ids.length) { alert('Vui lòng chọn ít nhất 1 bài viết.'); return; }
        document.getElementById('aiProgressTitle').textContent = label + ' (' + ids.length + ' bài)';
        logEl.innerHTML = ''; setBar(0, ids.length); statusEl.textContent = 'Bắt đầu...';
        closeBtn.disabled = true; pmModal.show();
        let ok = 0, fail = 0;
        for (let i = 0; i < ids.length; i++) {
            statusEl.textContent = `Đang xử lý ${i + 1}/${ids.length} (ID ${ids[i]})...`;
            try {
                const body = new URLSearchParams({ action, id: ids[i], save: '1', csrf_token: POST_CSRF });
                const r = await fetch('../ajax/post-ai.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
                const d = await r.json();
                if (d.success) { ok++; logLine(ids[i], d.title || '', true, d.message || 'OK'); }
                else { fail++; logLine(ids[i], d.title || '', false, d.message || 'Lỗi'); }
            } catch (e) { fail++; logLine(ids[i], '', false, 'Lỗi kết nối'); }
            setBar(i + 1, ids.length);
        }
        statusEl.innerHTML = `<strong>Hoàn tất:</strong> ${ok} thành công, ${fail} lỗi.`;
        closeBtn.disabled = false;
    }

    const btnR = document.getElementById('btnBulkRewrite');
    const btnA = document.getElementById('btnBulkAll');
    if (btnR) btnR.addEventListener('click', () => runBulk('rewrite', 'Viết bài chuẩn SEO+GEO'));
    if (btnA) btnA.addEventListener('click', () => runBulk('all', 'Viết bài + Tự động SEO'));

    const btnImport = document.getElementById('btnRunImport');
    if (btnImport) btnImport.addEventListener('click', async function () {
        const text = document.getElementById('ideasInput').value.trim();
        if (!text) { alert('Vui lòng nhập ít nhất 1 dòng.'); return; }
        btnImport.disabled = true; btnImport.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang tạo...';
        try {
            const body = new URLSearchParams({ ideas: text, csrf_token: POST_CSRF });
            const r = await fetch('../ajax/post-import.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
            const d = await r.json();
            if (d.success) { alert('Đã tạo ' + d.created + ' bài nháp' + (d.skipped ? (', bỏ qua ' + d.skipped) : '') + '. Tải lại danh sách.'); location.href = 'index.php?status=0'; }
            else { alert(d.message || 'Lỗi tạo bài.'); btnImport.disabled = false; btnImport.innerHTML = '<i class="bi bi-download me-1"></i>Tạo bài nháp'; }
        } catch (e) { alert('Lỗi kết nối.'); btnImport.disabled = false; btnImport.innerHTML = '<i class="bi bi-download me-1"></i>Tạo bài nháp'; }
    });
});
</script>
<?php require_once '../includes/footer.php'; ?>
