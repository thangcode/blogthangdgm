<?php
/** Bounded CLI worker intended for shared-hosting Cron. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/llm.php';
require_once __DIR__ . '/../includes/blog.php';
require_once __DIR__ . '/../includes/page-cache.php';
require_once __DIR__ . '/../includes/ai-jobs.php';
require_once __DIR__ . '/../includes/ai-import.php';
require_once __DIR__ . '/../includes/ai-job-runner.php';

$options = getopt('', ['max-jobs::', 'max-seconds::', 'once', 'check']);
$maxJobs = max(1, min(50, (int) ($options['max-jobs'] ?? 5)));
$maxSeconds = max(30, min(900, (int) ($options['max-seconds'] ?? 240)));
if (isset($options['once'])) $maxJobs = 1;

try {
    if (!ai_jobs_schema_ready($pdo)) { fwrite(STDERR, "AI queue migration is required.\n"); exit(2); }
    if (isset($options['check'])) {
        $row = $pdo->query("SELECT COUNT(*) queued FROM ai_jobs WHERE status IN ('queued','retry_wait')")->fetch();
        echo 'AI worker check OK; queued=' . (int) ($row['queued'] ?? 0) . PHP_EOL;
        exit(0);
    }
    $lockDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($lockDir) && !@mkdir($lockDir, 0750, true) && !is_dir($lockDir)) throw new RuntimeException('lock_dir');
    $lock = @fopen($lockDir . DIRECTORY_SEPARATOR . 'ai-worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Another AI worker is active.\n"; exit(0); }
    ai_jobs_prune_finished($pdo);

    $started = microtime(true); $processed = 0;
    $jobDeadline = $started + $maxSeconds - 3;
    $updateState = static function (string $workerState, ?int $jobId = null): void {
        $statePdo = ai_jobs_pdo();
        $statePdo->prepare("UPDATE ai_worker_state SET last_seen=NOW(),state=?,current_job=? WHERE id=1")
            ->execute([$workerState, $jobId]);
    };
    $updateState('idle');
    while ($processed < $maxJobs && microtime(true) < $jobDeadline - 8) {
        $job = ai_job_claim($pdo, min(1200, $maxSeconds + 120));
        if (!$job) break;
        $processed++; $updateState('running', (int) $job['id']);
        try {
            $stopWorker = false;
            while (($job['status'] ?? 'running') === 'running' && microtime(true) < $jobDeadline - 2) {
                $before = (string) $job['stage'];
                $needed = ai_runner_stage_min_seconds($before);
                if (($jobDeadline - microtime(true)) < $needed) {
                    $stopWorker = true;
                    break;
                }
                ai_run_job($job, $jobDeadline);
                if (($job['status'] ?? '') !== 'running') break;
                if ((string) $job['stage'] === $before) throw new RuntimeException('stage_stalled');
                $updateState('running', (int) $job['id']);
            }
            if (($job['status'] ?? '') === 'running') ai_job_yield($job);
            if ($stopWorker) break;
        } catch (AiJobException $e) {
            if ($e->errorCode === 'cancelled') ai_job_mark_cancelled($job);
            else ai_job_mark_error($job, $e->errorCode, $e->retryable);
        } catch (Throwable $e) {
            if ($e->getMessage() === 'cancelled') ai_job_mark_cancelled($job);
            elseif ($e->getMessage() !== 'claim_lost') ai_job_mark_error($job, 'worker_error', false);
        }
        db_ensure_alive($pdo); $updateState('idle');
    }
    $updateState('idle');
    echo "AI worker completed; jobs={$processed}.\n";
} catch (Throwable $e) {
    try { if (isset($pdo) && $pdo instanceof PDO && ai_jobs_schema_ready($pdo)) $pdo->prepare("UPDATE ai_worker_state SET last_seen=NOW(),state='error',current_job=NULL WHERE id=1")->execute(); } catch (Throwable $ignored) {}
    fwrite(STDERR, "AI worker stopped because a safe runtime check failed.\n");
    exit(1);
}
