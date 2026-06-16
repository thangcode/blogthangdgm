<?php
// admin/document-requests/index.php — Lead nhận tài liệu
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';

// Xuất CSV (trước khi include header để không dính HTML)
if (isset($_GET['export'])) {
    require_admin_login();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=document_requests_' . date('Ymd_His') . '.csv');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 cho Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Họ tên', 'Email', 'File', 'Bài viết', 'Trạng thái', 'IP', 'Trình duyệt', 'Thiết bị', 'Trang nguồn', 'Thời gian']);
    try {
        $rows = $pdo->query("SELECT dr.*, p.title FROM document_requests dr LEFT JOIN posts p ON p.id=dr.post_id ORDER BY dr.id DESC");
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['fullname'], $r['email'], $r['file_name'], $r['title'] ?? '', $r['status'], $r['ip_address'], $r['browser'] ?? '', $r['device'] ?? '', $r['source_url'] ?? '', $r['created_at']]);
        }
    } catch (Throwable $e) {}
    fclose($out);
    exit;
}

$current_page = 'document-requests';
require_once '../includes/header.php';

$status_filter = $_GET['status'] ?? '';
$where = '';
$params = [];
if (in_array($status_filter, ['sent', 'failed'], true)) {
    $where = "WHERE dr.status = ?";
    $params[] = $status_filter;
}

try {
    $st = $pdo->prepare("SELECT dr.*, p.title, p.slug FROM document_requests dr LEFT JOIN posts p ON p.id=dr.post_id $where ORDER BY dr.id DESC LIMIT 500");
    $st->execute($params);
    $rows = $st->fetchAll();
    $total = (int) $pdo->query("SELECT COUNT(*) FROM document_requests")->fetchColumn();
    $sent = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status='sent'")->fetchColumn();
    $failed = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status='failed'")->fetchColumn();
} catch (Throwable $e) {
    $rows = []; $total = $sent = $failed = 0;
}
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Lead nhận tài liệu</h1>
        <a href="?export=1" class="btn btn-outline-success"><i class="bi bi-download"></i> Xuất CSV</a>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Tổng</div><div class="h3 mb-0"><?php echo number_format($total); ?></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Đã gửi</div><div class="h3 mb-0 text-success"><?php echo number_format($sent); ?></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Lỗi gửi</div><div class="h3 mb-0 text-danger"><?php echo number_format($failed); ?></div></div></div></div>
    </div>

    <div class="mb-3">
        <a href="index.php" class="btn btn-sm <?php echo $status_filter===''?'btn-primary':'btn-outline-primary'; ?>">Tất cả</a>
        <a href="?status=sent" class="btn btn-sm <?php echo $status_filter==='sent'?'btn-success':'btn-outline-success'; ?>">Đã gửi</a>
        <a href="?status=failed" class="btn btn-sm <?php echo $status_filter==='failed'?'btn-danger':'btn-outline-danger'; ?>">Lỗi</a>
    </div>

    <div class="table-responsive bg-white rounded shadow-sm p-3">
        <table class="table table-hover align-middle">
            <thead><tr><th>ID</th><th>Họ tên</th><th>Email</th><th>Bài viết</th><th>File</th><th>Thiết bị</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo (int) $r['id']; ?></td>
                    <td><?php echo e($r['fullname']); ?></td>
                    <td><a href="mailto:<?php echo e($r['email']); ?>"><?php echo e($r['email']); ?></a></td>
                    <td><?php if (!empty($r['slug'])): ?><a href="<?php echo e(postUrl($r['slug'])); ?>" target="_blank" class="small"><?php echo e(mb_substr($r['title'] ?? '', 0, 40, 'UTF-8')); ?></a><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
                    <td class="small text-muted"><?php echo e($r['file_name']); ?></td>
                    <td class="small text-muted">
                        <?php if (!empty($r['device']) || !empty($r['browser'])): ?>
                            <?php echo e($r['browser'] ?? ''); ?><?php if (!empty($r['device'])): ?><br><span class="text-secondary"><?php echo e($r['device']); ?></span><?php endif; ?>
                            <?php if (!empty($r['source_url'])): ?><br><a href="<?php echo e($r['source_url']); ?>" target="_blank" rel="noopener" class="text-decoration-none" title="<?php echo e($r['source_url']); ?>"><i class="bi bi-box-arrow-up-right"></i> nguồn</a><?php endif; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td><?php echo $r['status'] === 'sent' ? '<span class="badge bg-success">Đã gửi</span>' : '<span class="badge bg-danger" title="' . e($r['error_note'] ?? '') . '">Lỗi</span>'; ?></td>
                    <td class="small"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="8" class="text-center text-muted">Chưa có yêu cầu nào.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
