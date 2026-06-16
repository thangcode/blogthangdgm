<?php
// admin/backup/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/backup-manager.php';

require_admin_login();

$backupManager = new BackupManager($pdo);
$backups = $backupManager->getBackups();

$page_title = "Quản lý Sao lưu";
$current_page = 'backup';
include '../includes/header.php';
?>

<style>
    .backup-card {
        border: none;
        border-radius: 12px;
        transition: all 0.3s ease;
        background: #fff;
    }

    .backup-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05) !important;
    }

    .btn-backup-start {
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        border: none;
        padding: 0.8rem 1.5rem;
        font-weight: 600;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
    }

    .btn-backup-start:hover {
        background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
        transform: scale(1.02);
        box-shadow: 0 5px 15px rgba(99, 102, 241, 0.4);
    }

    .progress-wrapper {
        padding: 1.5rem;
    }

    .progress {
        height: 12px;
        border-radius: 10px;
        background-color: #f1f5f9;
        overflow: visible;
        margin-bottom: 2rem;
    }

    .progress-bar {
        border-radius: 10px;
        background: linear-gradient(90deg, #6366f1 0%, #818cf8 100%);
        position: relative;
        transition: width 0.6s ease;
    }

    .progress-bar::after {
        content: '';
        position: absolute;
        right: -6px;
        top: -4px;
        width: 20px;
        height: 20px;
        background: #fff;
        border: 4px solid #6366f1;
        border-radius: 50%;
        box-shadow: 0 0 10px rgba(99, 102, 241, 0.5);
    }

    .status-step {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 1rem;
        color: #64748b;
        font-size: 0.95rem;
        transition: all 0.3s ease;
    }

    .status-step.active {
        color: #1e293b;
        font-weight: 600;
    }

    .status-step i {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
    }

    .status-step.active i {
        background: #6366f1;
        color: #fff;
        box-shadow: 0 0 10px rgba(99, 102, 241, 0.3);
    }

    .status-step.completed i {
        background: #22c55e;
        color: #fff;
    }

    .backup-icon-large {
        font-size: 3.5rem;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        width: 100px;
        height: 100px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 24px;
        margin: 0 auto 1.5rem;
        color: #6366f1;
    }

    /* Fix dropdown clipping in responsive tables */
    .table-responsive {
        overflow: visible !important;
    }

    .dropdown-menu {
        z-index: 1060;
        /* Above table sticky headers if any */
    }
</style>

<div class="container-fluid px-4">
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <h1 class="h3 mb-1 fw-bold text-dark">Hệ thống Sao lưu</h1>
            <p class="text-muted">Đóng gói toàn bộ mã nguồn và cơ sở dữ liệu để lưu trữ hoặc di chuyển hosting.</p>
        </div>
        <div class="col-md-4 text-md-end">
            <button
                onclick="alert('Mật khẩu giải nén của bạn là: ' + '<?php echo defined('BACKUP_PASSWORD') ? BACKUP_PASSWORD : 'Chưa thiết lập'; ?>')"
                class="btn btn-outline-secondary me-2">
                <i class="bi bi-key-fill me-2"></i> Lấy mật khẩu
            </button>
            <button id="btnCreateBackup" class="btn btn-primary btn-backup-start shadow-sm">
                <i class="bi bi-cloud-plus-fill me-2"></i> Khởi tạo sao lưu
            </button>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-9 col-lg-8">
            <div class="card backup-card shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="m-0 fw-bold text-dark">Lịch sử sao lưu</h6>
                        <span
                            class="badge bg-soft-primary text-primary px-3 py-2 rounded-pill"><?php echo count($backups); ?>
                            bản lưu</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 py-3 border-0">Tên bản lưu</th>
                                    <th class="py-3 border-0">Ngày tạo</th>
                                    <th class="py-3 border-0">Dung lượng</th>
                                    <th class="text-center py-3 border-0 pe-4">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($backups)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5 border-0">
                                            <div class="backup-icon-large">
                                                <i class="bi bi-folder2-open"></i>
                                            </div>
                                            <h5 class="fw-bold">Chưa có bản sao lưu</h5>
                                            <p class="text-muted small">Nhấn <b>Khởi tạo sao lưu</b> để bắt đầu bản lưu đầu
                                                tiên.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($backups as $backup): ?>
                                        <tr>
                                            <td class="ps-4 border-0" style="width: 45%;">
                                                <div class="d-flex align-items-center py-1">
                                                    <i class="bi bi-file-earmark-zip-fill text-primary fs-4 me-3"></i>
                                                    <div>
                                                        <div class="fw-bold text-dark mb-0" style="word-break: break-all;">
                                                            <?php echo e($backup['filename']); ?></div>
                                                        <div class="text-muted extra-small d-flex align-items-center">
                                                            <span class="text-success me-2"><i
                                                                    class="bi bi-shield-lock-fill"></i> Được mã hóa
                                                                (AES-256)</span>
                                                            <span class="text-muted border-start ps-2">Toàn bộ trang web</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="border-0" style="width: 25%;">
                                                <?php echo date("d/m/Y H:i", strtotime($backup['date'])); ?>
                                            </td>
                                            <td class="border-0" style="width: 15%;">
                                                <span
                                                    class="badge bg-light text-dark fw-normal border"><?php echo number_format($backup['size'] / 1048576, 2); ?>
                                                    MB</span>
                                            </td>
                                            <td class="text-center border-0 pe-4" style="width: 15%;">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-light border dropdown-toggle no-caret"
                                                        type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                                        data-bs-boundary="viewport">
                                                        <i class="bi bi-three-dots-vertical"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li><a class="dropdown-item py-2 no-loader"
                                                                href="../ajax/backup.php?action=download&filename=<?php echo urlencode($backup['filename']); ?>"><i
                                                                    class="bi bi-download me-2 text-success"></i> Tải về</a>
                                                        </li>
                                                        <li><a class="dropdown-item py-2 btn-restore-backup"
                                                                href="javascript:void(0)"
                                                                data-filename="<?php echo e($backup['filename']); ?>"><i
                                                                    class="bi bi-arrow-counterclockwise me-2 text-primary"></i> Khôi phục</a>
                                                        </li>
                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                        <li><a class="dropdown-item py-2 text-danger btn-delete-backup"
                                                                href="javascript:void(0)"
                                                                data-filename="<?php echo e($backup['filename']); ?>"><i
                                                                    class="bi bi-trash me-2"></i> Xóa vĩnh viễn</a></li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4">
            <div class="card backup-card border-0 shadow-sm mb-4 bg-primary text-white p-3">
                <div class="card-body">
                    <h5 class="fw-bold mb-3"><i class="bi bi-shield-check me-2"></i>An toàn & Bảo mật</h5>
                    <p class="small opacity-80">Hệ thống sử dụng nén Zip tiêu chuẩn. File backup bao gồm database và mã
                        nguồn, có thể dùng để khôi phục nhanh chóng.</p>
                </div>
            </div>

            <div class="card backup-card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Thông tin thư mục</h6>
                    <div class="d-flex justify-content-between mb-2 small">
                        <span class="text-muted">Đường dẫn:</span>
                        <span class="fw-600 text-dark">/backups</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 small">
                        <span class="text-muted">Bảo mật:</span>
                        <span class="text-success fw-bold">Active (.htaccess)</span>
                    </div>
                    <hr>
                    <p class="extra-small text-muted mb-0"><i class="bi bi-info-circle me-1"></i> Lưu ý: Hãy tải bản lưu
                        về máy và xóa trên server để tránh chiếm bộ nhớ.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Progress (Modern Redesign) -->
<div class="modal fade" id="backupProgressModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="backup-icon-large pulse-animation">
                        <i class="bi bi-cloud-arrow-up text-primary"></i>
                    </div>
                    <h4 class="fw-bold mb-2">Đang xử lý dữ liệu</h4>
                    <p class="text-muted" id="status-main-msg">Vui lòng chờ giây lát...</p>
                </div>

                <div class="progress-wrapper">
                    <div class="progress">
                        <div id="progress-bar-el" class="progress-bar progress-bar-striped progress-bar-animated"
                            role="progressbar" style="width: 0%"></div>
                    </div>

                    <div class="status-steps-list">
                        <div id="step-db" class="status-step">
                            <i class="bi bi-database"></i>
                            <span>Xuất cơ sở dữ liệu SQL</span>
                        </div>
                        <div id="step-zip" class="status-step">
                            <i class="bi bi-file-earmark-zip"></i>
                            <span>Nén mã nguồn & hình ảnh</span>
                        </div>
                        <div id="step-final" class="status-step">
                            <i class="bi bi-check-circle"></i>
                            <span>Hoàn tất & Dọn dẹp</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .bg-soft-primary {
        background-color: rgba(99, 102, 241, 0.1);
    }

    .extra-small {
        font-size: 0.75rem;
    }

    .fw-600 {
        font-weight: 600;
    }

    .no-caret::after {
        display: none;
    }

    @keyframes pulse {
        0% {
            transform: scale(1);
            opacity: 1;
        }

        50% {
            transform: scale(1.05);
            opacity: 0.8;
        }

        100% {
            transform: scale(1);
            opacity: 1;
        }
    }

    .pulse-animation {
        animation: pulse 2s infinite ease-in-out;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const btnCreate = document.getElementById('btnCreateBackup');
        const modalEl = document.getElementById('backupProgressModal');
        const progressModal = new bootstrap.Modal(modalEl);
        const progressBar = document.getElementById('progress-bar-el');
        const statusMsg = document.getElementById('status-main-msg');

        let timer = null;

        btnCreate.addEventListener('click', function () {
            if (!confirm('Bạn có muốn tạo bản sao lưu toàn bộ trang web ngay bây giờ?')) return;

            // Reset UI
            progressBar.style.width = '0%';
            statusMsg.innerText = 'Đang khởi tạo...';
            document.querySelectorAll('.status-step').forEach(s => s.classList.remove('active', 'completed'));

            progressModal.show();

            // Hide the global system loader that might have been triggered by fetch
            if (window.AdminLoader) window.AdminLoader.hide();

            // Start Polling
            timer = setInterval(pollStatus, 800);

            // Start Backup
            fetch('../ajax/backup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'create',
                    csrf_token: AdminSecurity.csrfToken()
                }).toString()
            })
                .then(r => r.json())
                .then(data => {
                    clearInterval(timer);
                    if (data.success) {
                        progressBar.style.width = '100%';
                        statusMsg.innerText = 'Hoàn thành!';
                        document.getElementById('step-final').classList.add('completed');
                        if (window.AdminPopup) window.AdminPopup.success('Đã tạo bản sao lưu thành công');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        progressModal.hide();
                        if (window.AdminPopup) window.AdminPopup.error(data.message || 'Không thể tạo bản sao lưu');
                    }
                })
                .catch(err => {
                    clearInterval(timer);
                    progressModal.hide();
                    if (window.AdminPopup) window.AdminPopup.error('Lỗi kết nối server');
                });
        });

        function pollStatus() {
            // Use GET to bypass the global fetch loader in admin/includes/footer.php
            fetch('../ajax/backup.php?action=get_status', {
                method: 'GET'
            })
                .then(r => r.json())
                .then(status => {
                    progressBar.style.width = status.percent + '%';
                    statusMsg.innerText = status.message;

                    // Step Logic
                    if (status.percent >= 15) {
                        document.getElementById('step-db').classList.add('active');
                    }
                    if (status.percent >= 40) {
                        document.getElementById('step-db').classList.add('completed');
                        document.getElementById('step-zip').classList.add('active');
                    }
                    if (status.percent >= 90) {
                        document.getElementById('step-zip').classList.add('completed');
                        document.getElementById('step-final').classList.add('active');
                    }
                })
                .catch(() => {
                    // Silence errors during polling
                });
        }

        // Delete handling
        document.querySelectorAll('.btn-delete-backup').forEach(btn => {
            btn.addEventListener('click', function () {
                const filename = this.dataset.filename;
                if (!confirm('Bạn có chắc chắn muốn xóa bản sao lưu này vĩnh viễn?')) return;

                fetch('../ajax/backup.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'delete',
                        filename: filename,
                        csrf_token: AdminSecurity.csrfToken()
                    }).toString()
                })
                    .then(r => r.json())
                    .then(data => {
                        if (window.AdminLoader) window.AdminLoader.hide();
                        if (data.success) {
                            if (window.AdminPopup) window.AdminPopup.success('Đã xóa bản sao lưu');
                            this.closest('tr').remove();
                        } else {
                            if (window.AdminPopup) window.AdminPopup.error('Không thể xóa file');
                        }
                    });
            });
        });

        // Restore handling
        document.querySelectorAll('.btn-restore-backup').forEach(btn => {
            btn.addEventListener('click', function () {
                const filename = this.dataset.filename;
                if (!confirm('CẢNH BÁO: Khôi phục sẽ GHI ĐÈ toàn bộ cơ sở dữ liệu hiện tại bằng dữ liệu trong bản sao lưu này. Hành động này KHÔNG THỂ hoàn tác (ngoại trừ bản sao lưu an toàn được tạo tự động trước khi khôi phục).\n\nBạn có chắc chắn muốn tiếp tục?')) return;

                fetch('../ajax/backup.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'restore',
                        filename: filename,
                        csrf_token: AdminSecurity.csrfToken()
                    }).toString()
                })
                    .then(r => r.json())
                    .then(data => {
                        if (window.AdminLoader) window.AdminLoader.hide();
                        if (data.success) {
                            if (window.AdminPopup) window.AdminPopup.success(data.message || 'Đã khôi phục cơ sở dữ liệu');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            if (window.AdminPopup) window.AdminPopup.error(data.message || 'Không thể khôi phục');
                        }
                    })
                    .catch(() => {
                        if (window.AdminLoader) window.AdminLoader.hide();
                        if (window.AdminPopup) window.AdminPopup.error('Lỗi kết nối server');
                    });
            });
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
