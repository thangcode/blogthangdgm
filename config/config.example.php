<?php
// config/config.example.php
// Rename this file to config.php and fill in your actual details

// Database Credentials — ưu tiên biến môi trường (production), fallback placeholder cho local.
define('DB_HOST', getenv('SHOPSSS_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SHOPSSS_DB_NAME') ?: 'your_database_name');
define('DB_USER', getenv('SHOPSSS_DB_USER') ?: 'your_database_user');
define('DB_PASS', getenv('SHOPSSS_DB_PASS') !== false ? getenv('SHOPSSS_DB_PASS') : 'your_database_password');

// Base URL Logic
$protocol = 'http';
if (
    (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1)) ||
    (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
    (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ||
    (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
) {
    $protocol = 'https';
}

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_path = '/'; 

if ($host === 'localhost' || $host === '127.0.0.1') {
    $base_path = '/store/';
}

define('BASE_URL', $protocol . '://' . $host . $base_path);

// Security Key - QUAN TRỌNG: Thay đổi key này!
// Tạo key mới bằng lệnh: php -r "echo bin2hex(random_bytes(32));"
// Ưu tiên biến môi trường SHOPSSS_SECURITY_KEY (đặt trên server) để không lưu khóa trong file.
define('SECURITY_KEY', getenv('SHOPSSS_SECURITY_KEY') ?: 'YOUR_64_CHARACTER_RANDOM_KEY_HERE');
define('BACKUP_PASSWORD', substr(hash('sha256', SECURITY_KEY), 0, 16)); // Derived password for encrypted backups

// Path Constants
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ADMIN_PATH', ROOT_PATH . 'admin' . DIRECTORY_SEPARATOR);
define('ASSETS_PATH', BASE_URL . 'assets/');
define('UPLOADS_PATH', ROOT_PATH . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
define('UPLOADS_URL', BASE_URL . 'assets/uploads/');

// Site Info
define('SITE_NAME', 'ShopSieuSale');

// Timezone
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Error Reporting (Set to 0 for production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
?>