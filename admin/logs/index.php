<?php
// admin/logs/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'logs';
// Ensure admin login (this is usually handled in header, but good to check session)
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../includes/header.php';

// Pagination setup
$limit = 20;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1)
    $page = 1;
$offset = ($page - 1) * $limit;

// Filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$username_filter = isset($_GET['username']) ? $_GET['username'] : '';

// Check if table exists, if not create it (Self-healing)
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->rowCount() > 0;
    if (!$tableExists) {
        $sql = "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(100) NULL,
            action VARCHAR(100) NOT NULL,
            resource_type VARCHAR(100) NULL, 
            resource_id INT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (action),
            INDEX (resource_type),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);
    }
} catch (PDOException $e) {
    // Ignore error, query might fail later if table really doesn't exist
}

// Build Query
$where = "WHERE 1=1";
$params = [];

if ($search) {
    // Search in details, resource_type, ip
    $where .= " AND (details LIKE ? OR resource_type LIKE ? OR ip_address LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($action_filter) {
    $where .= " AND action = ?";
    $params[] = $action_filter;
}

if ($username_filter) {
    $where .= " AND username LIKE ?";
    $params[] = "%$username_filter%";
}

// Get Total Count
$count_sql = "SELECT COUNT(*) FROM audit_logs $where";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Get Records
$sql = "SELECT * FROM audit_logs $where ORDER BY id DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get distinct actions for filter
$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

?>

<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Lịch sử hoạt động (Audit Logs)</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                <i class="bi bi-arrow-clockwise"></i> Làm mới
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="">
                <div class="row g-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="search" placeholder="Tìm kiếm (IP, chi tiết...)"
                            value="<?php echo e($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="action">
                            <option value="">-- Tất cả hành động --</option>
                            <?php foreach ($actions as $act): ?>
                                <option value="<?php echo e($act); ?>" <?php echo $action_filter === $act ? 'selected' : ''; ?>>
                                    <?php echo e($act); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="username" placeholder="Tên người dùng"
                            value="<?php echo e($username_filter); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Lọc</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="table-responsive bg-white rounded shadow-sm p-3">
        <table class="table table-hover align-middle table-sm" style="font-size: 0.9rem;">
            <thead>
                <tr>
                    <th width="50">ID</th>
                    <th width="150">Thời gian</th>
                    <th width="150">Người dùng</th>
                    <th width="120">Hành động</th>
                    <th width="150">Tài nguyên</th>
                    <th>Chi tiết</th>
                    <th width="120">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($logs) > 0): ?>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td>#
                                <?php echo $row['id']; ?>
                            </td>
                            <td>
                                <?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?>
                            </td>
                            <td>
                                <span class="fw-bold">
                                    <?php echo e($row['username']); ?>
                                </span>
                                <div class="text-muted small" style="font-size:0.75rem;">ID:
                                    <?php echo $row['user_id']; ?>
                                </div>
                            </td>
                            <td>
                                <?php
                                $badgeClass = 'bg-secondary';
                                if (strpos($row['action'], 'create') !== false || strpos($row['action'], 'add') !== false)
                                    $badgeClass = 'bg-success';
                                elseif (strpos($row['action'], 'update') !== false || strpos($row['action'], 'edit') !== false)
                                    $badgeClass = 'bg-primary';
                                elseif (strpos($row['action'], 'delete') !== false)
                                    $badgeClass = 'bg-danger';
                                elseif (strpos($row['action'], 'login') !== false)
                                    $badgeClass = 'bg-info text-dark';
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo e($row['action']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['resource_type']): ?>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo e($row['resource_type']); ?>
                                    </span>
                                    <?php if ($row['resource_id']): ?>
                                        <span class="text-muted small">#
                                            <?php echo $row['resource_id']; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="max-width: 400px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">
                                    <?php echo e($row['details']); ?>
                                </div>
                            </td>
                            <td>
                                <?php echo e($row['ip_address']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">Chưa có nhật ký hoạt động nào.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link"
                                href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&action=<?php echo urlencode($action_filter); ?>&username=<?php echo urlencode($username_filter); ?>">Trước</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link"
                                href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&action=<?php echo urlencode($action_filter); ?>&username=<?php echo urlencode($username_filter); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link"
                                href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&action=<?php echo urlencode($action_filter); ?>&username=<?php echo urlencode($username_filter); ?>">Sau</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>