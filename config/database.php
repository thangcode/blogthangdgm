<?php
// config/database.php

require_once 'config.php';

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
    try {
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        try {
            $pdo = db_connect();
        } catch (Throwable $e2) {
            // Để truy vấn kế tiếp ném lỗi rõ ràng nếu vẫn không kết nối được.
        }
    }
}

try {
    $pdo = db_connect();

} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        // Keep CLI diagnostics usable for maintenance scripts.
        throw $e;
    }
    die('Database connection failed. Please check server configuration.');
}
?>
