<?php
// includes/blocks/post_links.php — Khối liên kết tham khảo ở cuối bài viết (trước CTA)
// và ngay dưới banner sidebar.
// Cấu hình trong Admin > Cấu hình > Footer:
//   post_links_title  : tiêu đề khối (mặc định "Tham khảo thêm")
//   post_links_items  : mỗi dòng "Tên hiển thị|https://url" (bỏ trống = không hiện)
if (!function_exists('get_setting')) {
    return;
}
$plinks_raw = trim((string) get_setting('post_links_items', ''));
if ($plinks_raw === '') {
    return;
}
$plinks = [];
foreach (preg_split('/\r\n|\r|\n/', $plinks_raw) as $plinks_line) {
    $plinks_line = trim((string) $plinks_line);
    if ($plinks_line === '') {
        continue;
    }
    $plinks_text = '';
    $plinks_url = '';
    if (strpos($plinks_line, '|') !== false) {
        [$plinks_text, $plinks_url] = array_map('trim', explode('|', $plinks_line, 2));
    } elseif (preg_match('#(https?://\S+)#i', $plinks_line, $plinks_m)) {
        $plinks_url = trim($plinks_m[1]);
        $plinks_text = trim(str_replace($plinks_url, '', $plinks_line), ' :|,-');
        if ($plinks_text === '') {
            $plinks_text = (string) (parse_url($plinks_url, PHP_URL_HOST) ?: $plinks_url);
        }
    }
    if ($plinks_text === '' || !preg_match('#^https?://#i', (string) $plinks_url)) {
        continue;
    }
    $plinks[] = ['text' => $plinks_text, 'url' => $plinks_url];
    if (count($plinks) >= 20) {
        break;
    }
}
if (empty($plinks)) {
    return;
}
$plinks_title = trim((string) get_setting('post_links_title', ''));
if ($plinks_title === '') {
    $plinks_title = 'Tham khảo thêm';
}
?>
<nav class="plinks" aria-label="<?php echo e($plinks_title); ?>">
    <div class="plinks__head">
        <span class="plinks__badge"><i class="bi bi-stars"></i></span>
        <span><?php echo e($plinks_title); ?></span>
    </div>
    <ul class="plinks__list">
        <?php foreach ($plinks as $plinks_item): ?>
            <li class="plinks__row">
                <a class="plinks__item" href="<?php echo e($plinks_item['url']); ?>" target="_blank" rel="noopener">
                    <span class="plinks__ico"><i class="bi bi-link-45deg"></i></span>
                    <span class="plinks__text"><?php echo e($plinks_item['text']); ?></span>
                    <span class="plinks__go"><i class="bi bi-arrow-right"></i></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
<style>
.plinks{margin:1.5rem 0;padding:1.1rem 1.1rem 1.2rem;border-radius:16px;background:linear-gradient(135deg,#eef4ff 0%,#f6f9ff 60%,#fff 100%);border:1px solid rgba(13,110,253,.2);box-shadow:0 10px 30px rgba(15,46,109,.08)}
.plinks__head{display:flex;align-items:center;gap:.55rem;font-weight:800;font-size:1.02rem;color:#0f2e6d;margin-bottom:.75rem;letter-spacing:-.01em}
.plinks__badge{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#0d6efd,#3b82f6);color:#fff;font-size:.95rem;box-shadow:0 4px 10px rgba(13,110,253,.35)}
.plinks__list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem}
.plinks__item{display:flex;align-items:center;gap:.7rem;padding:.72rem .9rem;border-radius:12px;background:#fff;border:1px solid rgba(13,110,253,.16);text-decoration:none;color:#12316b;font-weight:600;font-size:.92rem;transition:all .18s ease}
.plinks__ico{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;background:rgba(13,110,253,.1);color:#0d6efd;font-size:1.05rem;transition:all .18s ease}
.plinks__text{flex:1;min-width:0}
.plinks__go{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:rgba(13,110,253,.08);color:#0d6efd;font-size:.85rem;transition:all .18s ease}
.plinks__item:hover{border-color:#0d6efd;box-shadow:0 8px 20px rgba(13,110,253,.18);transform:translateX(3px);color:#0a2a5e}
.plinks__item:hover .plinks__ico{background:#0d6efd;color:#fff}
.plinks__item:hover .plinks__go{background:#0d6efd;color:#fff;transform:translateX(2px)}
</style>
