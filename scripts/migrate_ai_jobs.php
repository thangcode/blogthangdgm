<?php
/** Install the durable AI queue. Run explicitly with PHP CLI before enabling Cron. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        batch_id CHAR(32) NOT NULL,
        request_key CHAR(64) NOT NULL,
        payload_hash CHAR(64) NOT NULL,
        active_key VARCHAR(191) DEFAULT NULL,
        kind VARCHAR(20) NOT NULL,
        action VARCHAR(20) NOT NULL,
        entity_id INT DEFAULT NULL,
        payload LONGTEXT NOT NULL,
        checkpoint LONGTEXT NOT NULL,
        result LONGTEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        stage VARCHAR(40) NOT NULL DEFAULT 'prepare',
        attempts INT NOT NULL DEFAULT 0,
        recoveries INT NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        claim_token CHAR(32) DEFAULT NULL,
        lease_until DATETIME DEFAULT NULL,
        cancel_requested TINYINT NOT NULL DEFAULT 0,
        error_code VARCHAR(60) DEFAULT NULL,
        message VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_ai_request (owner_id, request_key),
        UNIQUE KEY uq_ai_active (active_key),
        KEY idx_ai_claim (status, available_at, id),
        KEY idx_ai_batch (owner_id, batch_id, id),
        KEY idx_ai_owner (owner_id, id),
        KEY idx_ai_lease (status, lease_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_worker_state (
        id TINYINT PRIMARY KEY,
        last_seen DATETIME DEFAULT NULL,
        state VARCHAR(30) NOT NULL DEFAULT 'never',
        current_job BIGINT UNSIGNED DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO ai_worker_state (id, state) VALUES (1, 'never')");
    $jobColumns = [
        'owner_id' => 'INT NOT NULL DEFAULT 0',
        'batch_id' => "CHAR(32) NOT NULL DEFAULT ''",
        'request_key' => "CHAR(64) NOT NULL DEFAULT ''",
        'payload_hash' => "CHAR(64) NOT NULL DEFAULT ''",
        'active_key' => 'VARCHAR(191) DEFAULT NULL',
        'kind' => "VARCHAR(20) NOT NULL DEFAULT ''",
        'action' => "VARCHAR(20) NOT NULL DEFAULT ''",
        'entity_id' => 'INT DEFAULT NULL',
        'payload' => 'LONGTEXT NULL',
        'checkpoint' => 'LONGTEXT NULL',
        'result' => 'LONGTEXT DEFAULT NULL',
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'queued'",
        'stage' => "VARCHAR(40) NOT NULL DEFAULT 'prepare'",
        'attempts' => 'INT NOT NULL DEFAULT 0',
        'recoveries' => 'INT NOT NULL DEFAULT 0',
        'available_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'claim_token' => 'CHAR(32) DEFAULT NULL',
        'lease_until' => 'DATETIME DEFAULT NULL',
        'cancel_requested' => 'TINYINT NOT NULL DEFAULT 0',
        'error_code' => 'VARCHAR(60) DEFAULT NULL',
        'message' => 'VARCHAR(500) DEFAULT NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'finished_at' => 'DATETIME DEFAULT NULL',
    ];
    $colCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($jobColumns as $column => $type) {
        $colCheck->execute(['ai_jobs', $column]);
        if (!(int) $colCheck->fetchColumn()) $pdo->exec("ALTER TABLE ai_jobs ADD COLUMN `$column` $type");
    }
    $pdo->exec("UPDATE ai_jobs SET payload='{}' WHERE payload IS NULL");
    $pdo->exec("UPDATE ai_jobs SET checkpoint='{}' WHERE checkpoint IS NULL");
    foreach (['last_seen' => 'DATETIME DEFAULT NULL', 'state' => "VARCHAR(30) NOT NULL DEFAULT 'never'", 'current_job' => 'BIGINT UNSIGNED DEFAULT NULL'] as $column => $type) {
        $colCheck->execute(['ai_worker_state', $column]);
        if (!(int) $colCheck->fetchColumn()) $pdo->exec("ALTER TABLE ai_worker_state ADD COLUMN `$column` $type");
    }
    $indexCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    foreach (['uq_ai_request' => 'UNIQUE KEY uq_ai_request (owner_id, request_key)',
              'uq_ai_active' => 'UNIQUE KEY uq_ai_active (active_key)',
              'idx_ai_claim' => 'KEY idx_ai_claim (status, available_at, id)',
              'idx_ai_batch' => 'KEY idx_ai_batch (owner_id, batch_id, id)',
              'idx_ai_owner' => 'KEY idx_ai_owner (owner_id, id)',
              'idx_ai_lease' => 'KEY idx_ai_lease (status, lease_until)'] as $name => $ddl) {
        $indexCheck->execute(['ai_jobs', $name]);
        if (!(int) $indexCheck->fetchColumn()) $pdo->exec("ALTER TABLE ai_jobs ADD $ddl");
    }
    $criticalTables = ['posts', 'pages', 'categories', 'products', 'tags', 'post_tags', 'audit_logs', 'ai_jobs', 'ai_worker_state'];
    $engineCheck = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($criticalTables as $table) {
        $engineCheck->execute([$table]);
        $engine = strtoupper((string) $engineCheck->fetchColumn());
        if ($engine !== '' && $engine !== 'INNODB') {
            $pdo->exec("ALTER TABLE `$table` ENGINE=InnoDB");
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_write_ideas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        owner_id BIGINT UNSIGNED NOT NULL,
        idea VARCHAR(500) NOT NULL,
        brief TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        job_id BIGINT UNSIGNED DEFAULT NULL,
        post_id BIGINT UNSIGNED DEFAULT NULL,
        message VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_aiw_owner_status (owner_id, status),
        KEY idx_aiw_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $colCheck->execute(['ai_write_ideas', 'brief']);
    if (!(int) $colCheck->fetchColumn()) $pdo->exec('ALTER TABLE ai_write_ideas ADD COLUMN brief TEXT DEFAULT NULL AFTER idea');
    foreach (['posts' => ['ai_import_token' => 'CHAR(64) DEFAULT NULL'],
              'pages' => ['focus_keyword' => 'VARCHAR(255) DEFAULT NULL'],
              'categories' => ['content' => 'LONGTEXT DEFAULT NULL', 'focus_keyword' => 'VARCHAR(255) DEFAULT NULL']] as $table => $columns) {
        foreach ($columns as $column => $type) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            if (!(int) $stmt->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
        }
    }
    $index = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='posts' AND INDEX_NAME='uq_posts_ai_import_token'");
    $index->execute();
    if (!(int) $index->fetchColumn()) $pdo->exec('ALTER TABLE posts ADD UNIQUE KEY uq_posts_ai_import_token (ai_import_token)');
    echo "AI queue schema ready. Configure the CLI Cron worker next.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "AI queue migration failed. Check database availability and schema permissions; no credentials are printed.\n");
    exit(1);
}
