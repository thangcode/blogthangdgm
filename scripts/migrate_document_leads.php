<?php
/**
 * migrate_document_leads.php
 * Di chuyển 122 lead "FORM GỬI TÀI LIỆU" (FluentForm form_id=4) từ WordPress
 * sang bảng document_requests. Idempotent: dedupe theo (email, created_at).
 *
 * Map: names.first_name -> fullname, email, file_name (basename),
 *      ip/browser/device, _wp_http_referer -> source_url + match post_id theo slug.
 *
 * Chạy: php scripts/migrate_document_leads.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once __DIR__ . '/../config/database.php'; // $pdo (đích)
$pdo->exec("SET NAMES utf8mb4");

const WP_DB   = 'thangdgm_db';
const WP_HOST = 'localhost';
const WP_USER = 'root';
const WP_PASS = '';
const FORM_ID = 4;
const WP_DOMAINS = ['thang-dgm.com', 'www.thang-dgm.com', 'thangdgm.test', 'www.thangdgm.test'];

$src = new PDO('mysql:host=' . WP_HOST . ';dbname=' . WP_DB . ';charset=utf8mb4', WP_USER, WP_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function logline($m) { echo $m . PHP_EOL; }

// Cache slug -> post_id và title -> post_id (đích)
$slugMap = [];
$titleMap = [];
foreach ($pdo->query("SELECT id, slug, title FROM posts") as $p) {
    $slugMap[strtolower(trim($p['slug']))] = (int) $p['id'];
    $titleMap[mb_strtolower(trim($p['title']), 'UTF-8')] = (int) $p['id'];
}

function slug_from_referer(string $ref): string
{
    $ref = trim($ref);
    if ($ref === '') return '';
    // Có thể là path "/slug/" hoặc full URL.
    $path = parse_url($ref, PHP_URL_PATH);
    if ($path === null || $path === false) $path = $ref;
    return strtolower(trim($path, '/'));
}

$stats = ['total' => 0, 'ins' => 0, 'dup' => 0, 'nopost' => 0, 'err' => 0];

$rows = $src->prepare("SELECT id, response, source_url, browser, device, ip, status, created_at
                       FROM wp_fluentform_submissions WHERE form_id = ? ORDER BY id ASC");
$rows->execute([FORM_ID]);

$chkDup = $pdo->prepare("SELECT id FROM document_requests WHERE email = ? AND created_at = ? LIMIT 1");
$ins = $pdo->prepare("INSERT INTO document_requests
    (post_id, fullname, email, file_name, ip_address, source_url, device, browser, status, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?)");

foreach ($rows as $r) {
    $stats['total']++;
    try {
        $resp = json_decode((string) $r['response'], true);
        if (!is_array($resp)) { $stats['err']++; continue; }

        $fullname = '';
        if (isset($resp['names']['first_name'])) {
            $fullname = trim((string) $resp['names']['first_name']);
            if (isset($resp['names']['last_name'])) {
                $fullname = trim($fullname . ' ' . $resp['names']['last_name']);
            }
        }
        $email = trim((string) ($resp['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $stats['err']++; continue; }
        $fullname = $fullname !== '' ? mb_substr($fullname, 0, 150, 'UTF-8') : '(không tên)';

        // file_name: lấy basename từ URL pdf
        $fileUrl = (string) ($resp['file_name'] ?? '');
        $fileName = $fileUrl !== '' ? basename(parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl) : '';
        $fileName = mb_substr(urldecode($fileName), 0, 255, 'UTF-8');

        // Match post_id: ưu tiên slug từ _wp_http_referer, fallback theo title ("hidden")
        $ref = (string) ($resp['_wp_http_referer'] ?? $r['source_url'] ?? '');
        $slug = slug_from_referer($ref);
        $postId = $slugMap[$slug] ?? 0;
        if ($postId === 0 && isset($resp['hidden'])) {
            $t = mb_strtolower(trim((string) $resp['hidden']), 'UTF-8');
            $postId = $titleMap[$t] ?? 0;
        }
        if ($postId === 0) $stats['nopost']++;

        // source_url: chuẩn hoá domain cũ -> path
        $sourceUrl = trim((string) ($r['source_url'] ?: $ref));
        $sourceUrl = mb_substr($sourceUrl, 0, 255, 'UTF-8');

        $browser = mb_substr(trim((string) $r['browser']) ?: 'Other', 0, 60, 'UTF-8');
        $device  = mb_substr(trim((string) $r['device']) ?: 'unknown', 0, 60, 'UTF-8');
        $createdAt = $r['created_at'] ?: date('Y-m-d H:i:s');

        // Idempotent: bỏ qua nếu đã có (email, created_at)
        $chkDup->execute([$email, $createdAt]);
        if ($chkDup->fetchColumn()) { $stats['dup']++; continue; }

        $ins->execute([$postId, $fullname, $email, $fileName, $r['ip'], $sourceUrl, $device, $browser, 'sent', $createdAt]);
        $stats['ins']++;
    } catch (Throwable $e) {
        $stats['err']++;
        logline("[ERR] submission {$r['id']}: " . $e->getMessage());
    }
}

logline("=== MIGRATE DOCUMENT LEADS ===");
foreach ($stats as $k => $v) logline(str_pad($k, 8) . ": $v");
logline("=== HOÀN TẤT ===");
