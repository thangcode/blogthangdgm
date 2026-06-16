<?php
/**
 * CASSO AddressKit Proxy
 * Acts as a bridge to bypass CORS restrictions
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

enforce_traffic_ip_block($pdo, ['json' => true]);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_GET['action'] ?? '';
$api_base = "https://production.cas.so/address-kit/latest";

if ($action === 'provinces') {
    $url = $api_base . "/provinces";
} elseif ($action === 'communes' && !empty($_GET['provinceId'])) {
    // SECURITY: Only allow numeric province IDs — prevents SSRF/URL injection
    $provinceId = (string)($_GET['provinceId']);
    if (!ctype_digit($provinceId) || strlen($provinceId) > 10) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid provinceId']);
        exit;
    }
    $url = $api_base . "/provinces/" . $provinceId . "/communes";
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or missing parameters']);
    exit;
}

// More robust fetch using cURL if available, otherwise file_get_contents
function fetchUrl($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FPTStore-Server/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400)
            return false;
        return $response;
    } else {
        $options = [
            "http" => [
                "method" => "GET",
                "follow_location" => 1,
                "header" => "User-Agent: FPTStore-Server/1.0\r\nAccept: application/json\r\n",
                "timeout" => 10
            ]
        ];
        $context = stream_context_create($options);
        return @file_get_contents($url, false, $context);
    }
}

$response = fetchUrl($url);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Failed to fetch data from source API'
    ]);
} else {
    echo $response;
}
