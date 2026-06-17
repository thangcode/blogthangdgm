<?php
require_once __DIR__ . '/../config/database.php';

function clean_wp_shortcodes($content) {
    if (!$content) return $content;

    // 1. [caption]...[/caption]
    $content = preg_replace_callback('/\[caption[^\]]*\](.*?)\[\/caption\]/is', function($m) {
        return '<figure class="wp-caption text-center mb-4">' . trim($m[1]) . '</figure>';
    }, $content);

    // 2. [embed]...[/embed]
    $content = preg_replace_callback('/\[embed[^\]]*\](.*?)\[\/embed\]/is', function($m) {
        $url = trim(strip_tags($m[1]));
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            $video_id = '';
            if (preg_match('/youtube\.com\/watch\?v=([^\&\?\/]+)/', $url, $id)) {
                $video_id = $id[1];
            } else if (preg_match('/youtu\.be\/([^\&\?\/]+)/', $url, $id)) {
                $video_id = $id[1];
            }
            if ($video_id) {
                return '<div class="ratio ratio-16x9 mb-4"><iframe src="https://www.youtube.com/embed/'.$video_id.'" allowfullscreen></iframe></div>';
            }
        }
        return '<a href="'.htmlspecialchars($url).'" target="_blank">'.$url.'</a>';
    }, $content);

    // 3. Plugin shortcodes (remove entirely)
    $plugins = ['fluentform', 'dwqa-list-questions', 'dwqa-submit-question-form', 'contact-form-7', 'elementor-template'];
    $pattern = '/\[(' . implode('|', $plugins) . ')[^\]]*\]/is';
    $content = preg_replace($pattern, '', $content);

    return $content;
}

$tables = ['pages', 'posts'];
$total_updated = 0;

foreach ($tables as $table) {
    echo "Processing table: $table\n";
    $stmt = $pdo->query("SELECT id, content FROM $table WHERE content LIKE '%[%]%'");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updated_count = 0;
    foreach ($rows as $row) {
        $old_content = $row['content'];
        $new_content = clean_wp_shortcodes($old_content);
        
        if ($old_content !== $new_content) {
            $up_stmt = $pdo->prepare("UPDATE $table SET content = ? WHERE id = ?");
            $up_stmt->execute([$new_content, $row['id']]);
            $updated_count++;
        }
    }
    echo "Updated $updated_count rows in $table.\n\n";
    $total_updated += $updated_count;
}

echo "Done! Total rows updated: $total_updated\n";
