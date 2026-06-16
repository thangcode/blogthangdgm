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

        fwrite($handle, "-- ShopSieuSale Database Backup\n");
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
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($files as $file) {
                $file = realpath($file);
                $relativePath = substr($file, strlen($source) + 1);

                // Exclude some directories
                if (strpos($relativePath, 'backups') === 0)
                    continue;
                if (strpos($relativePath, 'node_modules') === 0)
                    continue;
                if (strpos($relativePath, '.git') === 0)
                    continue;

                if (is_dir($file)) {
                    $zip->addEmptyDir($relativePath);
                } else if (is_file($file)) {
                    $content = file_get_contents($file);
                    $zip->addFromString($relativePath, $content);

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
}
