<?php
// config/database.php

require_once __DIR__ . '/config.php';

/**
 * Tạo kết nối PDO mới (dùng lại khi cần kết nối lại sau tác vụ dài).
 */
function db_connect(): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $dsn = "mysql:host=" . trim(DB_HOST) . ";dbname=" . trim(DB_NAME) . ";charset=utf8mb4";
    return new PDO($dsn, trim(DB_USER), DB_PASS, $options);
}

/**
 * Đảm bảo kết nối còn sống; nếu bị rớt (MySQL "server has gone away" sau khi gọi LLM
 * hoặc tác vụ chạy lâu vượt wait_timeout) thì tự kết nối lại.
 */
function db_ensure_alive(PDO &$pdo): void
{
    $original = $pdo;
    $inTransaction = false;
    try {
        $inTransaction = $pdo->inTransaction();
        $pdo->query('SELECT 1');
        return;
    } catch (PDOException $e) {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $disconnected = in_array($driverCode, [2006, 2013, 2055], true)
            || in_array($state, ['08003', '08006', '08S01'], true);
        if ($inTransaction) {
            throw new RuntimeException('db_transaction_lost');
        }
        if (!$disconnected) {
            throw new RuntimeException('db_health_check_failed');
        }
    } catch (Throwable $e) {
        throw new RuntimeException('db_health_check_failed');
    }
    try {
        $replacement = db_connect();
    } catch (Throwable $e) {
        throw new RuntimeException('db_reconnect_failed');
    }
    // Replace the shared connection only when it is the same instance, not an unrelated DB.
    if (($GLOBALS['pdo'] ?? null) === $original) {
        $GLOBALS['pdo'] = $replacement;
    }
    $pdo = $replacement;
}

try {
    $pdo = db_connect();

} catch (PDOException $e) {
    error_log('Database connection failed.');
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('db_connection_failed');
    }
    die('Database connection failed. Please check server configuration.');
}
?>
