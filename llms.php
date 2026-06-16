<?php
// llms.php — Sinh nội dung llms.txt (chuẩn cho công cụ AI / GEO)
header('Content-Type: text/plain; charset=utf-8');
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/url-helper.php';

$site_name = get_setting('site_name', 'Thắng Digital Marketing');
$desc = get_setting('site_description', 'Blog chia sẻ kiến thức Facebook Ads, Google Ads, kinh doanh online, AI & automation, thiết kế website và SEO.');

echo "# {$site_name}\n\n";
echo "> {$desc}\n\n";
echo "Website: " . BASE_URL . "\n\n";

echo "## Chuyên mục\n";
try {
    $cats = $pdo->query("SELECT DISTINCT c.name, c.slug FROM categories c
                         JOIN post_categories pc ON pc.category_id=c.id
                         JOIN posts p ON p.id=pc.post_id AND p.status=1
                         WHERE c.status=1 ORDER BY c.name")->fetchAll();
    foreach ($cats as $c) {
        echo "- [{$c['name']}](" . categoryUrl($c['slug'], true) . ")\n";
    }
} catch (Throwable $e) {}

echo "\n## Bài viết mới nhất\n";
try {
    $posts = $pdo->query("SELECT title, slug FROM posts WHERE status=1 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 50")->fetchAll();
    foreach ($posts as $p) {
        echo "- [{$p['title']}](" . postUrl($p['slug'], true) . ")\n";
    }
} catch (Throwable $e) {}

echo "\n## Sitemap\n- " . BASE_URL . "sitemap.xml\n";
