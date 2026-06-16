<?php
// feed.php — RSS 2.0 feed cho blog (bài viết mới nhất)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/url-helper.php';

$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$site_desc = get_setting('site_description', get_setting('meta_description', $site_name));
$self_url  = rtrim(BASE_URL, '/') . '/feed';

try {
    $posts = $pdo->query("SELECT title, slug, summary, content, created_at, updated_at, author_name
                          FROM posts WHERE status = 1 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {
    $posts = [];
}

$xmlesc = fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$last_build = !empty($posts) ? date(DATE_RSS, strtotime($posts[0]['created_at'])) : date(DATE_RSS);

// Dọn mọi output buffer/khoảng trắng do include sinh ra để <?xml nằm đầu tài liệu
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/rss+xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
    <title><?php echo $xmlesc($site_name); ?></title>
    <link><?php echo $xmlesc(BASE_URL); ?></link>
    <description><?php echo $xmlesc($site_desc ?: $site_name); ?></description>
    <language>vi-VN</language>
    <lastBuildDate><?php echo $last_build; ?></lastBuildDate>
    <atom:link href="<?php echo $xmlesc($self_url); ?>" rel="self" type="application/rss+xml" />
<?php foreach ($posts as $p):
    $link = postUrl($p['slug'], true);
    $desc = trim((string) ($p['summary'] ?? ''));
    if ($desc === '') {
        $desc = mb_substr(trim(strip_tags((string) $p['content'])), 0, 300, 'UTF-8');
    }
?>
    <item>
        <title><?php echo $xmlesc($p['title']); ?></title>
        <link><?php echo $xmlesc($link); ?></link>
        <guid isPermaLink="true"><?php echo $xmlesc($link); ?></guid>
        <pubDate><?php echo date(DATE_RSS, strtotime($p['created_at'])); ?></pubDate>
<?php if (!empty($p['author_name'])): ?>
        <dc:creator><?php echo $xmlesc($p['author_name']); ?></dc:creator>
<?php endif; ?>
        <description><?php echo $xmlesc($desc); ?></description>
    </item>
<?php endforeach; ?>
</channel>
</rss>
