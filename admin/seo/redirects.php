<?php
// admin/seo/redirects.php - Quản lý Redirect 301
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'seo-redirects';
$page_title = 'Quản lý Redirect 301';
require_admin_login();

// Auto-create table if missing
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `slug_redirects` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `old_path`    VARCHAR(500) NOT NULL,
        `new_path`    VARCHAR(500) NOT NULL,
        `entity_type` ENUM('product','category','post','custom') NOT NULL DEFAULT 'custom',
        `entity_id`   INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_old_path` (`old_path`(191)),
        INDEX `idx_entity`   (`entity_type`, `entity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { /* ignore */ }

$success = '';
$error   = '';

// --- DELETE ---
if (isset($_POST['delete_id'])) {
    require_valid_csrf_token();
    $del_id = (int) $_POST['delete_id'];
    try {
        $pdo->prepare("DELETE FROM slug_redirects WHERE id = ?")->execute([$del_id]);
        $success = 'Đã xóa redirect thành công.';
    } catch (PDOException $e) {
        $error = 'Lỗi xóa: ' . $e->getMessage();
    }
}

// --- ADD ---
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    require_valid_csrf_token();
    $old_path = '/' . ltrim(trim($_POST['old_path'] ?? ''), '/');
    $new_path = trim($_POST['new_path'] ?? '');
    if (!preg_match('/^https?:\/\//i', $new_path)) {
        $new_path = '/' . ltrim($new_path, '/');
    }
    if ($old_path === '/' || $new_path === '/' || $new_path === '') {
        $error = 'Vui lòng nhập đường dẫn hợp lệ.';
    } elseif ($old_path === $new_path) {
        $error = 'Đường dẫn cũ và mới không được giống nhau.';
    } else {
        try {
            $pdo->prepare("INSERT INTO slug_redirects (old_path, new_path, entity_type, entity_id)
                           VALUES (?, ?, 'custom', 0)")->execute([$old_path, $new_path]);
            $success = 'Đã thêm redirect thành công.';
        } catch (PDOException $e) {
            $error = 'Lỗi thêm: ' . $e->getMessage();
        }
    }
}

// --- EDIT ---
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    require_valid_csrf_token();
    $edit_id  = (int) $_POST['edit_id'];
    $old_path = '/' . ltrim(trim($_POST['old_path'] ?? ''), '/');
    $new_path = trim($_POST['new_path'] ?? '');
    if (!preg_match('/^https?:\/\//i', $new_path)) {
        $new_path = '/' . ltrim($new_path, '/');
    }
    if ($old_path === '/' || $new_path === '/' || $new_path === '') {
        $error = 'Vui lòng nhập đường dẫn hợp lệ.';
    } elseif ($old_path === $new_path) {
        $error = 'Đường dẫn cũ và mới không được giống nhau.';
    } else {
        try {
            $pdo->prepare("UPDATE slug_redirects SET old_path=?, new_path=? WHERE id=?")->execute([$old_path, $new_path, $edit_id]);
            $success = 'Đã cập nhật redirect thành công.';
        } catch (PDOException $e) {
            $error = 'Lỗi cập nhật: ' . $e->getMessage();
        }
    }
}

// --- PAGINATION ---
$per_page    = 20;
$cur_page    = max(1, (int) ($_GET['page'] ?? 1));
$search      = trim($_GET['search'] ?? '');
$type_filter = $_GET['type'] ?? '';

$where  = 'WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (old_path LIKE ? OR new_path LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($type_filter !== '') {
    $where .= ' AND entity_type = ?';
    $params[] = $type_filter;
}

try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM slug_redirects $where");
    $count_stmt->execute($params);
    $total = (int) $count_stmt->fetchColumn();
} catch (PDOException $e) { $total = 0; }

$total_pages = max(1, (int) ceil($total / $per_page));
$cur_page    = min($cur_page, $total_pages);
$offset      = ($cur_page - 1) * $per_page;

try {
    $list_stmt = $pdo->prepare("SELECT * FROM slug_redirects $where ORDER BY id DESC LIMIT $per_page OFFSET $offset");
    $list_stmt->execute($params);
    $redirects = $list_stmt->fetchAll();
} catch (PDOException $e) { $redirects = []; }

// Edit row
$edit_row = null;
if (isset($_GET['edit'])) {
    try {
        $s = $pdo->prepare("SELECT * FROM slug_redirects WHERE id = ?");
        $s->execute([(int)$_GET['edit']]);
        $edit_row = $s->fetch();
    } catch (PDOException $e) {}
}

// 404 LOGS PAGINATION
$log_per_page = 20;
$log_cur_page = max(1, (int) ($_GET['log_page'] ?? 1));
try {
    $count_log_stmt = $pdo->query("SELECT COUNT(*) FROM error_404_logs");
    $total_logs = (int) $count_log_stmt->fetchColumn();
} catch (PDOException $e) { $total_logs = 0; }

$total_log_pages = max(1, (int) ceil($total_logs / $log_per_page));
$log_cur_page    = min($log_cur_page, $total_log_pages);
$log_offset      = ($log_cur_page - 1) * $log_per_page;

try {
    $logs_stmt = $pdo->prepare("SELECT * FROM error_404_logs ORDER BY last_seen DESC LIMIT $log_per_page OFFSET $log_offset");
    $logs_stmt->execute();
    $error_logs = $logs_stmt->fetchAll();
} catch (PDOException $e) { $error_logs = []; }

$active_tab = $_GET['tab'] ?? 'redirects';

require_once '../includes/header.php';
?>

<div class="container-fluid">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="bi bi-arrow-left-right me-2 text-warning"></i>Quản lý Redirect 301</h2>
            <p class="text-muted small mt-1 mb-0">Các đường dẫn cũ sẽ tự động chuyển hướng 301 đến đường dẫn mới — an toàn cho SEO.</p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Quay lại SEO
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?php echo e($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?php echo e($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Form thêm / sửa -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 sticky-top" style="top: 80px;">
                <div class="card-header <?php echo $edit_row ? 'bg-warning text-dark' : 'bg-primary text-white'; ?>">
                    <h5 class="mb-0">
                        <i class="bi bi-<?php echo $edit_row ? 'pencil-square' : 'plus-circle'; ?> me-2"></i>
                        <?php echo $edit_row ? 'Sửa Redirect' : 'Thêm Redirect mới'; ?>
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="<?php echo $edit_row ? 'edit' : 'add'; ?>">
                        <?php if ($edit_row): ?>
                            <input type="hidden" name="edit_id" value="<?php echo (int)$edit_row['id']; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-link-45deg text-danger me-1"></i>Đường dẫn CŨ
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted" style="font-size:0.8rem"><?php echo rtrim(BASE_URL, '/'); ?></span>
                                <input type="text" class="form-control" name="old_path"
                                    value="<?php echo e($edit_row['old_path'] ?? ''); ?>"
                                    placeholder="/ten-danh-muc/slug-cu" required>
                            </div>
                            <div class="form-text">VD: <code>/cong-cu-marketing/thangdgm-extension</code></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-arrow-right text-success me-1"></i>Đường dẫn MỚI
                            </label>
                            <input type="text" class="form-control" name="new_path"
                                value="<?php echo e($edit_row['new_path'] ?? ''); ?>"
                                placeholder="/slug-moi hoặc https://sub.domain.com/slug" required>
                            <div class="form-text">URL nội bộ bắt đầu bằng <code>/</code> hoặc link ngoài <code>https://...</code></div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn <?php echo $edit_row ? 'btn-warning fw-bold' : 'btn-primary'; ?>">
                                <i class="bi bi-<?php echo $edit_row ? 'check-lg' : 'plus-lg'; ?> me-1"></i>
                                <?php echo $edit_row ? 'Lưu thay đổi' : 'Thêm Redirect'; ?>
                            </button>
                            <?php if ($edit_row): ?>
                                <a href="redirects.php" class="btn btn-outline-secondary">Hủy bỏ</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Hướng dẫn -->
                <div class="card-footer bg-light p-3">
                    <p class="small text-muted mb-2"><strong><i class="bi bi-info-circle me-1"></i>Cách hoạt động:</strong></p>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>Redirect 301 = "Chuyển vĩnh viễn" — Google chuyển toàn bộ điểm SEO sang URL mới.</li>
                        <li>Các redirect từ <strong>sửa slug sản phẩm</strong> được tạo tự động tại đây.</li>
                        <li>Bạn có thể thêm thủ công redirect cho bất kỳ URL nào.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Danh sách -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom p-0">
                    <ul class="nav nav-tabs border-0 pt-2 px-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <a href="?tab=redirects" class="nav-link <?php echo $active_tab === 'redirects' ? 'active fw-bold' : 'text-muted'; ?>">
                                <i class="bi bi-list-ul me-1"></i> Danh sách Redirect
                                <span class="badge bg-secondary ms-1 rounded-pill"><?php echo $total; ?></span>
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a href="?tab=404" class="nav-link <?php echo $active_tab === '404' ? 'active fw-bold text-danger' : 'text-muted'; ?>">
                                <i class="bi bi-exclamation-triangle me-1"></i> Nhật ký 404
                                <span class="badge bg-danger ms-1 rounded-pill"><?php echo $total_logs; ?></span>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <div class="card-body p-0">
                    <?php if ($active_tab === 'redirects'): ?>
                        <!-- SEARCH & FILTER cho Redirect -->
                        <div class="p-3 bg-light border-bottom d-flex justify-content-end align-items-center">
                            <form method="GET" class="d-flex gap-2 align-items-center m-0">
                                <input type="hidden" name="tab" value="redirects">
                                <select name="type" class="form-select form-select-sm" style="width:130px" onchange="this.form.submit()">
                                    <option value="">Tất cả loại</option>
                                    <option value="product"  <?php echo $type_filter === 'product'  ? 'selected' : ''; ?>>Sản phẩm</option>
                                    <option value="category" <?php echo $type_filter === 'category' ? 'selected' : ''; ?>>Danh mục</option>
                                    <option value="post"     <?php echo $type_filter === 'post'     ? 'selected' : ''; ?>>Bài viết</option>
                                    <option value="custom"   <?php echo $type_filter === 'custom'   ? 'selected' : ''; ?>>Thủ công</option>
                                </select>
                                <div class="input-group input-group-sm" style="width:220px">
                                    <input type="text" class="form-control" name="search" placeholder="Tìm đường dẫn..." value="<?php echo e($search); ?>">
                                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                                    <?php if ($search || $type_filter): ?>
                                        <a href="redirects.php?tab=redirects" class="btn btn-outline-danger"><i class="bi bi-x"></i></a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40" class="ps-3">#</th>
                                        <th>Từ (URL cũ)</th>
                                        <th>Đến (URL mới)</th>
                                        <th width="100">Loại</th>
                                        <th width="120">Ngày tạo</th>
                                        <th width="100" class="text-end pe-3">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($redirects)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                <?php echo $search || $type_filter ? 'Không tìm thấy kết quả.' : 'Chưa có redirect nào.'; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($redirects as $i => $r): ?>
                                            <tr <?php echo (isset($_GET['edit']) && (int)$_GET['edit'] === (int)$r['id']) ? 'class="table-warning"' : ''; ?>>
                                                <td class="text-muted small ps-3"><?php echo $offset + $i + 1; ?></td>
                                                <td>
                                                    <code class="text-danger small"><?php echo e($r['old_path']); ?></code>
                                                </td>
                                                <td>
                                                    <?php
                                                    $is_absolute = preg_match('/^https?:\/\//i', $r['new_path']);
                                                    $target_url = $is_absolute ? e($r['new_path']) : BASE_URL . ltrim(e($r['new_path']), '/');
                                                    ?>
                                                    <a href="<?php echo $target_url; ?>" target="_blank"
                                                       class="text-decoration-none small">
                                                        <code class="text-success"><?php echo e($r['new_path']); ?></code>
                                                        <i class="bi bi-box-arrow-up-right ms-1" style="font-size:0.7rem"></i>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php
                                                    $type_badges = [
                                                        'product'  => ['bg-primary',   'Sản phẩm'],
                                                        'category' => ['bg-success',   'Danh mục'],
                                                        'post'     => ['bg-info',      'Bài viết'],
                                                        'custom'   => ['bg-secondary', 'Thủ công'],
                                                    ];
                                                    $tb = $type_badges[$r['entity_type']] ?? ['bg-dark', $r['entity_type']];
                                                    ?>
                                                    <span class="badge <?php echo $tb[0]; ?>"><?php echo $tb[1]; ?></span>
                                                </td>
                                                <td class="text-muted small"><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
                                                <td class="text-end pe-3">
                                                    <a href="redirects.php?edit=<?php echo (int)$r['id']; ?>&tab=redirects<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $type_filter ? '&type=' . urlencode($type_filter) : ''; ?>&page=<?php echo $cur_page; ?>"
                                                       class="btn btn-sm btn-outline-warning me-1" title="Sửa">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <form method="POST" class="d-inline"
                                                        data-confirm="Xóa redirect này?" data-confirm-title="Xác nhận xóa" data-confirm-ok="Xóa" data-confirm-class="btn-danger">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                                                        <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination cho Redirect -->
                        <?php if ($total_pages > 1): ?>
                            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                                <small class="text-muted">Trang <?php echo $cur_page; ?>/<?php echo $total_pages; ?> &mdash; <?php echo $total; ?> bản ghi</small>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php if ($cur_page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?tab=redirects&page=<?php echo $cur_page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php
                                        $start_p = max(1, $cur_page - 2);
                                        $end_p   = min($total_pages, $cur_page + 2);
                                        for ($p = $start_p; $p <= $end_p; $p++):
                                        ?>
                                            <li class="page-item <?php echo $p === $cur_page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?tab=redirects&page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">
                                                    <?php echo $p; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($cur_page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?tab=redirects&page=<?php echo $cur_page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- TAB 404 LOGS -->
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40" class="ps-3">#</th>
                                        <th>Đường dẫn lỗi (404)</th>
                                        <th width="100" class="text-center">Số lượt</th>
                                        <th width="150">Lần cuối</th>
                                        <th width="120" class="text-end pe-3">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($error_logs)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>
                                                Tuyệt vời! Không có lỗi 404 nào được ghi nhận.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($error_logs as $i => $log): ?>
                                            <tr>
                                                <td class="text-muted small ps-3"><?php echo $log_offset + $i + 1; ?></td>
                                                <td>
                                                    <a href="<?php echo BASE_URL . ltrim(e($log['path']), '/'); ?>" target="_blank" class="text-decoration-none small text-danger fw-medium">
                                                        <?php echo e($log['path']); ?>
                                                    </a>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge bg-secondary"><?php echo number_format($log['hits']); ?></span>
                                                </td>
                                                <td class="text-muted small">
                                                    <?php echo date('H:i d/m/Y', strtotime($log['last_seen'])); ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" title="Tạo Redirect 301 cho link này" onclick="fillAddRedirect('<?php echo e(addslashes($log['path'])); ?>')">
                                                        <i class="bi bi-arrow-right-circle me-1"></i> Tạo Redirect
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination cho 404 Logs -->
                        <?php if ($total_log_pages > 1): ?>
                            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                                <small class="text-muted">Trang <?php echo $log_cur_page; ?>/<?php echo $total_log_pages; ?> &mdash; <?php echo $total_logs; ?> lỗi</small>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php if ($log_cur_page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?tab=404&log_page=<?php echo $log_cur_page - 1; ?>">
                                                    <i class="bi bi-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php
                                        $start_p = max(1, $log_cur_page - 2);
                                        $end_p   = min($total_log_pages, $log_cur_page + 2);
                                        for ($p = $start_p; $p <= $end_p; $p++):
                                        ?>
                                            <li class="page-item <?php echo $p === $log_cur_page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?tab=404&log_page=<?php echo $p; ?>">
                                                    <?php echo $p; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($log_cur_page < $total_log_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?tab=404&log_page=<?php echo $log_cur_page + 1; ?>">
                                                    <i class="bi bi-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function fillAddRedirect(oldPath) {
    document.querySelector('input[name="old_path"]').value = oldPath;
    document.querySelector('input[name="new_path"]').focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<?php require_once '../includes/footer.php'; ?>
