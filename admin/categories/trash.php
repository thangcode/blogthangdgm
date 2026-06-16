<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!has_table_column($pdo, 'categories', 'deleted_at')) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER status");
    } catch (Exception $e) {
        // keep page usable
    }
}
if (!has_table_column($pdo, 'categories', 'deleted_by')) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN deleted_by INT NULL DEFAULT NULL AFTER deleted_at");
    } catch (Exception $e) {
        // keep page usable
    }
}

if (isset($_POST['restore_id'])) {
    $id = (int) ($_POST['restore_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new RuntimeException('Danh mục không tồn tại trong thùng rác.');
        }

        $stmt = $pdo->prepare("UPDATE categories SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);

        if (function_exists('log_activity')) {
            log_activity('restore', 'category', $id, "Restore category: {$name}");
        }

        header('Location: trash.php?restored=1&name=' . rawurlencode($name));
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Lỗi khi khôi phục: ' . $e->getMessage();
    }
}

if (isset($_POST['force_delete_id'])) {
    $id = (int) ($_POST['force_delete_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new RuntimeException('Danh mục không tồn tại trong thùng rác.');
        }

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE services SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM categories WHERE id = ? AND deleted_at IS NOT NULL")->execute([$id]);
        $pdo->commit();

        if (function_exists('log_activity')) {
            log_activity('delete_permanent', 'category', $id, "Force delete category: {$name}");
        }

        header('Location: trash.php?deleted=1&name=' . rawurlencode($name));
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Lỗi khi xóa vĩnh viễn: ' . $e->getMessage();
    }
}

$success = '';
if (isset($_GET['moved']) && $_GET['moved'] === '1') {
    $success = 'Đã chuyển danh mục "' . ($_GET['name'] ?? '') . '" vào thùng rác.';
}
if (isset($_GET['restored']) && $_GET['restored'] === '1') {
    $success = 'Đã khôi phục danh mục "' . ($_GET['name'] ?? '') . '".';
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $success = 'Đã xóa vĩnh viễn danh mục "' . ($_GET['name'] ?? '') . '".';
}

$deleted_categories = [];
if (!isset($error)) {
    try {
        $stmt = $pdo->query("SELECT c.id, c.name, c.slug, c.deleted_at, (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id) AS linked_services FROM categories c WHERE c.deleted_at IS NOT NULL ORDER BY c.deleted_at DESC, c.id DESC");
        $deleted_categories = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Lỗi tải dữ liệu: ' . $e->getMessage();
    }
}

$current_page = 'categories';
require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-trash3 me-2"></i>Thùng rác Danh mục</h1>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Quay lại danh sách
        </a>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="table-responsive bg-white rounded shadow-sm p-3">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th width="70">ID</th>
                    <th>Tên danh mục</th>
                    <th>Slug</th>
                    <th width="130">Dịch vụ liên kết</th>
                    <th width="190">Xóa lúc</th>
                    <th class="text-end" width="230">Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($deleted_categories)): ?>
                    <?php foreach ($deleted_categories as $row): ?>
                        <tr>
                            <td>#<?php echo (int) $row['id']; ?></td>
                            <td><strong><?php echo e($row['name']); ?></strong></td>
                            <td><code><?php echo e($row['slug']); ?></code></td>
                            <td>
                                <span class="badge bg-light text-dark"><?php echo (int) $row['linked_services']; ?></span>
                            </td>
                            <td><?php echo e($row['deleted_at']); ?></td>
                            <td class="text-end">
                                <form method="POST" class="d-inline me-1"
                                    data-confirm="Khôi phục danh mục &quot;<?php echo e($row['name']); ?>&quot;?"
                                    data-confirm-title="Khôi phục danh mục"
                                    data-confirm-ok="Khôi phục"
                                    data-confirm-class="btn-success">
                                    <input type="hidden" name="restore_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-arrow-counterclockwise"></i> Khôi phục
                                    </button>
                                </form>
                                <form method="POST" class="d-inline"
                                    data-confirm="Xóa vĩnh viễn danh mục &quot;<?php echo e($row['name']); ?>&quot;? Hành động này không thể hoàn tác."
                                    data-confirm-title="Xóa vĩnh viễn"
                                    data-confirm-ok="Xóa vĩnh viễn"
                                    data-confirm-class="btn-danger">
                                    <input type="hidden" name="force_delete_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i> Xóa vĩnh viễn
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            Không có danh mục nào trong thùng rác.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
