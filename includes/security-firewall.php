<?php
/**
 * Lop tuong lua nhe (lightweight firewall) cho blog.
 *
 * Muc tieu:
 *  - Chan cac user-agent xau (scanner / scraper / tool tan cong) giong file nginx.conf cua WordPress.
 *  - Tu dong ban IP khi vuot nguong request trong mot khung thoi gian (chong flood / cao du lieu).
 *
 * Nguyen tac an toan:
 *  - KHONG BAO GIO chan cac bot lon hop le (Googlebot, Bingbot, ...): co whitelist UA rieng.
 *  - Co whitelist IP/CIDR (IP cua chinh ban, monitoring, ...).
 *  - Tan dung ha tang san co: bang traffic_blocked_ips + ham enforce_traffic_ip_block (render 403).
 *  - Moi loi deu nuot (try/catch) de tuong lua khong bao gio lam sap website.
 *
 * Tat ca cau hinh luu trong bang settings (chinh duoc trong Admin > Thong ke truy cap).
 */

if (!function_exists('security_fw_get')) {
    /**
     * Doc 1 setting voi gia tri mac dinh (uu tien dung get_setting da co cache).
     */
    function security_fw_get($key, $default = '')
    {
        if (function_exists('get_setting')) {
            $val = get_setting($key, $default);
            return $val === '' && $default !== '' ? $default : $val;
        }
        return $default;
    }
}

/**
 * Danh sach UA xau mac dinh (so khop dang chua chuoi, khong phan biet hoa thuong).
 * Chi gom cac cong cu tan cong / cao du lieu RO RANG, tranh bat nham client hop le.
 */
function security_fw_default_bad_ua()
{
    return [
        'sqlmap', 'nikto', 'acunetix', 'wpscan', 'fimap', 'dirbuster', 'nessus',
        'openvas', 'jaeles', 'nuclei', 'zgrab', 'masscan', 'webinspect', 'paros',
        'w3af', 'havij', 'hydra', 'morfeus', 'zmeu', 'arachni', 'metis',
        'BlackWidow', 'WebCopier', 'WebReaper', 'Teleport Pro', 'HTTrack',
        'Indy Library', 'libwww-perl', 'lwp-trivial', 'WWW-Mechanize',
        'EmailCollector', 'EmailSiphon', 'ExtractorPro', 'Mass Download',
        'Go!Zilla', 'GrabNet', 'WebBandit', 'WebStripper', 'SiteSnagger',
        'Xenu', 'panscient', 'PHPCrawl', 'feedfinder',
    ];
}

/**
 * Regex nhan dien BOT LON HOP LE -> luon duoc bo qua, khong bao gio bi chan/ban.
 */
function security_fw_good_bot_regex()
{
    return '/(googlebot|google-inspectiontool|storebot-google|adsbot-google|mediapartners-google|apis-google|feedfetcher-google|google favicon|bingbot|bingpreview|adidxbot|msnbot|slurp|duckduckbot|duckduckgo|yandex(bot)?|baiduspider|applebot|facebookexternalhit|facebot|meta-externalagent|twitterbot|linkedinbot|pinterest(bot)?|telegrambot|whatsapp|discordbot|coccocbot|seznambot|petalbot|ahrefsbot|semrushbot|uptimerobot|ia_archiver|chrome-lighthouse|gtmetrix|pingdom|google-pagespeed)/i';
}

/**
 * Lay toan bo cau hinh tuong lua (da ep kieu, da co mac dinh an toan).
 */
function security_fw_settings($pdo)
{
    $enabled       = security_fw_get('security_fw_enabled', '1');
    $blockBadUa    = security_fw_get('security_fw_block_bad_ua', '1');
    $autoban       = security_fw_get('security_fw_autoban_enabled', '1');
    $threshold     = (int) security_fw_get('security_fw_autoban_threshold', '240');
    $window        = (int) security_fw_get('security_fw_autoban_window', '60');
    $duration      = (int) security_fw_get('security_fw_autoban_duration', '3600');
    $whitelistIps  = (string) security_fw_get('security_fw_whitelist_ips', '');
    $badUaExtra    = (string) security_fw_get('security_fw_bad_ua_extra', '');

    return [
        'enabled'          => security_fw_truthy($enabled),
        'block_bad_ua'     => security_fw_truthy($blockBadUa),
        'autoban_enabled'  => security_fw_truthy($autoban),
        'autoban_threshold' => $threshold > 0 ? $threshold : 240,
        'autoban_window'   => $window > 0 ? $window : 60,
        'autoban_duration' => $duration > 0 ? $duration : 3600,
        'whitelist_ips'    => $whitelistIps,
        'bad_ua_extra'     => $badUaExtra,
    ];
}

function security_fw_truthy($value)
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * UA co phai bot lon hop le khong.
 */
function security_fw_is_good_bot($ua)
{
    $ua = (string) $ua;
    if ($ua === '') {
        return false;
    }
    return preg_match(security_fw_good_bot_regex(), $ua) === 1;
}

/**
 * IP co nam trong whitelist (danh sach moi dong 1 IP hoac dai CIDR) khong.
 */
function security_fw_ip_whitelisted($ip, $whitelistText)
{
    $ip = trim((string) $ip);
    if ($ip === '' || trim((string) $whitelistText) === '') {
        return false;
    }
    $lines = preg_split('/[\r\n,]+/', (string) $whitelistText);
    foreach ($lines as $line) {
        $entry = trim($line);
        if ($entry === '') {
            continue;
        }
        if (function_exists('ip_in_cidr')) {
            if (ip_in_cidr($ip, $entry)) {
                return true;
            }
        } elseif ($ip === $entry) {
            return true;
        }
    }
    return false;
}

/**
 * UA co khop danh sach UA xau (mac dinh + admin them) khong.
 */
function security_fw_bad_ua_match($ua, $extraText = '')
{
    $ua = trim((string) $ua);
    if ($ua === '') {
        return false; // khong tu chan UA rong de tranh bat nham health-check
    }
    $list = security_fw_default_bad_ua();
    $extra = preg_split('/[\r\n,]+/', (string) $extraText);
    foreach ($extra as $e) {
        $e = trim($e);
        if ($e !== '') {
            $list[] = $e;
        }
    }
    foreach ($list as $needle) {
        if ($needle !== '' && stripos($ua, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Bo sung cot expires_at cho traffic_blocked_ips (cho phep ban tam thoi tu het han)
 * va tao bang dem rate-limit. Chi chay 1 lan / request.
 */
function security_fw_ensure_storage($pdo)
{
    static $done = false;
    if ($done || !$pdo instanceof PDO) {
        return;
    }
    $done = true;

    try {
        $col = $pdo->query("SHOW COLUMNS FROM `traffic_blocked_ips` LIKE 'expires_at'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $pdo->exec("ALTER TABLE `traffic_blocked_ips` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        // bo qua
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `security_fw_rate` (
            `ip_address` VARCHAR(45) NOT NULL,
            `bucket` BIGINT UNSIGNED NOT NULL,
            `hits` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`ip_address`, `bucket`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // bo qua
    }
}

/**
 * Dua IP vao danh sach chan. Chi them neu chua bi chan (khong de duplicate-ban
 * lam mat ban vinh vien thu cong). $duration <= 0 => ban vinh vien.
 */
function security_fw_apply_ban($pdo, $ip, $reason, $duration)
{
    if (!$pdo instanceof PDO || $ip === '') {
        return;
    }
    try {
        $existing = function_exists('traffic_get_blocked_ip') ? traffic_get_blocked_ip($pdo, $ip) : null;
        if (!empty($existing)) {
            return; // da bi chan roi -> de enforce lo
        }
        $duration = (int) $duration;
        $expiresAt = $duration > 0 ? date('Y-m-d H:i:s', time() + $duration) : null;
        $reason = substr((string) $reason, 0, 255);
        $stmt = $pdo->prepare(
            "INSERT INTO `traffic_blocked_ips`
                (ip_address, block_reason, blocked_by, attempts_count, last_attempt_at, created_at, updated_at, expires_at)
             VALUES (?, ?, 'auto-firewall', 1, NOW(), NOW(), NOW(), ?)
             ON DUPLICATE KEY UPDATE attempts_count = attempts_count + 1, last_attempt_at = NOW()"
        );
        $stmt->execute([$ip, $reason, $expiresAt]);
    } catch (Throwable $e) {
        // bo qua
    }
}

/**
 * Tang bo dem request cua IP trong khung thoi gian hien tai, tra ve so request.
 */
function security_fw_rate_increment($pdo, $ip, $window)
{
    if (!$pdo instanceof PDO || $ip === '') {
        return 0;
    }
    $window = max(1, (int) $window);
    $bucket = intdiv(time(), $window);
    try {
        $pdo->prepare(
            "INSERT INTO `security_fw_rate` (ip_address, bucket, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1"
        )->execute([$ip, $bucket]);

        $stmt = $pdo->prepare("SELECT hits FROM `security_fw_rate` WHERE ip_address = ? AND bucket = ?");
        $stmt->execute([$ip, $bucket]);
        $hits = (int) $stmt->fetchColumn();

        // Don rac thua thot (xac suat thap) de bang khong phinh to.
        if (mt_rand(1, 50) === 1) {
            $pdo->prepare("DELETE FROM `security_fw_rate` WHERE bucket < ?")->execute([$bucket - 3]);
        }
        return $hits;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Cong chinh: chay som trong luong request (truoc khi tra noi dung / cache).
 */
function security_fw_guard($pdo, array $options = [])
{
    if (!$pdo instanceof PDO || PHP_SAPI === 'cli') {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if (!empty($options['skip_admin']) && strpos($uri, '/admin/') !== false) {
        return;
    }

    try {
        $s = security_fw_settings($pdo);
        if (empty($s['enabled'])) {
            return;
        }

        $ip = function_exists('traffic_normalize_ip_address')
            ? traffic_normalize_ip_address(get_client_ip_address())
            : (string) get_client_ip_address();
        if ($ip === '') {
            return;
        }

        // 1) Whitelist IP -> luon cho qua.
        if (security_fw_ip_whitelisted($ip, $s['whitelist_ips'])) {
            return;
        }

        // 2) Bot lon hop le -> luon cho qua (khong chan, khong dem).
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (security_fw_is_good_bot($ua)) {
            return;
        }

        security_fw_ensure_storage($pdo);

        // 3) Chan UA xau.
        if (!empty($s['block_bad_ua']) && security_fw_bad_ua_match($ua, $s['bad_ua_extra'])) {
            security_fw_apply_ban($pdo, $ip, 'Bad user-agent (scanner/scraper)', (int) $s['autoban_duration']);
            if (function_exists('enforce_traffic_ip_block')) {
                enforce_traffic_ip_block($pdo, ['skip_admin' => true]);
            }
            return;
        }

        // 4) Auto-ban khi vuot nguong request.
        if (!empty($s['autoban_enabled'])) {
            $hits = security_fw_rate_increment($pdo, $ip, (int) $s['autoban_window']);
            if ($hits > (int) $s['autoban_threshold']) {
                $reason = 'Auto-ban: ' . $hits . ' request / ' . $s['autoban_window'] . 's (nguong ' . $s['autoban_threshold'] . ')';
                security_fw_apply_ban($pdo, $ip, $reason, (int) $s['autoban_duration']);
                if (function_exists('enforce_traffic_ip_block')) {
                    enforce_traffic_ip_block($pdo, ['skip_admin' => true]);
                }
                return;
            }
        }
    } catch (Throwable $e) {
        // Tuong lua khong duoc lam sap site.
        return;
    }
}

/**
 * Luu cau hinh tu form admin. Tra ve true neu thanh cong.
 */
function security_fw_save_settings($pdo, array $post)
{
    if (!$pdo instanceof PDO) {
        return false;
    }
    $map = [
        'security_fw_enabled'        => isset($post['security_fw_enabled']) ? '1' : '0',
        'security_fw_block_bad_ua'   => isset($post['security_fw_block_bad_ua']) ? '1' : '0',
        'security_fw_autoban_enabled' => isset($post['security_fw_autoban_enabled']) ? '1' : '0',
        'security_fw_autoban_threshold' => (string) max(10, (int) ($post['security_fw_autoban_threshold'] ?? 240)),
        'security_fw_autoban_window' => (string) max(5, (int) ($post['security_fw_autoban_window'] ?? 60)),
        'security_fw_autoban_duration' => (string) max(60, (int) ($post['security_fw_autoban_duration'] ?? 3600)),
        'security_fw_whitelist_ips'  => trim((string) ($post['security_fw_whitelist_ips'] ?? '')),
        'security_fw_bad_ua_extra'   => trim((string) ($post['security_fw_bad_ua_extra'] ?? '')),
    ];
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'security')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ($map as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
