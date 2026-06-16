<?php
// admin/short-links/add.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();
    
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $target_url = trim($_POST['target_url'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $redirect_type = (int)($_POST['redirect_type'] ?? 307);
    
    // Options
    $parameter_forwarding = isset($_POST['parameter_forwarding']) ? 1 : 0;
    $is_tracking_enabled = isset($_POST['is_tracking_enabled']) ? 1 : 0;
    $status = isset($_POST['status']) ? 1 : 0;

    // UTM
    $utm_source = trim($_POST['utm_source'] ?? '');
    $utm_medium = trim($_POST['utm_medium'] ?? '');
    $utm_campaign = trim($_POST['utm_campaign'] ?? '');
    $utm_term = trim($_POST['utm_term'] ?? '');
    $utm_content = trim($_POST['utm_content'] ?? '');

    if (empty($title) || empty($target_url)) {
        $error = 'Vui lòng nhập Tiêu đề và Target URL.';
    } elseif (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        $error = 'Target URL không đúng định dạng.';
    } else {
        if (empty($slug)) {
            $slug = uniqid('s_');
        } else {
            $slug = create_slug($slug);
        }

        try {
            // Check if slug exists (short link khác)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM short_links WHERE slug = ?");
            $stmt->execute([$slug]);
            // Check trùng slug bài viết: router ưu tiên bài viết trước short link,
            // nên slug trùng sẽ khiến short link không bao giờ chạy được.
            $stmtPost = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE slug = ?");
            $stmtPost->execute([$slug]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Đường dẫn rút gọn (slug) đã tồn tại. Vui lòng chọn slug khác.';
            } elseif ($stmtPost->fetchColumn() > 0) {
                $error = 'Slug này trùng với một bài viết đã có. Short link sẽ không hoạt động vì bài viết được ưu tiên. Vui lòng chọn slug khác.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO short_links (title, description, slug, target_url, redirect_type, parameter_forwarding, is_tracking_enabled, status, utm_source, utm_medium, utm_campaign, utm_term, utm_content) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $title, $description, $slug, $target_url, $redirect_type, 
                    $parameter_forwarding, $is_tracking_enabled, $status,
                    $utm_source, $utm_medium, $utm_campaign, $utm_term, $utm_content
                ]);
                
                redirect('index.php?success=' . urlencode('Thêm link rút gọn thành công!'));
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }
}

$current_page = 'short-links';
require_once '../includes/header.php';
?>

<div class="container-fluid mb-5">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Thêm Link Rút Gọn Mới</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Quay lại
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        
        <div class="row g-4">
            <!-- Cột trái: Thông tin chính -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body p-4">
                        <div class="mb-4">
                            <label for="title" class="form-label fw-bold">Tiêu đề <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg bg-light border-0" id="title" name="title" required value="<?php echo e($_POST['title'] ?? ''); ?>" placeholder="VD: Chiến dịch Sale Hè 2026">
                        </div>

                        <div class="mb-4">
                            <label for="description" class="form-label fw-bold">Mô tả chi tiết</label>
                            <textarea class="form-control bg-light border-0" id="description" name="description" rows="3" placeholder="Ghi chú nội bộ cho link này"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-4">
                            <label for="redirect_type" class="form-label fw-bold">Redirect Type <span class="text-danger">*</span></label>
                            <select class="form-select bg-light border-0" id="redirect_type" name="redirect_type">
                                <option value="307" <?php echo (isset($_POST['redirect_type']) && $_POST['redirect_type'] == 307) ? 'selected' : ''; ?>>307 (Temporary Redirect) - Track better</option>
                                <option value="301" <?php echo (isset($_POST['redirect_type']) && $_POST['redirect_type'] == 301) ? 'selected' : ''; ?>>301 (Permanent Redirect) - Good for SEO</option>
                                <option value="302" <?php echo (isset($_POST['redirect_type']) && $_POST['redirect_type'] == 302) ? 'selected' : ''; ?>>302 (Found)</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="target_url" class="form-label fw-bold">Target URL <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text border-0 bg-light text-muted"><i class="bi bi-link-45deg"></i></span>
                                <input type="url" class="form-control border-0 bg-light" id="target_url" name="target_url" required value="<?php echo e($_POST['target_url'] ?? ''); ?>" placeholder="https://example.com/page?ref=123">
                                <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#utmBuilderCollapse" aria-expanded="false" aria-controls="utmBuilderCollapse">
                                    UTM Builder
                                </button>
                            </div>
                        </div>

                        <div class="collapse mb-4" id="utmBuilderCollapse">
                            <div class="card card-body bg-light border-0 border-start border-primary border-4">
                                <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-funnel"></i> UTM Parameters</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted fw-semibold">utm_source</label>
                                        <input type="text" class="form-control form-control-sm" name="utm_source" value="<?php echo e($_POST['utm_source'] ?? ''); ?>" placeholder="e.g. google, newsletter">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted fw-semibold">utm_medium</label>
                                        <input type="text" class="form-control form-control-sm" name="utm_medium" value="<?php echo e($_POST['utm_medium'] ?? ''); ?>" placeholder="e.g. cpc, banner, email">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted fw-semibold">utm_campaign</label>
                                        <input type="text" class="form-control form-control-sm" name="utm_campaign" value="<?php echo e($_POST['utm_campaign'] ?? ''); ?>" placeholder="e.g. summer_sale">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted fw-semibold">utm_term</label>
                                        <input type="text" class="form-control form-control-sm" name="utm_term" value="<?php echo e($_POST['utm_term'] ?? ''); ?>" placeholder="e.g. running+shoes">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small text-muted fw-semibold">utm_content</label>
                                        <input type="text" class="form-control form-control-sm" name="utm_content" value="<?php echo e($_POST['utm_content'] ?? ''); ?>" placeholder="e.g. textlink">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="slug" class="form-label fw-bold">Shortened URL (Tùy chỉnh slug)</label>
                            <div class="input-group mb-3">
                                <span class="input-group-text bg-primary text-white border-0 fw-semibold" id="basic-addon3" style="border-radius: 8px 0 0 8px;">
                                    <?php echo BASE_URL; ?>
                                </span>
                                <input type="text" class="form-control bg-light border-0 fw-semibold" id="slug" name="slug" value="<?php echo e($_POST['slug'] ?? ''); ?>" placeholder="de-trong-de-tao-ngau-nhien">
                            </div>
                            <div class="form-text">Ví dụ: nhap 'fpt' => <?php echo BASE_URL; ?>fpt</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cột phải: Tùy chọn -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 mb-4 sticky-top" style="top: 80px; z-index: 1;">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                        <h5 class="fw-bold m-0"><i class="bi bi-sliders me-2 text-primary"></i> Link Options</h5>
                    </div>
                    <div class="card-body p-4">
                        
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="status" name="status" value="1" <?php echo (!isset($_POST['status']) || $_POST['status'] == 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2" for="status">
                                <span class="fw-semibold text-dark">Hoạt động</span><br>
                                <small class="text-muted">Link có thể truy cập được trên môi trường public</small>
                            </label>
                        </div>

                        <hr class="text-muted opacity-25">

                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_tracking_enabled" name="is_tracking_enabled" value="1" <?php echo (!isset($_POST['is_tracking_enabled']) || $_POST['is_tracking_enabled'] == 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2" for="is_tracking_enabled">
                                <span class="fw-semibold text-dark">Bật Tracking (Thống kê)</span><br>
                                <small class="text-muted">Ghi nhận lượt Click, Địa chỉ IP, Trình duyệt, Hệ điều hành, Nguồn tới.</small>
                            </label>
                        </div>

                        <hr class="text-muted opacity-25">

                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="parameter_forwarding" name="parameter_forwarding" value="1" <?php echo (isset($_POST['parameter_forwarding']) && $_POST['parameter_forwarding'] == 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2" for="parameter_forwarding">
                                <span class="fw-semibold text-dark">Truyền Parameter (Forward)</span><br>
                                <small class="text-muted">Mang theo các tham số GET <code>?id=123</code> đang có trên link rút gọn chèn vào link Target.</small>
                            </label>
                        </div>

                        <div class="d-grid mt-4 pt-2">
                            <button type="submit" class="btn btn-primary btn-lg rounded-3 fw-bold shadow-sm">
                                <i class="bi bi-save me-2"></i> Lưu Link Mới
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
.form-switch .form-check-input {
    width: 3em;
    height: 1.5em;
    cursor: pointer;
}
.form-check-label {
    cursor: pointer;
}
.form-control:focus, .form-select:focus {
    box-shadow: none;
    background-color: #f8fafc !important;
    border-bottom: 2px solid #6366f1 !important;
}
</style>

<?php require_once '../includes/footer.php'; ?>
