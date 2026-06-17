<?php
$f = 'includes/header.php';
$c = file_get_contents($f);

// 1. Update LCP Logic
$s1 = <<<EOT
    // Preload LCP image: first active banner for faster mobile rendering
    // Chỉ preload nếu đang ở trang chủ và block slider (hero) đang được bật
    \$is_homepage = isset(\$page_key) && \$page_key === 'home';
    \$is_hero_active = false;
    
    if (\$is_homepage) {
        try {
            \$hero_stmt = \$pdo->query("SELECT 1 FROM homepage_blocks WHERE block_key = 'hero' AND is_visible = 1 LIMIT 1");
            if (\$hero_stmt && \$hero_stmt->fetchColumn()) {
                \$is_hero_active = true;
            }
        } catch (Exception \$e) {
            // Fallback trong trường hợp bảng chưa tồn tại hoặc lỗi
            \$is_hero_active = true; 
        }
    }

    if (\$is_hero_active):
        try {
            \$lcp_stmt = \$pdo->query("SELECT image_path, mobile_image_path FROM banners WHERE status = 1 ORDER BY sort_order ASC, id DESC LIMIT 1");
            \$lcp_banner = \$lcp_stmt ? \$lcp_stmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Exception \$e) {
            \$lcp_banner = null;
        }
        if (\$lcp_banner && !empty(\$lcp_banner['image_path'])):
            \$lcp_desktop = get_image_url(\$lcp_banner['image_path'], 'banner');
            \$lcp_mobile = !empty(\$lcp_banner['mobile_image_path']) ? get_image_url(\$lcp_banner['mobile_image_path'], 'banner') : null;
    ?>
    <?php if (\$lcp_mobile): ?>
    <link rel="preload" as="image" href="<?php echo \$lcp_mobile; ?>" media="(max-width: 768px)">
    <link rel="preload" as="image" href="<?php echo \$lcp_desktop; ?>" media="(min-width: 769px)">
    <?php else: ?>
    <link rel="preload" as="image" href="<?php echo \$lcp_desktop; ?>">
    <?php endif; ?>
    <?php 
        endif;
    endif; 
EOT;

$r1 = <<<EOT
    // Preload LCP image: first active banner for faster mobile rendering
    // Hoặc ảnh LCP do trang con truyền vào (ví dụ: post.php truyền ảnh đại diện)
    \$is_homepage = isset(\$page_key) && \$page_key === 'home';
    \$is_hero_active = false;
    
    if (\$is_homepage) {
        try {
            \$hero_stmt = \$pdo->query("SELECT 1 FROM homepage_blocks WHERE block_key = 'hero' AND is_visible = 1 LIMIT 1");
            if (\$hero_stmt && \$hero_stmt->fetchColumn()) {
                \$is_hero_active = true;
            }
        } catch (Exception \$e) {
            \$is_hero_active = true; 
        }
    }

    if (!isset(\$lcp_desktop) && \$is_hero_active):
        try {
            \$lcp_stmt = \$pdo->query("SELECT image_path, mobile_image_path FROM banners WHERE status = 1 ORDER BY sort_order ASC, id DESC LIMIT 1");
            \$lcp_banner = \$lcp_stmt ? \$lcp_stmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Exception \$e) {
            \$lcp_banner = null;
        }
        if (\$lcp_banner && !empty(\$lcp_banner['image_path'])):
            \$lcp_desktop = get_image_url(\$lcp_banner['image_path'], 'banner');
            \$lcp_mobile = !empty(\$lcp_banner['mobile_image_path']) ? get_image_url(\$lcp_banner['mobile_image_path'], 'banner') : null;
        endif;
    endif; 
    
    if (!empty(\$lcp_desktop)):
    ?>
    <?php if (!empty(\$lcp_mobile)): ?>
    <link rel="preload" as="image" href="<?php echo \$lcp_mobile; ?>" media="(max-width: 768px)">
    <link rel="preload" as="image" href="<?php echo \$lcp_desktop; ?>" media="(min-width: 769px)">
    <?php else: ?>
    <link rel="preload" as="image" href="<?php echo \$lcp_desktop; ?>">
    <?php endif; ?>
    <?php 
    endif; 
EOT;
$c = str_replace($s1, $r1, $c);

// 2. Bootstrap preload
$s2 = <<<EOT
    <!-- CDN preconnect (giảm latency DNS + TLS cho Bootstrap) -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <!-- Bootstrap CSS (render-blocking — cần cho grid, form, layout) -->
EOT;
$r2 = <<<EOT
    <!-- CDN preconnect (giảm latency DNS + TLS cho Bootstrap) -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Preload Bootstrap CSS -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style">
    <!-- Bootstrap CSS (render-blocking — cần cho grid, form, layout) -->
EOT;
$c = str_replace($s2, $r2, $c);

file_put_contents($f, $c);
echo "OK header.php\n";
