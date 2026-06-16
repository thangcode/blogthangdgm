<?php
/**
 * blog_schema.php
 * Tạo/nâng cấp schema cho Blog thuần (idempotent — chạy lại an toàn).
 * - Mở rộng bảng `posts` (thêm cột blog/SEO/tài liệu).
 * - Tạo bảng `tags`, `post_categories`, `post_tags`, `document_requests`.
 * - Tạo thư mục assets/uploads/documents + .htaccess chặn thực thi.
 *
 * Chạy: php scripts/blog_schema.php
 */

if (PHP_SAPI !== 'cli' && !defined('BLOG_SCHEMA_BOOTSTRAP')) {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Đảm bảo schema blog tồn tại. Idempotent, bọc try/catch từng bước.
 */
function blog_ensure_schema(PDO $pdo): array
{
    $log = [];
    $add = function (string $msg) use (&$log) { $log[] = $msg; };

    // 1) Mở rộng bảng posts (chỉ thêm cột còn thiếu).
    $postColumns = [
        'thumbnail_alt'       => "ADD COLUMN `thumbnail_alt` VARCHAR(255) NULL AFTER `thumbnail`",
        'author_name'         => "ADD COLUMN `author_name` VARCHAR(150) NULL AFTER `thumbnail_alt`",
        'views'               => "ADD COLUMN `views` INT NOT NULL DEFAULT 0 AFTER `author_name`",
        'schema_type'         => "ADD COLUMN `schema_type` VARCHAR(30) NOT NULL DEFAULT 'BlogPosting' AFTER `focus_keyword`",
        'document_path'       => "ADD COLUMN `document_path` VARCHAR(255) NULL AFTER `schema_type`",
        'document_name'       => "ADD COLUMN `document_name` VARCHAR(255) NULL AFTER `document_path`",
        'primary_category_id' => "ADD COLUMN `primary_category_id` INT NULL AFTER `document_name`",
        'updated_at'          => "ADD COLUMN `updated_at` DATETIME NULL AFTER `created_at`",
    ];
    foreach ($postColumns as $col => $ddl) {
        try {
            if (!has_table_column($pdo, 'posts', $col)) {
                $pdo->exec("ALTER TABLE `posts` {$ddl}");
                $add("[OK] posts.+{$col}");
            } else {
                $add("[SKIP] posts.{$col} đã có");
            }
        } catch (Throwable $e) {
            $add("[ERR] posts.{$col}: " . $e->getMessage());
        }
    }

    // Nới rộng meta_title nếu đang quá ngắn (WP title có thể > 70).
    try {
        $pdo->exec("ALTER TABLE `posts` MODIFY `meta_title` VARCHAR(255) NULL");
        $add("[OK] posts.meta_title -> VARCHAR(255)");
    } catch (Throwable $e) {
        $add("[SKIP] posts.meta_title modify: " . $e->getMessage());
    }

    // Index slug (unique) + primary_category để truy vấn nhanh.
    try {
        $hasIdx = $pdo->query("SHOW INDEX FROM `posts` WHERE Key_name = 'uniq_posts_slug'")->fetch();
        if (!$hasIdx) {
            // Có thể trùng slug ở dữ liệu cũ -> dùng index thường thay vì unique nếu fail.
            try {
                $pdo->exec("ALTER TABLE `posts` ADD UNIQUE KEY `uniq_posts_slug` (`slug`)");
                $add("[OK] posts UNIQUE(slug)");
            } catch (Throwable $e) {
                $pdo->exec("ALTER TABLE `posts` ADD KEY `idx_posts_slug` (`slug`)");
                $add("[WARN] slug không unique được, dùng index thường: " . $e->getMessage());
            }
        } else {
            $add("[SKIP] index slug đã có");
        }
    } catch (Throwable $e) {
        $add("[ERR] index slug: " . $e->getMessage());
    }

    // 2) Bảng tags
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `tags` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `slug` VARCHAR(191) NOT NULL,
            `description` TEXT NULL,
            `meta_title` VARCHAR(255) NULL,
            `meta_description` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_tags_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $add("[OK] bảng tags");
    } catch (Throwable $e) {
        $add("[ERR] tags: " . $e->getMessage());
    }

    // 3) Bảng quan hệ post_categories
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `post_categories` (
            `post_id` INT NOT NULL,
            `category_id` INT NOT NULL,
            PRIMARY KEY (`post_id`, `category_id`),
            KEY `idx_pc_category` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $add("[OK] bảng post_categories");
    } catch (Throwable $e) {
        $add("[ERR] post_categories: " . $e->getMessage());
    }

    // 4) Bảng quan hệ post_tags
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `post_tags` (
            `post_id` INT NOT NULL,
            `tag_id` INT NOT NULL,
            PRIMARY KEY (`post_id`, `tag_id`),
            KEY `idx_pt_tag` (`tag_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $add("[OK] bảng post_tags");
    } catch (Throwable $e) {
        $add("[ERR] post_tags: " . $e->getMessage());
    }

    // 5) Bảng document_requests (lead nhận tài liệu)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `document_requests` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `post_id` INT NOT NULL,
            `fullname` VARCHAR(150) NOT NULL DEFAULT '',
            `email` VARCHAR(191) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
            `error_note` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_dr_post` (`post_id`),
            KEY `idx_dr_created` (`created_at`),
            KEY `idx_dr_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $add("[OK] bảng document_requests");
    } catch (Throwable $e) {
        $add("[ERR] document_requests: " . $e->getMessage());
    }

    // 6) Bảng pages (trang tĩnh: giới thiệu, dịch vụ, chính sách... — giữ URL /{slug}/ như WordPress)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `pages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL,
            `summary` TEXT NULL,
            `content` LONGTEXT NULL,
            `meta_title` VARCHAR(255) NULL,
            `meta_description` TEXT NULL,
            `meta_keywords` VARCHAR(255) NULL,
            `status` TINYINT NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            UNIQUE KEY `uniq_pages_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $add("[OK] bảng pages");
    } catch (Throwable $e) {
        $add("[ERR] pages: " . $e->getMessage());
    }

    return $log;
}

/**
 * Tạo thư mục uploads/documents + .htaccess chặn thực thi script.
 */
function blog_ensure_upload_dirs(): array
{
    $log = [];
    $root = dirname(__DIR__);
    $dirs = [
        $root . '/assets/uploads/documents',
        $root . '/assets/uploads/wp',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0755, true)) {
                $log[] = "[OK] mkdir " . str_replace($root, '', $dir);
            } else {
                $log[] = "[ERR] mkdir fail " . str_replace($root, '', $dir);
            }
        } else {
            $log[] = "[SKIP] đã có " . str_replace($root, '', $dir);
        }
    }

    // .htaccess chặn thực thi PHP trong thư mục documents (defense-in-depth).
    $htaccess = $root . '/assets/uploads/documents/.htaccess';
    if (!file_exists($htaccess)) {
        $content = "# Chặn thực thi script trong thư mục upload tài liệu\n"
            . "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "Options -ExecCGI\n";
        if (@file_put_contents($htaccess, $content) !== false) {
            $log[] = "[OK] tạo documents/.htaccess";
        } else {
            $log[] = "[ERR] tạo documents/.htaccess fail";
        }
    } else {
        $log[] = "[SKIP] documents/.htaccess đã có";
    }

    return $log;
}

// Chạy trực tiếp qua CLI
if (PHP_SAPI === 'cli' && !defined('BLOG_SCHEMA_BOOTSTRAP')) {
    $pdo->exec("SET NAMES utf8mb4");
    echo "=== BLOG SCHEMA ===\n";
    foreach (blog_ensure_schema($pdo) as $line) {
        echo $line . "\n";
    }
    foreach (blog_ensure_upload_dirs() as $line) {
        echo $line . "\n";
    }
    echo "=== HOÀN TẤT ===\n";
}
