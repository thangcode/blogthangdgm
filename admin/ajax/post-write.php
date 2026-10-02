<?php
/**
 * Endpoint viết bài AI theo từ khóa.
 *   action=add_ideas    : topics[] dòng -> lưu vào bảng ý tưởng ai_write_ideas (status=pending)
 *   action=delete_ideas : idea_ids[]    -> xóa ý tưởng (không xóa khi job đang chạy)
 *   action=write        : idea_ids[] hoặc topics -> xếp hàng job kind='write' (chạy nền bởi worker)
 * Cấu hình viết bài (độ dài, số ảnh, thumbnail, trạng thái, chuyên mục) đọc từ settings chung.
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/ai-write.php';
require_once '../../includes/ai-endpoint.php';

/** Kích worker CLI chạy nền ngay (best-effort). Cron là phương án chắc chắn. */
function ai_write_kick_worker(): bool
{
    $worker = realpath(__DIR__ . '/../../scripts/ai-worker.php');
    if (!$worker) { return false; }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $php = (defined('PHP_BINARY') && PHP_BINARY && @is_file(PHP_BINARY)) ? PHP_BINARY : 'php';
    $args = ' --max-jobs=10 --max-seconds=600';
    if (stripos(PHP_OS, 'WIN') === 0) {
        if (!function_exists('popen') || in_array('popen', $disabled, true)) { return false; }
        $h = @popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . $args, 'r');
        if ($h !== false) { @pclose($h); return true; }
        return false;
    }
    if (!function_exists('exec') || in_array('exec', $disabled, true)) { return false; }
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . $args . ' > /dev/null 2>&1 &');
    return true;
}

function ai_write_json(array $data, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$author = mb_substr(trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin')), 0, 255, 'UTF-8');
$ownerId = ai_endpoint_begin();
ai_write_ensure_schema($pdo);

$action = trim((string) ($_POST['action'] ?? 'write'));

// ---- Thêm ý tưởng vào danh sách ----
if ($action === 'add_ideas') {
    // Dạng mới: titles[] + descs[] song song (mỗi ý tưởng = tiêu đề + mô tả tuỳ chọn).
    // Giữ tương thích textarea 'topics': mỗi dòng 1 ý tưởng, có thể "tiêu đề || mô tả".
    $items = [];
    if (isset($_POST['titles']) && is_array($_POST['titles'])) {
        $titles = $_POST['titles'];
        $descs = is_array($_POST['descs'] ?? null) ? $_POST['descs'] : [];
        foreach ($titles as $i => $t) {
            $t = trim((string) $t);
            if ($t === '') continue;
            $items[] = [$t, trim((string) ($descs[$i] ?? ''))];
        }
    } else {
        $raw = trim((string) ($_POST['topics'] ?? $_POST['ideas'] ?? ''));
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($v) => $v !== '');
        foreach ($lines as $line) {
            $parts = preg_split('/\s*\|\|\s*/', $line, 2);
            $items[] = [trim((string) ($parts[0] ?? '')), trim((string) ($parts[1] ?? ''))];
        }
    }
    $items = array_values(array_filter($items, fn($it) => $it[0] !== ''));
    if (!$items || count($items) > 100) ai_endpoint_error('invalid_input');
    $ins = $pdo->prepare("INSERT INTO ai_write_ideas (owner_id, idea, brief, status, created_at, updated_at) VALUES (?, ?, ?, 'pending', NOW(), NOW())");
    $added = 0;
    try {
        $pdo->beginTransaction();
        foreach ($items as [$idea, $brief]) {
            $ins->execute([$ownerId, mb_substr($idea, 0, 500, 'UTF-8'), $brief !== '' ? mb_substr($brief, 0, 2000, 'UTF-8') : null]);
            $added++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ai_endpoint_error('worker_error', 500);
    }
    ai_write_json(['success' => true, 'added' => $added]);
}

// ---- Xóa ý tưởng (không đụng job đang chạy) ----
if ($action === 'delete_ideas') {
    $rawIds = (string) ($_POST['idea_ids'] ?? $_POST['idea_id'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $rawIds)), fn($v) => $v > 0)));
    if (!$ids || count($ids) > 100) ai_endpoint_error('invalid_input');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE i FROM ai_write_ideas i LEFT JOIN ai_jobs j ON j.id = i.job_id
        WHERE i.owner_id = ? AND i.id IN ($ph)
          AND (i.job_id IS NULL OR j.status IS NULL OR j.status IN ('succeeded','failed','cancelled'))");
    $st->execute(array_merge([$ownerId], $ids));
    ai_write_json(['success' => true, 'deleted' => $st->rowCount()]);
}

// ---- Xếp hàng viết ----
if ($action !== 'write') ai_endpoint_error('invalid_input');

// Cấu hình viết bài dùng chung từ settings (trang ai-write.php cấu hình một lần).
$targetWords = max(400, min(3000, (int) get_setting('ai_write_default_words', '1200')));
$imageCount = max(0, min(3, (int) get_setting('ai_write_default_images', '1')));
$withThumb = (string) get_setting('ai_write_default_thumb', '1') !== '0';
$status = (int) get_setting('ai_write_default_status', '0');
if (!in_array($status, [0, 1, 2], true)) $status = 0;
$categoryId = max(0, (int) get_setting('ai_write_default_category', '0'));

$targets = []; // [idea_id|null, topic]
if (isset($_POST['idea_ids']) || isset($_POST['idea_id'])) {
    $raw = (string) ($_POST['idea_ids'] ?? $_POST['idea_id']);
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $raw)), fn($v) => $v > 0)));
    if (!$ids || count($ids) > 100) ai_endpoint_error('invalid_input');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, idea, brief, status FROM ai_write_ideas WHERE owner_id = ? AND id IN ($ph)");
    $st->execute(array_merge([$ownerId], $ids));
    $ideas = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ideas as $row) {
        // Chỉ xếp hàng ý tưởng chưa viết / đã lỗi; bỏ qua đang chạy hoặc đã xong.
        if (in_array($row['status'], ['pending', 'failed'], true)) {
            $targets[] = [(int) $row['id'], (string) $row['idea'], (string) ($row['brief'] ?? '')];
        }
    }
    if (!$targets) {
        ai_write_json(['success' => true, 'queued' => false, 'skipped' => count($ids), 'message' => 'Các ý tưởng đã chọn đang chạy hoặc đã viết xong.']);
    }
} else {
    $raw = trim((string) ($_POST['topics'] ?? $_POST['ideas'] ?? ''));
    $lines = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($v) => $v !== '')));
    if (!$lines || count($lines) > 100) ai_endpoint_error('invalid_input');
    foreach ($lines as $line) {
        $targets[] = [null, trim(mb_substr($line, 0, 2000, 'UTF-8')), ''];
    }
}

$base = (string) ($_POST['request_key'] ?? '');
$specs = [];
$ideaIds = [];
foreach ($targets as $i => [$ideaId, $topic, $brief]) {
    $specs[] = [
        'request_key' => ai_endpoint_request_key($base, $i),
        'kind' => 'write', 'action' => 'all', 'entity_id' => null,
        'payload' => [
            'save' => true, 'topic' => $topic, 'brief' => $brief ?? '', 'idea_id' => $ideaId,
            'target_words' => $targetWords, 'image_count' => $imageCount, 'with_thumb' => $withThumb,
            'status' => $status, 'category_id' => $categoryId, 'author_name' => $author,
        ],
        'active_key' => 'write:' . $ownerId . ':' . hash('sha256', ($ideaId ? 'idea:' . $ideaId : 'topic:' . $topic)),
    ];
    $ideaIds[] = $ideaId;
}

try {
    $result = ai_jobs_enqueue_many($pdo, $ownerId, $specs);
} catch (InvalidArgumentException $e) {
    ai_endpoint_error('invalid_input');
} catch (RuntimeException $e) {
    $code = in_array($e->getMessage(), ['migration_required', 'conflict'], true) ? $e->getMessage() : 'worker_error';
    ai_endpoint_error($code, $code === 'migration_required' ? 503 : 409);
} catch (Throwable $e) {
    ai_endpoint_error('worker_error', 500);
}

// Gắn job_id + trạng thái running cho ý tưởng đã xếp hàng.
$upd = $pdo->prepare("UPDATE ai_write_ideas SET status='running', job_id=?, message='' WHERE id=?");
foreach ($ideaIds as $i => $ideaId) {
    if ($ideaId) $upd->execute([(int) ($result['job_ids'][$i] ?? 0), $ideaId]);
}
$result['kicked'] = ai_write_kick_worker();
ai_write_json($result, 202);
