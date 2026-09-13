<?php
/** Queue AI work for a page. */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/ai-endpoint.php';
require_once '../../includes/ai-job-runner.php';
$ownerId=ai_endpoint_begin();$action=(string)($_POST['action']??'');
if(!in_array($action,['rewrite','seo','all'],true))ai_endpoint_error('invalid_input');
if(($action!=='seo'&&!llm_feature_available('write'))||($action!=='rewrite'&&!llm_feature_available('seo')))ai_endpoint_error('not_configured',409);
$id=(int)($_POST['id']??0);$save=(string)($_POST['save']??'1')!=='0';
$stmt=$pdo->prepare('SELECT * FROM pages WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();if(!$row)ai_endpoint_error('not_found',404);
ai_endpoint_enqueue([['request_key'=>ai_endpoint_request_key((string)($_POST['request_key']??'')),'kind'=>'page','action'=>$action,'entity_id'=>$id,
'payload'=>['save'=>$save,'snapshot'=>ai_entity_snapshot($row,'page')],'active_key'=>ai_jobs_active_key('page',$action,$id,$save)]],$ownerId);
