<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/page-cache.php';
try { if (class_exists('PageCache') && method_exists('PageCache','flush')) PageCache::flush(); } catch (Throwable $e) {}
try { $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('cache_version',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([(string)time()]); } catch (Throwable $e) {}
