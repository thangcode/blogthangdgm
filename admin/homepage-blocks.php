<?php
// admin/homepage-blocks.php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/page-cache.php';

$current_page = 'homepage-blocks';
require_admin_login();

function normalize_hex_color_local(string $value, string $default = '#ffffff'): string
{
    $value = trim($value);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) {
        return strtolower($value);
    }
    return strtolower($default);
}

// Migration: add style columns for hard blocks
try {
    $check = $pdo->query("SHOW COLUMNS FROM homepage_blocks LIKE 'layout_style'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE homepage_blocks
            ADD COLUMN layout_style VARCHAR(20) NOT NULL DEFAULT 'simple' AFTER block_icon,
            ADD COLUMN wave_top_color VARCHAR(20) DEFAULT '#f8f9fa' AFTER layout_style,
            ADD COLUMN wave_bottom_color VARCHAR(20) DEFAULT '#ffffff' AFTER wave_top_color");
    }
} catch (Throwable $e) {
    // keep page working even if migration fails
}

// Đảm bảo các hard block blog cơ bản tồn tại (hero/news/faq) để có thể bật/tắt + kéo-thả.
try {
    $hardBlocks = [
        ['hero', 'Banner chính', 'bi-images'],
        ['news', 'Bài viết mới nhất', 'bi-newspaper'],
        ['faq', 'Câu hỏi thường gặp', 'bi-question-circle'],
    ];
    foreach ($hardBlocks as [$bk, $bn, $bi]) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM homepage_blocks WHERE block_key = ?");
        $chk->execute([$bk]);
        if ((int) $chk->fetchColumn() === 0) {
            $maxSort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM homepage_blocks")->fetchColumn();
            $pdo->prepare("INSERT INTO homepage_blocks (block_key, block_name, block_icon, sort_order, is_visible) VALUES (?, ?, ?, ?, 1)")
                ->execute([$bk, $bn, $bi, $maxSort + 1]);
        }
    }
} catch (Throwable $e) {
    // bỏ qua nếu lỗi
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    require_valid_csrf_token(true);
    
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'reorder':
                    $order = json_decode($_POST['order'], true);
                    if (is_array($order)) {
                        foreach ($order as $index => $id) {
                            $stmt = $pdo->prepare("UPDATE homepage_blocks SET sort_order = ? WHERE id = ?");
                            $stmt->execute([$index + 1, $id]);
                        }
                        $response['success'] = true;
                        $response['message'] = 'Đã lưu thứ tự mới!';
                    }
                    break;
                    
                case 'toggle':
                    $id = (int)$_POST['id'];
                    $visible = (int)$_POST['visible'];
                    $stmt = $pdo->prepare("UPDATE homepage_blocks SET is_visible = ? WHERE id = ?");
                    $stmt->execute([$visible, $id]);
                    $response['success'] = true;
                    $response['message'] = $visible ? 'Đã hiện block!' : 'Đã ẩn block!';
                    break;
                    
                case 'rename':
                    $id = (int)$_POST['id'];
                    $name = trim($_POST['name']);
                    if (!empty($name)) {
                        $stmt = $pdo->prepare("UPDATE homepage_blocks SET block_name = ? WHERE id = ?");
                        $stmt->execute([$name, $id]);
                        $response['success'] = true;
                        $response['message'] = 'Đã đổi tên block!';
                        $response['name'] = $name;
                    } else {
                        $response['message'] = 'Tên không được để trống!';
                    }
                    break;

                case 'save_style':
                    $id = (int) ($_POST['id'] ?? 0);
                    $layout_style = $_POST['layout_style'] ?? 'simple';
                    if (!in_array($layout_style, ['simple', 'wave', 'gradient', 'glass', 'aurora', 'sunset', 'minimal', 'neon', 'editorial'], true)) {
                        $layout_style = 'simple';
                    }
                    $wave_top_color = normalize_hex_color_local((string) ($_POST['wave_top_color'] ?? '#f8f9fa'), '#f8f9fa');
                    $wave_bottom_color = normalize_hex_color_local((string) ($_POST['wave_bottom_color'] ?? '#ffffff'), '#ffffff');

                    $metaStmt = $pdo->prepare("SELECT block_key FROM homepage_blocks WHERE id = ? LIMIT 1");
                    $metaStmt->execute([$id]);
                    $blockMeta = $metaStmt->fetch();
                    if (!$blockMeta) {
                        throw new RuntimeException('Block không tồn tại.');
                    }

                    $blockKey = (string) ($blockMeta['block_key'] ?? '');
                    if (strpos($blockKey, 'dynamic_') === 0) {
                        // Bảng dynamic_blocks dùng cột khóa 'block_key' (một số bản cũ có thể là 'block_id')
                        $dynKeyCol = 'block_key';
                        $colChk = $pdo->query("SHOW COLUMNS FROM dynamic_blocks LIKE 'block_id'");
                        if ($colChk && $colChk->fetch()) {
                            $dynKeyCol = 'block_id';
                        }
                        $dynStmt = $pdo->prepare("UPDATE dynamic_blocks
                                                  SET layout_style = ?, wave_top_color = ?, wave_bottom_color = ?
                                                  WHERE {$dynKeyCol} = ?");
                        $dynStmt->execute([$layout_style, $wave_top_color, $wave_bottom_color, $blockKey]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE homepage_blocks
                                               SET layout_style = ?, wave_top_color = ?, wave_bottom_color = ?
                                               WHERE id = ?");
                        $stmt->execute([$layout_style, $wave_top_color, $wave_bottom_color, $id]);
                    }
                    // Lưu spacing (padding/margin px) vào homepage_blocks.settings cho MỌI block
                    $clamp = fn($v) => max(0, min(300, (int) $v));
                    $spacing = [
                        'pt' => $clamp($_POST['pt'] ?? 48),
                        'pb' => $clamp($_POST['pb'] ?? 48),
                        'mt' => $clamp($_POST['mt'] ?? 0),
                        'mb' => $clamp($_POST['mb'] ?? 0),
                    ];
                    $pdo->prepare("UPDATE homepage_blocks SET settings = ? WHERE id = ?")
                        ->execute([json_encode($spacing), $id]);

                    // Riêng block "Banner chính" (hero): lưu thêm kiểu banner full/boxed
                    if ($blockKey === 'hero' && isset($_POST['hero_width'])) {
                        $hw = ($_POST['hero_width'] === 'boxed') ? 'boxed' : 'full';
                        $hck = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key='hero_width'");
                        $hck->execute();
                        if ((int) $hck->fetchColumn() > 0) {
                            $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='hero_width'")->execute([$hw]);
                        } else {
                            $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES ('hero_width', ?, 'general')")->execute([$hw]);
                        }
                    }
                    if (class_exists('PageCache')) { try { PageCache::flush(); } catch (Throwable $e) {} }
                    $response['success'] = true;
                    $response['message'] = 'Đã lưu style block!';
                    break;
            }
        }
    } catch (Throwable $e) {
        error_log('Homepage blocks error: ' . $e->getMessage());
        $response['message'] = 'Không thể xử lý yêu cầu lúc này.';
    }
    
    echo json_encode($response);
    exit;
}

// Fetch blocks
    $blocks = [];
    $load_error = '';
    try {
        $blocks = $pdo->query("SELECT * FROM homepage_blocks ORDER BY sort_order ASC")->fetchAll();
        $dynamicStyles = [];
        if (!empty($blocks)) {
            $dynamicKeys = [];
        foreach ($blocks as $b) {
            $key = (string) ($b['block_key'] ?? '');
            if (strpos($key, 'dynamic_') === 0) {
                $dynamicKeys[] = $key;
            }
            }
            if (!empty($dynamicKeys)) {
                $dynamicKeyColumn = 'block_key';
                try {
                    $colStmt = $pdo->query("SHOW COLUMNS FROM dynamic_blocks LIKE 'block_id'");
                    if (!$colStmt->fetch()) {
                        $colStmt = $pdo->query("SHOW COLUMNS FROM dynamic_blocks LIKE 'block_key'");
                        if (!$colStmt->fetch()) {
                            throw new RuntimeException('Cấu trúc bảng dynamic_blocks thiếu khóa block_id/block_key.');
                        }
                    } else {
                        $dynamicKeyColumn = 'block_id';
                    }
                } catch (Throwable $e) {
                    throw $e;
                }

                $placeholders = implode(',', array_fill(0, count($dynamicKeys), '?'));
                $styleStmt = $pdo->prepare("SELECT {$dynamicKeyColumn} AS block_id, layout_style, wave_top_color, wave_bottom_color
                                        FROM dynamic_blocks
                                        WHERE {$dynamicKeyColumn} IN ($placeholders)");
                $styleStmt->execute($dynamicKeys);
                foreach ($styleStmt->fetchAll() as $row) {
                    $dynamicStyles[(string) $row['block_id']] = $row;
                }
            }
    }
    foreach ($blocks as &$block) {
        $effective = [
            'layout_style' => (string) ($block['layout_style'] ?? 'simple'),
            'wave_top_color' => (string) ($block['wave_top_color'] ?? '#f8f9fa'),
            'wave_bottom_color' => (string) ($block['wave_bottom_color'] ?? '#ffffff'),
        ];
        $key = (string) ($block['block_key'] ?? '');
        if (strpos($key, 'dynamic_') === 0 && isset($dynamicStyles[$key])) {
            $dyn = $dynamicStyles[$key];
            if (!empty($dyn['layout_style'])) {
                $effective['layout_style'] = (string) $dyn['layout_style'];
            }
            if (!empty($dyn['wave_top_color'])) {
                $effective['wave_top_color'] = (string) $dyn['wave_top_color'];
            }
            if (!empty($dyn['wave_bottom_color'])) {
                $effective['wave_bottom_color'] = (string) $dyn['wave_bottom_color'];
            }
        }
        $block['effective_layout_style'] = $effective['layout_style'];
        $block['effective_wave_top_color'] = $effective['wave_top_color'];
        $block['effective_wave_bottom_color'] = $effective['wave_bottom_color'];
    }
    unset($block);
} catch (Throwable $e) {
    $load_error = $e->getMessage();
}

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="bi bi-layout-text-window-reverse me-2"></i>Quản Lý Block Trang Chủ</h1>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
        <i class="bi bi-info-circle-fill me-2 fs-5"></i>
        <div>
            <strong>Hướng dẫn:</strong> Kéo thả để sắp xếp thứ tự hiển thị. Dùng công tắc để ẩn/hiện từng block trên trang chủ.
        </div>
    </div>

    <!-- Cấu hình banner đã được tích hợp vào nút "Style" của block "Banner chính" -->
    <?php $cur_hero_width = (string) get_setting('hero_width', 'full'); ?>
    <script>window.CURRENT_HERO_WIDTH = <?php echo json_encode($cur_hero_width === 'boxed' ? 'boxed' : 'full'); ?>;</script>

    <?php if (!empty($load_error)): ?>
    <div class="alert alert-danger">Không thể tải dữ liệu block: <?php echo e($load_error); ?></div>
    <?php endif; ?>

    <!-- Blocks List -->
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Danh sách Block</h5>
                <span class="badge bg-primary"><?php echo count($blocks); ?> block</span>
            </div>
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush" id="blocksList">
                <?php foreach ($blocks as $block): ?>
                <?php $bsp = json_decode((string)($block['settings'] ?? ''), true); $bsp = is_array($bsp) ? $bsp : []; ?>
                <li class="list-group-item block-item d-flex align-items-center py-3"
                    data-id="<?php echo $block['id']; ?>"
                    data-block-key="<?php echo e($block['block_key']); ?>"
                    data-layout-style="<?php echo e($block['effective_layout_style'] ?? 'simple'); ?>"
                    data-wave-top-color="<?php echo e($block['effective_wave_top_color'] ?? '#f8f9fa'); ?>"
                    data-wave-bottom-color="<?php echo e($block['effective_wave_bottom_color'] ?? '#ffffff'); ?>"
                    data-pt="<?php echo (int)($bsp['pt'] ?? 48); ?>"
                    data-pb="<?php echo (int)($bsp['pb'] ?? 48); ?>"
                    data-mt="<?php echo (int)($bsp['mt'] ?? 0); ?>"
                    data-mb="<?php echo (int)($bsp['mb'] ?? 0); ?>">
                    <!-- Drag Handle -->
                    <div class="drag-handle me-3 text-muted" style="cursor: grab;">
                        <i class="bi bi-grip-vertical fs-4"></i>
                    </div>
                    
                    <!-- Block Icon -->
                    <div class="block-icon me-3">
                        <div class="icon-box bg-primary bg-opacity-10 rounded-3 p-2">
                            <i class="bi <?php echo e($block['block_icon']); ?> text-primary fs-4"></i>
                        </div>
                    </div>
                    
                    <!-- Block Info -->
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-bold block-name" data-id="<?php echo $block['id']; ?>">
                            <?php echo e($block['block_name']); ?>
                            <button class="btn btn-sm btn-link p-0 ms-2 edit-name-btn" data-id="<?php echo $block['id']; ?>" data-name="<?php echo e($block['block_name']); ?>" title="Sửa tên">
                                <i class="bi bi-pencil text-muted"></i>
                            </button>
                        </h6>
                        <span class="badge bg-light text-muted"><?php echo e($block['block_key']); ?></span>
                        <span class="badge bg-secondary-subtle text-secondary ms-1"><?php echo e($block['effective_layout_style'] ?? 'simple'); ?></span>
                    </div>
                    
                    <div class="me-3">
                        <button type="button" class="btn btn-sm btn-outline-primary style-btn" data-id="<?php echo $block['id']; ?>">
                            <i class="bi bi-palette me-1"></i>Style
                        </button>
                    </div>

                    <!-- Visibility Toggle -->
                    <div class="form-check form-switch">
                        <input class="form-check-input visibility-toggle" type="checkbox" role="switch" 
                               id="toggle-<?php echo $block['id']; ?>" 
                               data-id="<?php echo $block['id']; ?>"
                               <?php echo $block['is_visible'] ? 'checked' : ''; ?>
                               style="width: 3em; height: 1.5em; cursor: pointer;">
                        <label class="form-check-label small text-muted ms-2" for="toggle-<?php echo $block['id']; ?>">
                            <?php echo $block['is_visible'] ? 'Hiện' : 'Ẩn'; ?>
                        </label>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    
    <!-- Preview Link -->
    <div class="mt-4 text-center">
        <a href="<?php echo BASE_URL; ?>" target="_blank" class="btn btn-outline-primary">
            <i class="bi bi-eye me-2"></i>Xem trang chủ
        </a>
    </div>
</div>

<!-- Style Modal -->
<div class="modal fade" id="styleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-palette me-2"></i>Cấu hình Style Block</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="styleBlockId" value="">
                <div class="mb-3" id="heroWidthWrap" style="display:none;">
                    <label class="form-label fw-semibold"><i class="bi bi-aspect-ratio me-1"></i>Kiểu hiển thị Banner</label>
                    <select class="form-select" id="styleHeroWidth">
                        <option value="full">Full-width (tràn viền)</option>
                        <option value="boxed">Vừa khung (trong container, bo góc)</option>
                    </select>
                    <small class="text-muted">Chỉ áp dụng cho block "Banner chính".</small>
                    <hr>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Layout Style</label>
                    <select class="form-select" id="styleLayout">
                        <option value="simple">Simple</option>
                        <option value="wave">Wave</option>
                        <option value="gradient">Gradient</option>
                        <option value="glass">Glass</option>
                        <option value="aurora">Aurora</option>
                        <option value="sunset">Sunset</option>
                        <option value="minimal">Minimal</option>
                        <option value="neon">Neon</option>
                        <option value="editorial">Editorial</option>
                    </select>
                </div>
                <div id="waveColorsWrap">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Màu sóng trên</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color" id="styleWaveTopPicker" value="#f8f9fa" title="Chọn màu trên">
                                <input type="text" class="form-control" id="styleWaveTop" value="#f8f9fa">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Màu sóng dưới</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color" id="styleWaveBottomPicker" value="#ffffff" title="Chọn màu dưới">
                                <input type="text" class="form-control" id="styleWaveBottom" value="#ffffff">
                            </div>
                        </div>
                    </div>
                    <small class="text-muted">Hỗ trợ định dạng màu: #rgb, #rgba, #rrggbb, #rrggbbaa</small>
                </div>

                <hr>
                <label class="form-label fw-semibold">Khoảng cách block (px)</label>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-1">Padding trên</label>
                        <input type="number" class="form-control" id="stylePt" min="0" max="300" value="48">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Padding dưới</label>
                        <input type="number" class="form-control" id="stylePb" min="0" max="300" value="48">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Margin trên</label>
                        <input type="number" class="form-control" id="styleMt" min="0" max="300" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Margin dưới</label>
                        <input type="number" class="form-control" id="styleMb" min="0" max="300" value="0">
                    </div>
                </div>
                <small class="text-muted">Padding = khoảng cách bên trong block; Margin = khoảng cách ngoài (giữa các block).</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Đóng</button>
                <button type="button" class="btn btn-primary" id="saveStyleBtn">Lưu style</button>
            </div>
        </div>
    </div>
</div>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<style>
.block-item {
    transition: all 0.2s ease;
    border-left: 3px solid transparent;
}

.block-item:hover {
    background-color: #f8f9fa;
    border-left-color: var(--bs-primary);
}

.block-item.sortable-ghost {
    opacity: 0.4;
    background-color: #e3f2fd;
}

.block-item.sortable-chosen {
    background-color: #fff;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

.drag-handle:hover {
    color: var(--bs-primary) !important;
}

.icon-box {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.form-switch .form-check-input:checked {
    background-color: #28a745;
    border-color: #28a745;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const blocksList = document.getElementById('blocksList');

    const styleModalEl = document.getElementById('styleModal');
    const styleModal = styleModalEl ? new bootstrap.Modal(styleModalEl) : null;
    const styleLayout = document.getElementById('styleLayout');
    const waveColorsWrap = document.getElementById('waveColorsWrap');
    const styleWaveTop = document.getElementById('styleWaveTop');
    const styleWaveBottom = document.getElementById('styleWaveBottom');
    const styleWaveTopPicker = document.getElementById('styleWaveTopPicker');
    const styleWaveBottomPicker = document.getElementById('styleWaveBottomPicker');
    const styleBlockId = document.getElementById('styleBlockId');
    const saveStyleBtn = document.getElementById('saveStyleBtn');
    const styleLabelMap = { simple: 'Simple', wave: 'Wave', gradient: 'Gradient', glass: 'Glass', aurora: 'Aurora', sunset: 'Sunset', minimal: 'Minimal', neon: 'Neon', editorial: 'Editorial' };

    function normalizeHexInput(value) {
        const normalized = (value || '').trim().toLowerCase();
        return /^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/.test(normalized) ? normalized : '';
    }

    function expandShortHex(value) {
        const hex = (value || '').replace('#', '');
        if (hex.length === 3) {
            return `#${hex[0]}${hex[0]}${hex[1]}${hex[1]}${hex[2]}${hex[2]}`;
        }
        if (hex.length === 4) {
            return `#${hex[0]}${hex[0]}${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`;
        }
        if (hex.length === 6 || hex.length === 8) {
            return `#${hex}`;
        }
        return '#f8f9fa';
    }

    function syncWaveColorsFromInputs() {
        const topColor = normalizeHexInput(styleWaveTop.value);
        const bottomColor = normalizeHexInput(styleWaveBottom.value);
        if (topColor && styleWaveTopPicker) {
            styleWaveTopPicker.value = expandShortHex(topColor);
        }
        if (bottomColor && styleWaveBottomPicker) {
            styleWaveBottomPicker.value = expandShortHex(bottomColor);
        }
    }
    
    // Initialize SortableJS
    const sortable = new Sortable(blocksList, {
        handle: '.drag-handle',
        animation: 200,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function() {
            saveOrder();
        }
    });
    
    // Save order via AJAX
    function saveOrder() {
        const order = [];
        blocksList.querySelectorAll('.block-item').forEach(item => {
            order.push(item.dataset.id);
        });
        
        fetch('homepage-blocks.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'reorder',
                order: JSON.stringify(order),
                csrf_token: AdminSecurity.csrfToken()
            }).toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
            }
        });
    }
    
    // Toggle visibility
    document.querySelectorAll('.visibility-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const id = this.dataset.id;
            const visible = this.checked ? 1 : 0;
            const label = this.nextElementSibling;
            
            fetch('homepage-blocks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'toggle',
                    id: id,
                    visible: visible,
                    csrf_token: AdminSecurity.csrfToken()
                }).toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    label.textContent = visible ? 'Hiện' : 'Ẩn';
                    showToast(data.message, 'success');
                }
            });
        });
    });
    
    // Edit block name
    document.querySelectorAll('.edit-name-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const id = this.dataset.id;
            const currentName = this.dataset.name;
            const newName = prompt('Nhập tên mới cho block:', currentName);
            
            if (newName && newName.trim() !== '' && newName !== currentName) {
                fetch('homepage-blocks.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'rename',
                        id: id,
                        name: newName.trim(),
                        csrf_token: AdminSecurity.csrfToken()
                    }).toString()
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Update UI
                        const nameEl = document.querySelector(`.block-name[data-id="${id}"]`);
                        nameEl.firstChild.textContent = data.name + ' ';
                        this.dataset.name = data.name;
                        showToast(data.message, 'success');
                    } else {
                        alert(data.message);
                    }
                });
            }
        });
    });

    function refreshWaveFields() {
        if (!styleLayout || !waveColorsWrap) return;
        waveColorsWrap.style.display = styleLayout.value === 'wave' ? '' : 'none';
        if (styleLayout.value === 'wave') {
            syncWaveColorsFromInputs();
        }
    }
    if (styleLayout) {
        styleLayout.addEventListener('change', refreshWaveFields);
        refreshWaveFields();
    }

    if (styleWaveTopPicker) {
        styleWaveTopPicker.addEventListener('input', function() {
            if (styleWaveTop) {
                styleWaveTop.value = this.value;
            }
        });
    }
    if (styleWaveBottomPicker) {
        styleWaveBottomPicker.addEventListener('input', function() {
            if (styleWaveBottom) {
                styleWaveBottom.value = this.value;
            }
        });
    }
    if (styleWaveTop) {
        styleWaveTop.addEventListener('input', syncWaveColorsFromInputs);
    }
    if (styleWaveBottom) {
        styleWaveBottom.addEventListener('input', syncWaveColorsFromInputs);
    }

    document.querySelectorAll('.style-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const li = this.closest('.block-item');
            if (!li || !styleModal) return;
            styleBlockId.value = li.dataset.id || '';
            styleLayout.value = li.dataset.layoutStyle || 'simple';
            // Hiện ô chọn kiểu banner nếu là block hero
            const heroWrap = document.getElementById('heroWidthWrap');
            const heroSelM = document.getElementById('styleHeroWidth');
            if (heroWrap && heroSelM) {
                if ((li.dataset.blockKey || '') === 'hero') {
                    heroWrap.style.display = '';
                    heroSelM.value = window.CURRENT_HERO_WIDTH || 'full';
                } else {
                    heroWrap.style.display = 'none';
                }
            }
            styleWaveTop.value = li.dataset.waveTopColor || '#f8f9fa';
            styleWaveBottom.value = li.dataset.waveBottomColor || '#ffffff';
            document.getElementById('stylePt').value = li.dataset.pt || '48';
            document.getElementById('stylePb').value = li.dataset.pb || '48';
            document.getElementById('styleMt').value = li.dataset.mt || '0';
            document.getElementById('styleMb').value = li.dataset.mb || '0';
            syncWaveColorsFromInputs();
            refreshWaveFields();
            styleModal.show();
        });
    });

    if (saveStyleBtn) {
        saveStyleBtn.addEventListener('click', function() {
            const id = styleBlockId.value;
            const payload = new URLSearchParams();
            payload.set('action', 'save_style');
            payload.set('id', id);
            payload.set('layout_style', styleLayout.value);
            syncWaveColorsFromInputs();
            payload.set('wave_top_color', styleWaveTop.value.trim());
            payload.set('wave_bottom_color', styleWaveBottom.value.trim());
            payload.set('pt', document.getElementById('stylePt').value || '0');
            payload.set('pb', document.getElementById('stylePb').value || '0');
            payload.set('mt', document.getElementById('styleMt').value || '0');
            payload.set('mb', document.getElementById('styleMb').value || '0');
            // Gửi kèm kiểu banner nếu đang sửa block hero
            const liEl = document.querySelector(`.block-item[data-id="${id}"]`);
            const heroSelM = document.getElementById('styleHeroWidth');
            if (liEl && (liEl.dataset.blockKey || '') === 'hero' && heroSelM) {
                payload.set('hero_width', heroSelM.value);
            }
            payload.set('csrf_token', AdminSecurity.csrfToken());

            fetch('homepage-blocks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Không thể lưu style');
                    return;
                }
                const li = document.querySelector(`.block-item[data-id="${id}"]`);
                if (li) {
                    li.dataset.layoutStyle = styleLayout.value;
                    li.dataset.waveTopColor = styleWaveTop.value.trim();
                    li.dataset.waveBottomColor = styleWaveBottom.value.trim();
                    li.dataset.pt = document.getElementById('stylePt').value;
                    li.dataset.pb = document.getElementById('stylePb').value;
                    li.dataset.mt = document.getElementById('styleMt').value;
                    li.dataset.mb = document.getElementById('styleMb').value;
                    const badge = li.querySelector('.badge.bg-secondary-subtle');
                    if (badge) badge.textContent = styleLabelMap[styleLayout.value] || styleLayout.value;
                }
                showToast(data.message || 'Đã lưu style', 'success');
                styleModal.hide();
                // Cập nhật giá trị hero width hiện tại trong bộ nhớ
                const heroSelM2 = document.getElementById('styleHeroWidth');
                if (li && (li.dataset.blockKey || '') === 'hero' && heroSelM2) {
                    window.CURRENT_HERO_WIDTH = heroSelM2.value;
                }
            })
            .catch(() => alert('Lỗi kết nối khi lưu style'));
        });
    }
    
    // Toast notification
    function showToast(message, type = 'info') {
        // Create toast container if not exists
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1100';
            document.body.appendChild(container);
        }
        
        const toastId = 'toast-' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : 'bg-info';
        
        container.innerHTML = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi bi-check-circle me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        const toast = new bootstrap.Toast(document.getElementById(toastId), { delay: 2000 });
        toast.show();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>


