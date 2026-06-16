<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';

$current_page = 'menus';
require_admin_login();

$allowedPositions = ['header', 'footer'];
$activePosition = $_GET['position'] ?? 'header';
if (!in_array($activePosition, $allowedPositions, true)) {
    $activePosition = 'header';
}

$error = '';
$success = '';

function fetch_menus_by_position($pdo, $position)
{
    $stmt = $pdo->prepare("SELECT * FROM menus WHERE position = ? ORDER BY parent_id ASC, sort_order ASC, id ASC");
    $stmt->execute([$position]);
    return $stmt->fetchAll();
}

function build_admin_menu_tree(array $items)
{
    $map = [];
    foreach ($items as $item) {
        $item['id'] = (int) $item['id'];
        $item['parent_id'] = (int) ($item['parent_id'] ?? 0);
        $item['status'] = (int) ($item['status'] ?? 1);
        $item['children'] = [];
        $map[$item['id']] = $item;
    }

    $tree = [];
    foreach ($map as $id => &$item) {
        $parentId = (int) $item['parent_id'];
        if ($parentId > 0 && isset($map[$parentId])) {
            $map[$parentId]['children'][] = &$item;
        } else {
            $tree[] = &$item;
        }
    }
    unset($item);

    return $tree;
}

function flatten_tree_options(array $items, $depth = 0)
{
    $result = [];
    foreach ($items as $item) {
        $result[] = [
            'id' => (int) $item['id'],
            'label' => str_repeat('— ', $depth) . ($item['name'] ?? '')
        ];

        if (!empty($item['children'])) {
            $result = array_merge($result, flatten_tree_options($item['children'], $depth + 1));
        }
    }

    return $result;
}

function render_admin_menu_items(array $items)
{
    foreach ($items as $item) {
        $id = (int) $item['id'];
        $name = e($item['name'] ?? 'Menu');
        $url = e($item['url'] ?? '');
        $parentId = (int) ($item['parent_id'] ?? 0);
        $status = (int) ($item['status'] ?? 1);
        $position = e($item['position'] ?? 'header');
        $hasChildren = !empty($item['children']);

        echo '<li class="menu-item" data-id="' . $id . '" data-name="' . $name . '" data-url="' . $url . '" data-parent-id="' . $parentId . '" data-position="' . $position . '" data-status="' . $status . '">';
        echo '<div class="menu-item-card">';
        echo '<div class="menu-item-main">';
        echo '<span class="drag-handle" title="Kéo để sắp xếp"><i class="bi bi-grip-vertical"></i></span>';
        echo '<div class="menu-item-meta">';
        echo '<h6 class="mb-1">' . $name . '</h6>';
        echo '<div class="small text-muted">' . ($url !== '' ? $url : '#') . '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="menu-item-actions">';
        if ($status === 1) {
            echo '<span class="badge bg-success-subtle text-success border border-success-subtle">Hiển thị</span>';
        } else {
            echo '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Ẩn</span>';
        }
        echo '<button type="button" class="btn btn-sm btn-outline-primary edit-menu-btn"><i class="bi bi-pencil-square"></i></button>';
        echo '<form method="POST" class="d-inline" data-confirm="&#88;&#243;a menu n&#224;y? Menu con s&#7869; &#273;&#432;&#7907;c &#273;&#432;a l&#234;n c&#7845;p g&#7889;c." data-confirm-title="X&#243;a menu" data-confirm-ok="X&#243;a ngay" data-confirm-class="btn-danger">';
        echo '<input type="hidden" name="action" value="delete_item">';
        echo '<input type="hidden" name="position" value="' . $position . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<ul class="menu-sortable child-list">';
        if ($hasChildren) {
            render_admin_menu_items($item['children']);
        }
        echo '</ul>';
        echo '</li>';
    }
}

function update_menu_tree($pdo, array $nodes, $position, $parentId = 0)
{
    $stmt = $pdo->prepare("UPDATE menus SET parent_id = ?, sort_order = ?, position = ? WHERE id = ?");
    foreach ($nodes as $index => $node) {
        if (!isset($node['id'])) {
            continue;
        }

        $id = (int) $node['id'];
        $sortOrder = $index + 1;
        $stmt->execute([(int) $parentId, $sortOrder, $position, $id]);

        if (!empty($node['children']) && is_array($node['children'])) {
            update_menu_tree($pdo, $node['children'], $position, $id);
        }
    }
}

function collect_descendant_ids(array $childrenMap, $startId, array &$result)
{
    if (empty($childrenMap[$startId])) {
        return;
    }

    foreach ($childrenMap[$startId] as $childId) {
        $result[] = $childId;
        collect_descendant_ids($childrenMap, $childId, $result);
    }
}

/**
 * Nếu URL menu trỏ tới một danh mục đang TẮT (status=0) hoặc đã xóa/không tồn tại,
 * trả về slug danh mục đó; ngược lại trả null. Dùng để chặn thêm vào menu.
 */
function menu_url_disabled_category($pdo, $url)
{
    $url = trim((string) $url);
    if ($url === '') return null;
    $prefix = function_exists('getUrlPrefix') ? getUrlPrefix('category') : 'danh-muc';
    if (!preg_match('~(?:^|/)' . preg_quote($prefix, '~') . '/([^/?#]+)~u', trim($url, '/'), $mm)) {
        return null; // không phải URL danh mục -> bỏ qua
    }
    $slug = $mm[1];
    $delF = (function_exists('has_table_column') && has_table_column($pdo, 'categories', 'deleted_at')) ? ' AND deleted_at IS NULL' : '';
    $chk = $pdo->prepare("SELECT status FROM categories WHERE slug = ?{$delF} LIMIT 1");
    $chk->execute([$slug]);
    $st = $chk->fetchColumn();
    if ($st === false) return $slug;   // đã xóa / không tồn tại
    if ((int) $st === 0) return $slug; // đang tắt
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token(isset($_POST['action']) && $_POST['action'] === 'save_tree');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_tree') {
        header('Content-Type: application/json');

        $position = $_POST['position'] ?? 'header';
        if (!in_array($position, $allowedPositions, true)) {
            echo json_encode(['success' => false, 'message' => 'Vị trí menu không hợp lệ.']);
            exit;
        }

        $tree = json_decode($_POST['tree'] ?? '[]', true);
        if (!is_array($tree)) {
            echo json_encode(['success' => false, 'message' => 'Dữ liệu kéo thả không hợp lệ.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            update_menu_tree($pdo, $tree, $position, 0);
            $pdo->commit();

            if (function_exists('log_activity')) {
                log_activity('sort', 'menu', null, "Sắp xếp menu vị trí: $position");
            }

            echo json_encode(['success' => true, 'message' => 'Đã lưu thứ tự menu thành công.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Menu tree save error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Không thể lưu thứ tự menu.']);
        }
        exit;
    }

    try {
        if ($action === 'add_item') {
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $position = $_POST['position'] ?? 'header';
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            $status = (int) ($_POST['status'] ?? 1);

            if ($name === '') {
                throw new RuntimeException('Tên menu không được để trống.');
            }

            $disabledSlug = menu_url_disabled_category($pdo, $url);
            if ($disabledSlug !== null) {
                throw new RuntimeException('Danh mục "' . $disabledSlug . '" đang tắt hoặc không tồn tại — không thể thêm vào menu.');
            }

            if (!in_array($position, $allowedPositions, true)) {
                $position = 'header';
            }

            if ($parentId > 0) {
                $parentCheck = $pdo->prepare("SELECT id FROM menus WHERE id = ? AND position = ?");
                $parentCheck->execute([$parentId, $position]);
                if (!$parentCheck->fetch()) {
                    $parentId = 0;
                }
            }

            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM menus WHERE position = ? AND parent_id = ?");
            $maxStmt->execute([$position, $parentId]);
            $sortOrder = (int) $maxStmt->fetchColumn() + 1;

            $stmt = $pdo->prepare("INSERT INTO menus (name, url, parent_id, sort_order, position, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $url, $parentId, $sortOrder, $position, $status]);
            $new_id = $pdo->lastInsertId();

            if (function_exists('log_activity')) {
                log_activity('create', 'menu', $new_id, "Thêm menu: $name");
            }

            $success = 'Đã thêm menu mới.';
            $activePosition = $position;
        }

        if ($action === 'update_item') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $position = $_POST['position'] ?? 'header';
            $parentId = (int) ($_POST['parent_id'] ?? 0);
            $status = (int) ($_POST['status'] ?? 1);

            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Dữ liệu cập nhật không hợp lệ.');
            }

            $disabledSlug = menu_url_disabled_category($pdo, $url);
            if ($disabledSlug !== null) {
                throw new RuntimeException('Danh mục "' . $disabledSlug . '" đang tắt hoặc không tồn tại — không thể cập nhật menu này.');
            }

            if (!in_array($position, $allowedPositions, true)) {
                $position = 'header';
            }

            if ($parentId === $id) {
                $parentId = 0;
            }

            if ($parentId > 0) {
                $parentCheck = $pdo->prepare("SELECT id FROM menus WHERE id = ? AND position = ?");
                $parentCheck->execute([$parentId, $position]);
                if (!$parentCheck->fetch()) {
                    $parentId = 0;
                }
            }

            if ($parentId > 0) {
                $menusForPosition = fetch_menus_by_position($pdo, $position);
                $childrenMap = [];
                foreach ($menusForPosition as $menuRow) {
                    $pid = (int) ($menuRow['parent_id'] ?? 0);
                    $childrenMap[$pid][] = (int) $menuRow['id'];
                }

                $descendantIds = [];
                collect_descendant_ids($childrenMap, $id, $descendantIds);
                if (in_array($parentId, $descendantIds, true)) {
                    $parentId = 0;
                }
            }

            $updateStmt = $pdo->prepare("UPDATE menus SET name = ?, url = ?, parent_id = ?, position = ?, status = ? WHERE id = ?");
            $updateStmt->execute([$name, $url, $parentId, $position, $status, $id]);

            if (function_exists('log_activity')) {
                log_activity('update', 'menu', $id, "Cập nhật menu: $name");
            }

            $success = 'Đã cập nhật menu.';
            $activePosition = $position;
        }

        if ($action === 'delete_item') {
            $id = (int) ($_POST['id'] ?? 0);
            $position = $_POST['position'] ?? $activePosition;
            if (!in_array($position, $allowedPositions, true)) {
                $position = 'header';
            }

            if ($id > 0) {
                $pdo->beginTransaction();

                $liftStmt = $pdo->prepare("UPDATE menus SET parent_id = 0 WHERE parent_id = ?");
                $liftStmt->execute([$id]);

                $deleteStmt = $pdo->prepare("DELETE FROM menus WHERE id = ?");
                $deleteStmt->execute([$id]);

                $pdo->commit();
                if (function_exists('log_activity')) {
                    log_activity('delete', 'menu', $id, "Xóa menu ID: $id");
                }
                $success = 'Đã xóa menu.';
            }

            $activePosition = $position;
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Lỗi hệ thống: ' . $e->getMessage();
    }
}

$menusByPosition = [];
$menuTrees = [];
$menuOptionsPayload = [];

foreach ($allowedPositions as $position) {
    $menusByPosition[$position] = fetch_menus_by_position($pdo, $position);
    $menuTrees[$position] = build_admin_menu_tree($menusByPosition[$position]);
    $menuOptionsPayload[$position] = flatten_tree_options($menuTrees[$position]);
}

// Load danh mục để gợi ý URL menu tự động (chọn nhanh thay vì gõ URL tay)
if (!function_exists('categoryUrl')) {
    require_once '../../includes/url-helper.php';
}
$menu_cat_options = [];
try {
    $cat_deleted_filter = has_table_column($pdo, 'categories', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
    $menu_categories = $pdo->query("SELECT id, name, slug, parent_id FROM categories WHERE status = 1{$cat_deleted_filter} ORDER BY parent_id ASC, sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $cat_parents = [];
    $cat_children = [];
    foreach ($menu_categories as $c) {
        if ((int) $c['parent_id'] === 0) {
            $cat_parents[] = $c;
        } else {
            $cat_children[(int) $c['parent_id']][] = $c;
        }
    }
    foreach ($cat_parents as $p) {
        $menu_cat_options[] = ['name' => $p['name'], 'url' => categoryUrl($p['slug']), 'depth' => 0];
        foreach ($cat_children[(int) $p['id']] ?? [] as $ch) {
            $menu_cat_options[] = ['name' => $ch['name'], 'url' => categoryUrl($ch['slug']), 'depth' => 1];
        }
    }
} catch (Throwable $e) {
    $menu_cat_options = [];
}

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-list-nested me-2"></i>Cấu hình Menu</h1>
        <a href="<?php echo BASE_URL; ?>" target="_blank" class="btn btn-outline-primary">
            <i class="bi bi-eye me-1"></i>Xem giao diện
        </a>
    </div>

    <div id="menuNotice">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo e($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo e($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Thêm menu</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="addMenuForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_item">

                        <div class="mb-3">
                            <label class="form-label">Tên hiển thị</label>
                            <input type="text" class="form-control" name="name" id="addName" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="text" class="form-control" name="url" id="addUrlInput" placeholder="index.php hoặc https://...">
                            <div class="form-text">Có thể nhập URL nội bộ, URL đầy đủ, `#`, `tel:` hoặc `mailto:`.</div>
                        </div>

                        <?php if (!empty($menu_cat_options)): ?>
                        <div class="mb-3">
                            <label class="form-label">Hoặc chọn nhanh theo danh mục</label>
                            <select class="form-select" id="addCategoryQuick">
                                <option value="">-- Chọn danh mục để tự điền URL --</option>
                                <?php foreach ($menu_cat_options as $opt): ?>
                                    <option value="<?php echo e($opt['url']); ?>" data-name="<?php echo e($opt['name']); ?>">
                                        <?php echo ($opt['depth'] > 0 ? '— ' : '') . e($opt['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Chọn danh mục sẽ tự điền URL (và Tên nếu đang để trống).</div>
                        </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Vị trí</label>
                                <select class="form-select" name="position" id="addPositionSelect">
                                    <option value="header" <?php echo $activePosition === 'header' ? 'selected' : ''; ?>>
                                        Header</option>
                                    <option value="footer" <?php echo $activePosition === 'footer' ? 'selected' : ''; ?>>
                                        Footer</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Trạng thái</label>
                                <select class="form-select" name="status">
                                    <option value="1" selected>Hiển thị</option>
                                    <option value="0">Ẩn</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Menu cha</label>
                            <select class="form-select" name="parent_id" id="addParentSelect">
                                <option value="0">-- Cấp gốc --</option>
                            </select>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Thêm
                                menu</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activePosition === 'header' ? 'active' : ''; ?>"
                                data-bs-toggle="tab" data-bs-target="#tab-header" type="button"
                                role="tab">Header</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $activePosition === 'footer' ? 'active' : ''; ?>"
                                data-bs-toggle="tab" data-bs-target="#tab-footer" type="button"
                                role="tab">Footer</button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-1"></i>Kéo thả để đổi thứ tự và phân cấp. Kéo vào vùng trống bên
                        dưới mỗi item để tạo menu con.
                    </div>

                    <div class="tab-content">
                        <?php foreach ($allowedPositions as $position): ?>
                            <div class="tab-pane fade <?php echo $activePosition === $position ? 'show active' : ''; ?>"
                                id="tab-<?php echo $position; ?>" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3"
                                    data-position="<?php echo $position; ?>">
                                    <h6 class="mb-0 text-capitalize"><?php echo $position; ?> menu</h6>
                                    <button type="button" class="btn btn-sm btn-primary save-tree-btn"
                                        data-position="<?php echo $position; ?>">
                                        <i class="bi bi-floppy me-1"></i>Lưu thứ tự
                                    </button>
                                </div>

                                <ul class="menu-sortable menu-tree-root" id="menuTree-<?php echo $position; ?>"
                                    data-position="<?php echo $position; ?>">
                                    <?php render_admin_menu_items($menuTrees[$position]); ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editMenuModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editMenuForm">
                <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="id" id="editId">

                <div class="modal-header">
                    <h5 class="modal-title">Cập nhật menu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tên hiển thị</label>
                        <input type="text" class="form-control" name="name" id="editName" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="text" class="form-control" name="url" id="editUrl">
                    </div>

                    <?php if (!empty($menu_cat_options)): ?>
                    <div class="mb-3">
                        <label class="form-label">Hoặc chọn nhanh theo danh mục</label>
                        <select class="form-select" id="editCategoryQuick">
                            <option value="">-- Chọn danh mục để tự điền URL --</option>
                            <?php foreach ($menu_cat_options as $opt): ?>
                                <option value="<?php echo e($opt['url']); ?>" data-name="<?php echo e($opt['name']); ?>">
                                    <?php echo ($opt['depth'] > 0 ? '— ' : '') . e($opt['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Chọn danh mục sẽ tự điền URL (và Tên nếu đang để trống).</div>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Vị trí</label>
                            <select class="form-select" name="position" id="editPosition">
                                <option value="header">Header</option>
                                <option value="footer">Footer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="1">Hiển thị</option>
                                <option value="0">Ẩn</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Menu cha</label>
                        <select class="form-select" name="parent_id" id="editParentSelect">
                            <option value="0">-- Cấp gốc --</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
    const menuOptionsByPosition = <?php echo json_encode($menuOptionsPayload, JSON_UNESCAPED_UNICODE); ?>;

    function buildParentSelectOptions(selectEl, position, selectedValue = '0', excludeId = null) {
        const options = menuOptionsByPosition[position] || [];
        selectEl.innerHTML = '<option value="0">-- Cấp gốc --</option>';

        options.forEach((item) => {
            if (excludeId && Number(item.id) === Number(excludeId)) {
                return;
            }

            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.label;
            if (String(item.id) === String(selectedValue)) {
                opt.selected = true;
            }
            selectEl.appendChild(opt);
        });
    }

    function serializeTree(listEl) {
        return Array.from(listEl.children)
            .filter((item) => item.classList.contains('menu-item'))
            .map((item) => {
                const childList = item.querySelector(':scope > .child-list');
                return {
                    id: Number(item.dataset.id),
                    children: childList ? serializeTree(childList) : []
                };
            });
    }

    function showNotice(type, message) {
        if (window.AdminPopup && typeof window.AdminPopup.show === 'function') {
            window.AdminPopup.show(message, type === 'danger' ? 'danger' : type);
            return;
        }
    }

    function initNestedSortable(scopeEl) {
        scopeEl.querySelectorAll('.menu-sortable').forEach((list) => {
            if (list.dataset.sortableInitialized === '1') {
                return;
            }

            const position = list.closest('[data-position]')?.dataset.position || 'header';
            new Sortable(list, {
                group: 'nested-' + position,
                handle: '.drag-handle',
                animation: 180,
                fallbackOnBody: true,
                swapThreshold: 0.7,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen'
            });

            list.dataset.sortableInitialized = '1';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tab-pane').forEach((pane) => initNestedSortable(pane));

        const addPositionSelect = document.getElementById('addPositionSelect');
        const addParentSelect = document.getElementById('addParentSelect');
        const addMenuForm = document.getElementById('addMenuForm');
        buildParentSelectOptions(addParentSelect, addPositionSelect.value, '0');
        addPositionSelect.addEventListener('change', function () {
            buildParentSelectOptions(addParentSelect, this.value, '0');
        });
        if (addMenuForm) {
            addMenuForm.setAttribute('data-confirm', '\u0042\u1ea1n c\u00f3 ch\u1eafc mu\u1ed1n th\u00eam menu n\u00e0y?');
            addMenuForm.setAttribute('data-confirm-title', '\u0054h\u00eam menu m\u1edbi');
            addMenuForm.setAttribute('data-confirm-ok', '\u0054h\u00eam ngay');
            addMenuForm.setAttribute('data-confirm-class', 'btn-primary');
        }

        const editModalEl = document.getElementById('editMenuModal');
        const editModal = new bootstrap.Modal(editModalEl);
        const editId = document.getElementById('editId');
        const editName = document.getElementById('editName');
        const editUrl = document.getElementById('editUrl');
        const editPosition = document.getElementById('editPosition');
        const editStatus = document.getElementById('editStatus');
        const editParentSelect = document.getElementById('editParentSelect');

        document.querySelectorAll('.edit-menu-btn').forEach((btn) => {
            btn.addEventListener('click', function () {
                const menuItem = this.closest('.menu-item');
                if (!menuItem) return;

                const id = menuItem.dataset.id;
                const name = menuItem.dataset.name || '';
                const url = menuItem.dataset.url || '';
                const position = menuItem.dataset.position || 'header';
                const parentId = menuItem.dataset.parentId || '0';
                const status = menuItem.dataset.status || '1';

                editId.value = id;
                editName.value = name;
                editUrl.value = url;
                editPosition.value = position;
                editStatus.value = status;
                buildParentSelectOptions(editParentSelect, position, parentId, id);

                editModal.show();
            });
        });

        editPosition.addEventListener('change', function () {
            buildParentSelectOptions(editParentSelect, this.value, '0', editId.value);
        });

        document.querySelectorAll('.save-tree-btn').forEach((btn) => {
            btn.addEventListener('click', function () {
                const position = this.dataset.position;
                const rootList = document.getElementById('menuTree-' + position);
                if (!rootList) return;

                const tree = serializeTree(rootList);
                const body = new URLSearchParams();
                body.set('action', 'save_tree');
                body.set('position', position);
                body.set('tree', JSON.stringify(tree));
                body.set('csrf_token', AdminSecurity.csrfToken());

                fetch('index.php?position=' + encodeURIComponent(position), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body.toString()
                })
                    .then((res) => res.json())
                    .then((data) => {
                        if (data.success) {
                            showNotice('success', data.message);
                            return;
                        }
                        showNotice('danger', data.message || '\u004b\u0068\u00f4\u006e\u0067 \u0074\u0068\u1ec3 \u006c\u01b0u \u0074\u0068\u1ee9 \u0074\u1ef1 menu.');
                    })
                    .catch(() => {
                        showNotice('danger', '\u004c\u1ed7i k\u1ebft n\u1ed1i khi l\u01b0u menu.');
                    });
            });
        });

        document.querySelectorAll('[data-bs-toggle="tab"]').forEach((tabTrigger) => {
            tabTrigger.addEventListener('shown.bs.tab', function (event) {
                const targetSelector = event.target.getAttribute('data-bs-target');
                const pane = document.querySelector(targetSelector);
                if (pane) {
                    initNestedSortable(pane);
                }
            });
        });
    });
</script>

<style>
    .menu-tree-root,
    .child-list {
        list-style: none;
        margin: 0;
        padding: 0;
        min-height: 14px;
    }

    .menu-tree-root {
        border: 1px dashed #dce3ea;
        border-radius: 12px;
        padding: 0.85rem;
        background: #fafcff;
    }

    .menu-item {
        margin-bottom: 0.65rem;
    }

    .menu-item-card {
        background: #fff;
        border: 1px solid #e7edf4;
        border-radius: 12px;
        padding: 0.75rem 0.8rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .menu-item-card:hover {
        border-color: #c8d9ea;
        box-shadow: 0 8px 18px rgba(30, 41, 59, 0.08);
    }

    .menu-item-main {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        min-width: 0;
        flex: 1;
    }

    .menu-item-meta {
        min-width: 0;
    }

    .menu-item-meta h6,
    .menu-item-meta .small {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .menu-item-actions {
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }

    .drag-handle {
        cursor: grab;
        color: #95a4b5;
        font-size: 1.15rem;
        line-height: 1;
    }

    .drag-handle:hover {
        color: #0d6efd;
    }

    .child-list {
        margin-top: 0.55rem;
        margin-left: 1.6rem;
        padding-left: 0.65rem;
        border-left: 2px dashed #d8e2ec;
    }

    .sortable-ghost {
        opacity: 0.45;
    }

    .sortable-chosen .menu-item-card {
        border-color: #0d6efd;
        box-shadow: 0 12px 24px rgba(13, 110, 253, 0.22);
    }
</style>

<script>
// Chọn nhanh danh mục -> tự điền URL (và Tên nếu đang trống) cho form thêm/sửa menu.
(function () {
    function bindQuickCategory(selectId, urlId, nameId) {
        var sel = document.getElementById(selectId);
        if (!sel) return;
        sel.addEventListener('change', function () {
            var url = this.value;
            if (!url) return;
            var opt = this.options[this.selectedIndex];
            var urlInput = document.getElementById(urlId);
            var nameInput = document.getElementById(nameId);
            if (urlInput) urlInput.value = url;
            if (nameInput && !nameInput.value.trim()) {
                nameInput.value = opt.getAttribute('data-name') || '';
            }
        });
    }
    bindQuickCategory('addCategoryQuick', 'addUrlInput', 'addName');
    bindQuickCategory('editCategoryQuick', 'editUrl', 'editName');
})();
</script>

<?php require_once '../includes/footer.php'; ?>