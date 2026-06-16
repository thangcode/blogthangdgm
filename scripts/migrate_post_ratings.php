<?php
/**
 * Migrate kk Star Ratings aggregates from WordPress into the plain PHP blog.
 *
 * Source: thangdgm_db.wp_postmeta
 * Destination: post_rating_legacy
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/blog.php';
require __DIR__ . '/../includes/page-cache.php';

blog_ensure_post_ratings_schema($pdo);

$wp = new PDO('mysql:host=localhost;dbname=thangdgm_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$rows = $wp->query(
    "SELECT
        p.ID AS wp_id,
        p.post_name AS slug,
        p.post_title AS title,
        MAX(CASE WHEN pm.meta_key = '_kksr_casts' THEN pm.meta_value END) AS casts,
        MAX(CASE WHEN pm.meta_key = '_kksr_count_default' THEN pm.meta_value END) AS count_default,
        MAX(CASE WHEN pm.meta_key = '_kksr_ratings' THEN pm.meta_value END) AS ratings,
        MAX(CASE WHEN pm.meta_key = '_kksr_ratings_default' THEN pm.meta_value END) AS ratings_default,
        MAX(CASE WHEN pm.meta_key = '_kksr_avg' THEN pm.meta_value END) AS avg
     FROM wp_posts p
     INNER JOIN wp_postmeta pm ON pm.post_id = p.ID
     WHERE p.post_type = 'post'
       AND p.post_status IN ('publish', 'private')
       AND pm.meta_key IN ('_kksr_casts', '_kksr_count_default', '_kksr_ratings', '_kksr_ratings_default', '_kksr_avg')
     GROUP BY p.ID, p.post_name, p.post_title
     HAVING CAST(COALESCE(casts, count_default, 0) AS UNSIGNED) > 0"
)->fetchAll();

$findPost = $pdo->prepare("SELECT id, slug, title FROM posts WHERE slug = ? LIMIT 1");
$upsert = $pdo->prepare(
    "INSERT INTO post_rating_legacy (post_id, rating_count, rating_sum, rating_avg, source, source_ref, updated_at)
     VALUES (?, ?, ?, ?, 'kk-star-ratings', ?, NOW())
     ON DUPLICATE KEY UPDATE
        rating_count = VALUES(rating_count),
        rating_sum = VALUES(rating_sum),
        rating_avg = VALUES(rating_avg),
        source = VALUES(source),
        source_ref = VALUES(source_ref),
        updated_at = NOW()"
);

$imported = 0;
$skipped = 0;

foreach ($rows as $row) {
    $slug = trim((string) $row['slug']);
    if ($slug === '') {
        $skipped++;
        continue;
    }

    $findPost->execute([$slug]);
    $post = $findPost->fetch();
    if (!$post) {
        $skipped++;
        continue;
    }

    $count = (int) ($row['casts'] !== null && $row['casts'] !== '' ? $row['casts'] : ($row['count_default'] ?? 0));
    $sum = (float) ($row['ratings'] !== null && $row['ratings'] !== '' ? $row['ratings'] : ($row['ratings_default'] ?? 0));
    if ($count <= 0 || $sum <= 0) {
        $skipped++;
        continue;
    }

    $avg = $sum / $count;
    $upsert->execute([
        (int) $post['id'],
        $count,
        $sum,
        round($avg, 2),
        'wp:' . (int) $row['wp_id'],
    ]);
    $imported++;
}

$deletedCache = class_exists('PageCache') ? PageCache::flush() : 0;

echo "Imported legacy ratings: {$imported}\n";
echo "Skipped rows: {$skipped}\n";
echo "Cache files deleted: {$deletedCache}\n";
