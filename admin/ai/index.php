<?php
// admin/ai/index.php — Trung tâm cấu hình AI: Providers + Models + Gán model theo tính năng + Test.
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';

require_admin_login();

$current_page = 'ai';
require_once '../includes/header.php';

// Dữ liệu.
try { $providers = $pdo->query("SELECT * FROM ai_providers ORDER BY sort_order ASC, id ASC")->fetchAll(); }
catch (Throwable $e) { $providers = []; }
try { $models = $pdo->query("SELECT m.*, p.name AS provider_name, p.status AS provider_status FROM ai_models m LEFT JOIN ai_providers p ON p.id = m.provider_id ORDER BY m.provider_id ASC, m.sort_order ASC, m.id ASC")->fetchAll(); }
catch (Throwable $e) { $models = []; }

// Model đang bật theo loại (cho dropdown gán).
$chatModels = array_values(array_filter($models, fn($m) => $m['kind'] === 'chat' && (int)$m['status'] === 1 && (int)($m['provider_status'] ?? 0) === 1));
$imageModels = array_values(array_filter($models, fn($m) => $m['kind'] === 'image' && (int)$m['status'] === 1 && (int)($m['provider_status'] ?? 0) === 1));

$features = [
    'write'  => ['label' => 'Viết bài',            'kind' => 'chat',  'icon' => 'pencil-square'],
    'seo'    => ['label' => 'SEO (meta title/desc)', 'kind' => 'chat', 'icon' => 'graph-up-arrow'],
    'vision' => ['label' => 'Vision (đọc ảnh)',    'kind' => 'chat',  'icon' => 'eye', 'vision' => true],
    'image'  => ['label' => 'Tạo ảnh',             'kind' => 'image', 'icon' => 'image'],
];

$assign = [];
foreach (array_keys($features) as $f) {
    $assign[$f]['primary']  = (int) get_setting('ai_' . $f . '_primary', '0');
    $assign[$f]['fallback'] = (int) get_setting('ai_' . $f . '_fallback', '0');
}
$cfg_temp = (string) get_setting('llm_temperature', '0.6');
$cfg_maxtok = (string) get_setting('llm_max_tokens', '1200');
$cfg_imgsize = (string) get_setting('ai_image_size', '1024x1024');
$cfg_vision_on = (string) get_setting('ai_vision_enabled', '1') === '1';

/** Render <option> cho dropdown model theo loại. */
function ai_model_options(array $list, int $selected, bool $preferVision = false): string {
    $html = '<option value="0">— Không chọn —</option>';
    foreach ($list as $m) {
        $canVision = (int) $m['can_vision'] === 1;
        $badge = $preferVision && $canVision ? ' [vision]' : '';
        // Với tính năng vision: khóa model không có can_vision để tránh chọn nhầm —
        // llm_resolve_feature sẽ loại những model này và báo "Chưa gắn model".
        $disabled = $preferVision && !$canVision ? ' disabled' : '';
        $note = $preferVision && !$canVision ? ' (không đọc ảnh)' : '';
        $sel = ((int) $m['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . (int) $m['id'] . '"' . $sel . $disabled . '>'
            . e($m['label'] ?: $m['model_name']) . ' (' . e($m['provider_name'] ?? '?') . ')' . $badge . $note . '</option>';
    }
    return $html;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1"><i class="bi bi-robot me-2 text-primary"></i>Cấu hình AI</h1>
            <p class="text-muted mb-0 small">Quản lý nhà cung cấp, model và gán model cho từng tính năng.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>admin/settings/index.php?tab=ai" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="bi bi-arrow-left me-2"></i> Về Cấu hình
        </a>
    </div>

    <!-- ============ KHỐI 1: PROVIDERS ============ -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4 border-bottom">
            <h5 class="mb-0 fw-bold"><i class="bi bi-hdd-network me-2 text-primary"></i>Nhà cung cấp (Provider)</h5>
            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" id="btnAddProvider">
                <i class="bi bi-plus-lg me-1"></i> Thêm provider
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 small text-uppercase fw-bold text-muted">Tên</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Loại API</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Endpoint</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Key</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Trạng thái</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted pe-4 text-end">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="providerTbody">
                        <?php if (empty($providers)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">Chưa có provider. Bấm "Thêm provider".</td></tr>
                        <?php else: foreach ($providers as $p):
                            $hasKey = trim((string) ($p['api_key_enc'] ?? '')) !== '';
                        ?>
                            <tr data-id="<?php echo (int) $p['id']; ?>"
                                data-name="<?php echo e($p['name']); ?>"
                                data-api_type="<?php echo e($p['api_type']); ?>"
                                data-endpoint="<?php echo e($p['endpoint']); ?>"
                                data-status="<?php echo (int) $p['status']; ?>"
                                data-sort_order="<?php echo (int) $p['sort_order']; ?>"
                                data-haskey="<?php echo $hasKey ? '1' : '0'; ?>">
                                <td class="ps-4 fw-semibold"><?php echo e($p['name']); ?></td>
                                <td><span class="badge bg-<?php echo $p['api_type'] === 'anthropic' ? 'warning text-dark' : 'info text-dark'; ?> rounded-pill"><?php echo e($p['api_type']); ?></span></td>
                                <td class="small text-muted text-truncate" style="max-width:260px;"><?php echo e($p['endpoint']); ?></td>
                                <td><?php echo $hasKey ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo (int) $p['status'] === 1 ? 'success' : 'secondary'; ?> rounded-pill">
                                        <?php echo (int) $p['status'] === 1 ? 'Bật' : 'Tắt'; ?>
                                    </span>
                                </td>
                                <td class="pe-4 text-end">
                                    <button class="btn btn-sm btn-light border rounded-pill px-3 me-1 btn-edit-provider"><i class="bi bi-pencil text-primary"></i></button>
                                    <button class="btn btn-sm btn-light border rounded-pill px-3 btn-del-provider"><i class="bi bi-trash text-danger"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ KHỐI 2: MODELS ============ -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4 border-bottom">
            <h5 class="mb-0 fw-bold"><i class="bi bi-cpu me-2 text-primary"></i>Model</h5>
            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3" id="btnAddModel" <?php echo empty($providers) ? 'disabled' : ''; ?>>
                <i class="bi bi-plus-lg me-1"></i> Thêm model
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 small text-uppercase fw-bold text-muted">Nhãn / Model</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Provider</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Loại</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Vision</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Trạng thái</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted pe-4 text-end">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="modelTbody">
                        <?php if (empty($models)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">Chưa có model.</td></tr>
                        <?php else: foreach ($models as $m): ?>
                            <tr data-id="<?php echo (int) $m['id']; ?>"
                                data-provider_id="<?php echo (int) $m['provider_id']; ?>"
                                data-model_name="<?php echo e($m['model_name']); ?>"
                                data-label="<?php echo e($m['label']); ?>"
                                data-kind="<?php echo e($m['kind']); ?>"
                                data-can_vision="<?php echo (int) $m['can_vision']; ?>"
                                data-status="<?php echo (int) $m['status']; ?>"
                                data-sort_order="<?php echo (int) $m['sort_order']; ?>">
                                <td class="ps-4">
                                    <div class="fw-semibold"><?php echo e($m['label'] ?: $m['model_name']); ?></div>
                                    <div class="small text-muted"><code><?php echo e($m['model_name']); ?></code></div>
                                </td>
                                <td><?php echo e($m['provider_name'] ?? '—'); ?></td>
                                <td><span class="badge bg-<?php echo $m['kind'] === 'image' ? 'primary' : 'dark'; ?> rounded-pill"><?php echo e($m['kind']); ?></span></td>
                                <td><?php echo (int) $m['can_vision'] === 1 ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<span class="text-muted">—</span>'; ?></td>
                                <td><span class="badge bg-<?php echo (int) $m['status'] === 1 ? 'success' : 'secondary'; ?> rounded-pill"><?php echo (int) $m['status'] === 1 ? 'Bật' : 'Tắt'; ?></span></td>
                                <td class="pe-4 text-end">
                                    <button class="btn btn-sm btn-light border rounded-pill px-3 me-1 btn-edit-model"><i class="bi bi-pencil text-primary"></i></button>
                                    <button class="btn btn-sm btn-light border rounded-pill px-3 btn-del-model"><i class="bi bi-trash text-danger"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ KHỐI 3: GÁN MODEL THEO TÍNH NĂNG ============ -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
            <h5 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-primary"></i>Gán model theo tính năng</h5>
        </div>
        <div class="card-body p-4">
            <form id="assignForm">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th class="small text-uppercase fw-bold text-muted">Tính năng</th>
                                <th class="small text-uppercase fw-bold text-muted">Model chính</th>
                                <th class="small text-uppercase fw-bold text-muted">Model dự phòng</th>
                                <th class="small text-uppercase fw-bold text-muted text-end">Test</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($features as $fkey => $f):
                                $list = $f['kind'] === 'image' ? $imageModels : $chatModels;
                                $preferVision = !empty($f['vision']);
                            ?>
                            <tr>
                                <td class="fw-semibold" style="min-width:150px;">
                                    <i class="bi bi-<?php echo $f['icon']; ?> me-1 text-primary"></i><?php echo e($f['label']); ?>
                                    <div class="small text-muted fw-normal"><?php echo e($f['kind']); ?></div>
                                </td>
                                <td style="min-width:240px;">
                                    <select class="form-select form-select-sm" name="ai_<?php echo $fkey; ?>_primary">
                                        <?php echo ai_model_options($list, $assign[$fkey]['primary'], $preferVision); ?>
                                    </select>
                                </td>
                                <td style="min-width:240px;">
                                    <select class="form-select form-select-sm" name="ai_<?php echo $fkey; ?>_fallback">
                                        <?php echo ai_model_options($list, $assign[$fkey]['fallback'], $preferVision); ?>
                                    </select>
                                </td>
                                <td class="text-end" style="min-width:120px;">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 btn-test-feature" data-feature="<?php echo $fkey; ?>">
                                        <i class="bi bi-lightning-charge-fill me-1"></i>Test
                                    </button>
                                </td>
                            </tr>
                            <tr class="test-result-row" data-feature="<?php echo $fkey; ?>" style="display:none;">
                                <td colspan="4" class="bg-light-subtle"><div class="test-result-body small"></div></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr class="my-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-sliders me-1 text-primary"></i>Tham số chung</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Temperature</label>
                        <input type="number" step="0.1" min="0" max="2" class="form-control" name="llm_temperature" value="<?php echo e($cfg_temp); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Max tokens</label>
                        <input type="number" min="100" max="8000" class="form-control" name="llm_max_tokens" value="<?php echo e($cfg_maxtok); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold small">Kích thước ảnh</label>
                        <input type="text" class="form-control" name="ai_image_size" value="<?php echo e($cfg_imgsize); ?>" placeholder="1024x1024">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="ai_vision_enabled" name="ai_vision_enabled" <?php echo $cfg_vision_on ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold small" for="ai_vision_enabled">Bật Vision</label>
                        </div>
                    </div>
                </div>

                <div class="d-grid d-md-flex justify-content-md-end mt-4">
                    <button type="submit" class="btn btn-primary rounded-pill px-4" id="btnSaveAssign">
                        <i class="bi bi-save me-2"></i>Lưu gán model
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Provider -->
<div class="modal fade" id="providerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <form id="providerForm">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold" id="providerModalTitle">Thêm provider</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="0">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Tên <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="VD: OpenAI chính thức">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Loại API</label>
                        <select class="form-select" name="api_type">
                            <option value="openai">openai (chat/completions, images)</option>
                            <option value="anthropic">anthropic (messages)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Endpoint (Base URL) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="endpoint" required placeholder="https://api.openai.com/v1">
                        <div class="form-text">Không kèm <code>/chat/completions</code> — hệ thống tự nối path.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">API Key</label>
                        <input type="password" class="form-control" name="api_key" autocomplete="new-password" placeholder="Nhập key...">
                        <div class="form-text" id="providerKeyHint">Key được mã hóa khi lưu.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Thứ tự</label>
                            <input type="number" class="form-control" name="sort_order" value="0">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="status" id="providerStatus" checked>
                                <label class="form-check-label fw-bold small" for="providerStatus">Bật</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Model -->
<div class="modal fade" id="modelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <form id="modelForm">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold" id="modelModalTitle">Thêm model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="0">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Provider <span class="text-danger">*</span></label>
                        <select class="form-select" name="provider_id" required>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>"><?php echo e($p['name']); ?> (<?php echo e($p['api_type']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Tên model (gửi API) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="model_name" required placeholder="VD: gpt-4o, claude-opus-4.8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Nhãn hiển thị</label>
                        <input type="text" class="form-control" name="label" placeholder="Để trống = dùng tên model">
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Loại</label>
                            <select class="form-select" name="kind" id="modelKind">
                                <option value="chat">chat (viết/seo/vision)</option>
                                <option value="image">image (tạo ảnh)</option>
                            </select>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch" id="modelVisionWrap">
                                <input class="form-check-input" type="checkbox" name="can_vision" id="modelCanVision">
                                <label class="form-check-label fw-bold small" for="modelCanVision">Đọc ảnh (vision)</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Thứ tự</label>
                            <input type="number" class="form-control" name="sort_order" value="0">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="status" id="modelStatus" checked>
                                <label class="form-check-label fw-bold small" for="modelStatus">Bật</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

<script>
(function () {
    const providerModal = new bootstrap.Modal(document.getElementById('providerModal'));
    const modelModal = new bootstrap.Modal(document.getElementById('modelModal'));

    function post(url, data) {
        const body = new URLSearchParams();
        AdminSecurity.applyCsrf(body);
        Object.keys(data).forEach(k => body.set(k, data[k]));
        return fetch(url, {
            method: 'POST',
            headers: AdminSecurity.headers({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }),
            body
        }).then(r => r.json());
    }

    // ---------- PROVIDER ----------
    const pForm = document.getElementById('providerForm');
    document.getElementById('btnAddProvider').addEventListener('click', function () {
        pForm.reset();
        pForm.querySelector('[name=id]').value = '0';
        pForm.querySelector('[name=status]').checked = true;
        document.getElementById('providerModalTitle').textContent = 'Thêm provider';
        document.getElementById('providerKeyHint').textContent = 'Key được mã hóa khi lưu.';
        providerModal.show();
    });
    document.querySelectorAll('.btn-edit-provider').forEach(btn => {
        btn.addEventListener('click', function () {
            const tr = this.closest('tr'), d = tr.dataset;
            pForm.querySelector('[name=id]').value = d.id;
            pForm.querySelector('[name=name]').value = d.name;
            pForm.querySelector('[name=api_type]').value = d.api_type;
            pForm.querySelector('[name=endpoint]').value = d.endpoint;
            pForm.querySelector('[name=api_key]').value = '';
            pForm.querySelector('[name=sort_order]').value = d.sort_order;
            pForm.querySelector('[name=status]').checked = d.status === '1';
            document.getElementById('providerModalTitle').textContent = 'Sửa provider';
            document.getElementById('providerKeyHint').textContent = d.haskey === '1' ? 'Để trống để giữ key hiện tại.' : 'Key được mã hóa khi lưu.';
            providerModal.show();
        });
    });
    pForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const data = {
            id: pForm.querySelector('[name=id]').value,
            name: pForm.querySelector('[name=name]').value,
            api_type: pForm.querySelector('[name=api_type]').value,
            endpoint: pForm.querySelector('[name=endpoint]').value,
            api_key: pForm.querySelector('[name=api_key]').value,
            sort_order: pForm.querySelector('[name=sort_order]').value,
            status: pForm.querySelector('[name=status]').checked ? '1' : '0'
        };
        post('provider-save.php', data).then(r => {
            if (r.success) { AdminPopup.success(r.message); setTimeout(() => location.reload(), 700); }
            else AdminPopup.error(r.message || 'Lỗi.');
        }).catch(() => AdminPopup.error('Lỗi kết nối.'));
    });
    document.querySelectorAll('.btn-del-provider').forEach(btn => {
        btn.addEventListener('click', function () {
            const tr = this.closest('tr'), id = tr.dataset.id, name = tr.dataset.name;
            AdminPopup.confirm({ message: 'Xóa provider "' + name + '" và tất cả model thuộc nó?', title: 'Xóa provider', confirmClass: 'btn-danger', confirmText: 'Xóa' })
                .then(ok => {
                    if (!ok) return;
                    post('provider-delete.php', { id }).then(r => {
                        if (r.success) { AdminPopup.success(r.message); setTimeout(() => location.reload(), 700); }
                        else AdminPopup.error(r.message || 'Lỗi.');
                    });
                });
        });
    });

    // ---------- MODEL ----------
    const mForm = document.getElementById('modelForm');
    const modelKind = document.getElementById('modelKind');
    const modelVisionWrap = document.getElementById('modelVisionWrap');
    function syncVisionVisibility() {
        modelVisionWrap.style.display = modelKind.value === 'image' ? 'none' : '';
    }
    modelKind.addEventListener('change', syncVisionVisibility);

    const btnAddModel = document.getElementById('btnAddModel');
    if (btnAddModel) btnAddModel.addEventListener('click', function () {
        mForm.reset();
        mForm.querySelector('[name=id]').value = '0';
        mForm.querySelector('[name=status]').checked = true;
        document.getElementById('modelModalTitle').textContent = 'Thêm model';
        syncVisionVisibility();
        modelModal.show();
    });
    document.querySelectorAll('.btn-edit-model').forEach(btn => {
        btn.addEventListener('click', function () {
            const d = this.closest('tr').dataset;
            mForm.querySelector('[name=id]').value = d.id;
            mForm.querySelector('[name=provider_id]').value = d.provider_id;
            mForm.querySelector('[name=model_name]').value = d.model_name;
            mForm.querySelector('[name=label]').value = d.label;
            mForm.querySelector('[name=kind]').value = d.kind;
            mForm.querySelector('[name=can_vision]').checked = d.can_vision === '1';
            mForm.querySelector('[name=sort_order]').value = d.sort_order;
            mForm.querySelector('[name=status]').checked = d.status === '1';
            document.getElementById('modelModalTitle').textContent = 'Sửa model';
            syncVisionVisibility();
            modelModal.show();
        });
    });
    mForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const data = {
            id: mForm.querySelector('[name=id]').value,
            provider_id: mForm.querySelector('[name=provider_id]').value,
            model_name: mForm.querySelector('[name=model_name]').value,
            label: mForm.querySelector('[name=label]').value,
            kind: mForm.querySelector('[name=kind]').value,
            can_vision: mForm.querySelector('[name=can_vision]').checked ? '1' : '0',
            sort_order: mForm.querySelector('[name=sort_order]').value,
            status: mForm.querySelector('[name=status]').checked ? '1' : '0'
        };
        post('model-save.php', data).then(r => {
            if (r.success) { AdminPopup.success(r.message); setTimeout(() => location.reload(), 700); }
            else AdminPopup.error(r.message || 'Lỗi.');
        }).catch(() => AdminPopup.error('Lỗi kết nối.'));
    });
    document.querySelectorAll('.btn-del-model').forEach(btn => {
        btn.addEventListener('click', function () {
            const d = this.closest('tr').dataset;
            AdminPopup.confirm({ message: 'Xóa model "' + (d.label || d.model_name) + '"?', title: 'Xóa model', confirmClass: 'btn-danger', confirmText: 'Xóa' })
                .then(ok => {
                    if (!ok) return;
                    post('model-delete.php', { id: d.id }).then(r => {
                        if (r.success) { AdminPopup.success(r.message); setTimeout(() => location.reload(), 700); }
                        else AdminPopup.error(r.message || 'Lỗi.');
                    });
                });
        });
    });

    // ---------- ASSIGN SAVE ----------
    document.getElementById('assignForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData(this);
        const data = {};
        fd.forEach((v, k) => data[k] = v);
        data['ai_vision_enabled'] = this.querySelector('[name=ai_vision_enabled]').checked ? '1' : '0';
        const btn = document.getElementById('btnSaveAssign');
        btn.disabled = true;
        post('assign-save.php', data).then(r => {
            btn.disabled = false;
            if (r.success) AdminPopup.success(r.message);
            else AdminPopup.error(r.message || 'Lỗi.');
        }).catch(() => { btn.disabled = false; AdminPopup.error('Lỗi kết nối.'); });
    });

    // ---------- TEST FEATURE ----------
    function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function renderResult(entry) {
        if (!entry.assigned) {
            return '<div class="mb-1"><span class="badge bg-secondary me-2">' + escapeHtml(entry.role) + '</span><span class="text-muted">' + escapeHtml(entry.error || 'Chưa gán.') + '</span></div>';
        }
        const badge = entry.ok ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Lỗi</span>';
        let line = '<div class="mb-2"><span class="badge bg-primary me-2">' + escapeHtml(entry.role) + '</span>' + badge
            + ' <code>' + escapeHtml(entry.model_name) + '</code> <span class="text-muted">@' + escapeHtml(entry.provider) + '</span>'
            + ' · ' + (entry.latency_ms || 0) + 'ms';
        if (entry.ok) {
            if (entry.image_url) {
                line += '<br><img src="' + escapeHtml(entry.image_url) + '" alt="test" style="max-width:120px;border-radius:8px;margin-top:6px;">';
            } else {
                line += ' · <span class="text-success">' + escapeHtml(entry.snippet || '') + '</span>';
            }
        } else {
            line += '<br><span class="text-danger">' + escapeHtml(entry.error || '') + '</span>';
        }
        return line + '</div>';
    }
    document.querySelectorAll('.btn-test-feature').forEach(btn => {
        btn.addEventListener('click', function () {
            const feature = this.dataset.feature;
            const row = document.querySelector('.test-result-row[data-feature="' + feature + '"]');
            const body = row.querySelector('.test-result-body');
            row.style.display = '';
            body.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Đang test cả 2 model...';
            this.disabled = true;
            const original = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            post('test.php', { feature }).then(r => {
                this.disabled = false; this.innerHTML = original;
                if (!r.success) { body.innerHTML = '<span class="text-danger">' + escapeHtml(r.message || 'Lỗi.') + '</span>'; return; }
                body.innerHTML = r.results.map(renderResult).join('');
            }).catch(() => {
                this.disabled = false; this.innerHTML = original;
                body.innerHTML = '<span class="text-danger">Lỗi kết nối.</span>';
            });
        });
    });
})();
</script>
