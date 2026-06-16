<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();
ensure_traffic_tables($pdo);
ensure_conversion_logs_table($pdo);

function traffic_csv_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$type = trim((string) ($_GET['type'] ?? 'sessions'));
$days = max(1, min(90, (int) ($_GET['days'] ?? 7)));
$dateFrom = date('Y-m-d H:i:s', strtotime('-' . ($days - 1) . ' days 00:00:00'));
$sourceType = trim((string) ($_GET['source_type'] ?? ''));
$deviceType = trim((string) ($_GET['device_type'] ?? ''));
$utmCampaign = trim((string) ($_GET['utm_campaign'] ?? ''));
$pathFilter = trim((string) ($_GET['path'] ?? ''));
$ipFilter = trim((string) ($_GET['ip'] ?? ''));

$sessionConditions = ['started_at >= ?'];
$sessionParams = [$dateFrom];
$conversionConditions = ['created_at >= ?'];
$conversionParams = [$dateFrom];

if ($sourceType !== '') {
    $sessionConditions[] = 'source_type = ?';
    $sessionParams[] = $sourceType;
    $conversionConditions[] = 'source_type = ?';
    $conversionParams[] = $sourceType;
}
if ($deviceType !== '') {
    $sessionConditions[] = 'device_type = ?';
    $sessionParams[] = $deviceType;
    $conversionConditions[] = 'device_type = ?';
    $conversionParams[] = $deviceType;
}
if ($utmCampaign !== '') {
    $sessionConditions[] = 'utm_campaign = ?';
    $sessionParams[] = $utmCampaign;
    $conversionConditions[] = 'utm_campaign = ?';
    $conversionParams[] = $utmCampaign;
}
if ($ipFilter !== '') {
    $sessionConditions[] = 'ip_address LIKE ?';
    $sessionParams[] = '%' . $ipFilter . '%';
    $conversionConditions[] = 'ip_address LIKE ?';
    $conversionParams[] = '%' . $ipFilter . '%';
}
if ($pathFilter !== '') {
    $sessionConditions[] = '(landing_path LIKE ? OR exit_path LIKE ? OR session_key IN (SELECT DISTINCT session_key FROM traffic_pageviews WHERE page_path LIKE ?))';
    $sessionParams[] = '%' . $pathFilter . '%';
    $sessionParams[] = '%' . $pathFilter . '%';
    $sessionParams[] = '%' . $pathFilter . '%';
    $conversionConditions[] = 'page_url LIKE ?';
    $conversionParams[] = '%' . $pathFilter . '%';
}

$botDetectionSql = "(is_bot = 1 OR ((isp_name LIKE 'Google%' OR org_name LIKE 'Google%') AND (ip_address LIKE '66.102.%' OR ip_address LIKE '66.249.%' OR ip_address LIKE '64.233.%' OR ip_address LIKE '72.14.%' OR ip_address LIKE '74.125.%' OR ip_address LIKE '209.85.%' OR ip_address LIKE '216.239.%')))";
$whereSessions = implode(' AND ', $sessionConditions);
$whereHumanSessions = implode(' AND ', array_merge($sessionConditions, ["NOT {$botDetectionSql}"]));
$whereBotSessions = implode(' AND ', array_merge($sessionConditions, [$botDetectionSql]));
$whereConversions = implode(' AND ', $conversionConditions);

if ($type === 'bots') {
    $filename = 'traffic-bots-' . date('Ymd-His') . '.csv';
    $rows = traffic_csv_rows($pdo, "SELECT ip_address, source_type, source_name, source_host, device_type, browser_name, os_name, country_name, region_name, city_name, isp_name, utm_source, utm_medium, utm_campaign, landing_path, pageviews_count, started_at, last_activity_at FROM traffic_sessions WHERE {$whereBotSessions} ORDER BY started_at DESC", $sessionParams);
} elseif ($type === 'conversions') {
    $filename = 'traffic-conversions-' . date('Ymd-His') . '.csv';
    $rows = traffic_csv_rows($pdo, "SELECT type, session_key, visitor_key, ip_address, source_type, source_name, utm_source, utm_medium, utm_campaign, page_url, gtm_event, device_type, created_at FROM conversion_logs WHERE {$whereConversions} ORDER BY created_at DESC", $conversionParams);
} else {
    $filename = 'traffic-sessions-' . date('Ymd-His') . '.csv';
    $rows = traffic_csv_rows($pdo, "SELECT session_key, visitor_key, ip_address, source_type, source_name, source_host, utm_source, utm_medium, utm_campaign, landing_path, exit_path, device_type, browser_name, os_name, country_name, region_name, city_name, isp_name, pageviews_count, TIMESTAMPDIFF(SECOND, started_at, last_activity_at) AS session_duration, started_at, last_activity_at FROM traffic_sessions WHERE {$whereHumanSessions} ORDER BY started_at DESC", $sessionParams);
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename=' . $filename);

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

if (!empty($rows)) {
    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
} else {
    fputcsv($out, ['message']);
    fputcsv($out, ['Khong co du lieu']);
}

fclose($out);
exit;
