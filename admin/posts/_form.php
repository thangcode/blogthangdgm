<?php
// admin/posts/_form.php — Form dùng chung cho add.php & edit.php
// Biến vào: $post (array, rỗng nếu thêm mới), $all_categories, $error, $success
//           $post_cat_ids (array), $post_tags_csv (string), $is_edit (bool)
$is_edit = !empty($post['id']);
$v = function ($key, $default = '') use ($post) {
    return e($_POST[$key] ?? ($post[$key] ?? $default));
};
$post_cat_ids = $post_cat_ids ?? [];
$post_tags_csv = $post_tags_csv ?? '';
$cur_schema = $_POST['schema_type'] ?? ($post['schema_type'] ?? 'BlogPosting');
$cur_status = $_POST['status'] ?? ($post['status'] ?? 1);
?>
<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><?php echo $is_edit ? 'Sửa bài viết' : 'Thêm bài viết'; ?></h1>
        <div class="d-flex gap-2">
            <?php if ($is_edit && !empty($post['slug'])): ?>
                <a href="<?php echo e(postUrl($post['slug'], true)); ?>" target="_blank" class="btn btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i> Xem bài viết</a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Quay lại</a>
        </div>
    </div>

    <?php if (!empty($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><div><?php echo $success; ?></div></div><?php endif; ?>

    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
        <div class="row">
            <div class="col-md-8">
                <div class="mb-3">
                    <label class="form-label">Tiêu đề <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" required
                        value="<?php echo $v('title'); ?>"
                        onkeyup="if(document.getElementById('slug').dataset.touched!=='1'){document.getElementById('slug').value=createSlug(this.value)}">
                </div>
                <div class="mb-3">
                    <label class="form-label">Slug</label>
                    <input type="text" class="form-control" id="slug" name="slug" value="<?php echo $v('slug'); ?>" oninput="this.dataset.touched='1'">
                    <?php if ($is_edit): ?><div class="form-text">Đổi slug sẽ tự tạo redirect 301 từ slug cũ.</div><?php endif; ?>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-1">
                        <label class="form-label mb-0">Nội dung</label>
                        <?php if ($is_edit): ?>
                        <div class="d-flex gap-1 flex-wrap">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAiRewriteContent" data-id="<?php echo (int) $post['id']; ?>"><i class="bi bi-pencil-square me-1"></i>Viết bài SEO+GEO</button>
                            <button type="button" class="btn btn-sm btn-success" id="btnAiRewriteSeo" data-id="<?php echo (int) $post['id']; ?>"><i class="bi bi-stars me-1"></i>Viết bài + Tự động SEO</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <textarea class="form-control" id="content" name="content" rows="12"><?php echo e($_POST['content'] ?? ($post['content'] ?? '')); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tóm tắt</label>
                    <textarea class="form-control" name="summary" rows="3"><?php echo e($_POST['summary'] ?? ($post['summary'] ?? '')); ?></textarea>
                </div>
                <?php include '../includes/seo-fields.php'; ?>
            </div>

            <div class="col-md-4">
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-check-lg me-1"></i> Lưu bài viết</button>
                </div>

                <div class="card mb-3"><div class="card-body">
                    <label class="form-label fw-bold">Chuyên mục</label>
                    <div style="max-height:240px;overflow:auto;">
                        <?php
                        // Hiển thị chuyên mục dạng cây cha-con
                        $catChildren = [];
                        foreach ($all_categories as $c) { $catChildren[(int) ($c['parent_id'] ?? 0)][] = $c; }
                        $renderCatTree = function ($parentId, $level) use (&$renderCatTree, $catChildren, $post_cat_ids) {
                            if (empty($catChildren[$parentId])) return;
                            foreach ($catChildren[$parentId] as $c) {
                                $cid = (int) $c['id'];
                                $ml = $level * 18;
                                echo '<div class="form-check" style="margin-left:' . $ml . 'px">';
                                echo '<input class="form-check-input" type="checkbox" name="categories[]" value="' . $cid . '" id="cat' . $cid . '" ' . (in_array($cid, $post_cat_ids, true) ? 'checked' : '') . '>';
                                echo '<label class="form-check-label" for="cat' . $cid . '">' . ($level > 0 ? '<span class="text-muted">&#9492;&#9472; </span>' : '') . e($c['name']) . '</label>';
                                echo '</div>';
                                $renderCatTree($cid, $level + 1);
                            }
                        };
                        $renderCatTree(0, 0);
                        ?>
                        <?php if (empty($all_categories)): ?><div class="text-muted small">Chưa có chuyên mục. <a href="../categories/">Tạo mới</a></div><?php endif; ?>
                    </div>
                    <div class="form-text">Không chọn = bài viết ở dạng <strong>Chưa phân loại</strong>.</div>
                </div></div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Tags</label>
                    <div data-tag-input data-placeholder="Nhập tag rồi nhấn Enter...">
                        <input type="hidden" name="tags" value="<?php echo e($_POST['tags'] ?? $post_tags_csv); ?>">
                    </div>
                    <div class="form-text">Nhập từ khóa rồi nhấn <kbd>Enter</kbd> hoặc <kbd>,</kbd> để thêm tag. Click <kbd>×</kbd> để xóa.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Tác giả</label>
                    <?php
                        $cur_author = $_POST['author_name'] ?? ($post['author_name'] ?? '');
                        if (trim((string) $cur_author) === '') $cur_author = 'Admin';
                        $author_names = [];
                        foreach (($all_users ?? []) as $u) {
                            $nm = trim((string) (($u['full_name'] ?? '') ?: ($u['username'] ?? '')));
                            if ($nm !== '') $author_names[$nm] = $nm;
                        }
                        // Giữ tác giả hiện tại nếu chưa có trong danh sách người dùng (vd dữ liệu cũ từ WordPress)
                        if (!isset($author_names[$cur_author])) $author_names[$cur_author] = $cur_author;
                    ?>
                    <select class="form-select" name="author_name">
                        <?php foreach ($author_names as $val => $label): ?>
                            <option value="<?php echo e($val); ?>" <?php echo $cur_author === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Lấy từ danh sách <a href="../users/">Người dùng</a> (giống WordPress). Đổi tên hiển thị trong phần Người dùng &rarr; Họ tên.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Ảnh đại diện</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="thumbnail" name="thumbnail" readonly placeholder="Đường dẫn ảnh..." value="<?php echo $v('thumbnail'); ?>">
                        <button type="button" class="btn btn-primary init-media-selector" data-input="thumbnail" data-preview="thumbnail-preview"><i class="bi bi-images"></i></button>
                    </div>
                    <?php $tp = $_POST['thumbnail'] ?? ($post['thumbnail'] ?? ''); $tp_src = $tp === '' ? '' : ((strpos($tp,'http')===0||strpos($tp,'//')===0)?$tp:BASE_URL.$tp); ?>
                    <div class="preview-area <?php echo $tp === '' ? 'd-none' : ''; ?>">
                        <img src="<?php echo e($tp_src); ?>" id="thumbnail-preview" class="img-fluid rounded shadow-sm" style="max-height:180px;">
                    </div>
                    <input type="text" class="form-control mt-2" name="thumbnail_alt" placeholder="Alt ảnh (SEO)" value="<?php echo $v('thumbnail_alt'); ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Tài liệu đính kèm</label>
                    <?php if ($is_edit && !empty($post['document_path'])): ?>
                        <div class="alert alert-info py-2 small mb-2">
                            <i class="bi bi-file-earmark-text"></i> <?php echo e($post['document_name'] ?? basename($post['document_path'])); ?>
                            <label class="d-block mt-1"><input type="checkbox" name="remove_document" value="1"> Gỡ tài liệu</label>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
                    <div class="form-text">pdf, doc, docx, xls, xlsx, ppt, pptx, zip. Có tài liệu sẽ hiện form nhận tài liệu trên bài.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Loại schema (GEO)</label>
                    <select class="form-select" name="schema_type">
                        <?php foreach (['BlogPosting'=>'BlogPosting','Article'=>'Article','NewsArticle'=>'NewsArticle'] as $sv=>$sl): ?>
                            <option value="<?php echo $sv; ?>" <?php echo $cur_schema===$sv?'selected':''; ?>><?php echo $sl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Trạng thái</label>
                    <select class="form-select" name="status">
                        <option value="1" <?php echo (int)$cur_status===1?'selected':''; ?>>✅ Công khai</option>
                        <option value="0" <?php echo (int)$cur_status===0?'selected':''; ?>>📝 Nháp</option>
                        <option value="2" <?php echo (int)$cur_status===2?'selected':''; ?>>🚫 Ẩn</option>
                    </select>
                </div>

                <?php $cur_sb_mode = $_POST['sidebar_mode'] ?? ($post['sidebar_mode'] ?? 'default');
                      $cur_sb_pos  = $_POST['sidebar_position'] ?? ($post['sidebar_position'] ?? 'default'); ?>
                <div class="card mb-3"><div class="card-body">
                    <label class="form-label fw-bold"><i class="bi bi-layout-sidebar-inset-reverse me-1"></i> Thanh bên (Sidebar)</label>
                    <select class="form-select mb-2" name="sidebar_mode">
                        <option value="default" <?php echo $cur_sb_mode==='default'?'selected':''; ?>>Theo cài đặt chung</option>
                        <option value="show" <?php echo $cur_sb_mode==='show'?'selected':''; ?>>Bật sidebar</option>
                        <option value="hide" <?php echo $cur_sb_mode==='hide'?'selected':''; ?>>Tắt sidebar</option>
                    </select>
                    <select class="form-select" name="sidebar_position">
                        <option value="default" <?php echo $cur_sb_pos==='default'?'selected':''; ?>>Vị trí theo cài đặt chung</option>
                        <option value="right" <?php echo $cur_sb_pos==='right'?'selected':''; ?>>Bên phải</option>
                        <option value="left" <?php echo $cur_sb_pos==='left'?'selected':''; ?>>Bên trái</option>
                    </select>
                    <div class="form-text">Ghi đè riêng cho bài này. Mặc định dùng chung toàn site.</div>
                </div></div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script src="<?php echo BASE_URL; ?>assets/js/tag-input.js?v=<?php echo @filemtime(__DIR__ . '/../../assets/js/tag-input.js') ?: time(); ?>"></script>
<?php if ($is_edit): ?>
<script>
(function () {
    const FORM_CSRF = <?php echo json_encode(generate_csrf_token()); ?>;
    function doRewrite(btn, withSeo) {
        const msg = withSeo
            ? 'AI sẽ viết lại NỘI DUNG (chuẩn SEO+GEO), tạo SEO + tags và LƯU NGAY vào bài. Tiếp tục?'
            : 'AI sẽ viết lại NỘI DUNG bài (chuẩn SEO+GEO, bắt đầu từ H2, giữ video nếu có) và LƯU NGAY. Tiếp tục?';
        if (!confirm(msg)) return;
        const old = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (withSeo ? 'AI đang viết bài + SEO...' : 'AI đang viết...');
        const body = new URLSearchParams({ action: withSeo ? 'all' : 'rewrite', id: btn.dataset.id, save: '1', csrf_token: FORM_CSRF });
        fetch('../ajax/post-ai.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.content) {
                    if (window.tinymce && tinymce.get('content')) tinymce.get('content').setContent(d.content);
                    else document.getElementById('content').value = d.content;
                    const sumEl = document.querySelector('textarea[name="summary"]');
                    if (sumEl && !sumEl.value.trim() && d.description) sumEl.value = d.description;
                    if (d.thumbnail) {
                        const thumbEl = document.getElementById('thumbnail');
                        if (thumbEl) {
                            thumbEl.value = d.thumbnail;
                            const prev = document.getElementById('thumbnail-preview');
                            if (prev) {
                                prev.src = (/^https?:\/\//.test(d.thumbnail) ? d.thumbnail : (window.BASE_URL || '') + d.thumbnail);
                                const wrap = prev.closest('.preview-area');
                                if (wrap) wrap.classList.remove('d-none');
                            }
                        }
                    }
                    if (withSeo) {
                        const t = document.getElementById('metaTitle');
                        if (t && d.meta_title) { t.value = d.meta_title; t.dispatchEvent(new Event('input')); }
                        const ds = document.getElementById('metaDescription');
                        if (ds && d.meta_description) { ds.value = d.meta_description; ds.dispatchEvent(new Event('input')); }
                        if (typeof setTagInputValues === 'function') {
                            if (d.focus_keyword) setTagInputValues('focusKeywordHidden', [d.focus_keyword]);
                            if (d.meta_keywords) setTagInputValues('metaKeywordsHidden', String(d.meta_keywords).split(',').map(s => s.trim()).filter(Boolean));
                        }
                        if (d.tags) {
                            const th = document.querySelector('[data-tag-input] input[name="tags"]');
                            if (th) th.dispatchEvent(new CustomEvent('tags:set', { detail: { values: String(d.tags).split(',').map(s => s.trim()).filter(Boolean) } }));
                        }
                        alert('Đã viết bài + SEO + tags và LƯU. Bạn có thể chỉnh thêm rồi bấm "Lưu bài viết".');
                    } else {
                        alert('Đã viết nội dung mới và LƯU. Bạn có thể chỉnh thêm rồi bấm "Lưu bài viết".');
                    }
                } else {
                    alert(d.message || 'Không thể viết lại nội dung.');
                }
            })
            .catch(() => alert('Lỗi kết nối khi gọi AI.'))
            .finally(() => { btn.disabled = false; btn.innerHTML = old; });
    }
    const b1 = document.getElementById('btnAiRewriteContent');
    const b2 = document.getElementById('btnAiRewriteSeo');
    if (b1) b1.addEventListener('click', () => doRewrite(b1, false));
    if (b2) b2.addEventListener('click', () => doRewrite(b2, true));
})();
</script>
<?php endif; ?>
<script>
window.addEventListener('load', function () {
    tinymce.init({
        selector: '#content', height: 460, menubar: 'edit view insert format table',
        plugins: 'advlist autolink lists link image media table code fullscreen preview searchreplace visualblocks charmap help wordcount',
        toolbar: 'undo redo | blocks | bold italic underline forecolor | alignleft aligncenter alignright | bullist numlist outdent indent | blockquote link image media table | removeformat code fullscreen preview',
        branding: false, promotion: false, license_key: 'gpl', convert_urls: false, toolbar_mode: 'wrap',
        content_style: 'body{font-family:Inter,sans-serif;font-size:16px}img{max-width:100%;height:auto}',
        images_upload_handler: (blobInfo, progress) => new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '../ajax/summernote-upload.php');
            xhr.setRequestHeader('X-CSRF-Token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
            xhr.onload = () => {
                if (xhr.status < 200 || xhr.status >= 300) { reject('HTTP ' + xhr.status); return; }
                let json = null; try { json = JSON.parse(xhr.responseText); } catch (e) { reject('parse'); return; }
                if (!json || !json.success || !json.url) { reject((json && json.message) || 'fail'); return; }
                resolve(json.url);
            };
            xhr.onerror = () => reject('upload failed');
            const fd = new FormData(); fd.append('file', blobInfo.blob(), blobInfo.filename()); xhr.send(fd);
        })
    });
});
</script>
