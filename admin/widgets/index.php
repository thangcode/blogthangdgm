<?php
// admin/widgets/index.php — Quản lý widget sidebar (thêm / sửa / sắp xếp kéo-thả).
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';
require_once '../../includes/widgets.php';

require_admin_login();

$registry = widget_registry();

// ── Xử lý POST (add / update / delete / toggle) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Phiên đã hết hạn, vui lòng thử lại.';
        header('Location: index.php');
        exit;
    }
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'add') {
            $type = (string) ($_POST['type'] ?? '');
            if (!isset($registry[$type])) {
                $_SESSION['flash_error'] = 'Loại widget không hợp lệ.';
            } else {
                $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM widgets")->fetchColumn();
                $title = $registry[$type]['label'];
                $pdo->prepare("INSERT INTO widgets (type, title, settings, sort_order, is_active) VALUES (?,?,?,?,1)")
                    ->execute([$type, $title, json_encode(new stdClass(), JSON_UNESCAPED_UNICODE), $max + 1]);
                $_SESSION['flash_success'] = 'Đã thêm widget "' . $title . '". Cấu hình bên dưới.';
            }
        } elseif ($action === 'update' && $id > 0) {
            $st = $pdo->prepare("SELECT * FROM widgets WHERE id = ?");
            $st->execute([$id]);
            $w = $st->fetch();
            if ($w) {
                $type = $w['type'];
                $def = $registry[$type] ?? null;
                $title = trim((string) ($_POST['title'] ?? ''));
                $settings = [];
                if ($def) {
                    foreach ($def['fields'] as $key => $f) {
                        $val = $_POST['f'][$key] ?? '';
                        if ($f['type'] === 'number' || $f['type'] === 'category') {
                            $val = (int) $val;
                            if (isset($f['min'])) $val = max($f['min'], $val);
                            if (isset($f['max'])) $val = min($f['max'], $val);
                        } elseif ($f['type'] === 'checkbox') {
                            $val = isset($_POST['f'][$key]) ? 1 : 0;
                        } elseif ($f['type'] === 'select') {
                            $opts = $f['options'] ?? [];
                            $val = isset($opts[$val]) ? (string) $val : (string) ($f['default'] ?? '');
                        } else {
                            $val = trim((string) $val);
                        }
                        $settings[$key] = $val;
                    }
                }
                $pdo->prepare("UPDATE widgets SET title = ?, settings = ? WHERE id = ?")
                    ->execute([$title, json_encode($settings, JSON_UNESCAPED_UNICODE), $id]);
                $_SESSION['flash_success'] = 'Đã lưu widget.';
            }
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare("DELETE FROM widgets WHERE id = ?")->execute([$id]);
            $_SESSION['flash_success'] = 'Đã xóa widget.';
        } elseif ($action === 'toggle' && $id > 0) {
            $pdo->prepare("UPDATE widgets SET is_active = IF(is_active=1,0,1) WHERE id = ?")->execute([$id]);
            $_SESSION['flash_success'] = 'Đã đổi trạng thái widget.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Lỗi: ' . $e->getMessage();
    }
    header('Location: index.php');
    exit;
}

$current_page = 'widgets';
require_once '../includes/header.php';

$widgets = $pdo->query("SELECT * FROM widgets ORDER BY sort_order ASC, id ASC")->fetchAll();
try {
    $all_categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) { $all_categories = []; }
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$csrf = generate_csrf_token();
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-dark mb-0">Widget Sidebar</h1>
            <p class="text-muted small mb-0">Thêm block vào thanh bên, kéo để sắp xếp. Bật/tắt sidebar theo trang ở mục Cấu hình.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>admin/settings/index.php#sidebar" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="bi bi-sliders me-1"></i> Cài đặt hiển thị
        </a>
    </div>

    <?php if ($flash_success): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm border-0">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($flash_success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm border-0">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($flash_error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Palette: các loại block -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 sticky-lg-top" style="top:80px;z-index:1;">
                <div class="card-header bg-white border-0 pt-3 pb-0">
                    <h5 class="fw-bold mb-0"><i class="bi bi-grid-1x2 me-2 text-primary"></i>Các block có sẵn</h5>
                    <p class="text-muted small mt-1 mb-0">Bấm để thêm vào sidebar</p>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <?php foreach ($registry as $type => $def): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="type" value="<?php echo e($type); ?>">
                                <button type="submit" class="btn btn-light border w-100 text-start d-flex align-items-center gap-3 py-2 widget-palette-btn">
                                    <span class="widget-palette-icon"><i class="bi <?php echo e($def['icon']); ?>"></i></span>
                                    <span class="flex-grow-1">
                                        <span class="fw-semibold d-block"><?php echo e($def['label']); ?></span>
                                        <small class="text-muted"><?php echo e($def['desc']); ?></small>
                                    </span>
                                    <i class="bi bi-plus-circle text-primary"></i>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar: danh sách widget hiện có -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-3 pb-2 d-flex align-items-center justify-content-between">
                    <h5 class="fw-bold mb-0"><i class="bi bi-layout-sidebar-inset-reverse me-2 text-primary"></i>Sidebar hiện tại</h5>
                    <span class="badge bg-light text-dark border"><?php echo count($widgets); ?> widget</span>
                </div>
                <div class="card-body">
                    <?php if (empty($widgets)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
                            <p class="mt-2 mb-0">Chưa có widget nào. Bấm block bên trái để thêm.</p>
                        </div>
                    <?php else: ?>
                        <div id="widgetList" class="d-flex flex-column gap-3">
                            <?php foreach ($widgets as $w):
                                $def = $registry[$w['type']] ?? null;
                                $settings = widget_settings($w);
                                $active = (int) $w['is_active'] === 1;
                            ?>
                                <div class="widget-item border rounded-3 <?php echo $active ? '' : 'opacity-75'; ?>" data-id="<?php echo (int) $w['id']; ?>">
                                    <div class="d-flex align-items-center gap-2 p-3 widget-item__head">
                                        <span class="drag-handle text-muted" style="cursor:grab;font-size:1.2rem;" title="Kéo để sắp xếp"><i class="bi bi-grip-vertical"></i></span>
                                        <span class="widget-palette-icon"><i class="bi <?php echo e($def['icon'] ?? 'bi-question'); ?>"></i></span>
                                        <span class="flex-grow-1">
                                            <span class="fw-semibold"><?php echo e($w['title'] !== '' ? $w['title'] : ($def['label'] ?? $w['type'])); ?></span>
                                            <small class="text-muted d-block"><?php echo e($def['label'] ?? $w['type']); ?></small>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#wcfg<?php echo (int) $w['id']; ?>">
                                            <i class="bi bi-pencil"></i> Sửa
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?php echo (int) $w['id']; ?>">
                                            <button type="submit" class="btn btn-sm rounded-pill px-3 <?php echo $active ? 'btn-success' : 'btn-secondary'; ?>" title="Bật/tắt">
                                                <i class="bi bi-<?php echo $active ? 'eye' : 'eye-slash'; ?>"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline" data-confirm="Xóa widget này khỏi sidebar?" data-confirm-title="Xóa widget" data-confirm-ok="Xóa" data-confirm-class="btn-danger">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $w['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-light border rounded-pill px-3" title="Xóa"><i class="bi bi-trash text-danger"></i></button>
                                        </form>
                                    </div>
                                    <div class="collapse" id="wcfg<?php echo (int) $w['id']; ?>">
                                        <div class="border-top p-3 bg-light rounded-bottom-3">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="id" value="<?php echo (int) $w['id']; ?>">
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">Tiêu đề widget</label>
                                                    <input type="text" name="title" class="form-control" value="<?php echo e($w['title']); ?>" placeholder="Để trống nếu không muốn hiện tiêu đề">
                                                </div>
                                                <?php if ($def): foreach ($def['fields'] as $key => $f):
                                                    $val = widget_get($settings, $w['type'], $key, '');
                                                    $isPostsCategoryField = $w['type'] === 'posts' && $key === 'category_id';
                                                    $postSourceValue = $w['type'] === 'posts' ? widget_get($settings, $w['type'], 'source', 'all') : 'all';
                                                    $fieldClass = 'mb-3' . ($isPostsCategoryField && (string) $postSourceValue !== 'category' ? ' d-none' : '');
                                                    $fieldAttrs = $isPostsCategoryField ? ' data-post-widget-category-field' : '';
                                                ?>
                                                    <?php if ($f['type'] === 'checkbox'): ?>
                                                        <div class="form-check form-switch mb-3">
                                                            <input type="hidden" name="f[<?php echo e($key); ?>]" value="0">
                                                            <input type="checkbox" class="form-check-input" id="wf<?php echo (int) $w['id']; ?>_<?php echo e($key); ?>" name="f[<?php echo e($key); ?>]" value="1" <?php echo (int) $val === 1 ? 'checked' : ''; ?>>
                                                            <label class="form-check-label small fw-bold" for="wf<?php echo (int) $w['id']; ?>_<?php echo e($key); ?>"><?php echo e($f['label']); ?></label>
                                                        </div>
                                                    <?php else: ?>
                                                    <div class="<?php echo $fieldClass; ?>"<?php echo $fieldAttrs; ?>>
                                                        <label class="form-label small fw-bold"><?php echo e($f['label']); ?></label>
                                                        <?php if ($f['type'] === 'textarea'): ?>
                                                            <textarea name="f[<?php echo e($key); ?>]" class="form-control" rows="4"><?php echo e((string) $val); ?></textarea>
                                                        <?php elseif ($f['type'] === 'number'): ?>
                                                            <input type="number" name="f[<?php echo e($key); ?>]" class="form-control" value="<?php echo e((string) $val); ?>"
                                                                   <?php echo isset($f['min']) ? 'min="' . (int) $f['min'] . '"' : ''; ?>
                                                                   <?php echo isset($f['max']) ? 'max="' . (int) $f['max'] . '"' : ''; ?>>
                                                        <?php elseif ($f['type'] === 'select'): ?>
                                                            <select name="f[<?php echo e($key); ?>]" class="form-select"<?php echo $w['type'] === 'posts' && $key === 'source' ? ' data-post-widget-source' : ''; ?>>
                                                                <?php foreach (($f['options'] ?? []) as $ov => $ol): ?>
                                                                    <option value="<?php echo e((string) $ov); ?>" <?php echo (string) $val === (string) $ov ? 'selected' : ''; ?>><?php echo e($ol); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php elseif ($f['type'] === 'category'): ?>
                                                            <select name="f[<?php echo e($key); ?>]" class="form-select"<?php echo $isPostsCategoryField && (string) $postSourceValue !== 'category' ? ' disabled' : ''; ?>>
                                                                <option value="0">— Chọn danh mục —</option>
                                                                <?php foreach ($all_categories as $cat): ?>
                                                                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo (int) $val === (int) $cat['id'] ? 'selected' : ''; ?>><?php echo e($cat['name']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php else: ?>
                                                            <input type="<?php echo $f['type'] === 'url' ? 'url' : 'text'; ?>" name="f[<?php echo e($key); ?>]" class="form-control" value="<?php echo e((string) $val); ?>">
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                <?php endforeach; endif; ?>
                                                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="bi bi-save me-1"></i> Lưu widget</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.widget-palette-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#eef2ff,#faf5ff);color:#6366f1;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.widget-palette-btn:hover{border-color:#6366f1 !important;background:#f8f9ff !important;}
.widget-item{background:#fff;transition:box-shadow .15s;}
.widget-item.sortable-ghost{opacity:.4;}
.widget-item__head{flex-wrap:wrap;}
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
(function () {
    function syncPostWidgetCategory(form) {
        if (!form) return;
        const source = form.querySelector('[data-post-widget-source]');
        const categoryField = form.querySelector('[data-post-widget-category-field]');
        if (!source || !categoryField) return;

        const showCategory = source.value === 'category';
        categoryField.classList.toggle('d-none', !showCategory);

        const categorySelect = categoryField.querySelector('select');
        if (categorySelect) {
            categorySelect.disabled = !showCategory;
            if (!showCategory) categorySelect.value = '0';
        }
    }

    document.querySelectorAll('[data-post-widget-source]').forEach(function (source) {
        syncPostWidgetCategory(source.closest('form'));
        source.addEventListener('change', function () {
            syncPostWidgetCategory(source.closest('form'));
        });
    });
})();

(function () {
    const list = document.getElementById('widgetList');
    if (!list) return;
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;min-width:220px;display:none;';
    document.body.appendChild(toast);
    function showToast(msg, type) {
        toast.innerHTML = `<div class="alert alert-${type} shadow d-flex align-items-center gap-2 mb-0 py-2 px-3 rounded-3">
            <i class="bi bi-${type === 'success' ? 'check-circle-fill text-success' : 'x-circle-fill text-danger'}"></i><span>${msg}</span></div>`;
        toast.style.display = 'block';
        setTimeout(() => toast.style.display = 'none', 2200);
    }
    Sortable.create(list, {
        handle: '.drag-handle',
        animation: 160,
        ghostClass: 'sortable-ghost',
        onEnd: function () {
            const order = Array.from(list.querySelectorAll('.widget-item')).map(el => el.dataset.id);
            fetch('../ajax/widget-reorder.php', {
                method: 'POST',
                headers: AdminSecurity.headers({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ order, csrf_token: AdminSecurity.csrfToken() })
            }).then(r => r.json()).then(d => {
                showToast(d.success ? 'Đã lưu thứ tự!' : 'Lỗi lưu thứ tự!', d.success ? 'success' : 'danger');
            }).catch(() => showToast('Lỗi kết nối!', 'danger'));
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
