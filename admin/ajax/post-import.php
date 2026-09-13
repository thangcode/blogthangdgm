<?php
/** Queue every import line; metadata, draft creation and AI run in the CLI worker. */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/ai-endpoint.php';
$author=mb_substr(trim((string)($_SESSION['full_name']??$_SESSION['username']??'Admin')),0,255,'UTF-8');
$ownerId=ai_endpoint_begin();
$raw=trim((string)($_POST['ideas']??''));$lines=array_values(array_unique(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$raw)),fn($v)=>$v!=='')));
if(!$lines||count($lines)>100)ai_endpoint_error('invalid_input');
$base=(string)($_POST['request_key']??'');
$specs=[];foreach($lines as $i=>$line){$specs[]=['request_key'=>ai_endpoint_request_key($base,$i),'kind'=>'import','action'=>'all','entity_id'=>null,
'payload'=>['save'=>true,'line'=>mb_substr($line,0,2000,'UTF-8'),'author_name'=>$author],'active_key'=>'import:'.$ownerId.':'.hash('sha256',$line)];}
ai_endpoint_enqueue($specs,$ownerId);
