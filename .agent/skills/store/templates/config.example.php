<?php
// config/config.php (Template)
// Rename this file from config.example.php and fill in your actual details

/**
 * Database Credentials
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'fptstore_db');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');

/**
 * Security Key - QUAN TRỌNG: Thay đổi key này!
 * Dùng để mã hóa dữ liệu nhạy cảm (như backup).
 * Tạo key mới bằng lệnh: php -r "echo bin2hex(random_bytes(32));"
 */
define('SECURITY_KEY', 'YOUR_64_CHARACTER_RANDOM_KEY_HERE');

/**
 * Encryption Key - Dùng cho các tính năng mã hóa khác (nếu có)
 */
define('ENCRYPTION_KEY', 'YOUR_RANDOM_ENCRYPTION_KEY');

/**
 * Base URL
 * Luôn kết thúc bằng dấu gạch chéo /
 * Example: http://yourdomain.com/
 */
define('BASE_URL', 'http://localhost/fptstore/');

/**
 * Path Constants
 */
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ADMIN_PATH', ROOT_PATH . 'admin' . DIRECTORY_SEPARATOR);
define('ASSETS_PATH', BASE_URL . 'assets/');
define('UPLOADS_PATH', ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
define('UPLOADS_URL', BASE_URL . 'assets/uploads/');

/**
 * Site Info
 */
define('SITE_NAME', 'FPT Store');

/**
 * Timezone
 */
date_default_timezone_set('Asia/Ho_Chi_Minh');

/**
 * Error Reporting
 * Set to 0 (production) or E_ALL (development)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * API Keys (Optional)
 * Thêm các API key cần thiết ở đây hoặc quản lý qua bảng settings
 */
// define('OPENAI_API_KEY', 'your_key_here');
?>
