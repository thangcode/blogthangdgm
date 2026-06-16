<?php
// admin/faq/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check login
require_admin_login();

$current_page = 'faq';
require_once '../includes/header.php';

// Handle Delete
if (isset($_POST['delete_id'])) {
    $id = $_POST['delete_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM faqs WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Đã xóa FAQ thành công.";
    } catch (PDOException $e) {
        $error = "Lỗi khi xóa: " . $e->getMessage();
    }
}
?>

<div class="container-fluid py-4">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 fw-bold text-dark">Quản lý Câu hỏi thường gặp (FAQ)</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="add.php" class="btn btn-primary rounded-pill px-4 shadow-sm border-0"
                style="background: var(--primary-gradient);">
                <i class="bi bi-plus-lg me-2"></i> Thêm mới FAQ
            </a>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm border-0" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 py-3" style="width:44px;"></th>
                            <th class="ps-2 py-3 text-uppercase small fw-bold text-muted" style="width: 70px;">ID</th>
                            <th class="py-3 text-uppercase small fw-bold text-muted">Câu hỏi &amp; Câu trả lời</th>
                            <th class="py-3 text-uppercase small fw-bold text-muted" style="width: 100px;">Thứ tự</th>
                            <th class="py-3 text-uppercase small fw-bold text-muted" style="width: 120px;">Trạng thái
                            </th>
                            <th class="py-3 text-uppercase small fw-bold text-muted pe-4 text-end"
                                style="width: 150px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            $stmt = $pdo->query("SELECT * FROM faqs ORDER BY sort_order ASC, id DESC");
                            while ($row = $stmt->fetch()) {
                                $is_active = (bool) $row['status'];
                                $status_btn = '<button type="button" class="btn btn-sm rounded-pill px-3 faq-toggle-btn '
                                    . ($is_active ? 'btn-success' : 'btn-secondary') . '"
                                    data-id="' . $row['id'] . '"
                                    title="Click để đổi trạng thái">
                                    <i class="bi bi-' . ($is_active ? 'eye' : 'eye-slash') . ' me-1"></i>' .
                                    ($is_active ? 'Hiện' : 'Ẩn') . '</button>';

                                ?>
                                <tr data-id="<?php echo $row['id']; ?>" class="faq-row">
                                    <td class="ps-3 drag-handle" style="cursor:grab;color:#adb5bd;font-size:1.1rem;"
                                        title="Kéo để sắp xếp">
                                        <i class="bi bi-grip-vertical"></i>
                                    </td>
                                    <td class="ps-2 text-muted"><?php echo $row['id']; ?></td>
                                    <td>
                                        <div class="fw-bold text-dark mb-1"><?php echo e($row['question']); ?></div>
                                        <div class="small text-muted text-truncate" style="max-width: 400px;">
                                            <?php echo e(mb_substr(strip_tags($row['answer']), 0, 100)) . '...'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            class="badge bg-light text-dark border rounded-pill px-3 sort-badge"><?php echo $row['sort_order']; ?></span>
                                    </td>
                                    <td><?php echo $status_btn; ?></td>
                                    <td class="pe-4 text-end">
                                        <div class="btn-group">
                                            <a href="edit.php?id=<?php echo $row['id']; ?>"
                                                class="btn btn-sm btn-light rounded-pill px-3 border me-2" title="Chỉnh sửa">
                                                <i class="bi bi-pencil text-primary"></i>
                                            </a>
                                            <form method="POST" class="d-inline" data-confirm="Bạn có chắc muốn xóa FAQ này?"
                                                data-confirm-title="Xóa FAQ" data-confirm-ok="Xóa ngay"
                                                data-confirm-class="btn-danger">
                                                <input type="hidden" name="delete_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-light rounded-pill px-3 border"
                                                    title="Xóa">
                                                    <i class="bi bi-trash text-danger"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                            if ($stmt->rowCount() == 0) {
                                echo "<tr><td colspan='6' class='text-center py-5 text-muted'>Chưa có dữ liệu FAQ nào.</td></tr>";
                            }
                        } catch (PDOException $e) {
                            echo "<tr><td colspan='5' class='text-center py-5 text-danger'>Lỗi tải dữ liệu: " . $e->getMessage() . "</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
    (function () {
        const tbody = document.querySelector('table tbody');
        if (!tbody) return;

        // Toast element
        const toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;min-width:220px;display:none;';
        document.body.appendChild(toast);

        function showToast(msg, type) {
            toast.innerHTML = `<div class="alert alert-${type} shadow d-flex align-items-center gap-2 mb-0 py-2 px-3 rounded-3">
            <i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'x-circle-fill text-danger'}"></i>
            <span>${msg}</span></div>`;
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 2500);
        }

        Sortable.create(tbody, {
            handle: '.drag-handle',
            animation: 180,
            ghostClass: 'table-active',
            onEnd: function () {
                const rows = tbody.querySelectorAll('tr[data-id]');
                const order = Array.from(rows).map(r => r.dataset.id);

                // Cập nhật hiển thị số thứ tự
                rows.forEach((r, i) => {
                    const badge = r.querySelector('.sort-badge');
                    if (badge) badge.textContent = i + 1;
                });

                fetch('../ajax/faq-reorder.php', {
                    method: 'POST',
                    headers: AdminSecurity.headers({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ order, csrf_token: AdminSecurity.csrfToken() })
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) showToast('Đã lưu thứ tự!', 'success');
                        else showToast('Lỗi lưu thứ tự!', 'danger');
                    })
                    .catch(() => showToast('Lỗi kết nối!', 'danger'));
            }
        });

        // ── Toggle Hiện/Ẩn inline ──
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.faq-toggle-btn');
            if (!btn) return;

            const id = btn.dataset.id;
            btn.disabled = true;

            const fd = new FormData();
            AdminSecurity.applyCsrf(fd);
            fd.append('id', id);

            fetch('../ajax/faq-toggle.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (data.success) {
                        const isNowActive = data.status === 1;
                        btn.className = 'btn btn-sm rounded-pill px-3 faq-toggle-btn ' + (isNowActive ? 'btn-success' : 'btn-secondary');
                        btn.innerHTML = '<i class="bi bi-' + (isNowActive ? 'eye' : 'eye-slash') + ' me-1"></i>' + (isNowActive ? 'Hiện' : 'Ẩn');
                        showToast(isNowActive ? 'Đã bật hiển thị!' : 'Đã ẩn FAQ!', 'success');
                    } else {
                        showToast('Lỗi đổi trạng thái!', 'danger');
                    }
                })
                .catch(() => { btn.disabled = false; showToast('Lỗi kết nối!', 'danger'); });
        });

    })();
</script>
