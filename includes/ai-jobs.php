<?php
/** Durable AI queue repository. This file has no session or endpoint side effects. */

function ai_jobs_json_decode($value, array $default = []): array
{
    if (is_array($value)) return $value;
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : $default;
}

function ai_jobs_json_encode(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('invalid_json');
    return $json;
}

function ai_jobs_canonical_payload(array $payload): array
{
    unset($payload['snapshot'], $payload['tags_snapshot']);
    return $payload;
}

function ai_jobs_payload_hash(string $kind, string $action, ?int $entityId, array $payload): string
{
    return hash('sha256', $kind . '|' . $action . '|' . (string) $entityId . '|' . ai_jobs_json_encode(ai_jobs_canonical_payload($payload)));
}

function ai_jobs_schema_has_unique(PDO $pdo, string $table, array $columns): bool
{
    $stmt = $pdo->prepare('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
        ORDER BY INDEX_NAME, SEQ_IN_INDEX');
    $stmt->execute([$table]);
    $indexes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string) $row['INDEX_NAME'];
        if (!isset($indexes[$name])) {
            $indexes[$name] = ['unique' => (int) $row['NON_UNIQUE'] === 0, 'columns' => []];
        }
        $indexes[$name]['columns'][(int) $row['SEQ_IN_INDEX']] = (string) $row['COLUMN_NAME'];
    }
    $wanted = array_values($columns);
    foreach ($indexes as $index) {
        if (!$index['unique']) continue;
        if (array_values($index['columns']) === $wanted) return true;
    }
    return false;
}

function ai_jobs_schema_ready(PDO $pdo): bool
{
    try {
        $engineStmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $colStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $requiredTables = [
            'ai_jobs' => 'InnoDB',
            'ai_worker_state' => 'InnoDB',
            'posts' => 'InnoDB',
            'pages' => 'InnoDB',
            'categories' => 'InnoDB',
            'tags' => 'InnoDB',
            'post_tags' => 'InnoDB',
            'audit_logs' => 'InnoDB',
        ];
        foreach ($requiredTables as $table => $engine) {
            $engineStmt->execute([$table]);
            if (strtoupper((string) $engineStmt->fetchColumn()) !== strtoupper($engine)) return false;
        }
        $engineStmt->execute(['products']);
        $productsEngine = strtoupper((string) $engineStmt->fetchColumn());
        if ($productsEngine !== '' && $productsEngine !== 'INNODB') return false;

        $requiredColumns = [
            'ai_jobs' => ['id','owner_id','batch_id','request_key','payload_hash','active_key','kind','action','entity_id','payload','checkpoint','result','status','stage','attempts','recoveries','available_at','claim_token','lease_until','cancel_requested','error_code','message','created_at','updated_at','finished_at'],
            'ai_worker_state' => ['id','last_seen','state','current_job'],
            'posts' => ['ai_import_token'],
            'pages' => ['focus_keyword'],
            'categories' => ['content','focus_keyword'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                $colStmt->execute([$table, $column]);
                if (!(int) $colStmt->fetchColumn()) return false;
            }
        }
        return ai_jobs_schema_has_unique($pdo, 'ai_jobs', ['owner_id', 'request_key'])
            && ai_jobs_schema_has_unique($pdo, 'ai_jobs', ['active_key'])
            && ai_jobs_schema_has_unique($pdo, 'posts', ['ai_import_token']);
    } catch (Throwable $e) {
        return false;
    }
}

function ai_jobs_pdo(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) $pdo = db_connect();
    db_ensure_alive($pdo);
    return $pdo;
}

function ai_jobs_safe_message(string $code): string
{
    $messages = [
        'migration_required' => 'Chưa cài đặt bảng hàng đợi AI.',
        'not_configured' => 'Chưa cấu hình model cho tính năng AI này.',
        'invalid_input' => 'Dữ liệu gửi lên không hợp lệ.',
        'not_found' => 'Không tìm thấy dữ liệu cần xử lý.',
        'conflict' => 'Dữ liệu đã thay đổi trong lúc chờ; hệ thống không ghi đè.',
        'cancelled' => 'Tác vụ đã được hủy trước khi hoàn tất.',
        'owner_inactive' => 'Tài khoản tạo tác vụ không còn quyền chạy AI.',
        'timeout' => 'Dịch vụ AI phản hồi quá chậm.',
        'rate_limited' => 'Dịch vụ AI đang bận; tác vụ sẽ thử lại.',
        'provider_unavailable' => 'Dịch vụ AI tạm thời không khả dụng.',
        'transport_error' => 'Không kết nối được dịch vụ AI.',
        'transport_unavailable' => 'Máy chủ chưa hỗ trợ kết nối đến dịch vụ AI.',
        'authentication_failed' => 'Cấu hình xác thực dịch vụ AI không hợp lệ.',
        'request_rejected' => 'Dịch vụ AI từ chối yêu cầu này.',
        'content_filtered' => 'Nội dung bị bộ lọc an toàn của dịch vụ AI từ chối.',
        'invalid_output' => 'AI trả về nội dung không hợp lệ.',
        'truncated_output' => 'Nội dung AI bị cắt nên không được lưu.',
        'db_unavailable' => 'Database tạm thời không khả dụng.',
        'worker_error' => 'Worker gặp lỗi khi xử lý tác vụ.',
    ];
    return $messages[$code] ?? 'Không thể hoàn tất tác vụ AI.';
}

function ai_jobs_row(array $row, bool $withResult = false): array
{
    $out = [
        'id' => (int) $row['id'], 'batch_id' => (string) $row['batch_id'],
        'kind' => (string) $row['kind'], 'action' => (string) $row['action'],
        'entity_id' => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
        'status' => (string) $row['status'], 'stage' => (string) $row['stage'],
        'attempts' => (int) $row['attempts'], 'error_code' => (string) ($row['error_code'] ?? ''),
        'message' => (string) ($row['message'] ?? ''), 'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'], 'finished_at' => $row['finished_at'] ?? null,
    ];
    if ($withResult) {
        $out['result'] = $row['result'] !== null ? ai_jobs_json_decode($row['result']) : null;
        if ($out['result'] === null && in_array($out['status'], ['failed', 'cancelled'], true)) {
            $cp = ai_jobs_json_decode($row['checkpoint']);
            if (!empty($cp['result']) && is_array($cp['result'])) $out['result'] = $cp['result'];
        }
    }
    return $out;
}

function ai_jobs_create(PDO $pdo, int $ownerId, string $batchId, string $requestKey, string $kind, string $action, ?int $entityId, array $payload, ?string $activeKey): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $batchId) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $requestKey)) {
        throw new InvalidArgumentException('invalid_input');
    }
    $payloadJson = ai_jobs_json_encode($payload);
    $payloadHash = ai_jobs_payload_hash($kind, $action, $entityId, $payload);
    try {
        $stmt = $pdo->prepare("INSERT INTO ai_jobs
            (owner_id,batch_id,request_key,payload_hash,active_key,kind,action,entity_id,payload,checkpoint,status,stage,available_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,'{}','queued','prepare',NOW(),NOW(),NOW())");
        $stmt->execute([$ownerId, $batchId, $requestKey, $payloadHash, $activeKey, $kind, $action, $entityId, $payloadJson]);
        return ['id' => (int) $pdo->lastInsertId(), 'batch_id' => $batchId, 'duplicate' => false];
    } catch (PDOException $e) {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode !== 1062) throw $e;
        $stmt = $pdo->prepare('SELECT * FROM ai_jobs WHERE owner_id=? AND request_key=? LIMIT 1');
        $stmt->execute([$ownerId, $requestKey]);
        $existing = $stmt->fetch();
        if (!$existing || !hash_equals((string) $existing['payload_hash'], $payloadHash)) {
            throw new RuntimeException('conflict');
        }
        return ['id' => (int) $existing['id'], 'batch_id' => (string) $existing['batch_id'], 'duplicate' => true];
    }
}

function ai_jobs_enqueue_many(PDO $pdo, int $ownerId, array $specs, string $batchId = ''): array
{
    if (!ai_jobs_schema_ready($pdo)) throw new RuntimeException('migration_required');
    if (!$specs || count($specs) > 100) throw new InvalidArgumentException('invalid_input');
    $requestedBatchId = $batchId;
    $batchId = $batchId ?: bin2hex(random_bytes(16));
    $ids = [];
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $batchReplay = false;
        foreach ($specs as $index => $spec) {
            $created = ai_jobs_create($pdo, $ownerId, $batchId, (string) $spec['request_key'], (string) $spec['kind'],
                (string) $spec['action'], isset($spec['entity_id']) ? (int) $spec['entity_id'] : null,
                (array) ($spec['payload'] ?? []), $spec['active_key'] ?? null);
            if ($index === 0 && $requestedBatchId === '' && !empty($created['duplicate'])) {
                $batchId = $created['batch_id'];
                $batchReplay = true;
            } elseif ($created['batch_id'] !== $batchId) {
                throw new RuntimeException('conflict');
            }
            if ($batchReplay && empty($created['duplicate'])) throw new RuntimeException('conflict');
            $ids[] = $created['id'];
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['success' => true, 'queued' => true, 'batch_id' => $batchId, 'job_ids' => $ids];
}

function ai_jobs_active_key(string $kind, string $action, ?int $entityId, bool $save): ?string
{
    if (!$save || !$entityId) return null;
    return $kind . ':' . $entityId . ':write';
}

function ai_jobs_load_owned(PDO $pdo, int $ownerId, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM ai_jobs WHERE id=? AND owner_id=? LIMIT 1');
    $stmt->execute([$id, $ownerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ai_jobs_list_owned(PDO $pdo, int $ownerId, array $filters): array
{
    $where = ['owner_id=?']; $args = [$ownerId];
    if (!empty($filters['batch_id']) && preg_match('/^[a-f0-9]{32}$/', $filters['batch_id'])) { $where[]='batch_id=?'; $args[]=$filters['batch_id']; }
    if (!empty($filters['status']) && in_array($filters['status'], ['queued','running','retry_wait','succeeded','failed','cancelled'], true)) { $where[]='status=?'; $args[]=$filters['status']; }
    $page = max(1, (int) ($filters['page'] ?? 1)); $limit = 50; $offset = ($page - 1) * $limit;
    $sql = 'SELECT * FROM ai_jobs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql); $stmt->execute($args); $rows = $stmt->fetchAll();
    $more = count($rows) > $limit; if ($more) array_pop($rows);
    return ['jobs' => array_map(fn($r) => ai_jobs_row($r, !empty($filters['batch_id'])), $rows), 'has_more' => $more];
}

function ai_jobs_worker_state(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT last_seen,UNIX_TIMESTAMP(last_seen) AS last_seen_unix,UNIX_TIMESTAMP(NOW()) AS server_now_unix,state,current_job FROM ai_worker_state WHERE id=1')->fetch();
        return $row ?: ['last_seen'=>null,'last_seen_unix'=>null,'server_now_unix'=>time(),'state'=>'never','current_job'=>null];
    } catch (Throwable $e) { return ['last_seen'=>null,'state'=>'migration_required','current_job'=>null]; }
}

function ai_jobs_request_cancel(PDO $pdo, int $ownerId, int $id): bool
{
    $stmt = $pdo->prepare("UPDATE ai_jobs SET cancel_requested=1,
        finished_at=IF(status IN ('queued','retry_wait'),NOW(),finished_at),
        active_key=IF(status IN ('queued','retry_wait'),NULL,active_key),
        error_code=IF(status IN ('queued','retry_wait'),'cancelled',error_code),
        message=IF(status IN ('queued','retry_wait'),?,message),
        status=IF(status IN ('queued','retry_wait'),'cancelled',status), updated_at=NOW()
        WHERE id=? AND owner_id=? AND status NOT IN ('succeeded','failed','cancelled')");
    $stmt->execute([ai_jobs_safe_message('cancelled'), $id, $ownerId]);
    return $stmt->rowCount() > 0;
}

function ai_jobs_retry(PDO $pdo, int $ownerId, int $id): bool
{
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM ai_jobs WHERE id=? AND owner_id=? FOR UPDATE'); $stmt->execute([$id,$ownerId]); $row=$stmt->fetch();
        if (!$row || !in_array($row['status'], ['failed','cancelled'], true)) { $pdo->rollBack(); return false; }
        if ((string) ($row['error_code'] ?? '') === 'conflict') { $pdo->rollBack(); return false; }
        $payload = ai_jobs_json_decode($row['payload']);
        $active = ai_jobs_active_key((string)$row['kind'], (string)$row['action'], $row['entity_id']!==null?(int)$row['entity_id']:null, !empty($payload['save']));
        if ((string) $row['kind'] === 'import' && !empty($payload['save'])) {
            $active = 'import:' . $ownerId . ':' . hash('sha256', trim((string) ($payload['line'] ?? '')));
        }
        if ((string) $row['kind'] === 'write' && !empty($payload['save'])) {
            $active = 'write:' . $ownerId . ':' . hash('sha256', trim((string) ($payload['topic'] ?? '')));
        }
        if ($active !== null) {
            $busy = $pdo->prepare("SELECT id FROM ai_jobs WHERE active_key=? AND id<>? LIMIT 1");
            $busy->execute([$active, $id]);
            if ($busy->fetchColumn()) { $pdo->rollBack(); return false; }
        }
        $up=$pdo->prepare("UPDATE ai_jobs SET status='queued',available_at=NOW(),claim_token=NULL,lease_until=NULL,cancel_requested=0,error_code=NULL,message=NULL,finished_at=NULL,active_key=?,attempts=0,recoveries=0,updated_at=NOW() WHERE id=?");
        try { $up->execute([$active,$id]); } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { $pdo->rollBack(); return false; }
            throw $e;
        }
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function ai_job_decode(array $row): array
{
    $row['id']=(int)$row['id']; $row['owner_id']=(int)$row['owner_id']; $row['entity_id']=$row['entity_id']!==null?(int)$row['entity_id']:null;
    $row['attempts']=(int)$row['attempts']; $row['payload']=ai_jobs_json_decode($row['payload']); $row['checkpoint']=ai_jobs_json_decode($row['checkpoint']);
    $row['result']=$row['result']!==null?ai_jobs_json_decode($row['result']):null; return $row;
}

function ai_job_claim(PDO &$pdo, int $leaseSeconds = 360): ?array
{
    db_ensure_alive($pdo);
    $pdo->beginTransaction();
    try {
        $cancelled = $pdo->prepare("UPDATE ai_jobs SET status='cancelled',active_key=NULL,claim_token=NULL,lease_until=NULL,finished_at=NOW(),error_code='cancelled',message=?,updated_at=NOW()
            WHERE status='running' AND lease_until<NOW() AND cancel_requested=1");
        $cancelled->execute([ai_jobs_safe_message('cancelled')]);
        $pdo->exec("UPDATE ai_jobs SET status='queued',claim_token=NULL,lease_until=NULL,recoveries=recoveries+1,available_at=NOW(),updated_at=NOW()
            WHERE status='running' AND lease_until<NOW() AND cancel_requested=0 AND recoveries<3");
        $staleFail = $pdo->prepare("UPDATE ai_jobs SET status='failed',active_key=NULL,finished_at=NOW(),error_code='worker_error',message=?,updated_at=NOW()
            WHERE status='running' AND lease_until<NOW() AND cancel_requested=0 AND recoveries>=3");
        $staleFail->execute([ai_jobs_safe_message('worker_error')]);
        $row=$pdo->query("SELECT * FROM ai_jobs WHERE status IN ('queued','retry_wait') AND cancel_requested=0 AND available_at<=NOW() ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
        if (!$row) { $pdo->commit(); return null; }
        $token=bin2hex(random_bytes(16));
        $stmt=$pdo->prepare("UPDATE ai_jobs SET status='running',claim_token=?,lease_until=DATE_ADD(NOW(),INTERVAL ? SECOND),error_code=NULL,message=NULL,updated_at=NOW() WHERE id=?");
        $stmt->execute([$token,$leaseSeconds,$row['id']]); $pdo->commit();
        $row['claim_token']=$token; $row['status']='running'; $row['lease_seconds']=$leaseSeconds; return ai_job_decode($row);
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function ai_job_lock(PDO $pdo, array $job): array
{
    $stmt=$pdo->prepare('SELECT * FROM ai_jobs WHERE id=? FOR UPDATE'); $stmt->execute([$job['id']]); $row=$stmt->fetch();
    if (!$row || $row['status']!=='running' || !hash_equals((string)$row['claim_token'],(string)$job['claim_token'])) throw new RuntimeException('claim_lost');
    if (!empty($row['cancel_requested'])) throw new RuntimeException('cancelled');
    return ai_job_decode($row);
}

function ai_job_lease_seconds(array $job): int
{
    $configured = (int) ($job['lease_seconds'] ?? 360);
    return max(360, min(1200, $configured));
}

function ai_job_checkpoint(array &$job, array $checkpoint, string $nextStage, ?array $result=null): void
{
    $pdo=ai_jobs_pdo(); $pdo->beginTransaction();
    $storedResult = $result ?? (is_array($job['result'] ?? null) ? $job['result'] : null);
    $leaseSeconds = ai_job_lease_seconds($job);
    try { ai_job_lock($pdo,$job); $stmt=$pdo->prepare('UPDATE ai_jobs SET checkpoint=?,stage=?,result=?,attempts=0,lease_until=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=? AND claim_token=?');
        $stmt->execute([ai_jobs_json_encode($checkpoint),$nextStage,$storedResult===null?null:ai_jobs_json_encode($storedResult),$leaseSeconds,$job['id'],$job['claim_token']]); $pdo->commit();
        $job['checkpoint']=$checkpoint; $job['stage']=$nextStage; $job['result']=$storedResult; $job['attempts']=0;
    } catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if ($e instanceof PDOException || in_array($e->getMessage(), ['db_transaction_lost','db_health_check_failed','db_reconnect_failed'], true)) {
            throw new AiJobException('db_unavailable', 'Database is temporarily unavailable.', true, $e);
        }
        throw $e;
    }
}

function ai_job_transaction(array &$job, callable $fn)
{
    $pdo=ai_jobs_pdo(); $pdo->beginTransaction();
    $leaseSeconds = ai_job_lease_seconds($job);
    try { ai_job_lock($pdo,$job); $value=$fn($pdo); $stmt=$pdo->prepare('UPDATE ai_jobs SET entity_id=?,checkpoint=?,stage=?,result=?,attempts=0,lease_until=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=? AND claim_token=?');
        $stmt->execute([$job['entity_id'],ai_jobs_json_encode($job['checkpoint']),(string)$job['stage'],$job['result']===null?null:ai_jobs_json_encode($job['result']),$leaseSeconds,$job['id'],$job['claim_token']]); $pdo->commit(); $job['attempts']=0; return $value;
    } catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if ($e instanceof PDOException || in_array($e->getMessage(), ['db_transaction_lost','db_health_check_failed','db_reconnect_failed'], true)) {
            throw new AiJobException('db_unavailable', 'Database is temporarily unavailable.', true, $e);
        }
        throw $e;
    }
}

function ai_job_finish(array &$job, array $result): void
{
    $pdo=ai_jobs_pdo(); $pdo->beginTransaction();
    try { ai_job_lock($pdo,$job); $stmt=$pdo->prepare("UPDATE ai_jobs SET status='succeeded',result=?,active_key=NULL,claim_token=NULL,lease_until=NULL,error_code=NULL,message=?,finished_at=NOW(),updated_at=NOW() WHERE id=?");
        $stmt->execute([ai_jobs_json_encode($result),(string)($result['message']??'Hoàn tất.'),$job['id']]); $pdo->commit(); $job['status']='succeeded'; $job['result']=$result;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function ai_job_yield(array &$job): void
{
    $pdo=ai_jobs_pdo(); $pdo->beginTransaction();
    try {
        $locked = ai_job_lock($pdo, $job);
        $cancelled = !empty($locked['cancel_requested']);
        $stmt=$pdo->prepare("UPDATE ai_jobs SET status=?,available_at=IF(?,available_at,NOW()),active_key=IF(?,NULL,active_key),error_code=IF(?,'cancelled',error_code),message=IF(?,?,message),finished_at=IF(?,NOW(),finished_at),claim_token=NULL,lease_until=NULL,updated_at=NOW() WHERE id=? AND claim_token=? AND status='running'");
        $stmt->execute([$cancelled?'cancelled':'queued',$cancelled?1:0,$cancelled?1:0,$cancelled?1:0,$cancelled?1:0,ai_jobs_safe_message('cancelled'),$cancelled?1:0,$job['id'],$job['claim_token']]);
        $pdo->commit();
        $job['status'] = $cancelled ? 'cancelled' : 'queued';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getMessage() === 'cancelled') { ai_job_mark_cancelled($job); return; }
        throw $e;
    }
}

function ai_job_mark_error(array &$job, string $code, bool $retryable): void
{
    $pdo=ai_jobs_pdo(); $pdo->beginTransaction();
    try {
        $locked = ai_job_lock($pdo, $job);
        if (!empty($locked['cancel_requested'])) {
            throw new RuntimeException('cancelled');
        }
        $attempts = (int) ($locked['attempts'] ?? 0) + 1;
        $retry = $retryable && $attempts < 3;
        $checkpoint = is_array($job['checkpoint'] ?? null) ? $job['checkpoint'] : [];
        if (!$retry && (string) ($job['action'] ?? '') === 'all' && (string) ($job['stage'] ?? '') === 'seo'
            && !empty($checkpoint['persisted']) && !in_array($code, ['cancelled','conflict','db_unavailable','worker_error'], true)) {
            $checkpoint['warnings'][] = ['code' => 'seo_failed', 'message' => 'Nội dung đã được lưu nhưng bước SEO không thể hoàn tất.'];
            $job['checkpoint'] = $checkpoint;
            $result = function_exists('ai_runner_result') ? ai_runner_result($job, $checkpoint) : (is_array($job['result'] ?? null) ? $job['result'] : []);
            $result['success'] = true;
            $result['saved'] = true;
            $result['message'] = 'Đã lưu nội dung; bước SEO chưa hoàn tất.';
            $stmt = $pdo->prepare("UPDATE ai_jobs SET status='succeeded',checkpoint=?,result=?,attempts=?,active_key=NULL,claim_token=NULL,lease_until=NULL,error_code=NULL,message=?,finished_at=NOW(),updated_at=NOW() WHERE id=? AND claim_token=?");
            $stmt->execute([ai_jobs_json_encode($checkpoint),ai_jobs_json_encode($result),$attempts,$result['message'],$job['id'],$job['claim_token']]);
            $pdo->commit();
            $job['attempts']=$attempts; $job['status']='succeeded'; $job['result']=$result;
            return;
        }
        $delay = min(300, 20 * (2 ** max(0, $attempts - 1)));
        $partial = is_array($job['result'] ?? null) ? $job['result'] : null;
        $stmt=$pdo->prepare("UPDATE ai_jobs SET status=?,attempts=?,available_at=IF(?,DATE_ADD(NOW(),INTERVAL ? SECOND),available_at),claim_token=NULL,lease_until=NULL,error_code=?,message=?,result=COALESCE(?,result),active_key=IF(?,active_key,NULL),finished_at=IF(?,finished_at,NOW()),updated_at=NOW() WHERE id=? AND claim_token=?");
        $stmt->execute([$retry?'retry_wait':'failed',$attempts,$retry?1:0,$delay,$code,ai_jobs_safe_message($code),$partial===null?null:ai_jobs_json_encode($partial),$retry?1:0,$retry?1:0,$job['id'],$job['claim_token']]);
        $pdo->commit();
        $job['attempts']=$attempts; $job['status']=$retry?'retry_wait':'failed';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getMessage() === 'cancelled') {
            ai_job_mark_cancelled($job);
            return;
        }
        throw $e;
    }
}

function ai_job_mark_cancelled(array &$job): void
{
    $pdo=ai_jobs_pdo(); $stmt=$pdo->prepare("UPDATE ai_jobs SET status='cancelled',active_key=NULL,claim_token=NULL,lease_until=NULL,error_code='cancelled',message=?,finished_at=NOW(),updated_at=NOW() WHERE id=? AND claim_token=?");
    $stmt->execute([ai_jobs_safe_message('cancelled'),$job['id'],$job['claim_token']]); $job['status']='cancelled';
}

/**
 * Dọn job đã kết thúc quá hạn giữ (mặc định 30 ngày, cấu hình qua setting
 * ai_jobs_retention_days, kẹp [7,365]). Chỉ xóa trạng thái terminal có finished_at.
 * Mọi lỗi đều nuot — prune không bao giờ làm hỏng luồng chính.
 */
function ai_jobs_prune_finished(PDO $pdo, int $days = 0): int
{
    if ($days <= 0) {
        $days = function_exists('get_setting') ? (int) get_setting('ai_jobs_retention_days', '30') : 30;
    }
    $days = max(7, min(365, $days));
    try {
        $stmt = $pdo->query("DELETE FROM ai_jobs WHERE status IN ('succeeded','failed','cancelled') AND finished_at IS NOT NULL AND finished_at < DATE_SUB(NOW(), INTERVAL " . $days . " DAY)");
        return $stmt !== false ? $stmt->rowCount() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}
