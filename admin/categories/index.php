<?php
// admin/categories/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$has_category_sort_order = has_table_column($pdo, 'categories', 'sort_order');
if (!has_table_column($pdo, 'categories', 'deleted_at')) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status");
    } catch (Exception $e) {
        // Keep page usable if migration fails
    }
}
if (!has_table_column($pdo, 'categories', 'deleted_by')) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN deleted_by INT NULL DEFAULT NULL AFTER deleted_at");
    } catch (Exception $e) {
        // Keep page usable if migration fails
    }
}

if (isset($_POST['update_sort_order'])) {
    header('Content-Type: application/json');
    if (!is_admin_logged_in()) {
        echo json_encode(['success' => false, 'error' => 'Chưa đăng nhập quản trị']);
        exit;
    }
    require_valid_csrf_token(true);
    if (!$has_category_sort_order) {
        echo json_encode(['success' => false, 'error' => 'Không tìm thấy cột sort_order']);
        exit;
    }

    $id = (int) ($_POST['category_id'] ?? 0);
    $sort_order = (int) ($_POST['sort_order'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID danh mục không hợp lệ']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE categories SET sort_order = ? WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$sort_order, $id]);
        if (function_exists('log_activity')) {
            log_activity('update', 'category', $id, "Cập nhật sort_order: $sort_order");
        }
        echo json_encode(['success' => true, 'sort_order' => $sort_order]);
    } catch (PDOException $e) {
        error_log('Category sort order error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Không thể lưu thứ tự danh mục']);
    }
    exit;
}

// Handle Delete
if (isset($_POST['delete_id'])) {
    require_valid_csrf_token();
    $id = (int) ($_POST['delete_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $categoryName = $stmt->fetchColumn();
        if ($categoryName === false) {
            throw new RuntimeException('Danh mục không tồn tại hoặc đã nằm trong thùng rác.');
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE parent_id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $childCount = (int) $stmt->fetchColumn();
        if ($childCount > 0) {
            throw new RuntimeException('Không thể xóa danh mục cha vì còn danh mục con bên trong.');
        }

        $stmt = $pdo->prepare("UPDATE categories SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$_SESSION['user_id'] ?? null, $id]);
        if (function_exists('log_activity')) {
            log_activity('delete', 'category', $id, "Soft delete category: {$categoryName}");
        }
        header('Location: trash.php?moved=1&name=' . rawurlencode($categoryName));
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Category delete error: ' . $e->getMessage());
        $error = 'Lỗi khi xóa danh mục.';
    }
}

$current_page = 'categories';
require_once '../includes/header.php';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
?>

<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Quản lý Danh mục</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="trash.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-archive"></i> Thùng rác
            </a>
            <a href="add.php" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Thêm mới
            </a>
        </div>
    </div>

    <!-- Search Box -->
    <div class="row mb-3">
        <div class="col-md-4">
            <form method="GET" action="" class="d-flex gap-2">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" placeholder="Tìm kiếm danh mục..."
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

    <script>const CAT_CSRF = <?php echo json_encode(generate_csrf_token()); ?>;</script>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="badge bg-light text-dark border"><i class="bi bi-check2-square me-1"></i><span id="catSelCount">0</span> danh mục đã chọn</span>
        <button type="button" class="btn btn-sm btn-primary" id="btnCatBulkRewrite"><i class="bi bi-pencil-square me-1"></i>Viết nội dung SEO+GEO</button>
        <button type="button" class="btn btn-sm btn-success" id="btnCatBulkAll"><i class="bi bi-stars me-1"></i>Viết nội dung + Tự động SEO</button>
        <span class="text-muted small">Nút 1: chỉ viết nội dung. Nút 2: viết nội dung + điền SEO. Chọn nhiều để chạy hàng loạt.</span>
    </div>

    <div class="table-responsive bg-white rounded shadow-sm p-3">
        <table class="table table-hover align-middle" id="categoryTable">
            <thead>
                <tr>
                    <th scope="col" width="34"><input type="checkbox" id="catCheckAll" class="form-check-input" title="Chọn tất cả"></th>
                    <th scope="col" width="60">ID</th>
                    <?php if ($has_category_sort_order): ?>
                        <th scope="col" width="90">Thứ tự</th>
                    <?php endif; ?>
                    <th scope="col">Tên danh mục</th>
                    <th scope="col">Slug</th>
                    <th scope="col">Mô tả</th>
                    <th scope="col" width="100">Trạng thái</th>
                    <th scope="col" class="text-end" width="120">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Fetch all categories
                try {
                    $category_order_by = $has_category_sort_order
                        ? "ORDER BY parent_id ASC, sort_order ASC, name ASC"
                        : "ORDER BY parent_id ASC, name ASC";

                    if ($search) {
                        $stmt = $pdo->prepare("SELECT * FROM categories WHERE deleted_at IS NULL AND (name LIKE ? OR slug LIKE ? OR description LIKE ?) {$category_order_by}");
                        $searchTerm = "%{$search}%";
                        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
                    } else {
                        $stmt = $pdo->query("SELECT * FROM categories WHERE deleted_at IS NULL {$category_order_by}");
                    }
                    $categories = $stmt->fetchAll();

                    // Build parent name lookup
                    $parentNames = [];
                    foreach ($categories as $cat) {
                        $parentNames[$cat['id']] = $cat['name'];
                    }

                    function display_category_row($categories, $parentNames, $has_category_sort_order, $parent_id = null, $level = 0)
                    {
                        foreach ($categories as $row) {
                            if ($row['parent_id'] == $parent_id) {
                                // Visual hierarchy styling
                                $bgClass = $level === 0 ? 'table-light' : '';
                                $indent = '';
                                $levelBadge = '';

                                if ($level > 0) {
                                    $indent = '<span class="text-muted me-2">' . str_repeat('│ ', $level - 1) . '└─</span>';
                                } else {
                                    $indent = '';
                                }

                                $status_badge = $row['status']
                                    ? '<span class="badge bg-success">Hiển thị</span>'
                                    : '<span class="badge bg-secondary">Ẩn</span>';

                                // Get parent name for display
                                $parentInfo = '';
                                if ($row['parent_id'] && isset($parentNames[$row['parent_id']])) {
                                    $parentInfo = '<div class="small text-muted mt-1"><i class="bi bi-folder2"></i> ' . e($parentNames[$row['parent_id']]) . '</div>';
                                }

                                echo "<tr class='{$bgClass}' data-category-name='" . e($row['name']) . "'>";
                                echo "<td><input type='checkbox' class='form-check-input cat-check' value='" . (int) $row['id'] . "'></td>";
                                echo "<td class='fw-bold text-muted'>#{$row['id']}</td>";
                                if ($has_category_sort_order) {
                                    echo "<td>
                                            <div class='d-flex align-items-center gap-2'>
                                                <input
                                                    type='number'
                                                    class='form-control form-control-sm category-sort-order-input'
                                                    style='max-width: 78px;'
                                                    min='0'
                                                    value='" . (int) ($row['sort_order'] ?? 0) . "'
                                                    data-id='" . (int) $row['id'] . "'>
                                                <span class='sort-order-status text-muted small d-none'>Đã lưu</span>
                                            </div>
                                          </td>";
                                }
                                echo "<td>
                                        <div class='d-flex align-items-center'>
                                            {$indent}
                                            <div>
                                                <strong>" . e($row['name']) . "</strong>
                                                {$parentInfo}
                                            </div>
                                        </div>
                                      </td>";
                                echo "<td><code class='bg-light px-2 py-1 rounded'>" . e($row['slug']) . "</code></td>";
                                echo "<td class='text-muted'>" . e(mb_substr($row['description'], 0, 40)) . (strlen($row['description']) > 40 ? '...' : '') . "</td>";
                                echo "<td>{$status_badge}</td>";
                                echo '<td class="text-end">
                                        <a href="edit.php?id=' . $row['id'] . '" class="btn btn-sm btn-outline-primary me-1" title="Chỉnh sửa">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" class="d-inline" data-confirm="&#66;&#7841;n c&#243; ch&#7855;c mu&#7889;n chuy&#7875;n danh m&#7909;c &quot;' . e($row['name']) . '&quot; v&#224;o th&#249;ng r&#225;c?" data-confirm-title="X&#243;a danh m&#7909;c" data-confirm-ok="Chuy&#7875;n v&#224;o th&#249;ng r&#225;c" data-confirm-class="btn-danger">
                                            <input type="hidden" name="csrf_token" value="' . e(generate_csrf_token()) . '">
                                            <input type="hidden" name="delete_id" value="' . $row['id'] . '">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Xóa">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                      </td>';
                                echo "</tr>";

                                // Recursive call for children
                                display_category_row($categories, $parentNames, $has_category_sort_order, $row['id'], $level + 1);
                            }
                        }
                    }

                    if (count($categories) > 0) {
                        display_category_row($categories, $parentNames, $has_category_sort_order);
                    } else {
                        if ($search) {
                            echo "<tr><td colspan='" . ($has_category_sort_order ? 7 : 6) . "' class='text-center py-4'>
                                    <i class='bi bi-search fs-1 text-muted d-block mb-2'></i>
                                    Không tìm thấy danh mục nào với từ khóa \"<strong>" . e($search) . "</strong>\"
                                  </td></tr>";
                        } else {
                            echo "<tr><td colspan='" . ($has_category_sort_order ? 7 : 6) . "' class='text-center py-4'>
                                    <i class='bi bi-folder-x fs-1 text-muted d-block mb-2'></i>
                                    Chưa có danh mục nào. <a href='add.php'>Thêm mới</a>
                                  </td></tr>";
                        }
                    }

                } catch (PDOException $e) {
                    echo "<tr><td colspan='" . ($has_category_sort_order ? 7 : 6) . "' class='text-center'>Lỗi tải dữ liệu: " . $e->getMessage() . "</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <!-- Category Stats -->
    <?php
    try {
        $totalCategories = $pdo->query("SELECT COUNT(*) FROM categories WHERE deleted_at IS NULL")->fetchColumn();
        $parentCategories = $pdo->query("SELECT COUNT(*) FROM categories WHERE parent_id IS NULL AND deleted_at IS NULL")->fetchColumn();
        $childCategories = $totalCategories - $parentCategories;
        $activeCategories = $pdo->query("SELECT COUNT(*) FROM categories WHERE status = 1 AND deleted_at IS NULL")->fetchColumn();
        ?>
        <div class="row mt-4">
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 bg-light">
                    <div class="card-body py-2 text-center">
                        <div class="text-muted small">Tổng danh mục</div>
                        <div class="fw-bold text-primary fs-5"><?php echo $totalCategories; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 bg-light">
                    <div class="card-body py-2 text-center">
                        <div class="text-muted small">Danh mục cha</div>
                        <div class="fw-bold text-info fs-5"><?php echo $parentCategories; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 bg-light">
                    <div class="card-body py-2 text-center">
                        <div class="text-muted small">Danh mục con</div>
                        <div class="fw-bold text-secondary fs-5"><?php echo $childCategories; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card border-0 bg-light">
                    <div class="card-body py-2 text-center">
                        <div class="text-muted small">Đang hiển thị</div>
                        <div class="fw-bold text-success fs-5"><?php echo $activeCategories; ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php } catch (PDOException $e) { /* Silent fail for stats */
    } ?>
</div>

<style>
    #categoryTable tbody tr:hover {
        background-color: rgba(13, 110, 253, 0.05) !important;
    }

    #categoryTable code {
        font-size: 12px;
    }
</style>

<script>
document.querySelectorAll('.category-sort-order-input').forEach(function(input) {
    let lastValue = String(input.value);
    let saving = false;

    function setStatus(message, isError) {
        const statusEl = input.parentElement.querySelector('.sort-order-status');
        if (!statusEl) return;
        statusEl.classList.remove('d-none', 'text-muted', 'text-danger', 'text-success');
        statusEl.classList.add(isError ? 'text-danger' : 'text-success');
        statusEl.textContent = message;

        if (!isError) {
            setTimeout(function() {
                statusEl.classList.add('d-none');
            }, 1200);
        }
    }

    function saveSortOrder() {
        if (saving) return;

        const categoryId = input.dataset.id;
        const value = parseInt(input.value, 10);
        const sortOrder = Number.isNaN(value) ? 0 : Math.max(0, value);
        input.value = sortOrder;

        if (String(sortOrder) === String(lastValue)) return;

        saving = true;
        input.disabled = true;
        setStatus('Đang lưu...', false);

        const body = new URLSearchParams({
            update_sort_order: '1',
            category_id: categoryId,
            sort_order: String(sortOrder),
            csrf_token: AdminSecurity.csrfToken()
        });

        fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                input.value = lastValue;
                setStatus('Lỗi lưu', true);
                alert('Lỗi: ' + (data.error || 'Không thể cập nhật thứ tự'));
                return;
            }
            lastValue = String(sortOrder);
            setStatus('Đã lưu', false);
        })
        .catch(() => {
            input.value = lastValue;
            setStatus('Lỗi kết nối', true);
            alert('Lỗi kết nối server');
        })
        .finally(() => {
            saving = false;
            input.disabled = false;
        });
    }

    input.addEventListener('change', saveSortOrder);
    input.addEventListener('blur', saveSortOrder);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveSortOrder();
        }
    });
});
</script>

<!-- Modal: Tiến trình AI danh mục -->
<div class="modal fade" id="catAiProgressModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="catAiTitle">Đang xử lý AI...</h5></div>
      <div class="modal-body">
        <div class="progress mb-2" style="height:22px;">
          <div class="progress-bar progress-bar-striped progress-bar-animated" id="catAiBar" style="width:0%">0%</div>
        </div>
        <div class="small text-muted mb-2" id="catAiStatus">Chuẩn bị...</div>
        <div id="catAiLog" style="max-height:240px;overflow:auto;font-size:.82rem;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" id="catAiClose" data-bs-dismiss="modal" disabled>Đóng</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('catCheckAll');
    const selCount = document.getElementById('catSelCount');
    function checks() { return Array.from(document.querySelectorAll('.cat-check')); }
    function selectedIds() { return checks().filter(c => c.checked).map(c => parseInt(c.value, 10)); }
    function updateCount() { if (selCount) selCount.textContent = selectedIds().length; }
    if (checkAll) checkAll.addEventListener('change', () => { checks().forEach(c => c.checked = checkAll.checked); updateCount(); });
    checks().forEach(c => c.addEventListener('change', updateCount));

    const pm = document.getElementById('catAiProgressModal');
    const pmModal = { show() { if (typeof bootstrap !== 'undefined' && pm) bootstrap.Modal.getOrCreateInstance(pm).show(); } };
    const bar = document.getElementById('catAiBar');
    const statusEl = document.getElementById('catAiStatus');
    const logEl = document.getElementById('catAiLog');
    const closeBtn = document.getElementById('catAiClose');
    function setBar(d, t) { const p = t ? Math.round(d / t * 100) : 0; bar.style.width = p + '%'; bar.textContent = p + '%'; }
    function logLine(id, title, ok, msg) {
        const cls = ok ? 'text-success' : 'text-danger', ico = ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
        logEl.insertAdjacentHTML('beforeend', `<div class="${cls}"><i class="bi ${ico} me-1"></i>#${id} ${title ? '— ' + title : ''}: ${msg}</div>`);
        logEl.scrollTop = logEl.scrollHeight;
    }
    async function runBulk(action, label) {
        const ids = selectedIds();
        if (!ids.length) { alert('Vui lòng chọn ít nhất 1 danh mục.'); return; }
        document.getElementById('catAiTitle').textContent = label + ' (' + ids.length + ' danh mục)';
        logEl.innerHTML = ''; setBar(0, ids.length); statusEl.textContent = 'Bắt đầu...';
        closeBtn.disabled = true; pmModal.show();
        let ok = 0, fail = 0;
        for (let i = 0; i < ids.length; i++) {
            statusEl.textContent = `Đang xử lý ${i + 1}/${ids.length} (ID ${ids[i]})...`;
            try {
                const body = new URLSearchParams({ action, id: ids[i], save: '1', csrf_token: CAT_CSRF });
                const r = await fetch('../ajax/category-ai.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
                const d = await r.json();
                if (d.success) { ok++; logLine(ids[i], d.title || '', true, d.message || 'OK'); }
                else { fail++; logLine(ids[i], d.title || '', false, d.message || 'Lỗi'); }
            } catch (e) { fail++; logLine(ids[i], '', false, 'Lỗi kết nối'); }
            setBar(i + 1, ids.length);
        }
        statusEl.innerHTML = `<strong>Hoàn tất:</strong> ${ok} thành công, ${fail} lỗi.`;
        closeBtn.disabled = false;
    }
    const br = document.getElementById('btnCatBulkRewrite');
    const bs = document.getElementById('btnCatBulkAll');
    if (br) br.addEventListener('click', () => runBulk('rewrite', 'Viết nội dung SEO+GEO'));
    if (bs) bs.addEventListener('click', () => runBulk('all', 'Viết nội dung + Tự động SEO'));
});
</script>

<?php require_once '../includes/footer.php'; ?>

