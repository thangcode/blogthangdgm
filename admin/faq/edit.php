<?php
// admin/faq/edit.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();

$current_page = 'faq';
require_once '../includes/header.php';

$error = '';
$success = '';

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM faqs WHERE id = ?");
    $stmt->execute([$id]);
    $faq = $stmt->fetch();
    if (!$faq) {
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    $id = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $question = trim($_POST['question'] ?? '');
    $answer = trim($_POST['answer'] ?? '');
    $sort_order = (int) ($_POST['sort_order'] ?? 0);
    $status = isset($_POST['status']) ? 1 : 0;

    if ($question === '' || $answer === '') {
        $error = 'Vui lòng nhập câu hỏi và câu trả lời.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE faqs SET question = ?, answer = ?, sort_order = ?, status = ? WHERE id = ?");
            $stmt->execute([$question, $answer, $sort_order, $status, $id]);
            $success = 'Cập nhật FAQ thành công!';

            $faq['question'] = $question;
            $faq['answer'] = $answer;
            $faq['sort_order'] = $sort_order;
            $faq['status'] = $status;
        } catch (PDOException $e) {
            $error = 'Lỗi: ' . $e->getMessage();
        }
    }
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Chỉnh sửa FAQ</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="index.php" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="bi bi-arrow-left me-2"></i>Quay lại
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-4 p-md-5">
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show rounded-3" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="question" class="form-label fw-bold">Câu hỏi <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                class="form-control form-control-lg rounded-3"
                                id="question"
                                name="question"
                                value="<?php echo e($faq['question'] ?? ''); ?>"
                                required
                                placeholder="Ví dụ: Thời gian học kéo dài bao lâu?"
                            >
                        </div>

                        <div class="mb-4">
                            <label for="answer" class="form-label fw-bold">Câu trả lời <span class="text-danger">*</span></label>
                            <textarea
                                class="form-control rounded-3"
                                id="answer"
                                name="answer"
                                rows="6"
                                required
                                placeholder="Mô tả câu trả lời chi tiết..."
                            ><?php echo e($faq['answer'] ?? ''); ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label for="sort_order" class="form-label fw-bold">Thứ tự hiển thị</label>
                                <input
                                    type="number"
                                    class="form-control rounded-3"
                                    id="sort_order"
                                    name="sort_order"
                                    value="<?php echo (int) ($faq['sort_order'] ?? 0); ?>"
                                >
                                <div class="form-text">Số nhỏ hơn sẽ hiển thị trước.</div>
                            </div>

                            <div class="col-md-6 mb-4 d-flex align-items-center">
                                <div class="form-check form-switch mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        id="status"
                                        name="status"
                                        <?php echo !empty($faq['status']) ? 'checked' : ''; ?>
                                    >
                                    <label class="form-check-label fw-bold" for="status">Trạng thái kích hoạt</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button
                                type="submit"
                                class="btn btn-lg rounded-pill fw-bold py-3 shadow-sm border-0"
                                style="background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff;"
                            >
                                <i class="bi bi-save me-2"></i>Cập nhật FAQ
                            </button>
                            <a href="index.php" class="btn btn-link text-decoration-none text-muted mt-2">Hủy bỏ và quay lại</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
