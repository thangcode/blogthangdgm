<?php
// admin/posts/ai-write.php — Viết bài AI: quản lý danh sách ý tưởng + cấu hình chung + xếp hàng viết nền.
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/ai-write.php';
require_once '../../includes/ai-jobs.php';

require_admin_login();
$queueReady = function_exists('ai_jobs_schema_ready') && ai_jobs_schema_ready($pdo);
$ideasReady = $queueReady && ai_write_ensure_schema($pdo);

// Lưu cấu hình viết bài chung (settings).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'config') {
    require_valid_csrf_token();
    $save = static function (string $key, $value) use ($pdo): void {
        $st = $pdo->prepare('SELECT id FROM settings WHERE setting_key=?');
        $st->execute([$key]);
        if ($st->fetch()) {
            $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([(string) $value, $key]);
        } else {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'ai')")->execute([$key, (string) $value]);
        }
    };
    $words = max(400, min(3000, (int) ($_POST['ai_write_default_words'] ?? 1200)));
    $images = max(0, min(3, (int) ($_POST['ai_write_default_images'] ?? 1)));
    $status = (int) ($_POST['ai_write_default_status'] ?? 0);
    if (!in_array($status, [0, 1, 2], true)) $status = 0;
    $save('ai_write_default_words', $words);
    $save('ai_write_default_images', $images);
    $save('ai_write_default_thumb', !empty($_POST['ai_write_default_thumb']) ? '1' : '0');
    $save('ai_write_default_status', $status);
    $save('ai_write_default_category', max(0, (int) ($_POST['ai_write_default_category'] ?? 0)));
    $_SESSION['ai_write_saved'] = '1';
    header('Location: ai-write.php');
    exit;
}

$current_page = 'posts-ai-write';
require_once '../includes/header.php';

$writeOk = function_exists('llm_feature_available') && llm_feature_available('write');
$imageOk = function_exists('llm_feature_available') && llm_feature_available('image');
$ownerId = (int) ($_SESSION['user_id'] ?? 0);

try { $all_cats = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name")->fetchAll(); }
catch (Throwable $e) { $all_cats = []; }

// Đồng bộ trạng thái ý tưởng theo job thật (job fail/cancel thì idea về lỗi, xong thì done).
$ideas = [];
if ($ideasReady) {
    try {
        // Trạng thái theo job trước: post_id được gắn sớm (lúc draft rỗng) nên
        // KHÔNG được suy ra 'done' từ post_id — chỉ 'done' khi job succeeded.
        $pdo->exec("UPDATE ai_write_ideas i LEFT JOIN ai_jobs j ON j.id = i.job_id
            SET i.status = CASE
                WHEN j.status = 'succeeded' THEN 'done'
                WHEN j.status IN ('queued','running','retry_wait') THEN 'running'
                WHEN j.status IN ('failed','cancelled') THEN 'failed'
                WHEN i.job_id IS NULL THEN 'pending'
                ELSE i.status END");
        $st = $pdo->prepare("SELECT i.*, p.title AS post_title, p.slug AS post_slug, j.status AS job_status, j.stage AS job_stage, j.error_code AS job_error, j.message AS job_message
            FROM ai_write_ideas i
            LEFT JOIN posts p ON p.id = i.post_id
            LEFT JOIN ai_jobs j ON j.id = i.job_id
            WHERE i.owner_id = ? ORDER BY i.id DESC LIMIT 300");
        $st->execute([$ownerId]);
        $ideas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $ideas = []; }
}

$recentJobs = [];
if ($queueReady) {
    try {
        $st = $pdo->prepare("SELECT id, batch_id, entity_id, status, stage, attempts, message, payload, result, created_at
            FROM ai_jobs WHERE kind='write' AND owner_id=? ORDER BY id DESC LIMIT 30");
        $st->execute([$ownerId]);
        $recentJobs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $recentJobs = []; }
}
$hasActive = false;
foreach ($recentJobs as $rj) {
    if (in_array($rj['status'], ['queued', 'running', 'retry_wait'], true)) { $hasActive = true; break; }
}
$pendingIdeas = count(array_filter($ideas, fn($i) => in_array($i['status'], ['pending', 'failed'], true)));

$cfg = [
    'words'    => (int) get_setting('ai_write_default_words', '1200'),
    'images'   => (int) get_setting('ai_write_default_images', '1'),
    'thumb'    => (string) get_setting('ai_write_default_thumb', '1') !== '0',
    'status'   => (int) get_setting('ai_write_default_status', '0'),
    'category' => (int) get_setting('ai_write_default_category', '0'),
];
$ideaBadges = [
    'pending' => '<span class="badge bg-secondary rounded-pill">Chưa viết</span>',
    'running' => '<span class="badge bg-primary rounded-pill">Đang viết</span>',
    'done'    => '<span class="badge bg-success rounded-pill">Đã viết</span>',
    'failed'  => '<span class="badge bg-danger rounded-pill">Lỗi</span>',
];
$jobBadges = [
    'queued' => '<span class="badge bg-secondary rounded-pill">Chờ</span>',
    'running' => '<span class="badge bg-primary rounded-pill">Đang chạy</span>',
    'retry_wait' => '<span class="badge bg-warning text-dark rounded-pill">Chờ thử lại</span>',
    'succeeded' => '<span class="badge bg-success rounded-pill">Xong</span>',
    'failed' => '<span class="badge bg-danger rounded-pill">Lỗi</span>',
    'cancelled' => '<span class="badge bg-dark rounded-pill">Đã hủy</span>',
];
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-4 border-bottom">
        <div>
            <h1 class="h2 fw-bold text-dark mb-1"><i class="bi bi-magic me-2 text-primary"></i>Viết bài AI theo từ khóa</h1>
            <p class="text-muted mb-0 small">Thêm ý tưởng vào danh sách, chọn bài để viết — hệ thống tự tạo bài nháp, thumbnail + ảnh minh họa (nén WebP), viết nội dung chuẩn SEO/GEO.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>admin/ai/jobs.php" class="btn btn-outline-secondary rounded-pill px-4"><i class="bi bi-list-task me-2"></i>Hàng đợi AI</a>
            <a href="index.php" class="btn btn-outline-primary rounded-pill px-4"><i class="bi bi-arrow-left me-2"></i>Danh sách bài</a>
        </div>
    </div>

    <?php if (!empty($_SESSION['ai_write_saved'])): unset($_SESSION['ai_write_saved']); ?>
        <div class="alert alert-success rounded-4"><i class="bi bi-check-circle me-2"></i>Đã lưu cấu hình viết bài.</div>
    <?php endif; ?>
    <?php if (!$queueReady): ?>
        <div class="alert alert-danger rounded-4"><i class="bi bi-exclamation-triangle me-2"></i>Chưa cài đặt bảng hàng đợi AI. Chạy <code>php scripts/migrate_ai_jobs.php</code> trước.</div>
    <?php elseif (!$writeOk): ?>
        <div class="alert alert-warning rounded-4"><i class="bi bi-exclamation-triangle me-2"></i>Chưa gán model cho tính năng <strong>Viết bài</strong>. Vào <a href="<?php echo BASE_URL; ?>admin/ai/index.php" class="alert-link">Cấu hình AI</a> để gán model trước khi dùng.</div>
    <?php endif; ?>
    <?php if ($queueReady && !$imageOk && $cfg['thumb']): ?>
        <div class="alert alert-info rounded-4"><i class="bi bi-info-circle me-2"></i>Chưa gán model <strong>Tạo ảnh</strong> — thumbnail/ảnh minh họa sẽ bị bỏ qua. Gán model tại <a href="<?php echo BASE_URL; ?>admin/ai/index.php" class="alert-link">Cấu hình AI</a>.</div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-sliders me-2 text-primary"></i>Cấu hình viết bài (dùng chung)</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
                        <input type="hidden" name="form" value="config">
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Độ dài bài</label>
                            <select name="ai_write_default_words" class="form-select">
                                <?php foreach ([800 => '~800 từ (ngắn)', 1200 => '~1200 từ (vừa)', 1500 => '~1500 từ (dài)', 2000 => '~2000 từ (rất dài)', 2500 => '~2500 từ', 3000 => '~3000 từ'] as $w => $label): ?>
                                    <option value="<?php echo $w; ?>" <?php echo $cfg['words'] === $w ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Ảnh minh họa trong bài</label>
                            <select name="ai_write_default_images" class="form-select">
                                <?php for ($i = 0; $i <= 3; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $cfg['images'] === $i ? 'selected' : ''; ?>><?php echo $i === 0 ? 'Không chèn' : $i . ' ảnh'; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Trạng thái sau khi viết</label>
                            <select name="ai_write_default_status" class="form-select">
                                <option value="0" <?php echo $cfg['status'] === 0 ? 'selected' : ''; ?>>Nháp</option>
                                <option value="1" <?php echo $cfg['status'] === 1 ? 'selected' : ''; ?>>Công khai</option>
                                <option value="2" <?php echo $cfg['status'] === 2 ? 'selected' : ''; ?>>Ẩn</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Chuyên mục</label>
                            <select name="ai_write_default_category" class="form-select">
                                <option value="0">— Không gán —</option>
                                <?php foreach ($all_cats as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $cfg['category'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="cfgThumb" name="ai_write_default_thumb" <?php echo $cfg['thumb'] ? 'checked' : ''; ?> <?php echo $imageOk ? '' : 'disabled'; ?>>
                            <label class="form-check-label fw-bold small" for="cfgThumb">Tự tạo thumbnail AI <?php echo $imageOk ? '' : '(chưa cấu hình model ảnh)'; ?></label>
                        </div>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 w-100"><i class="bi bi-save me-2"></i>Lưu cấu hình</button>
                    </form>
                </div>
            </div>

            <div class="alert alert-light border rounded-4 small mb-0">
                <i class="bi bi-shield-check me-1 text-success"></i>
                <strong>Chống rớt tiến trình:</strong> mỗi bài là 1 tác vụ trong hàng đợi bền bỉ — worker chết giữa chừng sẽ tiếp tục đúng stage, gửi lại không tạo bài trùng, không chặn request web. Cron: <code>php scripts\ai-worker.php</code> mỗi phút.
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-2 text-success"></i>Thêm ý tưởng</h5>
                </div>
                <div class="card-body p-4">
                    <div id="ideaRows">
                        <div class="idea-row mb-2">
                            <input type="text" class="form-control form-control-sm mb-1 idea-title" maxlength="500" placeholder="Tiêu đề / từ khóa *">
                            <input type="text" class="form-control form-control-sm idea-desc" maxlength="2000" placeholder="Mô tả / yêu cầu thêm cho AI (tuỳ chọn)">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" id="btnAddRow"><i class="bi bi-plus-lg me-1"></i>Thêm dòng</button>
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-4" id="btnAddIdeas"><i class="bi bi-check-lg me-1"></i>Thêm vào danh sách</button>
                    </div>
                    <div class="form-text">Tối đa 100 ý tưởng/lần. Mô tả giúp AI viết sát ý hơn (góc nhìn, đối tượng, yêu cầu riêng).</div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4 border-bottom">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-lightbulb me-2 text-warning"></i>Danh sách ý tưởng <span class="badge bg-secondary rounded-pill"><?php echo count($ideas); ?></span></h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="small text-muted"><span id="selCount">0</span> đã chọn</span>
                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" id="btnWriteSelected" <?php echo ($queueReady && $writeOk) ? '' : 'disabled'; ?>><i class="bi bi-stars me-1"></i>Viết các mục đã chọn</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 py-2" style="width:34px"><input type="checkbox" id="checkAll" class="form-check-input"></th>
                                    <th class="py-2 small text-uppercase fw-bold text-muted">Ý tưởng</th>
                                    <th class="py-2 small text-uppercase fw-bold text-muted">Trạng thái</th>
                                    <th class="py-2 small text-uppercase fw-bold text-muted">Bài viết</th>
                                    <th class="py-2 small text-uppercase fw-bold text-muted">Thêm lúc</th>
                                    <th class="py-2 small text-uppercase fw-bold text-muted pe-4 text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ideas)): ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">Chưa có ý tưởng nào — thêm ở khung phía trên.</td></tr>
                                <?php else: foreach ($ideas as $idea):
                                    $stt = (string) $idea['status'];
                                    $writable = in_array($stt, ['pending', 'failed'], true);
                                    $stage = $stt === 'running' ? trim((string) ($idea['job_stage'] ?? '')) : '';
                                    $errMsg = $stt === 'failed' ? trim((string) ($idea['job_message'] ?? $idea['message'] ?? '')) : '';
                                ?>
                                    <tr>
                                        <td class="ps-4"><input type="checkbox" class="form-check-input idea-check" value="<?php echo (int) $idea['id']; ?>" <?php echo $writable ? '' : 'disabled'; ?>></td>
                                        <td class="fw-semibold" style="max-width:340px;">
                                            <span class="d-block text-truncate" style="max-width:330px;" title="<?php echo e($idea['idea']); ?>"><?php echo e($idea['idea']); ?></span>
                                            <?php if (trim((string) ($idea['brief'] ?? '')) !== ''): ?><small class="text-muted d-block text-truncate" style="max-width:330px;" title="<?php echo e((string) $idea['brief']); ?>"><i class="bi bi-card-text me-1"></i><?php echo e(mb_substr((string) $idea['brief'], 0, 90, 'UTF-8')); ?></small><?php endif; ?>
                                            <?php if ($errMsg !== ''): ?><small class="text-danger"><?php echo e(mb_substr($errMsg, 0, 80, 'UTF-8')); ?></small><?php endif; ?>
                                        </td>
                                        <td><?php echo $ideaBadges[$stt] ?? e($stt); ?><?php if ($stage !== ''): ?> <code class="small text-muted"><?php echo e($stage); ?></code><?php endif; ?></td>
                                        <td>
                                            <?php if (!empty($idea['post_id']) && $stt === 'done'): ?>
                                                <a href="edit.php?id=<?php echo (int) $idea['post_id']; ?>" class="small text-decoration-none" title="<?php echo e((string) ($idea['post_title'] ?? '')); ?>">
                                                    <i class="bi bi-file-earmark-text me-1"></i>#<?php echo (int) $idea['post_id']; ?> <?php echo e(mb_substr((string) ($idea['post_title'] ?? ''), 0, 30, 'UTF-8')); ?>
                                                </a>
                                            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                        </td>
                                        <td><small class="text-muted"><?php echo e(date('d/m H:i', strtotime((string) $idea['created_at']))); ?></small></td>
                                        <td class="pe-4 text-end">
                                            <?php if ($writable): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 btn-idea-write" data-id="<?php echo (int) $idea['id']; ?>" title="Viết bài này"><i class="bi bi-magic me-1"></i><?php echo $stt === 'failed' ? 'Viết lại' : 'Viết'; ?></button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 btn-idea-del" data-id="<?php echo (int) $idea['id']; ?>" title="Xóa ý tưởng" <?php echo $stt === 'running' ? 'disabled' : ''; ?>><i class="bi bi-trash text-danger"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tác vụ gần đây -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4 border-bottom">
            <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>Tác vụ viết bài gần đây</h5>
            <?php if ($hasActive): ?><span class="badge bg-primary rounded-pill"><span class="spinner-border spinner-border-sm me-1"></span>Đang xử lý — tự tải lại</span><?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 small text-uppercase fw-bold text-muted">#</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Chủ đề</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Trạng thái</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Stage</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Thông báo</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted">Tạo lúc</th>
                            <th class="py-3 small text-uppercase fw-bold text-muted pe-4 text-end">Bài viết</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentJobs)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">Chưa có tác vụ nào.</td></tr>
                        <?php else: foreach ($recentJobs as $rj):
                            $payload = json_decode((string) $rj['payload'], true);
                            $result = json_decode((string) ($rj['result'] ?? ''), true);
                            $topic = trim((string) ($payload['topic'] ?? ''));
                            $jobTitle = trim((string) ($result['title'] ?? '')) !== '' ? (string) $result['title'] : $topic;
                        ?>
                            <tr>
                                <td class="ps-4 text-muted">#<?php echo (int) $rj['id']; ?></td>
                                <td class="fw-semibold" style="max-width:280px;"><span class="text-truncate d-inline-block" style="max-width:270px;" title="<?php echo e($topic); ?>"><?php echo e(mb_substr($topic, 0, 80, 'UTF-8')); ?></span></td>
                                <td><?php echo $jobBadges[$rj['status']] ?? e($rj['status']); ?> <?php if ((int) $rj['attempts'] > 0): ?><small class="text-muted">(thử <?php echo (int) $rj['attempts']; ?>)</small><?php endif; ?></td>
                                <td><code class="small"><?php echo e((string) $rj['stage']); ?></code></td>
                                <td class="small text-muted" style="max-width:240px;"><?php echo e(mb_substr((string) ($rj['message'] ?? ''), 0, 90, 'UTF-8')); ?></td>
                                <td><small class="text-muted"><?php echo e(date('d/m H:i', strtotime((string) $rj['created_at']))); ?></small></td>
                                <td class="pe-4 text-end">
                                    <?php if (!empty($rj['entity_id'])): ?>
                                        <a href="edit.php?id=<?php echo (int) $rj['entity_id']; ?>" class="btn btn-sm btn-light border rounded-pill px-2" title="<?php echo e($jobTitle); ?>"><i class="bi bi-pencil text-primary"></i></a>
                                    <?php endif; ?>
                                    <?php if ($rj['status'] === 'failed' || $rj['status'] === 'cancelled'): ?>
                                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 btn-job-retry" data-id="<?php echo (int) $rj['id']; ?>" title="Chạy lại"><i class="bi bi-arrow-repeat text-success"></i></button>
                                    <?php elseif (in_array($rj['status'], ['queued', 'running', 'retry_wait'], true)): ?>
                                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 btn-job-cancel" data-id="<?php echo (int) $rj['id']; ?>" title="Hủy"><i class="bi bi-x-lg text-danger"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Tiến trình AI -->
<div class="modal fade" id="aiProgressModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="aiProgressTitle">Đang viết bài AI...</h5></div>
      <div class="modal-body">
        <div class="progress mb-2" style="height:22px;">
          <div class="progress-bar progress-bar-striped progress-bar-animated" id="aiProgressBar" style="width:0%">0%</div>
        </div>
        <div class="small text-muted mb-2" id="aiProgressStatus">Chuẩn bị...</div>
        <div id="aiProgressLog" style="max-height:240px;overflow:auto;font-size:.82rem;"></div>
      </div>
      <div class="modal-footer">
        <span class="small text-muted me-auto">Có thể đóng — tác vụ tiếp tục chạy nền.</span>
        <button type="button" class="btn btn-light" id="aiProgressClose" data-bs-dismiss="modal">Đóng</button>
      </div>
    </div>
  </div>
</div>

<script>const POST_CSRF = <?php echo json_encode(generate_csrf_token()); ?>;</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pm = document.getElementById('aiProgressModal');
    const bar = document.getElementById('aiProgressBar');
    const statusEl = document.getElementById('aiProgressStatus');
    const logEl = document.getElementById('aiProgressLog');
    const closeBtn = document.getElementById('aiProgressClose');
    const selCount = document.getElementById('selCount');
    const checkAll = document.getElementById('checkAll');

    async function postAction(params) {
        const body = new URLSearchParams(Object.assign({csrf_token: POST_CSRF}, params));
        const res = await fetch('../ajax/post-write.php', {method: 'POST', body, credentials: 'same-origin', cache: 'no-store'});
        let data;
        try { data = await res.json(); } catch (_) { throw new Error('Phản hồi không hợp lệ.'); }
        if (!res.ok || !data.success) throw new Error(data.message || data.error || ('HTTP ' + res.status));
        return data;
    }
    function setBar(done, total) {
        const pct = total ? Math.round(done / total * 100) : 0;
        bar.style.width = pct + '%'; bar.textContent = pct + '%';
    }
    function logLine(ok, text) {
        const line = document.createElement('div');
        line.className = ok ? 'text-success' : 'text-danger';
        const icon = document.createElement('i');
        icon.className = 'bi ' + (ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' me-1';
        line.append(icon, document.createTextNode(text));
        logEl.append(line);
        logEl.scrollTop = logEl.scrollHeight;
    }
    function selectedIds() {
        return Array.from(document.querySelectorAll('.idea-check:checked')).map(c => parseInt(c.value, 10));
    }
    function updateCount() { selCount.textContent = selectedIds().length; }
    if (checkAll) checkAll.addEventListener('change', () => {
        document.querySelectorAll('.idea-check:not(:disabled)').forEach(c => c.checked = checkAll.checked);
        updateCount();
    });
    document.querySelectorAll('.idea-check').forEach(c => c.addEventListener('change', updateCount));

    const ideaRows = document.getElementById('ideaRows');
    function addIdeaRow() {
        if (ideaRows.querySelectorAll('.idea-row').length >= 100) return;
        const row = document.createElement('div');
        row.className = 'idea-row mb-2 d-flex gap-1 align-items-start';
        row.innerHTML = '<div class="flex-grow-1"><input type="text" class="form-control form-control-sm mb-1 idea-title" maxlength="500" placeholder="Tiêu đề / từ khóa *">'
            + '<input type="text" class="form-control form-control-sm idea-desc" maxlength="2000" placeholder="Mô tả / yêu cầu thêm cho AI (tuỳ chọn)"></div>'
            + '<button type="button" class="btn btn-sm btn-light border rounded-pill px-2 btn-del-row" title="Bỏ dòng"><i class="bi bi-x-lg text-danger"></i></button>';
        row.querySelector('.btn-del-row').addEventListener('click', () => row.remove());
        ideaRows.appendChild(row);
        row.querySelector('.idea-title').focus();
    }
    document.getElementById('btnAddRow').addEventListener('click', addIdeaRow);

    document.getElementById('btnAddIdeas').addEventListener('click', async function () {
        const params = new URLSearchParams({action: 'add_ideas', csrf_token: POST_CSRF});
        let n = 0;
        ideaRows.querySelectorAll('.idea-row').forEach(row => {
            const t = row.querySelector('.idea-title').value.trim();
            const d = row.querySelector('.idea-desc').value.trim();
            if (t !== '') { params.append('titles[]', t); params.append('descs[]', d); n++; }
        });
        if (!n) { alert('Vui lòng nhập ít nhất 1 tiêu đề / từ khóa.'); return; }
        this.disabled = true;
        try {
            const res = await fetch('../ajax/post-write.php', {method: 'POST', body: params, credentials: 'same-origin', cache: 'no-store'});
            const data = await res.json();
            if (!res.ok || !data.success) throw new Error(data.message || 'Lỗi thêm ý tưởng.');
            location.reload();
        } catch (e) { alert(e.message); this.disabled = false; }
    });

    let jobSubmitted = false;
    pm.addEventListener('hidden.bs.modal', () => { if (jobSubmitted) location.reload(); });

    async function runWrite(ideaIds, label) {
        if (!ideaIds.length) { alert('Vui lòng chọn ít nhất 1 ý tưởng chưa viết.'); return; }
        document.getElementById('aiProgressTitle').textContent = label + ' (' + ideaIds.length + ' bài)';
        logEl.innerHTML = ''; setBar(0, ideaIds.length); statusEl.textContent = 'Đang xếp hàng...';
        if (typeof bootstrap !== 'undefined' && pm) bootstrap.Modal.getOrCreateInstance(pm).show();
        try {
            const accepted = await AIJobs.send('../ajax/post-write.php', new URLSearchParams({action: 'write', idea_ids: ideaIds.join(',')}));
            jobSubmitted = true;
            const jobs = await AIJobs.waitBatch(accepted, { onProgress: p => {
                statusEl.textContent = AIJobs.progressText(p);
                setBar(p.done || 0, p.total || ideaIds.length);
            }});
            let ok = 0;
            jobs.forEach(job => {
                const good = job.status === 'succeeded';
                if (good) ok++;
                const title = job.result && job.result.title ? job.result.title : ('#' + job.id);
                const extra = job.result && job.result.edit_url ? ' — ' + job.result.edit_url : '';
                logLine(good, title + ': ' + (job.message || job.status) + extra);
            });
            statusEl.textContent = 'Hoàn tất: ' + ok + '/' + jobs.length + ' bài thành công. Tải lại trang để cập nhật danh sách.';
            setTimeout(() => location.reload(), 2500);
        } catch (e) {
            statusEl.textContent = e.message || 'Không đọc được trạng thái hàng đợi.';
        }
    }

    document.getElementById('btnWriteSelected').addEventListener('click', () => runWrite(selectedIds(), 'Viết các bài đã chọn'));
    document.querySelectorAll('.btn-idea-write').forEach(btn => btn.addEventListener('click', () => runWrite([parseInt(btn.dataset.id, 10)], 'Viết bài #' + btn.dataset.id)));
    document.querySelectorAll('.btn-idea-del').forEach(btn => btn.addEventListener('click', async () => {
        if (!confirm('Xóa ý tưởng #' + btn.dataset.id + '?')) return;
        btn.disabled = true;
        try { await postAction({action: 'delete_ideas', idea_ids: btn.dataset.id}); location.reload(); }
        catch (e) { alert(e.message); btn.disabled = false; }
    }));
    document.querySelectorAll('.btn-job-retry').forEach(btn => btn.addEventListener('click', async () => {
        btn.disabled = true;
        try { await AIJobs.action('retry', btn.dataset.id); location.reload(); }
        catch (e) { alert(e.message); btn.disabled = false; }
    }));
    document.querySelectorAll('.btn-job-cancel').forEach(btn => btn.addEventListener('click', async () => {
        if (!confirm('Hủy tác vụ #' + btn.dataset.id + '?')) return;
        btn.disabled = true;
        try { await AIJobs.action('cancel', btn.dataset.id); location.reload(); }
        catch (e) { alert(e.message); btn.disabled = false; }
    }));
    <?php if ($hasActive): ?>setTimeout(() => { if (!pm.classList.contains('show')) location.reload(); }, 15000);<?php endif; ?>
});
</script>
<?php require_once '../includes/footer.php'; ?>
