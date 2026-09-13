<?php
/** Queue provider-neutral SEO generation for an unsaved admin form. */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/llm.php';
require_once '../../includes/ai-endpoint.php';
$ownerId=ai_endpoint_begin();if(!llm_feature_available('seo'))ai_endpoint_error('not_configured',409);
$title=trim(strip_tags((string)($_POST['title']??'')));if($title==='')ai_endpoint_error('invalid_input');
$payload=['save'=>false,'title'=>mb_substr($title,0,255,'UTF-8'),'description'=>mb_substr(trim(strip_tags((string)($_POST['description']??''))),0,1000,'UTF-8'),'content'=>mb_substr(trim(strip_tags((string)($_POST['content']??''))),0,3000,'UTF-8')];
ai_endpoint_enqueue([['request_key'=>ai_endpoint_request_key((string)($_POST['request_key']??'')),'kind'=>'seo','action'=>'seo','entity_id'=>null,'payload'=>$payload,'active_key'=>null]],$ownerId);
