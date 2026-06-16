<?php
/**
 * seed_blog_blocks.php — Dọn block sản phẩm cũ + tạo block động bài viết cho trang chủ blog.
 * Idempotent (block_key cố định). Chạy: php scripts/seed_blog_blocks.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$pdo->exec("SET NAMES utf8mb4");
function out($m){ echo $m . PHP_EOL; }

// 1) Xóa toàn bộ dynamic_blocks cũ + homepage_blocks tương ứng (làm sạch phần sản phẩm).
try {
    $oldKeys = $pdo->query("SELECT block_key FROM dynamic_blocks")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($oldKeys as $k) {
        $pdo->prepare("DELETE FROM homepage_blocks WHERE block_key = ?")->execute([$k]);
    }
    $pdo->exec("DELETE FROM dynamic_blocks");
    out("[OK] Đã xóa " . count($oldKeys) . " dynamic_blocks cũ + homepage_blocks tương ứng");
} catch (Throwable $e) { out("[ERR] clean dynamic: " . $e->getMessage()); }

// 2) Xóa block không còn render file (deal_today, hot_products, services, consultation_form...).
$pdo->exec("DELETE FROM homepage_blocks WHERE block_key IN ('deal_today','hot_products','services','consultation_form','products','categories')");

// 3) Map category theo slug
function catId(PDO $pdo, string $slug): ?int {
    $s = $pdo->prepare("SELECT id FROM categories WHERE slug = ? LIMIT 1");
    $s->execute([$slug]);
    $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}
$fb  = catId($pdo, 'quang-cao-facebook-ads');
$gg  = catId($pdo, 'quang-cao-google-ads');
$ai  = catId($pdo, 'ai-automation');
$kd  = catId($pdo, 'kinh-doanh-online');

// 4) Tạo dynamic_blocks bài viết (block_key cố định -> idempotent)
$blocks = [
    ['key'=>'dynamic_blog_latest','title'=>'Bài viết mới nhất','sub'=>'Cập nhật kiến thức mới mỗi tuần','cat'=>null,'count'=>8,'perrow'=>4,'layout'=>'simple','card'=>'grid','order'=>'newest','more_url'=>''],
    ['key'=>'dynamic_blog_fb','title'=>'Facebook Ads','sub'=>'Hướng dẫn chạy quảng cáo Facebook','cat'=>$fb,'count'=>4,'perrow'=>4,'layout'=>'wave','card'=>'overlay','order'=>'newest','more_url'=>''],
    ['key'=>'dynamic_blog_gg','title'=>'Google Ads','sub'=>'Tối ưu quảng cáo Google','cat'=>$gg,'count'=>4,'perrow'=>4,'layout'=>'simple','card'=>'grid','order'=>'newest','more_url'=>''],
    ['key'=>'dynamic_blog_ai','title'=>'AI & Automation','sub'=>'Ứng dụng AI và tự động hóa','cat'=>$ai,'count'=>7,'perrow'=>4,'layout'=>'gradient','card'=>'magazine','order'=>'newest','more_url'=>''],
];

// Đảm bảo cột card_layout tồn tại
try { if (!$pdo->query("SHOW COLUMNS FROM dynamic_blocks LIKE 'card_layout'")->fetch()) { $pdo->exec("ALTER TABLE dynamic_blocks ADD COLUMN card_layout VARCHAR(20) NOT NULL DEFAULT 'grid' AFTER display_mode"); } } catch (Throwable $e) {}

$sort = 2; // hero = 1
foreach ($blocks as $b) {
    if ($b['cat'] === null && $b['key'] !== 'dynamic_blog_latest') { out("[SKIP] {$b['title']} (không có category)"); continue; }
    $showMore = ($b['key'] === 'dynamic_blog_latest') ? 0 : 1;
    $catStr = $b['cat'] ? (string)$b['cat'] : null;
    $dispMode = $b['card'] === 'slide' ? 'slide' : 'row';
    $ex = $pdo->prepare("SELECT id FROM dynamic_blocks WHERE block_key = ?");
    $ex->execute([$b['key']]);
    if ($ex->fetchColumn()) {
        $pdo->prepare("UPDATE dynamic_blocks SET title=?, subtitle=?, type='news', display_mode=?, card_layout=?, items_count=?, items_per_row=?, `order_by`=?, category_id=?, layout_style=?, status=1, show_view_more=?, view_more_text='Xem tất cả', view_more_url=? WHERE block_key=?")
            ->execute([$b['title'],$b['sub'],$dispMode,$b['card'],$b['count'],$b['perrow'],$b['order'],$catStr,$b['layout'],$showMore,$b['more_url'] ?: null,$b['key']]);
    } else {
        $pdo->prepare("INSERT INTO dynamic_blocks (block_key,title,subtitle,type,display_mode,card_layout,rows_count,items_per_row,items_count,`order_by`,category_id,layout_style,wave_top_color,wave_bottom_color,status,show_view_more,view_more_text,view_more_url,created_at)
            VALUES (?,?,?, 'news',?,?,1,?,?,?,?,?, '#f8f9fa','#ffffff',1,?, 'Xem tất cả', ?, NOW())")
            ->execute([$b['key'],$b['title'],$b['sub'],$dispMode,$b['card'],$b['perrow'],$b['count'],$b['order'],$catStr,$b['layout'],$showMore,$b['more_url'] ?: null]);
    }
    // upsert homepage_blocks
    $hb = $pdo->prepare("SELECT id FROM homepage_blocks WHERE block_key = ?");
    $hb->execute([$b['key']]);
    if ($hb->fetchColumn()) {
        $pdo->prepare("UPDATE homepage_blocks SET block_name=?, block_icon='bi-newspaper', layout_style=?, sort_order=?, is_visible=1 WHERE block_key=?")
            ->execute([$b['title'],$b['layout'],$sort,$b['key']]);
    } else {
        $pdo->prepare("INSERT INTO homepage_blocks (block_key,block_name,block_icon,layout_style,sort_order,is_visible) VALUES (?,?, 'bi-newspaper', ?, ?, 1)")
            ->execute([$b['key'],$b['title'],$b['layout'],$sort]);
    }
    out("[OK] Block: {$b['title']} (cat=" . ($catStr ?? 'all') . ", order=$sort)");
    $sort++;
}

// 5) Đảm bảo hard blocks: hero (order 1), faq (cuối). Ẩn news (trùng "Bài viết mới nhất").
$pdo->prepare("UPDATE homepage_blocks SET sort_order=1, is_visible=1, block_name='Banner chính' WHERE block_key='hero'")->execute();
$pdo->prepare("UPDATE homepage_blocks SET sort_order=?, is_visible=1, block_name='Câu hỏi thường gặp' WHERE block_key='faq'")->execute([$sort]);
$pdo->prepare("UPDATE homepage_blocks SET is_visible=0 WHERE block_key='news'")->execute();

// Mặc định trang chủ: KHÔNG dùng sidebar (full-width để khoe các style block).
// Có thể bật lại trong Admin → Cấu hình → Sidebar (trang Trang chủ).
try {
    $pdo->prepare("UPDATE page_sidebar_settings SET sidebar_mode='hide' WHERE page_key='home'")->execute();
    out("[OK] Trang chủ: sidebar = hide (full-width). Đổi trong Admin → Cấu hình.");
} catch (Throwable $e) {}

out("\n=== HOMEPAGE BLOCKS hiện tại ===");
foreach ($pdo->query("SELECT block_key, block_name, sort_order, is_visible FROM homepage_blocks ORDER BY sort_order") as $r) {
    out("  #{$r['sort_order']} {$r['block_key']} ({$r['block_name']}) visible={$r['is_visible']}");
}
out("=== HOÀN TẤT ===");
