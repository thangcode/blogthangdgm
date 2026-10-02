<?php
// includes/blocks/post_links.php — Khối liên kết tham khảo ở cuối bài viết (trước CTA).
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
    <div class="plinks__head"><i class="bi bi-link-45deg"></i><?php echo e($plinks_title); ?></div>
    <div class="plinks__list">
        <?php foreach ($plinks as $plinks_item): ?>
            <a class="plinks__item" href="<?php echo e($plinks_item['url']); ?>" target="_blank" rel="noopener">
                <span class="plinks__text"><?php echo e($plinks_item['text']); ?></span>
                <i class="bi bi-arrow-up-right plinks__arrow"></i>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<style>
.plinks{margin:1.5rem 0;padding:1rem 1.1rem;border:1px solid rgba(13,110,253,.18);border-left:4px solid #0d6efd;border-radius:14px;background:linear-gradient(135deg,#f4f8ff 0%,#eef4ff 100%)}
.plinks__head{display:flex;align-items:center;gap:.45rem;font-weight:700;font-size:.95rem;color:#0f2e6d;margin-bottom:.6rem}
.plinks__head .bi{font-size:1.15rem;color:#0d6efd}
.plinks__list{display:flex;flex-wrap:wrap;gap:.5rem}
.plinks__item{display:inline-flex;align-items:center;gap:.35rem;padding:.42rem .8rem;border-radius:999px;background:#fff;border:1px solid rgba(13,110,253,.25);color:#0b3d91;text-decoration:none;font-size:.86rem;font-weight:600;line-height:1.2;transition:all .18s ease}
.plinks__item:hover{background:#0d6efd;border-color:#0d6efd;color:#fff;transform:translateY(-1px);box-shadow:0 6px 14px rgba(13,110,253,.22)}
.plinks__item:hover .plinks__arrow{color:#fff}
.plinks__arrow{font-size:.8rem;color:#0d6efd;transition:color .18s ease}
@media (max-width:575.98px){.plinks__list{flex-direction:column}.plinks__item{justify-content:space-between}}
</style>
