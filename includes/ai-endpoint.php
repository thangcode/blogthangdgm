<?php
/** Shared helpers for short authenticated enqueue endpoints. */
require_once __DIR__ . '/ai-jobs.php';

function ai_endpoint_begin(): int
{
    global $pdo;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!is_admin_logged_in()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Chưa đăng nhập.']); exit; }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']); exit; }
    require_valid_csrf_token(true);
    $owner=(int)($_SESSION['user_id']??0);
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if (!ai_jobs_schema_ready($pdo)) ai_endpoint_error('migration_required',503);
    return $owner;
}

function ai_endpoint_error(string $code, int $http=400): void
{
    http_response_code($http); echo json_encode(['success'=>false,'error'=>$code,'message'=>ai_jobs_safe_message($code)],JSON_UNESCAPED_UNICODE); exit;
}

function ai_endpoint_request_key(string $base, int $index=0): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/',$base)) ai_endpoint_error('invalid_input');
    return $index===0?$base:substr($base,0,50).'_'.substr(hash('sha256',$base.'|'.$index),0,12);
}

function ai_endpoint_ids(): array
{
    $raw=isset($_POST['ids'])?(string)$_POST['ids']:(string)($_POST['id']??'');
    $ids=array_values(array_unique(array_filter(array_map('intval',preg_split('/[\s,]+/',$raw)),fn($id)=>$id>0)));
    if (!$ids || count($ids)>100) ai_endpoint_error('invalid_input');
    return $ids;
}

function ai_endpoint_enqueue(array $specs, int $ownerId): void
{
    global $pdo;
    try { $result=ai_jobs_enqueue_many($pdo,$ownerId,$specs); http_response_code(202); echo json_encode($result,JSON_UNESCAPED_UNICODE); exit; }
    catch(InvalidArgumentException $e){ai_endpoint_error('invalid_input');}
    catch(RuntimeException $e){$code=in_array($e->getMessage(),['migration_required','conflict'],true)?$e->getMessage():'worker_error';ai_endpoint_error($code,$code==='migration_required'?503:409);}
    catch(Throwable $e){ai_endpoint_error('worker_error',500);}
}
