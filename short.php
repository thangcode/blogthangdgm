<?php
// short.php - Handle Short Link Redirects
require_once 'config/database.php';

$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    http_response_code(404);
    require '404.php';
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM short_links WHERE slug = ? AND status = 1");
    $stmt->execute([$slug]);
    $link = $stmt->fetch();

    if (!$link) {
        http_response_code(404);
        require '404.php';
        exit;
    }

    // Build target URL with UTM parameters if they exist
    $target_url = $link['target_url'];
    $parsed_url = parse_url($target_url);
    $query_params = [];
    
    if (isset($parsed_url['query'])) {
        parse_str($parsed_url['query'], $query_params);
    }
    
    if (!empty($link['utm_source'])) $query_params['utm_source'] = $link['utm_source'];
    if (!empty($link['utm_medium'])) $query_params['utm_medium'] = $link['utm_medium'];
    if (!empty($link['utm_campaign'])) $query_params['utm_campaign'] = $link['utm_campaign'];
    if (!empty($link['utm_term'])) $query_params['utm_term'] = $link['utm_term'];
    if (!empty($link['utm_content'])) $query_params['utm_content'] = $link['utm_content'];

    // Parameter forwarding (pass incoming GET vars excluding 'slug' to target)
    if (!empty($link['parameter_forwarding']) && $link['parameter_forwarding'] == 1) {
        foreach ($_GET as $key => $val) {
            if ($key !== 'slug') {
                $query_params[$key] = $val;
            }
        }
    }

    if (!empty($query_params)) {
        $query_string = http_build_query($query_params);
        $target_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if (isset($parsed_url['port'])) $target_url .= ':' . $parsed_url['port'];
        if (isset($parsed_url['path'])) $target_url .= $parsed_url['path'];
        $target_url .= '?' . $query_string;
        if (isset($parsed_url['fragment'])) $target_url .= '#' . $parsed_url['fragment'];
    }

    // Tracking
    if (!empty($link['is_tracking_enabled']) && $link['is_tracking_enabled'] == 1) {
        $ip_address = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        
        // Basic parsing for browser/os/device
        $browser = 'Unknown';
        $os = 'Unknown';
        $device = 'Desktop';
        
        if (preg_match('/mobile/i', $user_agent)) $device = 'Mobile';
        elseif (preg_match('/tablet/i', $user_agent)) $device = 'Tablet';
        
        if (preg_match('/windows|win32/i', $user_agent)) $os = 'Windows';
        elseif (preg_match('/macintosh|mac os x/i', $user_agent)) $os = 'macOS';
        elseif (preg_match('/linux/i', $user_agent)) $os = 'Linux';
        elseif (preg_match('/android/i', $user_agent)) $os = 'Android';
        elseif (preg_match('/iphone/i', $user_agent)) $os = 'iOS';
        
        if (preg_match('/msie|trident/i', $user_agent)) $browser = 'Internet Explorer';
        elseif (preg_match('/edg/i', $user_agent)) $browser = 'Edge';
        elseif (preg_match('/firefox|fxios/i', $user_agent)) $browser = 'Firefox';
        elseif (preg_match('/safari/i', $user_agent) && !preg_match('/chrome|crios/i', $user_agent)) $browser = 'Safari';
        elseif (preg_match('/chrome|crios/i', $user_agent)) $browser = 'Chrome';
        elseif (preg_match('/opera|opr/i', $user_agent)) $browser = 'Opera';

        try {
            // Check if table short_link_clicks exists, if not, skip tracking to prevent fatal errors
            $pdo->query("SELECT 1 FROM short_link_clicks LIMIT 1");
            
            $stmtClick = $pdo->prepare("INSERT INTO short_link_clicks (short_link_id, ip_address, browser, os, device, referrer) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtClick->execute([$link['id'], $ip_address, $browser, $os, $device, $referrer]);
        } catch (Exception $e) {
            // Tracking failed (table may not exist yet) - Do not block redirect
        }
    }

    // Set correct HTTP response code
    $redirect_type = (int) ($link['redirect_type'] ?? 307);
    if (!in_array($redirect_type, [301, 302, 307, 308])) {
        $redirect_type = 307;
    }
    
    // Disable caching for redirects
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    
    header("Location: " . $target_url, true, $redirect_type);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo "Lỗi hệ thống khi xử lý link rút gọn.";
    exit;
}
