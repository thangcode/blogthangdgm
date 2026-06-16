<?php
require 'config/database.php';
$stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'cache_version'");
$stmt->execute([time()]);
echo "Cache cleared!\n";
