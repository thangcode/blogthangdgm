<?php
/**
 * Cleanup orphaned media_library records (DB entry exists but file doesn't).
 * Run: php cleanup_orphaned_media.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied.');
}
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "=== Media Library Orphan Cleanup ===\n\n";

$stmt = $pdo->query("SELECT id, file_path, original_name FROM media_library ORDER BY id DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
echo "Scanning $total records...\n\n";

$deleted = 0;
$ok = 0;

foreach ($rows as $row) {
    $abs = ROOT_PATH . $row['file_path'];
    if (!file_exists($abs)) {
        echo "  [ORPHAN] ID {$row['id']} - {$row['original_name']} ({$row['file_path']})\n";
        $pdo->prepare("DELETE FROM media_library WHERE id = ?")->execute([$row['id']]);
        $deleted++;
    } else {
        $ok++;
    }
}

echo "\nDone! OK: $ok | Orphans removed: $deleted\n";
