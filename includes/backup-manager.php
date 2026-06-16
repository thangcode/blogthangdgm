<?php
// includes/backup-manager.php

class BackupManager
{
    private $pdo;
    private $backupDir;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->backupDir = dirname(__DIR__) . '/backups/';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    private function setStatus($message, $percent = 0)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['backup_status'] = [
            'message' => $message,
            'percent' => $percent,
            'time' => time()
        ];
        session_write_close();
    }

    /**
     * Generate a full backup (DB + Files)
     * @return string|bool Filename of the backup on success, false on failure
     */
    public function createFullBackup()
    {
        $this->setStatus("Khởi tạo tiến trình...", 5);
        $timestamp = date('Y-m-d_H-i-s');
        $dbFileName = "db_backup_{$timestamp}.sql";
        $zipFileName = "site_backup_{$timestamp}.zip";

        $dbPath = $this->backupDir . $dbFileName;
        $zipPath = $this->backupDir . $zipFileName;

        try {
            // 1. Export Database
            $this->setStatus("Đang xuất cơ sở dữ liệu...", 15);
            if (!$this->exportDatabase($dbPath)) {
                throw new Exception("Failed to export database.");
            }

            // 2. Create Zip Archive
            $this->setStatus("Đang nén và mã hóa dữ liệu (có thể mất vài phút)...", 40);
            $password = defined('BACKUP_PASSWORD') ? BACKUP_PASSWORD : null;
            if (!$this->createZip(dirname(__DIR__), $zipPath, $dbPath, $password)) {
                throw new Exception("Failed to create encrypted zip archive.");
            }

            // 3. Cleanup temp SQL file
            $this->setStatus("Đang hoàn tất và dọn dẹp...", 95);
            if (file_exists($dbPath)) {
                @unlink($dbPath);
            }

            $this->setStatus("Hoàn thành!", 100);
            return $zipFileName;
        } catch (Exception $e) {
            $this->setStatus("Lỗi: " . $e->getMessage(), 0);
            if (file_exists($dbPath))
                @unlink($dbPath);
            if (file_exists($zipPath))
                @unlink($zipPath);
            return false;
        }
    }

    /**
     * List all available backups
     */
    public function getBackups()
    {
        $files = glob($this->backupDir . "*.zip");
        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'date' => date("Y-m-d H:i:s", filemtime($file))
            ];
        }
        usort($backups, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        return $backups;
    }

    /**
     * Delete a backup file
     */
    public function deleteBackup($filename)
    {
        $filePath = $this->backupDir . basename($filename);
        if (file_exists($filePath) && strpos(basename($filename), 'site_backup_') === 0) {
            return unlink($filePath);
        }
        return false;
    }

    /**
     * Basic SQL Export logic via PHP (since mysqldump might be disabled)
     */
    private function exportDatabase($destPath)
    {
        $handle = fopen($destPath, 'w+');
        if (!$handle)
            return false;

        fwrite($handle, "-- Blog Thang DGM Database Backup\n");
        fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = [];
        $result = $this->pdo->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        foreach ($tables as $table) {
            // Drop & Create table
            $res = $this->pdo->query("SHOW CREATE TABLE `$table`")->fetch();
            fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
            fwrite($handle, $res['Create Table'] . ";\n\n");

            // Data
            $result = $this->pdo->query("SELECT * FROM `$table` shadow_rows");
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $keys = array_keys($row);
                $values = array_values($row);
                $values = array_map(function ($v) {
                    if ($v === null)
                        return 'NULL';
                    return $this->pdo->quote($v);
                }, $values);

                fwrite($handle, "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $values) . ");\n");
            }
            fwrite($handle, "\n\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
        return true;
    }

    /**
     * Recursive Zip logic with Encryption
     */
    private function createZip($source, $destination, $extraFile = null, $password = null)
    {
        if (!extension_loaded('zip') || !file_exists($source)) {
            return false;
        }

        $zip = new ZipArchive();
        if (!$zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            return false;
        }

        $source = realpath($source);

        if (is_dir($source)) {
            // Khi domain chính đặt tại public_html, các domain khác là thư mục con CÙNG CẤP.
            // Cắt nhánh: KHÔNG quét vào thư mục addon-domain (tên có dấu chấm) hoặc thư mục hệ thống hosting,
            // để backup chỉ gói mã nguồn blog, không nuốt website khác (tránh file khổng lồ / tràn bộ nhớ).
            $sourceNorm = str_replace('\\', '/', $source);
            $systemDirs = ['backups', 'node_modules', '.git', 'cgi-bin', '.well-known', '.trash', 'ssl', 'mail', 'tmp', 'logs', '.cpanel', '.htpasswds', 'perl', 'php', 'etc', '.ssh'];
            $shouldSkipTop = function ($name) use ($systemDirs) {
                if (in_array(strtolower($name), $systemDirs, true)) {
                    return true;
                }
                // Thư mục dạng domain (kết thúc bằng TLD): shop.thang-dgm.com, chiencuuho.com,
                // fpt5s.vn, googleads.io.vn ... — loại để không backup nhầm website khác.
                return (bool) preg_match('/\.(com|net|org|info|biz|co|io|vn|me|tv|asia|id|xyz|online|site|store|shop|app|dev|edu|gov|us|uk|jp|cn|in|de|fr|ru|club|pro|name|mobi)(\.[a-z]{2,})?$/i', $name);
            };

            $dirIterator = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($sourceNorm, $shouldSkipTop) {
                $path = str_replace('\\', '/', $current->getPathname());
                $rel = ltrim(substr($path, strlen($sourceNorm)), '/');
                $isTopLevel = (strpos($rel, '/') === false);
                // Chỉ cắt nhánh ở CẤP 1 và chỉ với THƯ MỤC (giữ nguyên file gốc cấp 1 như .htaccess, index.php).
                if ($isTopLevel && $current->isDir() && $shouldSkipTop($current->getFilename())) {
                    return false;
                }
                return true;
            });
            $files = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

            foreach ($files as $file) {
                $real = realpath($file->getPathname());
                if ($real === false) {
                    continue;
                }
                $relativePath = substr($real, strlen($source) + 1);
                if ($relativePath === '') {
                    continue;
                }

                if ($file->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } else if ($file->isFile()) {
                    $zip->addFile($real, $relativePath);
                    // Apply encryption if password is set
                    if ($password) {
                        $zip->setEncryptionName($relativePath, ZipArchive::EM_AES_256, $password);
                    }
                }
            }
        } else if (is_file($source)) {
            $zip->addFromString(basename($source), file_get_contents($source));
            if ($password) {
                $zip->setEncryptionName(basename($source), ZipArchive::EM_AES_256, $password);
            }
        }

        // Add extra SQL file if provided
        if ($extraFile && file_exists($extraFile)) {
            $zip->addFile($extraFile, 'database.sql');
            if ($password) {
                $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256, $password);
            }
        }

        return $zip->close();
    }

    /**
     * SQL-aware statement splitter.
     * Tracks single/double-quote string state, backslash escapes, doubled-quote
     * escapes, ignores "-- " line comments (requires whitespace/EOL after --),
     * and splits on ';' only when outside of a string literal.
     *
     * @return string[] Trimmed, non-empty statements.
     */
    public static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);

        $inSingle = false; // inside '...'
        $inDouble = false; // inside "..."
        $inComment = false; // inside a -- ... line comment

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            if ($inComment) {
                // Comment runs until end of line
                if ($ch === "\n") {
                    $inComment = false;
                    $current .= $ch;
                }
                continue;
            }

            if ($inSingle) {
                $current .= $ch;
                if ($ch === '\\') {
                    // Backslash escape: consume next char literally
                    if ($next !== '') {
                        $current .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($ch === "'") {
                    if ($next === "'") {
                        // Doubled-quote escape
                        $current .= $next;
                        $i++;
                        continue;
                    }
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                $current .= $ch;
                if ($ch === '\\') {
                    if ($next !== '') {
                        $current .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($ch === '"') {
                    if ($next === '"') {
                        $current .= $next;
                        $i++;
                        continue;
                    }
                    $inDouble = false;
                }
                continue;
            }

            // Outside any string/comment

            // Detect "-- " line comment: requires whitespace or EOL after --
            if ($ch === '-' && $next === '-') {
                $after = ($i + 2 < $len) ? $sql[$i + 2] : '';
                if ($after === '' || $after === ' ' || $after === "\t" || $after === "\r" || $after === "\n") {
                    $inComment = true;
                    continue;
                }
            }

            if ($ch === "'") {
                $inSingle = true;
                $current .= $ch;
                continue;
            }

            if ($ch === '"') {
                $inDouble = true;
                $current .= $ch;
                continue;
            }

            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Restore the database from a backup zip's database.sql entry.
     * Writes a safety dump before applying any changes.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function restoreDatabase(string $zipFilename): array
    {
        // Validate filename
        $base = basename($zipFilename);
        if (strpos($base, 'site_backup_') !== 0 || substr($base, -4) !== '.zip') {
            return ['success' => false, 'message' => 'Tên file backup không hợp lệ.'];
        }

        $zipPath = $this->backupDir . $base;
        if (!file_exists($zipPath)) {
            return ['success' => false, 'message' => 'File backup không tồn tại.'];
        }

        if (!extension_loaded('zip')) {
            return ['success' => false, 'message' => 'Thiếu PHP zip extension.'];
        }

        // Open zip and read database.sql
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'message' => 'Không thể mở file backup.'];
        }
        if (defined('BACKUP_PASSWORD')) {
            $zip->setPassword(BACKUP_PASSWORD);
        }
        $sql = $zip->getFromName('database.sql');
        $zip->close();

        if ($sql === false || trim($sql) === '') {
            return ['success' => false, 'message' => 'Không tìm thấy hoặc không đọc được database.sql trong backup.'];
        }

        // Safety dump BEFORE restoring
        $safetyName = 'pre_restore_db_' . date('Y-m-d_H-i-s') . '.sql';
        $safetyPath = $this->backupDir . $safetyName;
        if (!$this->exportDatabase($safetyPath)) {
            return ['success' => false, 'message' => 'Không thể tạo bản sao lưu an toàn trước khi khôi phục. Đã hủy.'];
        }

        $statements = self::splitSqlStatements($sql);

        $executed = 0;
        try {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach ($statements as $stmt) {
                $this->pdo->exec($stmt);
                $executed++;
            }
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        } catch (Exception $e) {
            // Re-enable FK checks; do not leave a silent half-restore unexplained
            try {
                $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            } catch (Exception $ignore) {
            }
            return [
                'success' => false,
                'message' => 'Lỗi khi khôi phục: ' . $e->getMessage()
                    . ' (đã thực thi ' . $executed . ' câu lệnh). Bản sao lưu an toàn: ' . $safetyName
            ];
        }

        return [
            'success' => true,
            'message' => 'Khôi phục thành công ' . $executed . ' câu lệnh. Bản sao lưu an toàn: ' . $safetyName
        ];
    }
}
