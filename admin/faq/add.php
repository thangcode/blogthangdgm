<?php
// admin/faq/add.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check login
require_admin_login();

$current_page = 'faq';
require_once '../includes/header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = trim($_POST['question']);
    $answer = trim($_POST['answer']);
    $sort_order = (int) $_POST['sort_order'];
    $status = isset($_POST['status']) ? 1 : 0;

    if (empty($question) || empty($answer)) {
        $error = 'Vui lòng nhập câu hỏi và câu trả lời.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO faqs (question, answer, sort_order, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$question, $answer, $sort_order, $status]);
            $success = 'Thêm FAQ thành công!';
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
}
?>

<div class="container-fluid py-4">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <h1 class="h2 fw-bold text-dark">Thêm FAQ mới</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="bi bi-arrow-left me-2"></i> Quay lại
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-4 p-md-5">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show rounded-3" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="question" class="form-label fw-bold">Câu hỏi <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg rounded-3" id="question"
                                name="question" value="<?php echo e($_POST['question'] ?? ''); ?>" required
                                placeholder="Ví dụ: Làm thế nào để đăng ký dịch vụ?">
                        </div>

                        <div class="mb-4">
                            <label for="answer" class="form-label fw-bold">Câu trả lời <span
                                    class="text-danger">*</span></label>
                            <textarea class="form-control rounded-3" id="answer" name="answer" rows="6" required
                                placeholder="Nhập nội dung câu trả lời chi tiết..."><?php echo e($_POST['answer'] ?? ''); ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label for="sort_order" class="form-label fw-bold">Thứ tự hiển thị</label>
                                <input type="number" class="form-control rounded-3" id="sort_order" name="sort_order"
                                    value="<?php echo $_POST['sort_order'] ?? 0; ?>">
                            </div>

                            <div class="col-md-6 mb-4 d-flex align-items-center">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="status" name="status" checked>
                                    <label class="form-check-label fw-bold" for="status">Trạng thái Kích hoạt</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid pt-3">
                            <button type="submit"
                                class="btn btn-primary btn-lg rounded-pill fw-bold py-3 shadow-sm border-0"
                                style="background: var(--primary-gradient);">
                                <i class="bi bi-save me-2"></i> Lưu Câu Hỏi
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>