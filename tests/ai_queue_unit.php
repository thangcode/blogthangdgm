<?php
require_once __DIR__ . '/../includes/ai-jobs.php';
require_once __DIR__ . '/../includes/ai-job-runner.php';
require_once __DIR__ . '/../includes/llm.php';

function check($condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
check(ai_jobs_json_decode('{"a":1}')['a'] === 1, 'json decode');
check(ai_jobs_json_decode('invalid', ['x'=>2])['x'] === 2, 'json fallback');
check(ai_jobs_safe_message('timeout') !== ai_jobs_safe_message('unknown'), 'safe error map');
check(ai_jobs_safe_message('content_filtered') !== ai_jobs_safe_message('unknown'), 'filtered error map');
check(ai_jobs_safe_message('request_rejected') !== ai_jobs_safe_message('unknown'), 'rejected error map');
check(ai_jobs_safe_message('owner_inactive') !== ai_jobs_safe_message('cancelled'), 'owner inactive is not cancel');
check(ai_jobs_active_key('post','all',12,true) === 'post:12:write', 'active key');
check(ai_jobs_active_key('post','all',12,false) === null, 'preview has no active key');
$row=['id'=>'1','batch_id'=>str_repeat('a',32),'kind'=>'post','action'=>'all','entity_id'=>'12','status'=>'queued','stage'=>'prepare','attempts'=>'0','error_code'=>null,'message'=>null,'created_at'=>'now','updated_at'=>'now','finished_at'=>null,'result'=>null,'checkpoint'=>'{}'];
$out=ai_jobs_row($row,false);check($out['id']===1&&$out['entity_id']===12&&!array_key_exists('result',$out),'public row');
check(ai_job_lease_seconds([])===360, 'default lease');
check(ai_job_lease_seconds(['lease_seconds'=>1020])===1020, 'configured lease');
check(ai_job_lease_seconds(['lease_seconds'=>5000])===1200, 'bounded lease');
$failed=$row;$failed['status']='failed';$failed['result']='{"saved":true}';
check(ai_jobs_row($failed,true)['result']['saved']===true,'partial result');
check(ai_jobs_payload_hash('post','all',12,['save'=>true,'snapshot'=>'aaa']) === ai_jobs_payload_hash('post','all',12,['save'=>true,'snapshot'=>'bbb','tags_snapshot'=>'x']), 'snapshot excluded from hash');
check(ai_jobs_payload_hash('post','all',12,['save'=>true]) !== ai_jobs_payload_hash('post','all',12,['save'=>false]), 'save affects hash');
check(ai_runner_stage_min_seconds('article') >= 20, 'article budget');
check(ai_runner_stage_min_seconds('seo') >= 15, 'seo budget');
check(ai_runner_stage_min_seconds('prepare') <= 2, 'cheap stage budget');
check(llm_parse_json_object('{"a":1}')['a'] === 1, 'json object parse');
check(llm_parse_json_object('prefix {"a":2} suffix')['a'] === 2, 'embedded json object parse');
check(llm_parse_json_object('not-json') === null, 'invalid json parse');
echo "AI queue unit tests passed.\n";
