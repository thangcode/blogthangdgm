<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

enforce_traffic_ip_block($pdo, ['json' => true]);

header('Content-Type: text/plain; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$eventName = trim((string) ($_POST['event_name'] ?? ''));
$eventLabel = trim((string) ($_POST['event_label'] ?? ''));
$pageUrl = trim((string) ($_POST['page_url'] ?? ''));
$metadataRaw = trim((string) ($_POST['metadata_json'] ?? ''));
$metadata = [];

if ($metadataRaw !== '') {
    $decoded = json_decode($metadataRaw, true);
    if (is_array($decoded)) {
        $metadata = $decoded;
    }
}

if ($pageUrl !== '') {
    $metadata['page_url'] = $pageUrl;
}

if ($eventName === '') {
    http_response_code(422);
    exit;
}

try {
    track_traffic_event($pdo, $eventName, $eventLabel, $metadata);
    http_response_code(204);
} catch (Throwable $e) {
    http_response_code(204);
}
exit;
