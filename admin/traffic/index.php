<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

require_admin_login();
ensure_traffic_tables($pdo);
ensure_conversion_logs_table($pdo);

$trafficReturnQuery = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
$trafficReturnUrl = 'index.php' . ($trafficReturnQuery !== '' ? '?' . $trafficReturnQuery : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trafficAction = trim((string) ($_POST['traffic_action'] ?? ''));
    if (in_array($trafficAction, ['block_ip', 'unblock_ip'], true)) {
        require_valid_csrf_token();

        $targetIp = traffic_normalize_ip_address($_POST['ip_address'] ?? '');
        if ($targetIp === '') {
            $_SESSION['traffic_flash'] = [
                'type' => 'danger',
                'message' => 'IP không hợp lệ.',
            ];
            redirect($trafficReturnUrl);
        }

        if ($trafficAction === 'block_ip') {
            $blockReason = trim((string) ($_POST['block_reason'] ?? ''));
            $blockedBy = trim((string) ($_SESSION['username'] ?? $_SESSION['full_name'] ?? 'admin'));
            traffic_block_ip($pdo, $targetIp, $blockReason, $blockedBy);
            $_SESSION['traffic_flash'] = [
                'type' => 'success',
                'message' => 'Đã chặn IP ' . $targetIp . '.',
            ];
        } else {
            traffic_unblock_ip($pdo, $targetIp);
            $_SESSION['traffic_flash'] = [
                'type' => 'success',
                'message' => 'Đã bỏ chặn IP ' . $targetIp . '.',
            ];
        }

        redirect($trafficReturnUrl);
    }
}

$current_page = 'traffic';
require_once '../includes/header.php';

function traffic_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function traffic_fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    } catch (Exception $e) {
        return [];
    }
}

$period = trim((string) ($_GET['period'] ?? 'today'));
$days = max(1, min(90, (int) ($_GET['days'] ?? 7)));
$periodOptions = [
    'today' => ['label' => 'Hôm nay', 'days' => 1],
    'yesterday' => ['label' => 'Hôm qua', 'days' => 1],
    'last7' => ['label' => '7 ngày qua', 'days' => 7],
    'last30' => ['label' => '30 ngày qua', 'days' => 30],
    'custom' => ['label' => 'Tùy chọn', 'days' => $days],
];
if (!isset($periodOptions[$period])) {
    $period = 'today';
}

$todayStart = strtotime('today 00:00:00');
$tomorrowStart = strtotime('tomorrow 00:00:00');
switch ($period) {
    case 'today':
        $dateFromTs = $todayStart;
        $dateToTs = $tomorrowStart;
        $days = 1;
        break;
    case 'yesterday':
        $dateFromTs = strtotime('yesterday 00:00:00');
        $dateToTs = $todayStart;
        $days = 1;
        break;
    case 'last30':
        $dateFromTs = strtotime('-29 days 00:00:00');
        $dateToTs = $tomorrowStart;
        $days = 30;
        break;
    case 'custom':
        $dateFromTs = strtotime('-' . ($days - 1) . ' days 00:00:00');
        $dateToTs = $tomorrowStart;
        break;
    case 'last7':
    default:
        $dateFromTs = strtotime('-6 days 00:00:00');
        $dateToTs = $tomorrowStart;
        $days = 7;
        break;
}
$dateFrom = date('Y-m-d H:i:s', $dateFromTs);
$dateTo = date('Y-m-d H:i:s', $dateToTs);
$sourceType = trim($_GET['source_type'] ?? '');
$deviceType = trim($_GET['device_type'] ?? '');
$utmCampaign = traffic_clean_tracking_value($_GET['utm_campaign'] ?? '', 150);
$pathFilter = trim($_GET['path'] ?? '');
$ipFilter = trim($_GET['ip'] ?? '');

$sessionConditions = ['started_at >= ?', 'started_at < ?'];
$sessionParams = [$dateFrom, $dateTo];
$eventConditions = ['created_at >= ?', 'created_at < ?'];
$eventParams = [$dateFrom, $dateTo];

if ($sourceType !== '') {
    $sessionConditions[] = 'source_type = ?';
    $sessionParams[] = $sourceType;
    $eventConditions[] = 'source_type = ?';
    $eventParams[] = $sourceType;
}

if ($deviceType !== '') {
    $sessionConditions[] = 'device_type = ?';
    $sessionParams[] = $deviceType;
    $eventConditions[] = 'device_type = ?';
    $eventParams[] = $deviceType;
}

if ($utmCampaign !== '') {
    $sessionConditions[] = 'utm_campaign = ?';
    $sessionParams[] = $utmCampaign;
}

if ($ipFilter !== '') {
    $sessionConditions[] = 'ip_address LIKE ?';
    $sessionParams[] = '%' . $ipFilter . '%';
    $eventConditions[] = 'ip_address LIKE ?';
    $eventParams[] = '%' . $ipFilter . '%';
}

if ($pathFilter !== '') {
    $sessionConditions[] = '(landing_path LIKE ? OR exit_path LIKE ? OR session_key IN (SELECT DISTINCT session_key FROM traffic_pageviews WHERE page_path LIKE ?))';
    $sessionParams[] = '%' . $pathFilter . '%';
    $sessionParams[] = '%' . $pathFilter . '%';
    $sessionParams[] = '%' . $pathFilter . '%';
    $eventConditions[] = 'page_path LIKE ?';
    $eventParams[] = '%' . $pathFilter . '%';
}

$whereSessions = implode(' AND ', $sessionConditions);
$whereEvents = implode(' AND ', $eventConditions);
$botDetectionSql = "(is_bot = 1 OR ((isp_name LIKE 'Google%' OR org_name LIKE 'Google%') AND (ip_address LIKE '66.102.%' OR ip_address LIKE '66.249.%' OR ip_address LIKE '64.233.%' OR ip_address LIKE '72.14.%' OR ip_address LIKE '74.125.%' OR ip_address LIKE '209.85.%' OR ip_address LIKE '216.239.%')))";
$humanSessionConditions = array_merge($sessionConditions, ["NOT {$botDetectionSql}"]);
$humanSessionParams = $sessionParams;
$whereHumanSessions = implode(' AND ', $humanSessionConditions);
$botSessionConditions = array_merge($sessionConditions, [$botDetectionSql]);
$botSessionParams = $sessionParams;
$whereBotSessions = implode(' AND ', $botSessionConditions);
$humanEventConditions = array_merge($eventConditions, ["session_key IN (SELECT session_key FROM traffic_sessions WHERE NOT {$botDetectionSql})"]);
$humanEventParams = $eventParams;
$whereHumanEvents = implode(' AND ', $humanEventConditions);

$summary = traffic_fetch_one(
     $pdo,
    "SELECT COUNT(*) AS sessions,
            COALESCE(SUM(pageviews_count), 0) AS pageviews,
            COUNT(DISTINCT visitor_key) AS visitors,
            COALESCE(SUM(CASE WHEN is_new_visitor = 1 THEN 1 ELSE 0 END), 0) AS new_visitors,
            COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, last_activity_at)), 0) AS avg_duration
     FROM traffic_sessions
     WHERE {$whereHumanSessions}",
    $humanSessionParams
);

$eventSummary = traffic_fetch_one(
    $pdo,
    "SELECT COUNT(*) AS total_events,
            COUNT(DISTINCT session_key) AS event_sessions
     FROM traffic_events
     WHERE {$whereHumanEvents}",
    $humanEventParams
);

$conversionSummary = traffic_fetch_one(
    $pdo,
    "SELECT COUNT(*) AS total_conversions
     FROM conversion_logs
     WHERE created_at >= ? AND created_at < ?",
    [$dateFrom, $dateTo]
);

$sourceRows = traffic_fetch_all($pdo, "SELECT source_type, COUNT(*) AS total FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY source_type ORDER BY total DESC, source_type ASC", $humanSessionParams);
$deviceRows = traffic_fetch_all($pdo, "SELECT device_type, COUNT(*) AS total FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY device_type ORDER BY total DESC, device_type ASC", $humanSessionParams);
$browserRows = traffic_fetch_all($pdo, "SELECT browser_name, COUNT(*) AS total FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY browser_name ORDER BY total DESC, browser_name ASC LIMIT 10", $humanSessionParams);
$osRows = traffic_fetch_all($pdo, "SELECT os_name, COUNT(*) AS total FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY os_name ORDER BY total DESC, os_name ASC LIMIT 10", $humanSessionParams);
$geoRows = traffic_fetch_all($pdo, "SELECT COALESCE(country_name, 'Chưa rõ') AS country_name, COALESCE(region_name, 'Chưa rõ') AS region_name, COALESCE(city_name, 'Chưa rõ') AS city_name, COUNT(*) AS total FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY country_name, region_name, city_name ORDER BY total DESC LIMIT 10", $humanSessionParams);
$ispRows = traffic_fetch_all($pdo, "SELECT COALESCE(isp_name, 'Chưa rõ') AS isp_name, COUNT(*) AS total FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY isp_name ORDER BY total DESC LIMIT 10", $humanSessionParams);
$landingRows = traffic_fetch_all($pdo, "SELECT landing_path, COUNT(*) AS total_sessions, COALESCE(SUM(pageviews_count), 0) AS total_pageviews FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY landing_path ORDER BY total_sessions DESC, landing_path ASC LIMIT 10", $humanSessionParams);
$recentRows = traffic_fetch_all($pdo, "SELECT started_at, last_activity_at, session_key, ip_address, source_type, source_name, source_host, landing_path, exit_path, device_type, browser_name, os_name, country_name, region_name, city_name, isp_name, pageviews_count, TIMESTAMPDIFF(SECOND, started_at, last_activity_at) AS session_duration FROM traffic_sessions WHERE {$whereHumanSessions} ORDER BY last_activity_at DESC, started_at DESC LIMIT 100", $humanSessionParams);

$eventRows = traffic_fetch_all($pdo, "SELECT event_name, COUNT(*) AS total FROM traffic_events WHERE {$whereHumanEvents} GROUP BY event_name ORDER BY total DESC, event_name ASC LIMIT 10", $humanEventParams);
$recentEventRows = traffic_fetch_all($pdo, "SELECT created_at, event_name, event_label, page_url, page_path, ip_address FROM traffic_events WHERE {$whereHumanEvents} ORDER BY created_at DESC LIMIT 15", $humanEventParams);
$conversionRows = traffic_fetch_all($pdo, "SELECT type, utm_source, utm_medium, utm_campaign, COUNT(*) AS total FROM conversion_logs WHERE created_at >= ? GROUP BY type, utm_source, utm_medium, utm_campaign ORDER BY total DESC, type ASC LIMIT 15", [$dateFrom]);
$recentConversionRows = traffic_fetch_all($pdo, "SELECT type, page_url, ip_address, source_type, source_name, utm_source, utm_medium, utm_campaign, created_at FROM conversion_logs WHERE created_at >= ? ORDER BY created_at DESC LIMIT 15", [$dateFrom]);
$funnelRows = traffic_fetch_all($pdo, "SELECT event_name, COUNT(DISTINCT session_key) AS total FROM traffic_events WHERE {$whereHumanEvents} AND event_name IN ('add_to_cart', 'begin_checkout', 'place_order', 'order_success', 'payment_success') GROUP BY event_name", $humanEventParams);
$funnelMap = [
    'visit' => (int) ($summary['sessions'] ?? 0),
    'add_to_cart' => 0,
    'begin_checkout' => 0,
    'place_order' => 0,
    'payment_success' => 0,
];
foreach ($funnelRows as $funnelRow) {
    $eventName = (string) ($funnelRow['event_name'] ?? '');
    if ($eventName === 'order_success') {
        $eventName = 'place_order';
    }
    if (isset($funnelMap[$eventName])) {
        $funnelMap[$eventName] += (int) ($funnelRow['total'] ?? 0);
    }
}
$funnelSteps = [
    ['key' => 'visit', 'label' => 'Visit', 'color' => '#2563eb'],
    ['key' => 'add_to_cart', 'label' => 'Add to cart', 'color' => '#0ea5e9'],
    ['key' => 'begin_checkout', 'label' => 'Checkout', 'color' => '#f59e0b'],
    ['key' => 'place_order', 'label' => 'Place order', 'color' => '#10b981'],
    ['key' => 'payment_success', 'label' => 'Payment success', 'color' => '#059669'],
];

$trendRows = traffic_fetch_all($pdo, "SELECT DATE(started_at) AS day, COUNT(*) AS total FROM traffic_sessions WHERE {$whereHumanSessions} GROUP BY DATE(started_at) ORDER BY day ASC", $humanSessionParams);
$daysMap = [];
$trendCursor = strtotime(date('Y-m-d 00:00:00', $dateFromTs));
$trendEnd = strtotime(date('Y-m-d 00:00:00', $dateToTs - 1));
while ($trendCursor <= $trendEnd) {
    $daysMap[date('Y-m-d', $trendCursor)] = 0;
    $trendCursor = strtotime('+1 day', $trendCursor);
}
foreach ($trendRows as $row) {
    if (isset($daysMap[$row['day']])) {
        $daysMap[$row['day']] = (int) $row['total'];
    }
}

$recentGroupedRows = [];
foreach ($recentRows as $row) {
    $groupKey = (string) ($row['ip_address'] ?: 'unknown');
    if (!isset($recentGroupedRows[$groupKey])) {
        $recentGroupedRows[$groupKey] = [
            'ip_address' => $row['ip_address'] ?: '-',
            'first_seen' => $row['started_at'],
            'last_seen' => $row['last_activity_at'] ?: $row['started_at'],
            'country_name' => $row['country_name'] ?: 'Chưa rõ',
            'region_name' => $row['region_name'] ?: 'Chưa rõ',
            'city_name' => $row['city_name'] ?: 'Chưa rõ',
            'isp_name' => $row['isp_name'] ?: 'Chưa rõ',
            'sessions' => 0,
            'pageviews' => 0,
            'duration' => 0,
            'sources' => [],
            'devices' => [],
            'paths' => [],
            'items' => [],
        ];
    }

    $recentGroupedRows[$groupKey]['sessions']++;
    $recentGroupedRows[$groupKey]['pageviews'] += (int) $row['pageviews_count'];
    $recentGroupedRows[$groupKey]['duration'] += (float) $row['session_duration'];
    $recentGroupedRows[$groupKey]['sources'][] = $row['source_type'] ?: 'unknown';
    $recentGroupedRows[$groupKey]['devices'][] = $row['device_type'] ?: 'unknown';
    $recentGroupedRows[$groupKey]['paths'][] = traffic_safe_page_label($row['landing_path'] ?? '', '');
    $recentGroupedRows[$groupKey]['items'][] = $row;

    $rowLastSeen = $row['last_activity_at'] ?: $row['started_at'];
    if (strtotime((string) $rowLastSeen) > strtotime((string) $recentGroupedRows[$groupKey]['last_seen'])) {
        $recentGroupedRows[$groupKey]['last_seen'] = $rowLastSeen;
    }
}

foreach ($recentGroupedRows as &$group) {
    usort($group['items'], static function ($a, $b) {
        $aTime = strtotime((string) ($a['last_activity_at'] ?? $a['started_at'] ?? ''));
        $bTime = strtotime((string) ($b['last_activity_at'] ?? $b['started_at'] ?? ''));
        return $bTime <=> $aTime;
    });
    $group['sources'] = array_values(array_unique($group['sources']));
    $group['devices'] = array_values(array_unique($group['devices']));
    $group['paths'] = array_values(array_unique($group['paths']));
}
unset($group);

uasort($recentGroupedRows, static function ($a, $b) {
    return strtotime((string) ($b['last_seen'] ?? '')) <=> strtotime((string) ($a['last_seen'] ?? ''));
});

$recentSessionKeys = [];
foreach ($recentRows as $row) {
    if (!empty($row['session_key'])) {
        $recentSessionKeys[] = $row['session_key'];
    }
}
$recentSessionKeys = array_values(array_unique($recentSessionKeys));
$pageviewsBySession = [];
if (!empty($recentSessionKeys)) {
    $placeholders = implode(',', array_fill(0, count($recentSessionKeys), '?'));
    $pageviewRows = traffic_fetch_all(
        $pdo,
        "SELECT session_key, page_path, page_url, page_title, created_at
         FROM traffic_pageviews
         WHERE session_key IN ({$placeholders})
         ORDER BY created_at ASC, id ASC",
        $recentSessionKeys
    );

    foreach ($pageviewRows as $pageviewRow) {
        $sessionKey = (string) ($pageviewRow['session_key'] ?? '');
        if ($sessionKey === '') {
            continue;
        }
        if (!isset($pageviewsBySession[$sessionKey])) {
            $pageviewsBySession[$sessionKey] = [];
        }
        $pageviewsBySession[$sessionKey][] = $pageviewRow;
    }
}

$blockedIpRows = traffic_fetch_all($pdo, "SELECT ip_address, block_reason, blocked_by, attempts_count, last_attempt_at, created_at, updated_at FROM traffic_blocked_ips ORDER BY updated_at DESC, created_at DESC");
$blockedIpMap = [];
foreach ($blockedIpRows as $blockedIpRow) {
    $blockedIpMap[(string) ($blockedIpRow['ip_address'] ?? '')] = $blockedIpRow;
}

function traffic_split_pageviews_by_gap(array $pageviews, int $gapSeconds = 600): array
{
    $chunks = [];
    $current = [];
    $lastTs = null;

    foreach ($pageviews as $pageview) {
        $ts = strtotime((string) ($pageview['created_at'] ?? ''));
        if ($lastTs !== null && $ts > 0 && ($ts - $lastTs) > $gapSeconds && !empty($current)) {
            $chunks[] = $current;
            $current = [];
        }
        $current[] = $pageview;
        if ($ts > 0) {
            $lastTs = $ts;
        }
    }

    if (!empty($current)) {
        $chunks[] = $current;
    }

    return $chunks;
}

function traffic_compact_timeline(array $pageviews, int $maxSteps = 10): array
{
    $steps = [];
    $lastPath = null;
    $lastIndex = -1;

    foreach ($pageviews as $index => $pageview) {
        $path = traffic_safe_page_label($pageview['page_path'] ?? '', $pageview['page_url'] ?? '');
        if ($path === $lastPath && $lastIndex >= 0) {
            $steps[$lastIndex]['count']++;
            $steps[$lastIndex]['is_exit'] = false;
        } else {
            $steps[] = [
                'path' => $path,
                'count' => 1,
                'is_landing' => false,
                'is_exit' => false,
            ];
            $lastIndex = count($steps) - 1;
            $lastPath = $path;
        }
    }

    if (!empty($steps)) {
        $steps[0]['is_landing'] = true;
        $steps[count($steps) - 1]['is_exit'] = true;
    }

    if (count($steps) <= $maxSteps) {
        return ['steps' => $steps, 'hidden' => 0];
    }

    $headCount = 5;
    $tailCount = 3;
    $hidden = count($steps) - ($headCount + $tailCount);
    $compact = array_merge(
        array_slice($steps, 0, $headCount),
        [[
            'path' => '... +' . $hidden . ' bước',
            'count' => 0,
            'is_landing' => false,
            'is_exit' => false,
            'is_more' => true,
        ]],
        array_slice($steps, -$tailCount)
    );

    return ['steps' => $compact, 'hidden' => $hidden];
}

function traffic_paginate_array(array $items, int $perPage, int $currentPage): array
{
    $totalItems = count($items);
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = max(1, min($totalPages, $currentPage));

    return [
        'items' => array_slice($items, ($currentPage - 1) * $perPage, $perPage),
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'total_items' => $totalItems,
        'per_page' => $perPage,
    ];
}

function traffic_render_pagination(array $pagination, array $baseQuery, string $pageParam, string $anchor = ''): void
{
    if (($pagination['total_pages'] ?? 1) <= 1) {
        return;
    }

    $currentPage = (int) ($pagination['current_page'] ?? 1);
    $totalPages = (int) ($pagination['total_pages'] ?? 1);
    echo '<div class="traffic-pagination">';

    for ($page = 1; $page <= $totalPages; $page++) {
        $query = $baseQuery;
        $query[$pageParam] = $page;
        $class = $page === $currentPage ? 'active' : '';
        echo '<a class="' . $class . '" href="index.php?' . htmlspecialchars(http_build_query($query)) . $anchor . '">' . $page . '</a>';
    }

    echo '</div>';
}

$sourceOptions = traffic_fetch_all($pdo, "SELECT DISTINCT source_type FROM traffic_sessions WHERE source_type IS NOT NULL AND source_type <> '' AND is_bot = 0 ORDER BY source_type ASC");
$deviceOptions = traffic_fetch_all($pdo, "SELECT DISTINCT device_type FROM traffic_sessions WHERE device_type IS NOT NULL AND device_type <> '' AND is_bot = 0 ORDER BY device_type ASC");
$utmCampaignOptions = traffic_fetch_all($pdo, "SELECT DISTINCT utm_campaign FROM traffic_sessions WHERE utm_campaign IS NOT NULL AND utm_campaign <> '' AND is_bot = 0 ORDER BY utm_campaign ASC");
$campaignRows = traffic_fetch_all($pdo, "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) AS total_sessions, COALESCE(SUM(pageviews_count), 0) AS total_pageviews FROM traffic_sessions WHERE {$whereHumanSessions} AND (utm_source <> '' OR utm_medium <> '' OR utm_campaign <> '') GROUP BY utm_source, utm_medium, utm_campaign ORDER BY total_sessions DESC, total_pageviews DESC LIMIT 15", $humanSessionParams);
$botSummary = traffic_fetch_one($pdo, "SELECT COUNT(*) AS bot_sessions, COUNT(DISTINCT ip_address) AS bot_ips FROM traffic_sessions WHERE {$whereBotSessions}", $botSessionParams);
$botRows = traffic_fetch_all($pdo, "SELECT ip_address, source_name, source_host, browser_name, os_name, isp_name, COUNT(*) AS total_sessions, MIN(started_at) AS first_seen, MAX(last_activity_at) AS last_seen FROM traffic_sessions WHERE {$whereBotSessions} GROUP BY ip_address, source_name, source_host, browser_name, os_name, isp_name ORDER BY total_sessions DESC, last_seen DESC LIMIT 20", $botSessionParams);
$activeFilters = [];
if ($sourceType !== '') { $activeFilters[] = 'Nguồn: ' . $sourceType; }
if ($deviceType !== '') { $activeFilters[] = 'Thiết bị: ' . $deviceType; }
if ($utmCampaign !== '') { $activeFilters[] = 'Campaign: ' . $utmCampaign; }
if ($pathFilter !== '') { $activeFilters[] = 'Đường dẫn: ' . $pathFilter; }
if ($ipFilter !== '') { $activeFilters[] = 'IP: ' . $ipFilter; }
$activeFilters[] = 'Khoảng ngày: ' . $periodOptions[$period]['label'];
$trafficFlash = $_SESSION['traffic_flash'] ?? null;
unset($_SESSION['traffic_flash']);
$trafficFilterBase = [
    'period' => $period,
    'days' => $days,
    'source_type' => $sourceType,
    'device_type' => $deviceType,
    'utm_campaign' => $utmCampaign,
    'path' => $pathFilter,
    'ip' => $ipFilter,
];
$recentTrafficQuery = $trafficFilterBase;
$conversionQuery = $trafficFilterBase;
$botQuery = $trafficFilterBase;
$recentTrafficPage = max(1, (int) ($_GET['recent_page'] ?? 1));
$conversionPage = max(1, (int) ($_GET['conversion_page'] ?? 1));
$botPage = max(1, (int) ($_GET['bot_page'] ?? 1));
$recentTrafficPagination = traffic_paginate_array(array_values($recentGroupedRows), 10, $recentTrafficPage);
$recentGroupedRows = $recentTrafficPagination['items'];
$recentConversionPagination = traffic_paginate_array($recentConversionRows, 10, $conversionPage);
$recentConversionRows = $recentConversionPagination['items'];
$botPagination = traffic_paginate_array($botRows, 10, $botPage);
$botRows = $botPagination['items'];
$pageviewsPerSession = (int) ($summary['sessions'] ?? 0) > 0 ? round(((int) ($summary['pageviews'] ?? 0)) / max(1, (int) ($summary['sessions'] ?? 0)), 1) : 0;
?>
<style>
.traffic-card,.traffic-table-card,.traffic-filter-card{border:1px solid #e5e7eb;border-radius:18px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.05)}
.traffic-card{padding:12px 14px;min-height:88px;height:100%}.traffic-card-label{color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}.traffic-card-value{color:#111827;font-size:22px;font-weight:700;line-height:1}.traffic-card-note,.traffic-small{color:#64748b;font-size:12px}.traffic-chart-wrap{min-height:150px;position:relative}.traffic-chart-card{padding:12px !important}.traffic-chart-card h5{margin-bottom:8px !important;font-size:16px}.traffic-table-card .table{margin-bottom:0}.badge-soft{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:600}
.badge-soft.badge-danger-soft{background:#fee2e2;color:#b91c1c}
.traffic-ip-form{display:flex;gap:12px;flex-wrap:wrap;align-items:end}
.traffic-ip-form .form-group{flex:1 1 220px}
.traffic-inline-form{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap}
.traffic-inline-form input[type="text"]{min-width:180px}
.traffic-block-reason{min-width:220px}
.traffic-summary-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
.traffic-filter-top{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.traffic-filter-chip{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:13px;font-weight:600;text-decoration:none}
.traffic-filter-chip.active{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}
.traffic-section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}
.traffic-section-meta{color:#64748b;font-size:12px}
.traffic-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.traffic-tab-btn{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:9px 16px;font-size:13px;font-weight:700}
.traffic-tab-btn.active{background:#0f172a;border-color:#0f172a;color:#fff}
.traffic-tab-pane{display:none}
.traffic-tab-pane.active{display:block}
.traffic-collapsible{border:1px solid #e5e7eb;border-radius:18px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.05);margin-bottom:16px}
.traffic-collapsible > summary{list-style:none;cursor:pointer;padding:16px 20px;font-weight:700;color:#0f172a;display:flex;align-items:center;justify-content:space-between}
.traffic-collapsible > summary::-webkit-details-marker{display:none}
.traffic-collapsible > summary::after{content:'+';font-size:18px;color:#64748b}
.traffic-collapsible[open] > summary::after{content:'-'}
.traffic-collapsible-body{padding:0 16px 16px}
.traffic-pagination{display:flex;gap:8px;justify-content:flex-end;align-items:center;padding-top:14px}
.traffic-pagination a{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 10px;border-radius:10px;border:1px solid #dbe2ea;background:#fff;color:#334155;text-decoration:none;font-weight:600}
.traffic-pagination a.active{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
.traffic-parent-row{cursor:pointer}
.traffic-parent-row:hover{background:#f8fafc}
.traffic-detail-wrap{background:#f8fafc;border-top:1px solid #e5e7eb}
.traffic-toggle-hint{display:inline-flex;align-items:center;gap:8px;color:#2563eb;font-size:12px;font-weight:600}
.traffic-toggle-icon{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:13px;font-weight:700;transition:transform .2s ease}
.traffic-parent-row[aria-expanded="true"] .traffic-toggle-icon{transform:rotate(90deg)}
.traffic-timeline{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.traffic-timeline-step{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:linear-gradient(135deg,#eef2ff 0%,#dbeafe 100%);color:#3730a3;font-size:12px;font-weight:600;box-shadow:inset 0 0 0 1px rgba(59,130,246,.08)}
.traffic-timeline-arrow{color:#94a3b8;font-size:12px}
.traffic-mini-badge{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.traffic-mini-badge.landing{background:#dcfce7;color:#166534}
.traffic-mini-badge.exit{background:#fee2e2;color:#991b1b}
.traffic-mini-badge.repeat{background:#e2e8f0;color:#334155}
.traffic-funnel{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.traffic-funnel-card{position:relative;padding:18px;border-radius:16px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border:1px solid #e5e7eb;overflow:hidden}
.traffic-funnel-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--funnel-color,#2563eb)}
.traffic-funnel-step{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:8px}
.traffic-funnel-value{font-size:30px;font-weight:800;color:#0f172a;line-height:1}
.traffic-funnel-rate{margin-top:8px;font-size:12px;color:#475569}
.traffic-funnel-bar{margin-top:12px;height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden}
.traffic-funnel-fill{height:100%;border-radius:999px;background:var(--funnel-color,#2563eb)}
.traffic-hitstat-shell{border:1px solid #e2e8f0;border-radius:18px;background:linear-gradient(180deg,#fff 0%,#f8fafc 100%);box-shadow:0 14px 34px rgba(15,23,42,.06)}
.traffic-visit-block{border-top:1px dashed #dbe2ea;padding-top:14px;margin-top:14px}
 .traffic-visit-block:first-child{border-top:0;padding-top:0;margin-top:0}
@media (max-width: 1199px){.traffic-summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width: 991px){.traffic-funnel{grid-template-columns:1fr 1fr}.traffic-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width: 575px){.traffic-funnel{grid-template-columns:1fr}.traffic-summary-grid{grid-template-columns:1fr}}
</style>
<div class="content-wrapper">
<div class="page-header"><div><h1 class="page-title">Lưu lượng truy cập</h1><p class="page-subtitle">Theo dõi nguồn truy cập, thiết bị, vị trí, ISP và hành vi của khách truy cập.</p></div></div>
<?php if (!empty($trafficFlash) && is_array($trafficFlash)): ?>
<div class="alert alert-<?php echo htmlspecialchars($trafficFlash['type'] ?? 'info'); ?> border-0 shadow-sm mb-4"><?php echo htmlspecialchars($trafficFlash['message'] ?? ''); ?></div>
<?php endif; ?>
<div class="card border-0 mb-4 traffic-filter-card"><div class="card-body p-4"><form method="GET" class="row g-3 align-items-end">
<div class="col-12"><div class="traffic-filter-top"><?php foreach ($periodOptions as $periodKey => $periodData): $periodQuery = $trafficFilterBase; $periodQuery['period'] = $periodKey; if ($periodKey !== 'custom') { unset($periodQuery['days']); } ?><a class="traffic-filter-chip <?php echo $period === $periodKey ? 'active' : ''; ?>" href="index.php?<?php echo htmlspecialchars(http_build_query($periodQuery)); ?>"><?php echo htmlspecialchars($periodData['label']); ?></a><?php endforeach; ?></div></div>
<input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
<div class="col-md-2"><label class="form-label">Số ngày</label><select name="days" class="form-select" <?php echo $period !== 'custom' ? 'disabled' : ''; ?>><?php foreach ([3,7,14,30,60,90] as $option): ?><option value="<?php echo $option; ?>" <?php echo $days === $option ? 'selected' : ''; ?>><?php echo $option; ?> ngày</option><?php endforeach; ?></select><?php if ($period !== 'custom'): ?><input type="hidden" name="days" value="<?php echo $days; ?>"><?php endif; ?></div>
<div class="col-md-2"><label class="form-label">Nguồn</label><select name="source_type" class="form-select"><option value="">Tất cả</option><?php foreach ($sourceOptions as $option): ?><option value="<?php echo htmlspecialchars($option['source_type']); ?>" <?php echo $sourceType === $option['source_type'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['source_type']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Thiết bị</label><select name="device_type" class="form-select"><option value="">Tất cả</option><?php foreach ($deviceOptions as $option): ?><option value="<?php echo htmlspecialchars($option['device_type']); ?>" <?php echo $deviceType === $option['device_type'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['device_type']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Campaign</label><select name="utm_campaign" class="form-select"><option value="">Tất cả</option><?php foreach ($utmCampaignOptions as $option): $cleanCampaignOption = traffic_clean_tracking_value($option['utm_campaign'] ?? '', 150); ?><option value="<?php echo e($cleanCampaignOption); ?>" <?php echo $utmCampaign === $cleanCampaignOption ? 'selected' : ''; ?>><?php echo e($cleanCampaignOption); ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Đường dẫn</label><input type="text" name="path" class="form-control" value="<?php echo htmlspecialchars($pathFilter); ?>" placeholder="/san-pham"></div>
<div class="col-md-2"><label class="form-label">IP</label><input type="text" name="ip" class="form-control" value="<?php echo htmlspecialchars($ipFilter); ?>" placeholder="123.45"></div>
<div class="col-md-2 d-grid"><button type="submit" class="btn btn-primary">Áp dụng</button></div>
<div class="col-12 d-flex gap-2 flex-wrap"><a href="index.php" class="btn btn-outline-secondary btn-sm">Đặt lại bộ lọc</a><a href="export.php?type=sessions&period=<?php echo urlencode($period); ?>&days=<?php echo urlencode((string) $days); ?>&source_type=<?php echo urlencode($sourceType); ?>&device_type=<?php echo urlencode($deviceType); ?>&utm_campaign=<?php echo urlencode($utmCampaign); ?>&path=<?php echo urlencode($pathFilter); ?>&ip=<?php echo urlencode($ipFilter); ?>" class="btn btn-outline-primary btn-sm">Export visitor CSV</a><a href="export.php?type=bots&period=<?php echo urlencode($period); ?>&days=<?php echo urlencode((string) $days); ?>&source_type=<?php echo urlencode($sourceType); ?>&device_type=<?php echo urlencode($deviceType); ?>&utm_campaign=<?php echo urlencode($utmCampaign); ?>&path=<?php echo urlencode($pathFilter); ?>&ip=<?php echo urlencode($ipFilter); ?>" class="btn btn-outline-primary btn-sm">Export bot CSV</a><a href="export.php?type=conversions&period=<?php echo urlencode($period); ?>&days=<?php echo urlencode((string) $days); ?>&source_type=<?php echo urlencode($sourceType); ?>&device_type=<?php echo urlencode($deviceType); ?>&utm_campaign=<?php echo urlencode($utmCampaign); ?>&path=<?php echo urlencode($pathFilter); ?>&ip=<?php echo urlencode($ipFilter); ?>" class="btn btn-outline-primary btn-sm">Export conversion CSV</a></div>
</form></div></div>
<?php if (!empty($activeFilters)): ?>
<div class="alert alert-info border-0 shadow-sm mb-4">Đang lọc theo: <?php echo htmlspecialchars(implode(' | ', $activeFilters)); ?></div>
<?php endif; ?>
<div class="traffic-table-card p-4 mb-4">
<div class="traffic-section-head"><div><h5 class="mb-0">Chặn IP truy cập website</h5><div class="traffic-small mt-2">IP bị chặn sẽ không truy cập được trang ngoài website và các API public liên quan.</div></div><div class="traffic-section-meta"><?php echo number_format(count($blockedIpRows)); ?> IP đang bị chặn</div></div>
<form method="POST" class="traffic-ip-form mb-3">
<input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
<input type="hidden" name="traffic_action" value="block_ip">
<div class="form-group"><label class="form-label">IP cần chặn</label><input type="text" name="ip_address" class="form-control" placeholder="Ví dụ: 123.123.123.123" required></div>
<div class="form-group"><label class="form-label">Ghi chú</label><input type="text" name="block_reason" class="form-control" placeholder="Lý do chặn, ví dụ spam hoặc quét bot"></div>
<div><button type="submit" class="btn btn-danger">Chặn IP</button></div>
</form>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>IP</th><th>Ghi chú</th><th>Người chặn</th><th class="text-end">Lượt chặn</th><th>Lần gần nhất</th><th>Thời gian</th><th class="text-end">Thao tác</th></tr></thead><tbody><?php if ($blockedIpRows): foreach ($blockedIpRows as $blockedRow): ?><tr><td><span class="badge-soft badge-danger-soft"><?php echo htmlspecialchars($blockedRow['ip_address']); ?></span></td><td><?php echo htmlspecialchars($blockedRow['block_reason'] ?: '-'); ?></td><td><?php echo htmlspecialchars($blockedRow['blocked_by'] ?: '-'); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) ($blockedRow['attempts_count'] ?? 0)); ?></td><td><?php echo htmlspecialchars($blockedRow['last_attempt_at'] ?: '-'); ?></td><td><?php echo htmlspecialchars($blockedRow['created_at']); ?></td><td class="text-end"><form method="POST" class="traffic-inline-form justify-content-end"><input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>"><input type="hidden" name="traffic_action" value="unblock_ip"><input type="hidden" name="ip_address" value="<?php echo htmlspecialchars($blockedRow['ip_address']); ?>"><button type="submit" class="btn btn-outline-secondary btn-sm">Bỏ chặn</button></form></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa có IP nào bị chặn.</td></tr><?php endif; ?></tbody></table></div>
</div>
<div class="traffic-summary-grid mb-4">
<div class="traffic-card"><div class="traffic-card-label">Sessions</div><div class="traffic-card-value"><?php echo number_format((int) ($summary['sessions'] ?? 0)); ?></div><div class="traffic-card-note">Visitors <?php echo number_format((int) ($summary['visitors'] ?? 0)); ?> | Khách mới <?php echo number_format((int) ($summary['new_visitors'] ?? 0)); ?></div></div>
<div class="traffic-card"><div class="traffic-card-label">Pageviews</div><div class="traffic-card-value"><?php echo number_format((int) ($summary['pageviews'] ?? 0)); ?></div><div class="traffic-card-note"><?php echo number_format((float) $pageviewsPerSession, 1); ?> trang / session</div></div>
<div class="traffic-card"><div class="traffic-card-label">Thời lượng TB</div><div class="traffic-card-value"><?php echo number_format((float) ($summary['avg_duration'] ?? 0), 1); ?>s</div><div class="traffic-card-note">Session có event <?php echo number_format((int) ($eventSummary['event_sessions'] ?? 0)); ?></div></div>
<div class="traffic-card"><div class="traffic-card-label">Events</div><div class="traffic-card-value"><?php echo number_format((int) ($eventSummary['total_events'] ?? 0)); ?></div><div class="traffic-card-note">Phát sinh trong khoảng lọc hiện tại</div></div>
<div class="traffic-card"><div class="traffic-card-label">Conversions</div><div class="traffic-card-value"><?php echo number_format((int) ($conversionSummary['total_conversions'] ?? 0)); ?></div><div class="traffic-card-note">Tổng chuyển đổi trong khoảng ngày</div></div>
<div class="traffic-card"><div class="traffic-card-label">Bots</div><div class="traffic-card-value"><?php echo number_format((int) ($botSummary['bot_sessions'] ?? 0)); ?></div><div class="traffic-card-note">IP bot <?php echo number_format((int) ($botSummary['bot_ips'] ?? 0)); ?></div></div>
</div>
<div class="row g-2 mb-3"><div class="col-lg-8"><div class="traffic-table-card traffic-chart-card h-100"><h5 class="mb-3">Xu hướng sessions</h5><div class="traffic-chart-wrap"><canvas id="trafficTrendChart"></canvas></div></div></div><div class="col-lg-4"><div class="traffic-table-card traffic-chart-card h-100"><h5 class="mb-3">Thiết bị</h5><div class="traffic-chart-wrap"><canvas id="trafficDeviceChart"></canvas></div></div></div></div>
<div class="row g-2 mb-3">
<div class="col-lg-4"><div class="traffic-table-card traffic-chart-card h-100"><h5 class="mb-3">Người dùng: Mới vs Quay lại</h5><div class="traffic-chart-wrap"><canvas id="trafficVisitorChart"></canvas></div></div></div>
<div class="col-lg-8"><div class="traffic-table-card p-4 h-100">
<h5 class="mb-3">Thống kê người dùng</h5>
<div class="traffic-summary-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));gap:12px">
<div class="traffic-card" style="background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);border-color:#bfdbfe">
<div class="traffic-card-label">Tổng Visitors</div>
<div class="traffic-card-value" style="color:#1d4ed8"><?php echo number_format((int) ($summary['visitors'] ?? 0)); ?></div>
<div class="traffic-card-note">Unique visitors trong khoảng lọc</div>
</div>
<div class="traffic-card" style="background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%);border-color:#bbf7d0">
<div class="traffic-card-label">Khách mới</div>
<div class="traffic-card-value" style="color:#15803d"><?php echo number_format((int) ($summary['new_visitors'] ?? 0)); ?></div>
<div class="traffic-card-note"><?php $totalVisitors = max(1, (int) ($summary['visitors'] ?? 0)); $newPct = round(((int)($summary['new_visitors']??0))/$totalVisitors*100,1); echo $newPct; ?>% tổng visitors</div>
</div>
<div class="traffic-card" style="background:linear-gradient(135deg,#fefce8 0%,#fef9c3 100%);border-color:#fde68a">
<div class="traffic-card-label">Quay lại</div>
<div class="traffic-card-value" style="color:#b45309"><?php $returning = max(0, (int)($summary['visitors']??0) - (int)($summary['new_visitors']??0)); echo number_format($returning); ?></div>
<div class="traffic-card-note"><?php echo round($returning/max(1,(int)($summary['visitors']??0))*100,1); ?>% tổng visitors</div>
</div>
</div>
<div class="mt-3">
<table class="table table-sm align-middle mb-0">
<thead><tr><th>Chỉ số</th><th class="text-end">Giá trị</th><th class="text-end">Tỉ lệ</th></tr></thead>
<tbody>
<tr><td class="fw-semibold">Sessions / Visitor</td><td class="text-end"><?php echo number_format((int)($summary['visitors']??0)>0 ? round((int)($summary['sessions']??0)/max(1,(int)($summary['visitors']??0)),2) : 0, 2); ?></td><td class="text-end text-muted">—</td></tr>
<tr><td class="fw-semibold">Pageviews / Visitor</td><td class="text-end"><?php echo number_format((int)($summary['visitors']??0)>0 ? round((int)($summary['pageviews']??0)/max(1,(int)($summary['visitors']??0)),1) : 0, 1); ?></td><td class="text-end text-muted">—</td></tr>
<tr><td class="fw-semibold">Thời lượng TB / Session</td><td class="text-end"><?php echo number_format((float)($summary['avg_duration']??0),1); ?>s</td><td class="text-end text-muted">—</td></tr>
<tr><td class="fw-semibold">Tỉ lệ bouncing (1 trang)</td><td class="text-end"><?php
$bounceCount = traffic_fetch_one($pdo, "SELECT COUNT(*) AS c FROM traffic_sessions WHERE {$whereHumanSessions} AND pageviews_count <= 1", $humanSessionParams);
$bounceRate = (int)($summary['sessions']??0) > 0 ? round(((int)($bounceCount['c']??0))/(int)($summary['sessions']??0)*100,1) : 0;
echo $bounceRate; ?>%</td><td class="text-end text-muted">—</td></tr>
</tbody>
</table>
</div>
</div></div>
</div>
<details class="traffic-collapsible">
<summary>Chuyển đổi và campaign</summary>
<div class="traffic-collapsible-body">
<div class="traffic-table-card p-4 mb-4"><div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Funnel chuyển đổi</h5><div class="traffic-small">Theo session người thật trong bộ lọc hiện tại</div></div><div class="traffic-funnel"><?php $previousValue = max(1, $funnelMap['visit']); foreach ($funnelSteps as $step): $value = (int) ($funnelMap[$step['key']] ?? 0); $baseValue = max(1, $funnelMap['visit']); $rateAll = $baseValue > 0 ? round(($value / $baseValue) * 100, 1) : 0; $ratePrev = $step['key'] === 'visit' ? 100 : round(($value / max(1, $previousValue)) * 100, 1); ?><div class="traffic-funnel-card" style="--funnel-color: <?php echo htmlspecialchars($step['color']); ?>"><div class="traffic-funnel-step"><?php echo htmlspecialchars($step['label']); ?></div><div class="traffic-funnel-value"><?php echo number_format($value); ?></div><div class="traffic-funnel-rate"><?php echo $step['key'] === 'visit' ? '100% gốc' : $ratePrev . '% so với bước trước'; ?> | <?php echo $rateAll; ?>% so với Visit</div><div class="traffic-funnel-bar"><div class="traffic-funnel-fill" style="width: <?php echo max(4, min(100, $rateAll)); ?>%"></div></div></div><?php $previousValue = max(1, $value); endforeach; ?></div></div>
<div class="traffic-table-card p-4 mb-4"><h5 class="mb-3">Campaign / Source / Medium</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>UTM Source</th><th>UTM Medium</th><th>UTM Campaign</th><th class="text-end">Sessions</th><th class="text-end">Pageviews</th></tr></thead><tbody><?php if ($campaignRows): foreach ($campaignRows as $row): ?><tr><td><?php echo e(traffic_clean_tracking_value($row['utm_source'] ?? '', 150) ?: '-'); ?></td><td><?php echo e(traffic_clean_tracking_value($row['utm_medium'] ?? '', 150) ?: '-'); ?></td><td><?php echo e(traffic_clean_tracking_value($row['utm_campaign'] ?? '', 150) ?: '-'); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total_sessions']); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total_pageviews']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">Chưa có dữ liệu campaign.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="traffic-table-card p-4 mb-4"><h5 class="mb-3">Campaign chuyển đổi</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Loại chuyển đổi</th><th>UTM Source</th><th>UTM Medium</th><th>UTM Campaign</th><th class="text-end">Số lần</th></tr></thead><tbody><?php if ($conversionRows): foreach ($conversionRows as $row): ?><tr><td><?php echo e($row['type']); ?></td><td><?php echo e(traffic_clean_tracking_value($row['utm_source'] ?? '', 150) ?: '-'); ?></td><td><?php echo e(traffic_clean_tracking_value($row['utm_medium'] ?? '', 150) ?: '-'); ?></td><td><?php echo e(traffic_clean_tracking_value($row['utm_campaign'] ?? '', 150) ?: '-'); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">Chưa có dữ liệu chuyển đổi gắn campaign.</td></tr><?php endif; ?></tbody></table></div></div>
</div>
</details>
<details class="traffic-collapsible">
<summary>Phân tích thêm</summary>
<div class="traffic-collapsible-body">
<div class="row g-4 mb-4"><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Nguồn truy cập</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nguồn</th><th class="text-end">Sessions</th></tr></thead><tbody><?php if ($sourceRows): foreach ($sourceRows as $row): ?><tr><td><span class="badge-soft"><?php echo htmlspecialchars($row['source_type'] ?: 'unknown'); ?></span></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="2" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr><?php endif; ?></tbody></table></div></div></div><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Top sự kiện</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Event</th><th class="text-end">Số lần</th></tr></thead><tbody><?php if ($eventRows): foreach ($eventRows as $row): ?><tr><td><?php echo htmlspecialchars($row['event_name']); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="2" class="text-center text-muted py-4">Chưa có event.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
<div class="row g-4 mb-4"><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Trình duyệt</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Browser</th><th class="text-end">Sessions</th></tr></thead><tbody><?php if ($browserRows): foreach ($browserRows as $row): ?><tr><td><?php echo htmlspecialchars($row['browser_name'] ?: 'Chưa rõ'); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="2" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr><?php endif; ?></tbody></table></div></div></div><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Hệ điều hành</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Hệ điều hành</th><th class="text-end">Sessions</th></tr></thead><tbody><?php if ($osRows): foreach ($osRows as $row): ?><tr><td><?php echo htmlspecialchars($row['os_name'] ?: 'Chưa rõ'); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="2" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
<div class="row g-4 mb-4"><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Top landing pages</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Đường dẫn</th><th class="text-end">Sessions</th><th class="text-end">Pageviews</th></tr></thead><tbody><?php if ($landingRows): foreach ($landingRows as $row): ?><tr><td><?php echo e(traffic_safe_page_label($row['landing_path'] ?? '', '')); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total_sessions']); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total_pageviews']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr><?php endif; ?></tbody></table></div></div></div><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Vị trí truy cập</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Quốc gia / Khu vực / Thành phố</th><th class="text-end">Sessions</th></tr></thead><tbody><?php if ($geoRows): foreach ($geoRows as $row): ?><tr><td><?php echo htmlspecialchars($row['country_name'] . ' / ' . $row['region_name'] . ' / ' . $row['city_name']); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="2" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
<div class="row g-4 mb-4"><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Nhà mạng / ISP</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>ISP</th><th class="text-end">Sessions</th></tr></thead><tbody><?php if ($ispRows): foreach ($ispRows as $row): ?><tr><td><?php echo htmlspecialchars($row['isp_name']); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="2" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr><?php endif; ?></tbody></table></div></div></div><div class="col-lg-6"><div class="traffic-table-card p-4 h-100"><h5 class="mb-3">Sự kiện gần đây</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Event</th><th>Trang</th><th>IP</th><th>Thời gian</th></tr></thead><tbody><?php if ($recentEventRows): foreach ($recentEventRows as $row): ?><tr><td><div class="fw-semibold"><?php echo htmlspecialchars($row['event_name']); ?></div><?php if (!empty($row['event_label'])): ?><div class="traffic-small"><?php echo htmlspecialchars($row['event_label']); ?></div><?php endif; ?></td><td class="traffic-small"><?php echo htmlspecialchars($row['page_path'] ?: $row['page_url'] ?: '-'); ?></td><td><?php echo htmlspecialchars($row['ip_address'] ?: '-'); ?></td><td><?php echo htmlspecialchars($row['created_at']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="4" class="text-center text-muted py-4">Chưa có event.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
<div class="traffic-table-card p-4 mb-4"><div class="traffic-section-head"><h5 class="mb-0">Chuyển đổi gần đây</h5><div class="traffic-section-meta"><?php echo number_format((int) ($recentConversionPagination['total_items'] ?? 0)); ?> mục</div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Loại</th><th>Nguồn / Campaign</th><th>Trang</th><th>IP / Hành trình</th><th>Thời gian</th></tr></thead><tbody><?php if ($recentConversionRows): foreach ($recentConversionRows as $row): $journeyFilters = $trafficFilterBase; $journeyFilters['ip'] = $row['ip_address'] ?: $ipFilter; ?><tr><td><div class="fw-semibold"><?php echo e($row['type']); ?></div><div class="traffic-small"><?php echo e($row['source_type'] ?: '-'); ?></div></td><td><div><?php echo e(traffic_clean_tracking_value($row['source_name'] ?? '', 150) ?: '-'); ?></div><div class="traffic-small"><?php echo e(trim((traffic_clean_tracking_value($row['utm_source'] ?? '', 150) ?: '-') . ' / ' . (traffic_clean_tracking_value($row['utm_medium'] ?? '', 150) ?: '-') . ' / ' . (traffic_clean_tracking_value($row['utm_campaign'] ?? '', 150) ?: '-'))); ?></div></td><td class="traffic-small"><?php echo e($row['page_url'] ?: '-'); ?></td><td><div class="fw-semibold"><?php echo e($row['ip_address'] ?: '-'); ?></div><?php if (!empty($row['ip_address'])): ?><div class="traffic-small"><a href="index.php?<?php echo e(http_build_query($journeyFilters)); ?>#recent-traffic">Xem hành trình IP này</a></div><?php endif; ?></td><td><?php echo e($row['created_at']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">Chưa có chuyển đổi nào gần đây.</td></tr><?php endif; ?></tbody></table></div><?php traffic_render_pagination($recentConversionPagination, $conversionQuery, 'conversion_page'); ?></div>
</div>
</details>
<details class="traffic-collapsible">
<summary>Bot</summary>
<div class="traffic-collapsible-body">
<div class="traffic-table-card p-4 mb-4"><div class="traffic-section-head"><h5 class="mb-0">Bot lớn và dải IP quan sát được</h5><div class="traffic-section-meta"><?php echo number_format((int) ($botPagination['total_items'] ?? 0)); ?> mục</div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>IP bot</th><th>Bot / nguồn</th><th>Browser / OS</th><th>ISP</th><th class="text-end">Sessions</th><th>First seen</th><th>Last seen</th></tr></thead><tbody><?php if ($botRows): foreach ($botRows as $row): ?><tr><td><span class="badge-soft"><?php echo htmlspecialchars($row['ip_address'] ?: '-'); ?></span></td><td><div class="fw-semibold"><?php echo htmlspecialchars($row['source_name'] ?: $row['source_host'] ?: 'Bot'); ?></div><div class="traffic-small"><?php echo htmlspecialchars($row['source_host'] ?: '-'); ?></div></td><td><div><?php echo htmlspecialchars($row['browser_name'] ?: 'Chưa rõ'); ?></div><div class="traffic-small"><?php echo htmlspecialchars($row['os_name'] ?: 'Chưa rõ'); ?></div></td><td><?php echo htmlspecialchars($row['isp_name'] ?: 'Chưa rõ'); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $row['total_sessions']); ?></td><td><?php echo htmlspecialchars($row['first_seen']); ?></td><td><?php echo htmlspecialchars($row['last_seen']); ?></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa ghi nhận bot nào trong bộ lọc hiện tại.</td></tr><?php endif; ?></tbody></table></div><?php traffic_render_pagination($botPagination, $botQuery, 'bot_page'); ?></div>
</div>
</details>
<details class="traffic-collapsible" open>
<summary>Hành trình truy cập</summary>
<div class="traffic-collapsible-body">
<div class="traffic-table-card traffic-hitstat-shell p-4 mb-4" id="recent-traffic"><div class="traffic-section-head"><div><h5 class="mb-0">Phiên truy cập gần đây</h5><div class="traffic-toggle-hint mt-2"><span class="traffic-toggle-icon">></span><span>Nhấn vào từng dòng IP để mở toàn bộ session và các trang đã truy cập</span></div></div><div class="traffic-section-meta"><?php echo number_format((int) ($recentTrafficPagination['total_items'] ?? 0)); ?> IP</div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>IP</th><th>Thời gian gần nhất</th><th>Nguồn</th><th>Thiết bị</th><th>Vị trí / ISP</th><th>Trang gần đây</th><th class="text-end">Sessions</th><th class="text-end">PV</th><th class="text-end">Duration</th><th class="text-end">Thao tác</th></tr></thead><tbody><?php if ($recentGroupedRows): $groupIndex = (($recentTrafficPagination['current_page'] ?? 1) - 1) * ((int) ($recentTrafficPagination['per_page'] ?? 10)); foreach ($recentGroupedRows as $group): $collapseId = 'traffic-ip-' . $groupIndex; $blockedRow = $blockedIpMap[$group['ip_address']] ?? null; ?><tr class="traffic-parent-row" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" aria-controls="<?php echo $collapseId; ?>"><td><div class="d-flex align-items-center gap-2"><span class="traffic-toggle-icon">></span><div><div class="fw-semibold"><?php echo htmlspecialchars($group['ip_address']); ?></div><div class="traffic-small"><?php echo $blockedRow ? 'IP đang bị chặn' : 'Nhấn để mở toàn bộ hành trình'; ?></div></div><?php if ($blockedRow): ?><span class="badge-soft badge-danger-soft">Blocked</span><?php endif; ?></div></td><td><?php echo htmlspecialchars($group['last_seen']); ?></td><td><div class="fw-semibold"><?php echo htmlspecialchars(implode(', ', array_slice($group['sources'], 0, 2))); ?></div><div class="traffic-small"><?php echo count($group['sources']) > 2 ? '+' . (count($group['sources']) - 2) . ' nguồn khác' : ''; ?></div></td><td><?php echo htmlspecialchars(implode(', ', $group['devices'])); ?></td><td><div><?php echo htmlspecialchars($group['country_name'] . ' / ' . $group['region_name'] . ' / ' . $group['city_name']); ?></div><div class="traffic-small"><?php echo htmlspecialchars($group['isp_name']); ?></div></td><td class="traffic-small"><?php echo e(implode(', ', array_slice($group['paths'], 0, 2))); ?><?php echo count($group['paths']) > 2 ? ' ...' : ''; ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $group['sessions']); ?></td><td class="text-end fw-semibold"><?php echo number_format((int) $group['pageviews']); ?></td><td class="text-end fw-semibold"><?php echo number_format((float) $group['duration'], 1); ?>s</td><td class="text-end" onclick="event.stopPropagation();"><?php if ($group['ip_address'] !== '-'): ?><?php if ($blockedRow): ?><form method="POST" class="traffic-inline-form justify-content-end"><input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>"><input type="hidden" name="traffic_action" value="unblock_ip"><input type="hidden" name="ip_address" value="<?php echo htmlspecialchars($group['ip_address']); ?>"><button type="submit" class="btn btn-outline-secondary btn-sm">Bỏ chặn</button></form><?php else: ?><form method="POST" class="traffic-inline-form justify-content-end"><input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>"><input type="hidden" name="traffic_action" value="block_ip"><input type="hidden" name="ip_address" value="<?php echo htmlspecialchars($group['ip_address']); ?>"><input type="text" name="block_reason" class="form-control form-control-sm traffic-block-reason" placeholder="Lý do chặn"><button type="submit" class="btn btn-outline-danger btn-sm">Chặn</button></form><?php endif; ?><?php endif; ?></td></tr><tr class="collapse" id="<?php echo $collapseId; ?>"><td colspan="10" class="p-0"><div class="traffic-detail-wrap p-3"><?php foreach ($group['items'] as $item): $sessionPages = $pageviewsBySession[$item['session_key']] ?? []; $visitChunks = traffic_split_pageviews_by_gap($sessionPages, 600); ?><div class="mb-3 rounded-3 border bg-white overflow-hidden"><div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center flex-wrap gap-2"><div><span class="fw-semibold">Session:</span> <span class="traffic-small"><?php echo htmlspecialchars(substr((string) $item['session_key'], 0, 16)); ?></span></div><div class="traffic-small"><?php echo htmlspecialchars($item['last_activity_at'] ?: $item['started_at']); ?> | <?php echo htmlspecialchars($item['source_type'] ?: 'unknown'); ?> | <?php echo htmlspecialchars($item['device_type'] ?: 'unknown'); ?> | <?php echo htmlspecialchars($item['browser_name'] ?: 'Chưa rõ'); ?>/<?php echo htmlspecialchars($item['os_name'] ?: 'Chưa rõ'); ?> | PV: <?php echo number_format((int) $item['pageviews_count']); ?> | Duration: <?php echo number_format((float) $item['session_duration'], 1); ?>s</div></div><div class="p-3"><?php if (!empty($visitChunks)): foreach ($visitChunks as $visitIndex => $visitPages): $compact = traffic_compact_timeline($visitPages, 10); ?><div class="traffic-visit-block"><div class="traffic-small fw-semibold mb-2">Đợt truy cập <?php echo $visitIndex + 1; ?><?php if ($visitIndex > 0): ?> <span class="text-muted">(ngắt sau hơn 10 phút)</span><?php endif; ?></div><div class="traffic-timeline mb-3"><?php foreach ($compact['steps'] as $stepIndex => $step): ?><span class="traffic-timeline-step"><?php echo e($step['path']); ?><?php if (!empty($step['is_landing'])): ?> <span class="traffic-mini-badge landing">landing</span><?php endif; ?><?php if (!empty($step['is_exit'])): ?> <span class="traffic-mini-badge exit">exit</span><?php endif; ?><?php if (!empty($step['count']) && $step['count'] > 1): ?> <span class="traffic-mini-badge repeat">x<?php echo (int) $step['count']; ?></span><?php endif; ?></span><?php if ($stepIndex < count($compact['steps']) - 1): ?><span class="traffic-timeline-arrow">-></span><?php endif; ?><?php endforeach; ?></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th style="width:180px">Thời gian</th><th style="width:280px">Trang</th><th>Tiêu đề</th></tr></thead><tbody><?php foreach ($visitPages as $pageIndex => $pageview): ?><tr><td><?php echo htmlspecialchars($pageview['created_at']); ?></td><td class="traffic-small"><?php echo e(traffic_safe_page_label($pageview['page_path'] ?? '', $pageview['page_url'] ?? '')); ?><?php if ($pageIndex === 0): ?> <span class="traffic-mini-badge landing">landing</span><?php endif; ?><?php if ($pageIndex === count($visitPages) - 1): ?> <span class="traffic-mini-badge exit">exit</span><?php endif; ?></td><td class="traffic-small"><?php echo htmlspecialchars($pageview['page_title'] ?: '-'); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endforeach; else: ?><div class="traffic-small text-muted">Chưa có pageview chi tiết cho session này.</div><?php endif; ?></div></div><?php endforeach; ?></div></td></tr><?php $groupIndex++; endforeach; else: ?><tr><td colspan="10" class="text-center text-muted py-4">Chưa có phiên truy cập nào.</td></tr><?php endif; ?></tbody></table></div><?php traffic_render_pagination($recentTrafficPagination, $recentTrafficQuery, 'recent_page', '#recent-traffic'); ?></div>
</div>
</details>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const trendLabels = <?php echo json_encode(array_map(static function ($day) { return date('d/m', strtotime($day)); }, array_keys($daysMap)), JSON_UNESCAPED_UNICODE); ?>;
const trendValues = <?php echo json_encode(array_values($daysMap)); ?>;
const deviceLabels = <?php echo json_encode(array_map(static function ($row) { return $row['device_type'] ?: 'unknown'; }, $deviceRows), JSON_UNESCAPED_UNICODE); ?>;
const deviceValues = <?php echo json_encode(array_map(static function ($row) { return (int) $row['total']; }, $deviceRows)); ?>;
const trendEl = document.getElementById('trafficTrendChart');
if (trendEl && window.Chart) { new Chart(trendEl, { type: 'line', data: { labels: trendLabels, datasets: [{ label: 'Sessions', data: trendValues, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.14)', fill: true, tension: .35 }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } }); }
const deviceEl = document.getElementById('trafficDeviceChart');
if (deviceEl && window.Chart) { new Chart(deviceEl, { type: 'doughnut', data: { labels: deviceLabels, datasets: [{ data: deviceValues, backgroundColor: ['#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6'], borderWidth: 0 }] }, options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } } }); }
const visitorEl = document.getElementById('trafficVisitorChart');
const newVisitors = <?php echo (int)($summary['new_visitors'] ?? 0); ?>;
const returningVisitors = <?php echo max(0, (int)($summary['visitors']??0) - (int)($summary['new_visitors']??0)); ?>;
if (visitorEl && window.Chart) { new Chart(visitorEl, { type: 'doughnut', data: { labels: ['Khách mới', 'Quay lại'], datasets: [{ data: [newVisitors, returningVisitors], backgroundColor: ['#15803d','#b45309'], borderWidth: 0 }] }, options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } } }); }
</script>
<?php require_once '../includes/footer.php'; ?>
