<?php
/**
 * Dynamic XML Sitemap Generator for FPTSTORE
 * Generates a valid sitemap.xml with all active pages
 * 
 * @package FPTSTORE
 */

header('Content-Type: application/xml; charset=utf-8');

require_once 'config/database.php';
require_once 'includes/url-helper.php';

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Helper function to output URL entry
function outputUrl($loc, $lastmod = null, $changefreq = 'weekly', $priority = '0.5')
{
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($loc) . "</loc>\n";
    if ($lastmod) {
        echo "    <lastmod>" . date('Y-m-d', strtotime($lastmod)) . "</lastmod>\n";
    }
    echo "    <changefreq>{$changefreq}</changefreq>\n";
    echo "    <priority>{$priority}</priority>\n";
    echo "  </url>\n";
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

try {
    // 1. Homepage
    outputUrl(BASE_URL, date('Y-m-d'), 'daily', '1.0');

    // 2. Trang tĩnh
    $static_pages = [
        'gioi-thieu' => ['file' => 'about.php', 'freq' => 'monthly', 'priority' => '0.5'],
        'lien-he'    => ['file' => 'contact.php', 'freq' => 'monthly', 'priority' => '0.5'],
    ];
    foreach ($static_pages as $seo_url => $settings) {
        $file_path = __DIR__ . '/' . $settings['file'];
        if (!file_exists($file_path)) continue;
        $lastmod = date('Y-m-d', filemtime($file_path));
        outputUrl(BASE_URL . $seo_url, $lastmod, $settings['freq'], $settings['priority']);
    }

    // 3. Chuyên mục (chỉ category có bài)
    $stmt = $pdo->query("SELECT DISTINCT c.slug, c.created_at FROM categories c
                         JOIN post_categories pc ON pc.category_id = c.id
                         JOIN posts p ON p.id = pc.post_id AND p.status = 1
                         WHERE c.status = 1");
    foreach ($stmt->fetchAll() as $category) {
        outputUrl(categoryUrl($category['slug'], true), $category['created_at'], 'weekly', '0.8');
    }

    // 4. Bài viết (root /{slug}/)
    $stmt = $pdo->query("SELECT slug, created_at, updated_at FROM posts WHERE status = 1 ORDER BY created_at DESC");
    foreach ($stmt->fetchAll() as $post) {
        outputUrl(postUrl($post['slug'], true), $post['updated_at'] ?: $post['created_at'], 'weekly', '0.7');
    }

    // 5. Tag (chỉ tag có bài)
    $stmt = $pdo->query("SELECT DISTINCT t.slug FROM tags t
                         JOIN post_tags pt ON pt.tag_id = t.id
                         JOIN posts p ON p.id = pt.post_id AND p.status = 1");
    foreach ($stmt->fetchAll() as $tag) {
        outputUrl(tagUrl($tag['slug'], true), null, 'weekly', '0.4');
    }

    // 6. Trang tĩnh (pages)
    try {
        $stmt = $pdo->query("SELECT slug, updated_at, created_at FROM pages WHERE status = 1");
        foreach ($stmt->fetchAll() as $pg) {
            outputUrl(BASE_URL . ltrim($pg['slug'], '/') . '/', $pg['updated_at'] ?: $pg['created_at'], 'monthly', '0.6');
        }
    } catch (Throwable $e) { /* bảng pages có thể chưa có */ }

} catch (PDOException $e) {
    outputUrl(BASE_URL, date('Y-m-d'), 'daily', '1.0');
}

echo '</urlset>';
?>
