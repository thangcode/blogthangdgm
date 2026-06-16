<?php
// admin/dynamic-blocks/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'dynamic-blocks';
require_admin_login();

unset($_GET['delete'], $_GET['toggle']);

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'delete' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid security token';
        header('Location: index.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT block_key FROM dynamic_blocks WHERE id = ?");
        $stmt->execute([$id]);
        $block = $stmt->fetch();

        if ($block) {
            $stmt = $pdo->prepare("DELETE FROM dynamic_blocks WHERE id = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("DELETE FROM homepage_blocks WHERE block_key = ?");
            $stmt->execute([$block['block_key']]);

            log_activity('delete', 'dynamic_block', $id, 'Deleted dynamic block');
            $_SESSION['flash_success'] = 'Deleted dynamic block successfully.';
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: index.php');
    exit;
}

if ($action === 'toggle' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid security token';
        header('Location: index.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE dynamic_blocks SET status = IF(status=1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash_success'] = 'Block status updated.';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
    }

    header('Location: index.php');
    exit;
}

// Handle delete — BEFORE header include for redirect to work
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("SELECT block_key FROM dynamic_blocks WHERE id = ?");
        $stmt->execute([$id]);
        $block = $stmt->fetch();

        if ($block) {
            $stmt = $pdo->prepare("DELETE FROM dynamic_blocks WHERE id = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("DELETE FROM homepage_blocks WHERE block_key = ?");
            $stmt->execute([$block['block_key']]);

            log_activity('delete', 'dynamic_block', $id, 'Deleted dynamic block');
            $_SESSION['flash_success'] = 'Đã xoá block thành công!';
        }
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Lỗi: ' . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

// Handle toggle status — BEFORE header include for redirect to work
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    try {
        $stmt = $pdo->prepare("UPDATE dynamic_blocks SET status = IF(status=1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['flash_success'] = 'Đã cập nhật trạng thái!';
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = 'Lỗi: ' . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

require_once '../includes/header.php';

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `dynamic_blocks` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `block_key` VARCHAR(50) NOT NULL UNIQUE,
        `title` VARCHAR(255) NOT NULL,
        `subtitle` VARCHAR(255) DEFAULT NULL,
        `type` ENUM('products','news') NOT NULL DEFAULT 'products',
        `display_mode` ENUM('row','slide') NOT NULL DEFAULT 'row',
        `rows_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `items_per_row` TINYINT UNSIGNED NOT NULL DEFAULT 4,
        `items_count` INT UNSIGNED NOT NULL DEFAULT 8,
        `order_by` VARCHAR(50) NOT NULL DEFAULT 'newest',
        `category_id` INT UNSIGNED DEFAULT NULL,
        `featured_only` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `layout_style` ENUM('simple','wave','gradient','glass','aurora','sunset','minimal','neon','editorial') NOT NULL DEFAULT 'simple',
        `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_status` (`status`),
        INDEX `idx_block_key` (`block_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure existing installations include new layout templates
    $layoutTypeStmt = $pdo->query("SHOW COLUMNS FROM `dynamic_blocks` LIKE 'layout_style'");
    $layoutTypeRow = $layoutTypeStmt ? $layoutTypeStmt->fetch() : false;
    if ($layoutTypeRow && strpos((string) $layoutTypeRow['Type'], 'editorial') === false) {
        $pdo->exec("ALTER TABLE `dynamic_blocks`
            MODIFY COLUMN `layout_style` ENUM('simple','wave','gradient','glass','aurora','sunset','minimal','neon','editorial') NOT NULL DEFAULT 'simple'");
    }
} catch (PDOException $e) { /* ignore */ }

// Fetch all blocks
$blocks = $pdo->query("SELECT db.*, c.name as category_name
    FROM dynamic_blocks db
    LEFT JOIN categories c ON db.category_id = c.id
    ORDER BY db.created_at DESC")->fetchAll();

// Sắp theo đúng thứ tự ngoài trang chủ (homepage_blocks.sort_order), khớp với cách
// trang chủ render (ORDER BY sort_order ASC). Block chưa có trên trang chủ xếp cuối.
try {
    $hpOrderMap = [];
    $hpRows = $pdo->query("SELECT block_key, sort_order FROM homepage_blocks")->fetchAll();
    foreach ($hpRows as $hpRow) {
        $hpOrderMap[(string) $hpRow['block_key']] = (int) $hpRow['sort_order'];
    }
    if (!empty($hpOrderMap) && !empty($blocks)) {
        usort($blocks, function ($a, $b) use ($hpOrderMap) {
            $sa = $hpOrderMap[(string) ($a['block_key'] ?? '')] ?? PHP_INT_MAX;
            $sb = $hpOrderMap[(string) ($b['block_key'] ?? '')] ?? PHP_INT_MAX;
            if ($sa === $sb) {
                // Cùng vị trí (hoặc cùng chưa có trên trang chủ): mới nhất trước
                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            }
            return $sa <=> $sb;
        });
    }
} catch (Throwable $e) {
    // Nếu không lấy được thứ tự trang chủ thì giữ nguyên thứ tự created_at DESC
    error_log('Dynamic blocks homepage-order sort error: ' . $e->getMessage());
}

$type_labels = ['products' => 'Sản phẩm', 'news' => 'Tin tức'];
$mode_labels = ['row' => 'Lưới (Row)', 'slide' => 'Slide'];
$style_labels = ['simple' => 'Đơn giản', 'wave' => 'Gợn sóng', 'gradient' => 'Gradient', 'glass' => 'Glass', 'aurora' => 'Aurora', 'sunset' => 'Sunset', 'minimal' => 'Minimal', 'neon' => 'Neon', 'editorial' => 'Editorial'];
$style_colors = ['simple' => 'secondary', 'wave' => 'warning', 'gradient' => 'primary', 'glass' => 'info', 'aurora' => 'primary', 'sunset' => 'danger', 'minimal' => 'dark', 'neon' => 'dark', 'editorial' => 'secondary'];
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-grid-3x3-gap me-2"></i>Block Động</h1>
        <a href="form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Tạo Block Mới
        </a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo e($_SESSION['flash_success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo e($_SESSION['flash_error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- Info Alert -->
    <div class="alert alert-info d-flex align-items-start mb-4" role="alert">
        <i class="bi bi-info-circle-fill me-2 fs-5 mt-1"></i>
        <div>
            <strong>Hướng dẫn:</strong> Tạo block động để hiển thị sản phẩm hoặc tin tức trên trang chủ.
            Sau khi tạo, block sẽ tự động xuất hiện trong
            <a href="<?php echo BASE_URL; ?>admin/homepage-blocks.php">Quản lý Block Trang Chủ</a>
            để kéo thả sắp xếp vị trí.
        </div>
    </div>

    <?php if (empty($blocks)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-grid-3x3-gap text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                <h5 class="mt-3 text-muted">Chưa có block động nào</h5>
                <p class="text-muted mb-4">Tạo block đầu tiên để hiển thị sản phẩm hoặc tin tức trên trang chủ</p>
                <a href="form.php" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Tạo Block Mới
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($blocks as $block): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card shadow-sm h-100 block-card <?php echo $block['status'] ? '' : 'opacity-60'; ?>">
                        <!-- Card Header -->
                        <div class="card-header bg-white border-bottom-0 pb-0 pt-3 px-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="badge bg-<?php echo $block['type'] === 'products' ? 'primary' : 'success'; ?>">
                                        <i class="bi bi-<?php echo $block['type'] === 'products' ? 'box-seam' : 'newspaper'; ?> me-1"></i>
                                        <?php echo $type_labels[$block['type']]; ?>
                                    </span>
                                    <span class="badge bg-<?php echo $style_colors[$block['layout_style']] ?? 'secondary'; ?>">
                                        <?php echo $style_labels[$block['layout_style']] ?? 'Simple'; ?>
                                    </span>
                                    <span class="badge bg-light text-dark">
                                        <i class="bi bi-<?php echo $block['display_mode'] === 'slide' ? 'play-circle' : 'grid-3x3'; ?> me-1"></i>
                                        <?php echo $mode_labels[$block['display_mode']]; ?>
                                    </span>
                                </div>
                                <div class="form-check form-switch ms-2">
                                    <form method="POST" action="index.php" class="m-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $block['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                                        <button type="submit" class="btn btn-link p-0 text-decoration-none" title="<?php echo $block['status'] ? 'Toggle off' : 'Toggle on'; ?>">
                                            <input class="form-check-input" type="checkbox" aria-label="Toggle status"
                                                   <?php echo $block['status'] ? 'checked' : ''; ?>
                                                   style="width: 2.5em; height: 1.3em; pointer-events: none;" readonly>
                                        </button>
                                    </form>
                                    <a href="index.php?toggle=<?php echo $block['id']; ?>"
                                       class="text-decoration-none"
                                       style="display:none;"
                                       title="<?php echo $block['status'] ? 'Ẩn' : 'Hiện'; ?>">
                                        <input class="form-check-input" type="checkbox"
                                               <?php echo $block['status'] ? 'checked' : ''; ?>
                                               style="width: 2.5em; height: 1.3em; cursor: pointer; pointer-events: none;">
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body px-3 pt-2">
                            <h5 class="fw-bold mb-1"><?php echo e($block['title']); ?></h5>
                            <?php if ($block['subtitle']): ?>
                                <p class="text-muted small mb-2"><?php echo e($block['subtitle']); ?></p>
                            <?php endif; ?>

                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-collection me-1"></i><?php echo $block['items_count']; ?> items
                                </small>
                                <?php if ($block['display_mode'] === 'row'): ?>
                                    <small class="text-muted">
                                        <i class="bi bi-grid me-1"></i><?php echo $block['items_per_row']; ?>/dòng × <?php echo $block['rows_count']; ?> dòng
                                    </small>
                                <?php endif; ?>
                                <?php if ($block['category_name']): ?>
                                    <small class="text-muted">
                                        <i class="bi bi-tag me-1"></i><?php echo e($block['category_name']); ?>
                                    </small>
                                <?php endif; ?>
                                <small class="text-muted">
                                    <i class="bi bi-sort-down me-1"></i><?php echo e($block['order_by']); ?>
                                </small>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="card-footer bg-white border-top pt-2 pb-2 px-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    <i class="bi bi-key me-1"></i><?php echo e($block['block_key']); ?>
                                </small>
                                <div class="btn-group btn-group-sm">
                                    <a href="form.php?id=<?php echo $block['id']; ?>" class="btn btn-outline-primary" title="Sửa">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="POST" action="index.php" class="m-0 me-1">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $block['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete" onclick="return confirm('Delete this block?');">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                        <a href="index.php?delete=<?php echo $block['id']; ?>"
                                           style="display:none;"
                                       class="btn btn-outline-danger"
                                       title="Xoá"
                                       onclick="return confirm('Bạn chắc chắn muốn xoá block này?');">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.block-card {
    transition: all 0.2s ease;
    border-radius: 12px;
    border: 1px solid rgba(0,0,0,0.08);
}
.block-card:hover {
    box-shadow: 0 8px 25px rgba(0,0,0,0.1) !important;
    transform: translateY(-2px);
}
.opacity-60 {
    opacity: 0.6;
}
</style>

<?php require_once '../includes/footer.php'; ?>
