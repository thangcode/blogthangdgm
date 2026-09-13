<?php
/** Queue product copy generation; results are returned through job polling. */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/ai-endpoint.php';
$ownerId=ai_endpoint_begin();
$mode=(string)($_POST['mode']??'description');if(!in_array($mode,['title','description','content','all'],true))ai_endpoint_error('invalid_input');
if(!llm_feature_available('write'))ai_endpoint_error('not_configured',409);
$text=trim((string)($_POST['text']??''));$name=trim((string)($_POST['name']??''));
if($text===''&&$name==='')ai_endpoint_error('invalid_input');
$images=isset($_POST['images'])&&is_array($_POST['images'])?array_values(array_slice(array_filter(array_map('strval',$_POST['images'])),0,4)):[];
ai_endpoint_enqueue([['request_key'=>ai_endpoint_request_key((string)($_POST['request_key']??'')),'kind'=>'product','action'=>$mode,'entity_id'=>null,
'payload'=>['save'=>false,'text'=>mb_substr($text,0,16000,'UTF-8'),'name'=>mb_substr($name,0,255,'UTF-8'),'images'=>$images],'active_key'=>null]],$ownerId);
