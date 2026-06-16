<?php
// admin/index.php — Dashboard blog
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/url-helper.php';

$current_page = 'dashboard';
require_once 'includes/header.php';

if (function_exists('maybe_prune_traffic_data')) {
    maybe_prune_traffic_data($pdo, 90);
}

$q1 = function (string $sql) use ($pdo) {
    try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
};

$stats = [
    'posts'      => $q1("SELECT COUNT(*) FROM posts WHERE status = 1"),
    'posts_all'  => $q1("SELECT COUNT(*) FROM posts"),
    'categories' => $q1("SELECT COUNT(*) FROM categories"),
    'tags'       => $q1("SELECT COUNT(*) FROM tags"),
    'short'      => $q1("SELECT COUNT(*) FROM short_links"),
    'docreq'     => $q1("SELECT COUNT(*) FROM document_requests"),
    'docfail'    => $q1("SELECT COUNT(*) FROM document_requests WHERE status='failed'"),
    'contacts'   => $q1("SELECT COUNT(*) FROM contacts"),
    'banners'    => $q1("SELECT COUNT(*) FROM banners"),
    'views'      => $q1("SELECT COALESCE(SUM(views),0) FROM posts"),
];

// Bài mới nhất
try {
    $recent_posts = $pdo->query("SELECT id, title, slug, views, created_at, status FROM posts ORDER BY created_at DESC LIMIT 6")->fetchAll();
} catch (Throwable $e) { $recent_posts = []; }

// Lead tài liệu gần đây
try {
    $recent_leads = $pdo->query("SELECT dr.fullname, dr.email, dr.status, dr.created_at, p.title
        FROM document_requests dr LEFT JOIN posts p ON p.id = dr.post_id ORDER BY dr.id DESC LIMIT 6")->fetchAll();
} catch (Throwable $e) { $recent_leads = []; }

// Bài theo chuyên mục (donut)
try {
    $by_cat = $pdo->query("SELECT c.name, COUNT(pc.post_id) cnt FROM categories c
        JOIN post_categories pc ON pc.category_id = c.id JOIN posts p ON p.id = pc.post_id AND p.status = 1
        GROUP BY c.id ORDER BY cnt DESC LIMIT 6")->fetchAll();
} catch (Throwable $e) { $by_cat = []; }

$hour = (int) date('H');
$greeting = $hour < 12 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');
$cat_labels = json_encode(array_column($by_cat, 'name'), JSON_UNESCAPED_UNICODE);
$cat_data = json_encode(array_map('intval', array_column($by_cat, 'cnt')));
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<style>
.welcome-card{background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:16px;color:#fff;padding:1.5rem 1.75rem;margin-bottom:1.5rem;}
.stat-card{border-radius:14px;padding:1.1rem 1.25rem;border:1px solid #e2e8f0;background:#fff;transition:.2s;height:100%;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,.08);}
.stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;}
.stat-value{font-size:1.6rem;font-weight:800;color:#1e293b;}
.stat-label{font-size:.78rem;color:#64748b;}
.chart-card{border-radius:14px;border:1px solid #e2e8f0;background:#fff;}
.chart-card .card-header{background:transparent;border-bottom:1px solid #f1f5f9;padding:.9rem 1.15rem;font-weight:700;font-size:.9rem;}
.quick-action{display:flex;align-items:center;gap:.7rem;padding:.7rem 1rem;border-radius:10px;border:1px solid #e2e8f0;background:#fff;color:#334155;text-decoration:none;transition:.15s;font-size:.85rem;font-weight:500;}
.quick-action:hover{border-color:#6366f1;color:#6366f1;background:#f5f3ff;}
.qa-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
</style>

<div class="welcome-card">
    <h4 class="fw-bold mb-1"><?php echo $greeting; ?>, <?php echo e($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin'); ?>! 👋</h4>
    <p class="mb-0 opacity-75" style="font-size:.88rem;">Tổng quan blog hôm nay — <?php echo date('d/m/Y'); ?>.</p>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Bài viết', $stats['posts'], 'bi-newspaper', '#ede9fe', '#6366f1', 'posts/index.php'],
        ['Chuyên mục', $stats['categories'], 'bi-tags-fill', '#dcfce7', '#16a34a', 'categories/index.php'],
        ['Tags', $stats['tags'], 'bi-tag-fill', '#fef3c7', '#d97706', 'tags/index.php'],
        ['Lượt xem', number_format($stats['views']), 'bi-eye-fill', '#e0f2fe', '#0284c7', 'posts/index.php'],
        ['Lead tài liệu', $stats['docreq'], 'bi-file-earmark-arrow-down', '#fce7f3', '#db2777', 'document-requests/index.php'],
        ['Short links', $stats['short'], 'bi-link-45deg', '#e0e7ff', '#4f46e5', 'short-links/index.php'],
        ['Liên hệ', $stats['contacts'], 'bi-envelope-fill', '#fef9c3', '#ca8a04', 'contacts/index.php'],
        ['Banner', $stats['banners'], 'bi-images', '#d1fae5', '#059669', 'banners/index.php'],
    ];
    foreach ($cards as [$label,$val,$icon,$bg,$col,$link]): ?>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="stat-icon" style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;"><i class="bi <?php echo $icon; ?>"></i></div>
                    <a href="<?php echo $link; ?>" class="text-decoration-none text-muted" style="font-size:.72rem;">Xem <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="stat-value"><?php echo $val; ?></div>
                <div class="stat-label"><?php echo $label; ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="chart-card h-100">
            <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Bài viết mới nhất</div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <thead><tr><th class="ps-3">Tiêu đề</th><th>Lượt xem</th><th>Ngày</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_posts as $p): ?>
                        <tr>
                            <td class="ps-3"><span class="fw-semibold"><?php echo e(mb_substr($p['title'],0,55,'UTF-8')); ?></span>
                                <?php if ((int)$p['status'] !== 1): ?><span class="badge bg-secondary ms-1">nháp/ẩn</span><?php endif; ?></td>
                            <td><small class="text-muted"><?php echo number_format((int)$p['views']); ?></small></td>
                            <td><small class="text-muted"><?php echo date('d/m/Y', strtotime($p['created_at'])); ?></small></td>
                            <td class="text-end pe-3">
                                <a href="posts/edit.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <a href="<?php echo e(postUrl($p['slug'])); ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_posts)): ?><tr><td colspan="4" class="text-center text-muted py-4">Chưa có bài viết.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="chart-card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2 text-success"></i>Bài theo chuyên mục</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (!empty($by_cat)): ?><canvas id="catDonut" height="220"></canvas>
                <?php else: ?><div class="text-muted py-4">Chưa có dữ liệu.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="chart-card h-100">
            <div class="card-header"><i class="bi bi-file-earmark-arrow-down me-2 text-danger"></i>Lead nhận tài liệu gần đây</div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <thead><tr><th class="ps-3">Họ tên</th><th>Email</th><th>Bài viết</th><th>Trạng thái</th><th>Ngày</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_leads as $l): ?>
                        <tr>
                            <td class="ps-3"><?php echo e($l['fullname']); ?></td>
                            <td><small><?php echo e($l['email']); ?></small></td>
                            <td><small class="text-muted"><?php echo e(mb_substr($l['title'] ?? '—',0,30,'UTF-8')); ?></small></td>
                            <td><?php echo $l['status']==='sent'?'<span class="badge bg-success">Đã gửi</span>':'<span class="badge bg-danger">Lỗi</span>'; ?></td>
                            <td><small class="text-muted"><?php echo date('d/m H:i', strtotime($l['created_at'])); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_leads)): ?><tr><td colspan="5" class="text-center text-muted py-4">Chưa có yêu cầu tài liệu.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="chart-card h-100">
            <div class="card-header"><i class="bi bi-lightning-charge-fill me-2 text-warning"></i>Thao tác nhanh</div>
            <div class="card-body d-grid gap-2">
                <a href="posts/add.php" class="quick-action"><i class="qa-icon" style="background:#ede9fe;color:#6366f1;"><span class="bi bi-pencil-square"></span></i> Viết bài mới</a>
                <a href="categories/index.php" class="quick-action"><i class="qa-icon" style="background:#dcfce7;color:#16a34a;"><span class="bi bi-tags"></span></i> Chuyên mục</a>
                <a href="tags/index.php" class="quick-action"><i class="qa-icon" style="background:#fef3c7;color:#d97706;"><span class="bi bi-tag"></span></i> Tags</a>
                <a href="document-requests/index.php" class="quick-action"><i class="qa-icon" style="background:#fce7f3;color:#db2777;"><span class="bi bi-people-fill"></span></i> Lead tài liệu<?php if ($stats['docfail']>0): ?><span class="badge bg-danger ms-auto"><?php echo $stats['docfail']; ?> lỗi</span><?php endif; ?></a>
                <a href="short-links/index.php" class="quick-action"><i class="qa-icon" style="background:#e0e7ff;color:#4f46e5;"><span class="bi bi-link-45deg"></span></i> Rút gọn link</a>
                <a href="settings/index.php" class="quick-action"><i class="qa-icon" style="background:#e0f2fe;color:#0284c7;"><span class="bi bi-gear-fill"></span></i> Cấu hình</a>
                <a href="<?php echo BASE_URL; ?>" target="_blank" class="quick-action"><i class="qa-icon" style="background:#f1f5f9;color:#475569;"><span class="bi bi-eye-fill"></span></i> Xem website</a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const el = document.getElementById('catDonut');
    if (el && window.Chart) {
        new Chart(el, {
            type: 'doughnut',
            data: { labels: <?php echo $cat_labels; ?>, datasets: [{ data: <?php echo $cat_data; ?>,
                backgroundColor: ['#6366f1','#10b981','#f59e0b','#ef4444','#3b82f6','#8b5cf6'], borderWidth:0, hoverOffset:6 }] },
            options: { cutout:'65%', plugins:{ legend:{ position:'bottom', labels:{ boxWidth:10, padding:12, font:{size:11} } } } }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
