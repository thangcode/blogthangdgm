<?php
/** Queue AI work for one or many categories. */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/ai-endpoint.php';
require_once '../../includes/ai-job-runner.php';
$ownerId=ai_endpoint_begin();$action=(string)($_POST['action']??'');
if(!in_array($action,['rewrite','seo','all'],true))ai_endpoint_error('invalid_input');
if(($action!=='seo'&&!llm_feature_available('write'))||($action!=='rewrite'&&!llm_feature_available('seo')))ai_endpoint_error('not_configured',409);
$save=(string)($_POST['save']??'1')!=='0';$ids=ai_endpoint_ids();$base=(string)($_POST['request_key']??'');$specs=[];
$stmt=$pdo->prepare('SELECT * FROM categories WHERE id=? LIMIT 1');
foreach($ids as $i=>$id){$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)ai_endpoint_error('not_found',404);
$specs[]=['request_key'=>ai_endpoint_request_key($base,$i),'kind'=>'category','action'=>$action,'entity_id'=>$id,
'payload'=>['save'=>$save,'snapshot'=>ai_entity_snapshot($row,'category')],'active_key'=>ai_jobs_active_key('category',$action,$id,$save)];}
ai_endpoint_enqueue($specs,$ownerId);
