<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ai-jobs.php';
require_once '../../includes/ai-endpoint.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_admin_logged_in()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Chưa đăng nhập.']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_valid_csrf_token(true);
$ownerId=(int)($_SESSION['user_id']??0);
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
try {
    if (!ai_jobs_schema_ready($pdo)) throw new RuntimeException('migration_required');
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $id=(int)($_GET['id']??0);
        if ($id>0) {
            $row=ai_jobs_load_owned($pdo,$ownerId,$id);
            if(!$row){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Không tìm thấy tác vụ.']);exit;}
            echo json_encode(['success'=>true,'jobs'=>[ai_jobs_row($row,true)],'worker'=>ai_jobs_worker_state($pdo),'has_more'=>false],JSON_UNESCAPED_UNICODE);exit;
        }
        $list=ai_jobs_list_owned($pdo,$ownerId,['batch_id'=>(string)($_GET['batch_id']??''),'status'=>(string)($_GET['status']??''),'page'=>(int)($_GET['page']??1)]);
        echo json_encode(['success'=>true]+$list+['worker'=>ai_jobs_worker_state($pdo)],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=(int)($_POST['id']??0);$action=(string)($_POST['action']??'');
        $ok=$action==='cancel'?ai_jobs_request_cancel($pdo,$ownerId,$id):($action==='retry'?ai_jobs_retry($pdo,$ownerId,$id):false);
        if(!$ok){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Không thể thực hiện hành động với trạng thái hiện tại.']);exit;}
        if($action==='retry')ai_jobs_kick_worker();
        echo json_encode(['success'=>true,'message'=>$action==='cancel'?'Đã yêu cầu hủy.':'Đã xếp lại tác vụ.'],JSON_UNESCAPED_UNICODE);exit;
    }
    http_response_code(405);echo json_encode(['success'=>false,'message'=>'Method not allowed.']);
} catch(Throwable $e){
    $code=$e->getMessage()==='migration_required'?'migration_required':'worker_error';
    http_response_code($code==='migration_required'?503:500);
    echo json_encode(['success'=>false,'error'=>$code,'message'=>ai_jobs_safe_message($code)],JSON_UNESCAPED_UNICODE);
}
