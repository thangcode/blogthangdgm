<?php
// admin/short-links/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();
$current_page = 'short-links';

// Auto-migrate tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `short_links` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `description` text COLLATE utf8mb4_unicode_ci,
        `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
        `target_url` text COLLATE utf8mb4_unicode_ci NOT NULL,
        `redirect_type` int(11) DEFAULT 307,
        `utm_source` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `utm_medium` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `utm_campaign` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `utm_term` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `utm_content` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `parameter_forwarding` tinyint(1) DEFAULT 0,
        `is_tracking_enabled` tinyint(1) DEFAULT 1,
        `status` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `short_link_clicks` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `short_link_id` int(11) NOT NULL,
        `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `browser` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `os` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `device` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `referrer` text COLLATE utf8mb4_unicode_ci,
        `clicked_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `short_link_id` (`short_link_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

} catch (PDOException $e) {
    //
}

// Handle Delete
if (isset($_POST['delete_id'])) {
    require_valid_csrf_token();
    $id = (int) $_POST['delete_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM short_links WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmt2 = $pdo->prepare("DELETE FROM short_link_clicks WHERE short_link_id = ?");
        $stmt2->execute([$id]);

        $success = 'Đã xóa link rút gọn thành công!';
    } catch (PDOException $e) {
        $error = 'Lỗi hệ thống khi xóa.';
    }
}

require_once '../includes/header.php';

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// Fetch links
$whereClause = "1=1";
$params = [];
if ($search) {
    $whereClause .= " AND (title LIKE ? OR slug LIKE ? OR target_url LIKE ?)";
    $term = "%{$search}%";
    $params = [$term, $term, $term];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM short_links WHERE $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

$stmt = $pdo->prepare("
    SELECT s.*, 
           (SELECT COUNT(*) FROM short_link_clicks c WHERE c.short_link_id = s.id) as clicks,
           (SELECT COUNT(DISTINCT ip_address) FROM short_link_clicks c WHERE c.short_link_id = s.id) as unique_clicks
    FROM short_links s 
    WHERE $whereClause 
    ORDER BY s.created_at DESC 
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$links = $stmt->fetchAll();

?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Quản lý Rút gọn Link</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="add.php" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Thêm link mới
            </a>
        </div>
    </div>

    <!-- Search Box -->
    <div class="row mb-3">
        <div class="col-md-6">
            <form method="GET" action="" class="d-flex gap-2">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" placeholder="Tìm kiếm title, slug, url..."
                        value="<?php echo e($search); ?>">
                </div>
                <button type="submit" class="btn btn-outline-primary">Tìm</button>
                <?php if ($search): ?>
                    <a href="index.php" class="btn btn-outline-secondary">Xóa lọc</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <style>
    .link-table {
        border-collapse: separate;
        border-spacing: 0 10px;
    }
    .link-table th {
        border-bottom: none !important;
        font-size: 0.85rem;
        font-weight: 700;
        color: #1e293b;
        padding-bottom: 0px;
    }
    .link-table td {
        background-color: #fff;
        padding: 0.85rem 0.5rem;
        vertical-align: middle;
        border-top: 1px solid #f1f5f9;
        border-bottom: 1px solid #f1f5f9;
    }
    .link-table tr:hover td {
        background-color: #f8fafc;
    }
    .link-table tr td:first-child {
        border-top-left-radius: 6px;
        border-bottom-left-radius: 6px;
        border-left: 1px solid #f1f5f9;
        padding-left: 1rem;
    }
    .link-table tr td:last-child {
        border-top-right-radius: 6px;
        border-bottom-right-radius: 6px;
        border-right: 1px solid #f1f5f9;
        padding-right: 1rem;
    }
    .url-box {
        display: inline-block;
        border: 1px solid #4f46e5;
        color: #4f46e5;
        padding: 0.2rem 0.6rem;
        border-radius: 4px;
        font-weight: 600;
        font-size: 0.8rem;
        max-width: 250px;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        text-decoration: none;
        background-color: #f5f3ff;
    }
    .url-box:hover {
        background-color: #e0e7ff;
        color: #4338ca;
    }
    .url-box-target {
        border: 1px solid #3b82f6;
        color: #3b82f6;
        background-color: #eff6ff;
    }
    .url-box-target:hover {
        background-color: #dbeafe;
        color: #1d4ed8;
    }
    .icon-btn-blue {
        color: #4f46e5;
        background: none;
        border: none;
        font-size: 1.1rem;
        padding: 0 0.25rem;
    }
    .icon-btn-blue:hover {
        color: #4338ca;
    }
    .icon-action {
        color: #9ca3af;
        background: none;
        border: none;
        font-size: 1rem;
        padding: 0 0.25rem;
        text-decoration: none;
    }
    .icon-action:hover {
        color: #6b7280;
    }
    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
    }
    .status-active {
        background-color: #10b981;
    }
    .status-inactive {
        background-color: #9ca3af;
    }
    </style>

    <div class="table-responsive bg-light p-3 rounded shadow-sm">
        <table class="table link-table align-middle border-0 mb-0">
            <thead>
                <tr>
                    <th scope="col" width="40" class="ps-3"><input type="checkbox" class="form-check-input border-secondary"></th>
                    <th scope="col" width="280">Title</th>
                    <th scope="col" class="text-center">Shortened URL</th>
                    <th scope="col" class="text-center">Target URL</th>
                    <th scope="col" width="80" class="text-center">Redirect<br>Type</th>
                    <th scope="col" width="80" class="text-center">Clicks</th>
                    <th scope="col" width="130">Date</th>
                    <th scope="col" class="text-end pe-3" width="120">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($links)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted bg-white border rounded">Không có link nào được tìm thấy.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($links as $link): ?>
                        <tr class="shadow-sm">
                            <td class="ps-3"><input type="checkbox" class="form-check-input border-secondary"></td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-star-fill text-muted me-2" style="font-size: 0.8rem; color: #cbd5e1 !important;"></i>
                                    <span class="status-dot <?php echo $link['status'] == 1 ? 'status-active' : 'status-inactive'; ?>"></span>
                                    <div class="text-truncate fw-bold text-dark" style="max-width: 220px; font-size: 0.85rem;" title="<?php echo e($link['title']); ?>">
                                        <?php echo e($link['title']); ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php $short_url = BASE_URL . $link['slug']; ?>
                                <div class="d-flex align-items-center justify-content-center">
                                    <a href="<?php echo $short_url; ?>" target="_blank" class="url-box" title="<?php echo $short_url; ?>">
                                        <?php echo $short_url; ?>
                                    </a>
                                    <button class="icon-btn-blue ms-1" onclick="navigator.clipboard.writeText('<?php echo $short_url; ?>'); AdminPopup.success('Đã copy link!');" title="Copy">
                                        <i class="bi bi-files"></i>
                                    </button>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="d-flex align-items-center justify-content-center">
                                    <a href="<?php echo e($link['target_url']); ?>" target="_blank" class="url-box url-box-target" title="<?php echo e($link['target_url']); ?>">
                                        <?php echo e($link['target_url']); ?>
                                    </a>
                                    <a href="<?php echo e($link['target_url']); ?>" target="_blank" class="icon-action ms-1" title="Mở Target">
                                        <i class="bi bi-box-arrow-up-right" style="font-size: 0.85rem;"></i>
                                    </a>
                                </div>
                            </td>
                            <td class="text-center text-muted" style="font-size: 0.85rem;">
                                <?php echo $link['redirect_type']; ?>
                            </td>
                            <td class="text-center">
                                <a href="stats.php?id=<?php echo $link['id']; ?>" class="fw-bold text-decoration-underline" style="font-size: 0.85rem; color: #0284c7;">
                                    <?php echo number_format($link['clicks']) . '/' . number_format($link['unique_clicks']); ?>
                                </a>
                            </td>
                            <td class="text-muted" style="font-size: 0.8rem; line-height: 1.2;">
                                <?php 
                                    $dateObj = new DateTime($link['created_at']);
                                    $months = [1=>'một', 2=>'hai', 3=>'ba', 4=>'tư', 5=>'năm', 6=>'sáu', 7=>'bảy', 8=>'tám', 9=>'chín', 10=>'mười', 11=>'mười một', 12=>'mười hai'];
                                    echo $dateObj->format('j') . ' Tháng ' . $months[(int)$dateObj->format('n')] . ',<br>' . $dateObj->format('Y');
                                ?>
                            </td>
                            <td class="text-end text-nowrap pe-3">
                                <a href="stats.php?id=<?php echo $link['id']; ?>" class="icon-action" title="Thống kê"><i class="bi bi-box-arrow-up-right"></i></a>
                                <a href="edit.php?id=<?php echo $link['id']; ?>" class="icon-action mx-1" title="Chỉnh sửa"><i class="bi bi-pencil"></i></a>
                                <form method="POST" class="d-inline m-0" onsubmit="return confirm('Bạn có chắc chắn muốn xóa link này không? Toàn bộ dữ liệu click cũng sẽ bị xóa.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo $link['id']; ?>">
                                    <button type="submit" class="icon-action" title="Xóa"><i class="bi bi-trash"></i></button>
                                </form>
                                <button type="button" class="icon-action ms-1" onclick="navigator.clipboard.writeText('<?php echo $short_url; ?>'); AdminPopup.success('Đã copy link!');" title="Copy"><i class="bi bi-files"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Phân trang -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
