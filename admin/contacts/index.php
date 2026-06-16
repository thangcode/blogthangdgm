<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$current_page = 'contacts';
require_once '../includes/header.php';

$success = '';
$error = '';

$allowed_tabs = ['consultation', 'general'];
$active_tab = $_GET['tab'] ?? 'general';
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'general';
}

$contact_page = isset($_GET['contact_page']) ? max(1, (int) $_GET['contact_page']) : 1;
$consultation_page = isset($_GET['consultation_page']) ? max(1, (int) $_GET['consultation_page']) : 1;
$limit = 20;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['tab'])) {
    $posted_tab = in_array((string) $_POST['tab'], $allowed_tabs, true) ? (string) $_POST['tab'] : $active_tab;
    $active_tab = $posted_tab;

    try {
        require_valid_csrf_token();

        if ($_POST['action'] === 'update_contact_status') {
            $id = (int) ($_POST['id'] ?? 0);
            $statusRaw = trim((string) ($_POST['status'] ?? ''));
            $validStatus = ['0', '1', '2'];
            $status = in_array($statusRaw, $validStatus, true) ? $statusRaw : '0';

            $stmt = $pdo->prepare("UPDATE contacts SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $id]);

            if (function_exists('log_activity')) {
                log_activity('update', 'contact', $id, "Cập nhật trạng thái liên hệ chung: $status");
            }

            $success = 'Cập nhật trạng thái liên hệ chung thành công.';
        } elseif ($_POST['action'] === 'delete_contact') {
            $id = (int) ($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM contacts WHERE id = ?");
            $stmt->execute([$id]);

            if (function_exists('log_activity')) {
                log_activity('delete', 'contact', $id, "Xóa liên hệ chung ID: $id");
            }

            $success = 'Đã xóa liên hệ chung #' . $id . ' thành công!';
        } elseif ($_POST['action'] === 'update_consultation_status') {
            $id = (int) ($_POST['id'] ?? 0);
            $statusRaw = trim((string) ($_POST['status'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $validStatus = ['pending', 'contacted', 'completed', 'cancelled'];
            $status = in_array($statusRaw, $validStatus, true) ? $statusRaw : 'pending';

            $stmt = $pdo->prepare("UPDATE product_registrations SET status = ?, notes = ? WHERE id = ?");
            $stmt->execute([$status, $notes, $id]);

            if (function_exists('log_activity')) {
                log_activity('update', 'registration', $id, "Cập nhật trạng thái tư vấn dịch vụ: $status");
            }

            $success = 'Cập nhật trạng thái tư vấn dịch vụ thành công.';
        } elseif ($_POST['action'] === 'delete_consultation') {
            $id = (int) ($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("DELETE FROM product_registrations WHERE id = ?");
            $stmt->execute([$id]);

            if (function_exists('log_activity')) {
                log_activity('delete', 'registration', $id, "Xóa tư vấn dịch vụ ID: $id");
            }

            $success = 'Đã xóa tư vấn dịch vụ #' . $id . ' thành công!';
        }
    } catch (PDOException $e) {
        error_log('Admin contact merge error: ' . $e->getMessage());
        $error = 'Xử lý dữ liệu thất bại. Vui lòng thử lại.';
    }
}

$contact_total_rows = (int) $pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
$contact_total_pages = max(1, (int) ceil($contact_total_rows / $limit));
$contact_offset = ($contact_page - 1) * $limit;
$contact_stmt = $pdo->prepare("SELECT * FROM contacts ORDER BY created_at DESC LIMIT ? OFFSET ?");
$contact_stmt->bindValue(1, $limit, PDO::PARAM_INT);
$contact_stmt->bindValue(2, $contact_offset, PDO::PARAM_INT);
$contact_stmt->execute();
$contacts = $contact_stmt->fetchAll();

// Tư vấn dịch vụ (product_registrations) đã gỡ khỏi blog — giữ rỗng để tương thích giao diện.
$consult_total_rows = 0;
$consult_total_pages = 1;
$consultations = [];

function extract_contact_email(array $row): string
{
    $email = trim((string) ($row['city'] ?? ''));
    if ($email !== '') {
        return $email;
    }

    $message = (string) ($row['message'] ?? '');
    if (preg_match('/(?:^|\R)Email:\\s*([^\\s]+@[^\\s]+)\\s*$/mi', $message, $matches)) {
        return trim($matches[1]);
    }

    return '';
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Quản lý liên hệ</h1>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" id="contactTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a href="?tab=consultation&consultation_page=<?php echo $consultation_page; ?>&contact_page=<?php echo $contact_page; ?>"
                        class="nav-link <?php echo $active_tab === 'consultation' ? 'active' : ''; ?>" role="tab">
                        Tư vấn dịch vụ
                        <span class="badge bg-info text-dark ms-1"><?php echo $consult_total_rows; ?></span>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a href="?tab=general&consultation_page=<?php echo $consultation_page; ?>&contact_page=<?php echo $contact_page; ?>"
                        class="nav-link <?php echo $active_tab === 'general' ? 'active' : ''; ?>" role="tab">
                        Liên hệ chung
                        <span class="badge bg-primary ms-1"><?php echo $contact_total_rows; ?></span>
                    </a>
                </li>
            </ul>

            <?php
            $tab_class_general = $active_tab === 'general' ? 'active show' : '';
            $tab_class_consultation = $active_tab === 'consultation' ? 'active show' : '';
            ?>

            <div class="tab-content">
                <div class="tab-pane fade <?php echo $tab_class_consultation; ?>" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Khách hàng</th>
                                    <th>Dịch vụ</th>
                                    <th>Khu vực</th>
                                    <th>Ngày đăng ký</th>
                                    <th>Trạng thái</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($consultations as $reg): ?>
                                    <?php
                                    $serviceName = !empty($reg['product_name']) ? $reg['product_name'] : 'Tư vấn dịch vụ';
                                    $statusText = [
                                        'pending' => 'Chờ xử lý',
                                        'contacted' => 'Đã liên hệ',
                                        'completed' => 'Hoàn thành',
                                        'cancelled' => 'Đã hủy',
                                    ];
                                    $statusClass = [
                                        'pending' => 'bg-warning text-dark',
                                        'contacted' => 'bg-info text-dark',
                                        'completed' => 'bg-success',
                                        'cancelled' => 'bg-danger',
                                    ];
                                    $rowStatus = (string) ($reg['status'] ?? 'pending');
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $reg['id']; ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo e($reg['fullname'] ?? 'Không rõ'); ?></div>
                                            <div class="small text-muted"><?php echo e($reg['phone'] ?? ''); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo e($serviceName); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo e(ucfirst($reg['district'] ?? '')); ?></div>
                                            <div class="small text-muted"><?php echo e(ucfirst($reg['province'] ?? '')); ?></div>
                                        </td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($reg['created_at'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo $statusClass[$rowStatus] ?? 'bg-secondary'; ?>">
                                                <?php echo $statusText[$rowStatus] ?? $rowStatus; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                    onclick="openConsultationEditModal(<?php echo htmlspecialchars(
                                                        json_encode(
                                                            $reg,
                                                            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>)">
                                                    <i class="bi bi-pencil-square"></i> Chi tiết
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="confirmConsultationDelete(<?php echo (int) $reg['id']; ?>, '<?php echo e((string) ($reg['fullname'] ?? '')); ?>')">
                                                    <i class="bi bi-trash"></i> Xóa
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($consultations)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">Chưa có tư vấn dịch vụ nào.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($consult_total_pages > 1): ?>
                        <nav aria-label="Pagination tư vấn dịch vụ" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php for ($i = 1; $i <= $consult_total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($consultation_page === $i) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?tab=consultation&consultation_page=<?php echo $i; ?>&contact_page=<?php echo $contact_page; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade <?php echo $tab_class_general; ?>" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Khách hàng</th>
                                    <th>Email</th>
                                    <th>Nội dung</th>
                                    <th>Trạng thái</th>
                                    <th>Ngày gửi</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contacts as $row): ?>
                                    <?php
                                    $message = (string) ($row['message'] ?? '');
                                    $messageText = $message !== '' ? $message : '(Không có nội dung)';
                                    $status = (string) ($row['status'] ?? '0');
                                    $statusClass = [
                                        '0' => 'bg-warning text-dark',
                                        '1' => 'bg-info text-dark',
                                        '2' => 'bg-success',
                                    ];
                                    $statusText = [
                                        '0' => 'Mới',
                                        '1' => 'Đã liên hệ',
                                        '2' => 'Đã xử lý',
                                    ];
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $row['id']; ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo e($row['name'] ?? 'Không rõ'); ?></div>
                                            <div class="small text-muted"><?php echo e($row['phone'] ?? ''); ?></div>
                                            <div class="small text-muted">Product ID: <?php echo e((string) ($row['product_id'] ?: 'Không')); ?></div>
                                        </td>
                                        <td><?php echo e(extract_contact_email($row)); ?></td>
                                        <td>
                                            <div style="max-width: 420px;" class="text-truncate" title="<?php echo e($messageText); ?>">
                                                <?php echo nl2br(e($messageText)); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $statusClass[$status] ?? 'bg-secondary'; ?>">
                                                <?php echo $statusText[$status] ?? 'Chưa xác định'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                    onclick="openContactEditModal(<?php echo (int) $row['id']; ?>, '<?php echo e($status); ?>', <?php echo htmlspecialchars(
                                                        json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>)">
                                                    <i class="bi bi-pencil-square"></i> Cập nhật
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="confirmContactDelete(<?php echo (int) $row['id']; ?>, '<?php echo e((string) $row['name']); ?>')">
                                                    <i class="bi bi-trash"></i> Xóa
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($contacts)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">Chưa có liên hệ chung nào.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($contact_total_pages > 1): ?>
                        <nav aria-label="Pagination liên hệ chung" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php for ($i = 1; $i <= $contact_total_pages; $i++): ?>
                                    <li class="page-item <?php echo ($contact_page === $i) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?tab=general&contact_page=<?php echo $i; ?>&consultation_page=<?php echo $consultation_page; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="contactEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cập nhật trạng thái liên hệ chung</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                <input type="hidden" name="tab" value="general">
                <input type="hidden" name="action" value="update_contact_status">
                <input type="hidden" name="id" id="contactEditId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold">Nội dung liên hệ:</label>
                        <p class="mb-1" id="contactViewMessage"></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Trạng thái</label>
                        <select class="form-select" name="status" id="contactEditStatus">
                            <option value="0">Mới</option>
                            <option value="1">Đã liên hệ</option>
                            <option value="2">Đã xử lý</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="contactDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Xác nhận xóa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <p>Bạn có chắc muốn xóa liên hệ chung của <strong id="contactDeleteName"></strong>?</p>
                <p class="text-danger mb-0"><small>Hành động này không thể hoàn tác.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <form method="POST" id="contactDeleteForm" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                    <input type="hidden" name="tab" value="general">
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="id" id="contactDeleteId">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Xóa</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="consultationEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Cập nhật trạng thái tư vấn dịch vụ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                <input type="hidden" name="tab" value="consultation">
                <input type="hidden" name="action" value="update_consultation_status">
                <input type="hidden" name="id" id="consultationEditId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold">Thông tin khách hàng:</label>
                        <p class="mb-1">Họ tên: <span id="consultationViewFullname"></span></p>
                        <p class="mb-1">SĐT: <span id="consultationViewPhone"></span></p>
                        <p class="mb-1">Địa chỉ: <span id="consultationViewAddress"></span></p>
                        <p class="mb-1">Dịch vụ: <span id="consultationViewProduct"></span></p>
                        <p class="mb-1">Ngày gửi: <span id="consultationViewCreatedAt"></span></p>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label">Trạng thái</label>
                        <select class="form-select" name="status" id="consultationEditStatus">
                            <option value="pending">Chờ xử lý</option>
                            <option value="contacted">Đã liên hệ</option>
                            <option value="completed">Hoàn thành</option>
                            <option value="cancelled">Đã hủy</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="consultationEditNotes" class="form-label">Ghi chú</label>
                        <textarea class="form-control" name="notes" id="consultationEditNotes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="consultationDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Xác nhận xóa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <p>Bạn có chắc muốn xóa yêu cầu tư vấn dịch vụ của <strong id="consultationDeleteName"></strong>?</p>
                <p class="text-danger mb-0"><small>Hành động này không thể hoàn tác.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <form method="POST" id="consultationDeleteForm" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                    <input type="hidden" name="tab" value="consultation">
                    <input type="hidden" name="action" value="delete_consultation">
                    <input type="hidden" name="id" id="consultationDeleteId">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Xóa</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function openContactEditModal(id, status, message) {
        document.getElementById('contactEditId').value = id;
        document.getElementById('contactEditStatus').value = status || '0';
        document.getElementById('contactViewMessage').textContent = message || '(Không có nội dung)';
        new bootstrap.Modal(document.getElementById('contactEditModal')).show();
    }

    function confirmContactDelete(id, name) {
        document.getElementById('contactDeleteId').value = id;
        document.getElementById('contactDeleteName').textContent = name || 'Khách hàng';
        new bootstrap.Modal(document.getElementById('contactDeleteModal')).show();
    }

    function openConsultationEditModal(data) {
        const createdAt = data.created_at ? new Date(data.created_at.replace(' ', 'T')) : null;
        const createdAtText = createdAt && !Number.isNaN(createdAt.getTime())
            ? createdAt.toLocaleDateString('vi-VN') + ' ' + createdAt.toLocaleTimeString('vi-VN', {
                hour: '2-digit',
                minute: '2-digit'
            })
            : (data.created_at || '');

        document.getElementById('consultationEditId').value = data.id;
        document.getElementById('consultationEditStatus').value = data.status || 'pending';
        document.getElementById('consultationEditNotes').value = data.notes || '';

        document.getElementById('consultationViewFullname').textContent = data.fullname || '';
        document.getElementById('consultationViewPhone').textContent = data.phone || '';
        const address = [data.address, data.district, data.province].filter(Boolean).join(', ');
        document.getElementById('consultationViewAddress').textContent = address || 'Không có địa chỉ';
        document.getElementById('consultationViewProduct').textContent = data.product_name || 'Tư vấn dịch vụ';
        document.getElementById('consultationViewCreatedAt').textContent = createdAtText;

        new bootstrap.Modal(document.getElementById('consultationEditModal')).show();
    }

    function confirmConsultationDelete(id, name) {
        document.getElementById('consultationDeleteId').value = id;
        document.getElementById('consultationDeleteName').textContent = name || 'Khách hàng';
        new bootstrap.Modal(document.getElementById('consultationDeleteModal')).show();
    }

</script>

<?php require_once '../includes/footer.php'; ?>
