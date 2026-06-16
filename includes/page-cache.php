<?php
// includes/page-cache.php
// PHP File-based Output Cache
// - Không cần Redis/Memcached, chạy trên mọi shared hosting
// - Bật/tắt qua Admin Settings (setting: page_cache_enabled)
// - Kiểm tra qua View Source: <!-- Page Cache: HIT/MISS -->

class PageCache
{
    /** Thư mục lưu file cache (tuyệt đối) */
    private static string $cacheDir = '';

    /** Thời gian sống mặc định: 300 giây (5 phút) */
    public static int $ttl = 300;

    // ─── Internal state ───────────────────────────────────────────────────────
    private static ?string $currentKey = null;
    private static float   $startTime  = 0.0;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Khởi tạo thư mục cache.
     */
    private static function dir(): string
    {
        if (self::$cacheDir === '') {
            self::$cacheDir = rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR
                            . 'cache' . DIRECTORY_SEPARATOR . 'pages';
        }
        return self::$cacheDir;
    }

    /**
     * TTL (giây) có thể cấu hình qua Admin Settings (setting: page_cache_ttl).
     * Kẹp trong khoảng [30, 86400], mặc định 300. Cache static theo từng request.
     */
    private static function ttl(): int
    {
        static $ttl = null;
        if ($ttl === null) {
            $val = (int) get_setting('page_cache_ttl', '300');
            if ($val < 30) {
                $val = 30;
            } elseif ($val > 86400) {
                $val = 86400;
            }
            $ttl = $val;
        }
        return $ttl;
    }

    /**
     * Đảm bảo thư mục cache tồn tại và luôn được bảo vệ:
     * - mkdir đệ quy nếu chưa có
     * - LUÔN ghi .htaccess chặn truy cập trực tiếp
     * - ghi index.html rỗng nếu chưa có
     */
    private static function ensureGuard(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $htaccess = "Require all denied\n"
                  . "<IfModule !mod_authz_core.c>\n"
                  . "Deny from all\n"
                  . "</IfModule>\n";
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', $htaccess);

        $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * Kiểm tra cache có đang bật không (đọc từ DB setting).
     */
    public static function isEnabled(): bool
    {
        static $enabled = null;
        if ($enabled === null) {
            $enabled = (get_setting('page_cache_enabled', '0') === '1');
        }
        return $enabled;
    }

    /**
     * Không cache nếu admin đang đăng nhập (để thấy thay đổi real-time).
     *
     * Lưu ý về phần động (đã xử lý để cache an toàn cho khách vãng lai):
     * - CSRF token: KHÔNG nhúng vào HTML khi cache bật; client tự nạp token đúng phiên
     *   qua api/csrf-token.php (xem assets/js/app.js + includes/header.php).
     * - traffic / IP-block: chạy server-side ở frontend_cache_prelude() TRƯỚC khi phục vụ
     *   cache HIT (vì header.php bị bỏ qua khi HIT).
     */
    private static function shouldSkip(): bool
    {
        // Admin đăng nhập: luôn phục vụ bản mới nhất.
        if (!empty($_SESSION['user_id'])) {
            return true;
        }

        return false;
    }

    /**
     * Cache có sẵn bản còn tươi và được phép phục vụ cho request hiện tại không?
     * KHÔNG echo gì — chỉ kiểm tra. Dùng để chạy tracking/IP-block server-side
     * ngay trước khi trả cache HIT (vì header.php bị bỏ qua khi HIT).
     */
    public static function willServeCache(string $key): bool
    {
        if (!self::isEnabled() || self::shouldSkip()) {
            return false;
        }

        $file = self::filePath($key);
        if (!is_file($file)) {
            return false;
        }

        return (time() - (int) filemtime($file)) < self::ttl();
    }

    /**
     * Đường dẫn file cache cho một key.
     */
    private static function filePath(string $key): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9_-]/', '_', $key) . '.html';
    }

    /**
     * Thử lấy HTML từ cache (nếu còn tươi).
     * Nếu HIT → echo HTML + comment + exit.
     * Nếu MISS → trả về false, caller sẽ gọi start().
     */
    public static function get(string $key): bool
    {
        if (!self::isEnabled() || self::shouldSkip()) {
            return false;
        }

        $file = self::filePath($key);

        if (!is_file($file)) {
            return false;
        }

        $age = time() - (int) filemtime($file);
        if ($age >= self::ttl()) {
            @unlink($file);
            return false;
        }

        // ── CACHE HIT ──────────────────────────────────────────────────────
        $html = file_get_contents($file);
        // Gắn comment vào cuối để kiểm tra qua View Source
        $comment = "\n<!-- Page Cache: HIT (age: {$age}s / ttl: " . self::ttl() . "s) -->";
        echo $html . $comment;
        return true;
    }

    /**
     * Bắt đầu capture output (ob_start).
     * Gọi sau khi get() trả về false.
     */
    public static function start(string $key): void
    {
        if (!self::isEnabled() || self::shouldSkip()) {
            return;
        }

        self::$currentKey = $key;
        self::$startTime  = microtime(true);
        ob_start();
    }

    /**
     * Lưu output đã capture vào file cache, rồi flush ra browser.
     * Gọi ở cuối trang (trước </html>).
     */
    public static function save(): void
    {
        if (self::$currentKey === null || !self::isEnabled() || self::shouldSkip()) {
            return;
        }

        $html = ob_get_clean();

        // Đảm bảo thư mục cache tồn tại và được bảo vệ
        self::ensureGuard();

        $file = self::filePath(self::$currentKey);
        @file_put_contents($file, $html, LOCK_EX);

        // Gắn comment MISS vào cuối (không lưu vào file, chỉ echo ra browser)
        $elapsed = round((microtime(true) - self::$startTime) * 1000, 1);
        echo $html . "\n<!-- Page Cache: MISS (generated: {$elapsed}ms) -->";

        self::$currentKey = null;
    }

    /**
     * Xóa 1 file cache theo key (gọi khi nội dung của đúng trang đó thay đổi).
     * Trả về true nếu có file và đã xóa được.
     */
    public static function delete(string $key): bool
    {
        $file = self::filePath($key);
        if (is_file($file)) {
            return @unlink($file);
        }
        return false;
    }

    /**
     * Xóa toàn bộ file cache (gọi khi admin bấm "Xóa cache").
     * Trả về số file đã xóa.
     */
    public static function flush(): int
    {
        self::ensureGuard();

        $dir   = self::dir();
        $count = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.html') ?: [] as $f) {
            // Không xóa file bảo vệ index.html
            if (basename($f) === 'index.html') {
                continue;
            }
            if (@unlink($f)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Thống kê cache hiện tại (dành cho admin).
     * @return array{count: int, size_kb: float, oldest_age: int}
     */
    public static function stats(): array
    {
        $dir   = self::dir();
        $files = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . '*.html') ?: []) : [];
        // Loại file bảo vệ index.html khỏi thống kê
        $files = array_values(array_filter($files, static function ($f) {
            return basename($f) !== 'index.html';
        }));

        $totalSize  = 0;
        $oldestTime = time();

        foreach ($files as $f) {
            $totalSize  += (int) filesize($f);
            $oldestTime  = min($oldestTime, (int) filemtime($f));
        }

        return [
            'count'      => count($files),
            'size_kb'    => round($totalSize / 1024, 1),
            'oldest_age' => count($files) ? (time() - $oldestTime) : 0,
        ];
    }
}
