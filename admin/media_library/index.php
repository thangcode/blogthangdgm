<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$current_page = 'media';
require_admin_login();
ensure_media_library_table($pdo);

// Fetch available months for filter
$months = $pdo->query("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') as m FROM media_library ORDER BY m DESC")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2 mb-0"><i class="bi bi-folder2-open me-2 text-primary"></i>Thư viện Media</h1>
        <div class="small text-muted">Quản lý file toàn hệ thống</div>
    </div>

    <!-- Upload Area -->
    <div class="card mb-3">
        <div class="card-body">
            <input type="hidden" id="csrfToken" value="<?php echo e(generate_csrf_token()); ?>">
            <div id="dropZone" class="media-dropzone rounded-3 border border-2 border-dashed p-4 text-center">
                <i class="bi bi-cloud-arrow-up fs-2 text-primary"></i>
                <div class="mt-2 text-muted small">Kéo thả ảnh hoặc bấm để chọn</div>
                <input type="file" id="mediaInput" class="d-none" accept="image/jpeg,image/png,image/gif,image/webp"
                    multiple>
                <button type="button" id="btnSelectFiles" class="btn btn-sm btn-outline-primary mt-2 rounded-pill px-3">
                    Chọn file
                </button>
            </div>
            <div id="uploadResult" class="mt-2 d-none"></div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom-0 py-3">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <!-- Search & Filter Toolbar -->
                <div class="d-flex gap-2 flex-grow-1" style="max-width: 600px;">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i
                                class="bi bi-search text-muted"></i></span>
                        <input type="text" id="searchInput" class="form-control bg-light border-start-0 ps-0"
                            placeholder="Tìm tên file...">
                    </div>
                    <select id="filterDate" class="form-select form-select-sm bg-light"
                        style="width: auto; max-width: 150px;">
                        <option value="">Tất cả thời gian</option>
                        <?php foreach ($months as $m): ?>
                            <option value="<?php echo $m; ?>"><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Right Side: Bulk & Meta -->
                <div class="d-flex gap-2 align-items-center">
                    <div id="bulkActions"
                        class="d-none animate__animated animate__fadeIn d-flex align-items-center gap-2">
                        <span class="badge bg-primary rounded-pill"><span id="selectedCount">0</span> đã chọn</span>
                        <button type="button" id="btnBulkDelete" class="btn btn-sm btn-danger"
                            title="Xóa các file đã chọn">
                            <i class="bi bi-trash"></i>
                        </button>
                        <button type="button" id="btnDeselectAll" class="btn btn-sm btn-light border px-2"
                            title="Hủy chọn">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <div class="vr mx-2"></div>
                    <button type="button" id="btnCleanupOrphans" class="btn btn-sm btn-outline-warning" title="Dọn dẹp record mồ côi (file không tồn tại trên server)">
                        <i class="bi bi-magic me-1"></i>Dọn rác
                    </button>
                    <div class="vr mx-2"></div>
                    <div class="text-muted small text-nowrap" id="mediaMeta">...</div>
                </div>
            </div>
        </div>

        <div class="card-body p-2">
            <div id="mediaGrid" class="row g-2"></div> <!-- g-2 for tighter spacing -->

            <div id="emptyState" class="text-center text-muted py-5 d-none">
                <i class="bi bi-image fs-1 d-block mb-2"></i>
                <p class="mb-0 small">Chưa có media nào.</p>
            </div>

            <div class="d-flex justify-content-center align-items-center gap-2 mt-3 pt-2 border-top flex-wrap">
                <nav aria-label="Phân trang media">
                    <ul class="pagination pagination-sm mb-0" id="pageNumbers"></ul>
                </nav>
                <div class="small text-muted ms-2" id="pageInfo">1/1</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="mediaPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="mediaPreviewTitle">Xem ảnh</h5>
                    <div class="small text-muted" id="mediaPreviewMeta"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body text-center bg-light">
                <img id="mediaPreviewImage" src="" alt="" class="img-fluid rounded shadow-sm">
            </div>
        </div>
    </div>
</div>

<style>
    .media-dropzone {
        background: #f8fafc;
        border-color: #e2e8f0 !important;
        transition: all 0.2s ease;
        padding: 1.5rem !important;
        cursor: pointer;
    }

    .media-dropzone:hover,
    .media-dropzone.active {
        border-color: #3b82f6 !important;
        background: #eff6ff;
    }

    .media-card {
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        overflow: hidden;
        background: #fff;
        position: relative;
        cursor: pointer;
        transition: transform 0.1s, box-shadow 0.1s;
    }

    .media-card:hover {
        z-index: 10;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        border-color: #cbd5e1;
    }

    .media-card.selected {
        border-color: #3b82f6;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
    }

    .media-thumb-wrap {
        aspect-ratio: 1 / 1;
        overflow: hidden;
        background: #f1f5f9;
        position: relative;
    }

    .media-format-badge {
        position: absolute;
        top: 6px;
        left: 6px;
        z-index: 15;
        padding: 3px 7px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.86);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        pointer-events: none;
    }

    .media-thumb-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .media-thumb-wrap img.is-loading {
        opacity: 0.65;
    }

    .media-thumb-wrap img.is-error {
        opacity: 0;
    }

    .media-thumb-fallback {
        position: absolute;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 8px;
        text-align: center;
        font-size: 10px;
        line-height: 1.4;
        color: #64748b;
        background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
    }

    .media-thumb-wrap.has-error .media-thumb-fallback {
        display: flex;
    }

    .media-check {
        position: absolute;
        top: 4px;
        left: 4px;
        z-index: 20;
        transform: scale(0.9);
        opacity: 0;
        transition: opacity 0.1s;
        cursor: pointer;
    }

    .media-card:hover .media-check,
    .media-card.selected .media-check {
        opacity: 1;
    }

    /* Overlay info on hover */
    .media-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, 0.75);
        color: #fff;
        padding: 4px 6px;
        font-size: 10px;
        transform: translateY(100%);
        transition: transform 0.15s;
        pointer-events: none;
    }

    .media-card:hover .media-overlay {
        transform: translateY(0);
    }

    /* Action buttons overlay */
    .media-actions-overlay {
        position: absolute;
        top: 0;
        right: 0;
        padding: 4px;
        opacity: 0;
        transition: opacity 0.1s;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .media-card:hover .media-actions-overlay {
        opacity: 1;
    }

    .btn-icon-overlay {
        width: 20px;
        height: 20px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        color: #475569;
        font-size: 10px;
        cursor: pointer;
    }

    .btn-icon-overlay:hover {
        background: #3b82f6;
        color: #fff;
    }

    #pageNumbers .page-link {
        min-width: 34px;
        text-align: center;
    }

    #mediaPreviewImage {
        max-height: 75vh;
        object-fit: contain;
    }
</style>

<script>
    (function () {
        const csrfToken = document.getElementById('csrfToken').value;
        const dropZone = document.getElementById('dropZone');
        const mediaInput = document.getElementById('mediaInput');
        const btnSelectFiles = document.getElementById('btnSelectFiles');
        const uploadResult = document.getElementById('uploadResult');

        const searchInput = document.getElementById('searchInput');
        const filterDate = document.getElementById('filterDate');

        const mediaGrid = document.getElementById('mediaGrid');
        const emptyState = document.getElementById('emptyState');
        const mediaMeta = document.getElementById('mediaMeta');
        const pageNumbers = document.getElementById('pageNumbers');
        const pageInfo = document.getElementById('pageInfo');
        const previewModalEl = document.getElementById('mediaPreviewModal');
        const previewImage = document.getElementById('mediaPreviewImage');
        const previewTitle = document.getElementById('mediaPreviewTitle');
        const previewMeta = document.getElementById('mediaPreviewMeta');

        const bulkActions = document.getElementById('bulkActions');
        const btnBulkDelete = document.getElementById('btnBulkDelete');
        const btnDeselectAll = document.getElementById('btnDeselectAll');
        const selectedCountSpan = document.getElementById('selectedCount');

        let currentPage = 1;
        let totalPages = 1;
        let currentQuery = '';
        let currentDate = '';
        const perPage = 48; // Increased per page since items are smaller

        let selectedIds = new Set();

        function esc(s) {
            return String(s || '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(0) + ' MB';
        }

        function renderPagination() {
            pageNumbers.innerHTML = '';
            if (totalPages <= 1) return;

            const addBtn = (label, page, opts = {}) => {
                const { disabled = false, active = false, title = '' } = opts;
                const li = document.createElement('li');
                li.className = `page-item${active ? ' active' : ''}${disabled ? ' disabled' : ''}`;
                const attrs = (disabled ? '' : ` data-page="${page}"`) + (title ? ` title="${title}" aria-label="${title}"` : '');
                li.innerHTML = `<button type="button" class="page-link"${attrs}>${label}</button>`;
                pageNumbers.appendChild(li);
            };

            // « Đầu  ‹ Trước
            addBtn('&laquo;', 1, { disabled: currentPage === 1, title: 'Trang đầu' });
            addBtn('&lsaquo;', currentPage - 1, { disabled: currentPage === 1, title: 'Trang trước' });

            // Cửa sổ số trang (luôn kèm trang 1 / trang cuối + dấu …)
            let start = Math.max(1, currentPage - 2);
            let end = Math.min(totalPages, currentPage + 2);
            if (currentPage <= 3) end = Math.min(totalPages, 5);
            if (currentPage >= totalPages - 2) start = Math.max(1, totalPages - 4);

            if (start > 1) addBtn('1', 1);
            if (start > 2) addBtn('&hellip;', 0, { disabled: true });

            for (let i = start; i <= end; i++) {
                addBtn(String(i), i, { active: i === currentPage });
            }

            if (end < totalPages - 1) addBtn('&hellip;', 0, { disabled: true });
            if (end < totalPages) addBtn(String(totalPages), totalPages);

            // Sau › Cuối »
            addBtn('&rsaquo;', currentPage + 1, { disabled: currentPage === totalPages, title: 'Trang sau' });
            addBtn('&raquo;', totalPages, { disabled: currentPage === totalPages, title: 'Trang cuối' });
        }

        function openPreview(item) {
            if (!previewModalEl || !window.bootstrap || typeof bootstrap.Modal !== 'function') {
                if (item.url) {
                    window.open(item.url, '_blank', 'noopener');
                }
                return;
            }
            previewImage.src = item.url || '';
            previewImage.alt = item.original_name || 'Media preview';
            previewTitle.textContent = item.original_name || 'Xem ảnh';
            previewMeta.textContent = [
                String(item.extension || '').toUpperCase(),
                item.file_size_text || '',
                item.dimension_text || ''
            ].filter(Boolean).join(' • ');
            const previewModal = bootstrap.Modal.getOrCreateInstance(previewModalEl);
            previewModal.show();
        }

        // --- Selection Logic ---
        function toggleSelection(id) {
            if (selectedIds.has(id)) {
                selectedIds.delete(id);
            } else {
                selectedIds.add(id);
            }
            updateUI();
        }

        function clearSelection() {
            selectedIds.clear();
            updateUI();
        }

        function updateUI() {
            document.querySelectorAll('.media-card').forEach(card => {
                const id = Number(card.dataset.id);
                const check = card.querySelector('.media-check');
                if (selectedIds.has(id)) {
                    card.classList.add('selected');
                    if (check) check.checked = true;
                } else {
                    card.classList.remove('selected');
                    if (check) check.checked = false;
                }
            });

            if (selectedIds.size > 0) {
                bulkActions.classList.remove('d-none');
                selectedCountSpan.textContent = selectedIds.size;
            } else {
                bulkActions.classList.add('d-none');
            }
        }

        async function uploadFiles(files) {
            if (!files || files.length === 0) return;
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            Array.from(files).forEach(file => formData.append('files[]', file));

            try {
                uploadResult.innerHTML = '<div class="text-primary small"><span class="spinner-border spinner-border-sm"></span> Đang upload...</div>';
                uploadResult.classList.remove('d-none');

                const res = await fetch('../ajax/media-upload.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (!res.ok || !data.success) throw new Error(data.message || 'Upload thất bại.');

                const uploaded = Array.isArray(data.uploaded) ? data.uploaded : [];
                const failed = Array.isArray(data.failed) ? data.failed : [];

                const converted = uploaded.filter(item => item?.conversion?.converted === true);
                const notConverted = uploaded.filter(item => item?.conversion?.attempted === true && item?.conversion?.converted !== true);

                let html = '<div class="small">';
                html += `<div class="text-success">Upload thành công: ${uploaded.length} file.</div>`;

                if (converted.length > 0) {
                    html += `<div class="text-success">Đã chuyển WebP: ${converted.length} file.</div>`;
                }

                if (notConverted.length > 0) {
                    html += `<div class="text-warning">Không chuyển được WebP: ${notConverted.length} file.</div>`;
                    html += '<ul class="mb-1 ps-3">';
                    notConverted.slice(0, 8).forEach(item => {
                        const reason = item?.conversion?.message || item?.conversion?.reason || 'unknown';
                        html += `<li>${esc(item.original_name)}: ${esc(reason)}</li>`;
                    });
                    html += '</ul>';
                }

                if (failed.length > 0) {
                    html += `<div class="text-danger">Upload thất bại: ${failed.length} file.</div>`;
                    html += '<ul class="mb-0 ps-3">';
                    failed.slice(0, 8).forEach(f => {
                        html += `<li>${esc(f.name)}: ${esc(f.message)}</li>`;
                    });
                    html += '</ul>';
                }

                html += '</div>';
                uploadResult.innerHTML = html;
                uploadResult.classList.remove('d-none');

                await loadMedia(1, currentQuery, currentDate);
            } catch (err) {
                uploadResult.innerHTML = `<div class="text-danger small">${esc(err.message)}</div>`;
                uploadResult.classList.remove('d-none');
            }
        }

        function renderItems(items) {
            mediaGrid.innerHTML = '';
            if (!items || items.length === 0) {
                emptyState.classList.remove('d-none');
                return;
            }
            emptyState.classList.add('d-none');

            for (const item of items) {
                const isSelected = selectedIds.has(item.id);
                const col = document.createElement('div');
                col.className = 'col-4 col-sm-3 col-md-2 col-xl-1';
                const loadMode = mediaGrid.children.length < 12 ? 'eager' : 'lazy';
                col.innerHTML = `
                    <div class="media-card h-100 ${isSelected ? 'selected' : ''}" data-id="${item.id}" data-file-path="${esc(item.file_path)}" data-url="${esc(item.url)}" data-name="${esc(item.original_name)}" data-extension="${esc(item.extension || '')}" data-size="${Number(item.file_size || 0)}" data-width="${item.width || ''}" data-height="${item.height || ''}">
                        <input type="checkbox" class="form-check-input media-check" ${isSelected ? 'checked' : ''}>
                        <div class="media-thumb-wrap">
                            <div class="media-format-badge">${esc(item.extension || '')}</div>
                            <img src="${esc(item.url)}" data-media-src="${esc(item.url)}" loading="${loadMode}" decoding="async" fetchpriority="${loadMode === 'eager' ? 'high' : 'low'}" alt="${esc(item.original_name)}" class="is-loading">
                            <div class="media-thumb-fallback">Khong tai duoc anh</div>
                        </div>
                        <div class="media-overlay">
                            <div class="text-truncate">${esc(item.original_name)}</div>
                            <div style="opacity:0.8">${formatSize(item.file_size)}</div>
                        </div>
                        <div class="media-actions-overlay">
                            <button type="button" class="btn-icon-overlay btn-copy-url" title="Copy URL"><i class="bi bi-link"></i></button>
                            <button type="button" class="btn-icon-overlay btn-copy-path" title="Copy Path"><i class="bi bi-code"></i></button>
                        </div>
                    </div>
                `;
                mediaGrid.appendChild(col);
            }

            bindImageLifecycle();
        }

        function bindImageLifecycle() {
            mediaGrid.querySelectorAll('.media-thumb-wrap img').forEach((img) => {
                if (img.dataset.lifecycleBound === '1') return;
                img.dataset.lifecycleBound = '1';

                const wrap = img.closest('.media-thumb-wrap');

                img.addEventListener('load', () => {
                    img.classList.remove('is-loading', 'is-error');
                    if (wrap) wrap.classList.remove('has-error');
                });

                img.addEventListener('error', () => {
                    const retryCount = Number(img.dataset.retryCount || '0');
                    const originalSrc = img.dataset.mediaSrc || img.getAttribute('src') || '';

                    if (retryCount < 1 && originalSrc) {
                        img.dataset.retryCount = String(retryCount + 1);
                        img.classList.add('is-loading');
                        img.src = originalSrc + (originalSrc.includes('?') ? '&' : '?') + '_media_retry=' + Date.now();
                        return;
                    }

                    img.classList.remove('is-loading');
                    img.classList.add('is-error');
                    if (wrap) wrap.classList.add('has-error');
                });

                if (img.complete) {
                    if (img.naturalWidth > 0) {
                        img.dispatchEvent(new Event('load'));
                    } else {
                        img.dispatchEvent(new Event('error'));
                    }
                }
            });
        }

        async function loadMedia(page = 1, q = '', date = '') {
            currentPage = page;
            currentQuery = q;
            currentDate = date;

            mediaMeta.innerHTML = `<span class="spinner-border spinner-border-sm text-secondary"></span>`;

            const url = `../ajax/media-list.php?page=${encodeURIComponent(page)}&per_page=${encodeURIComponent(perPage)}&q=${encodeURIComponent(q)}&filter_date=${encodeURIComponent(date)}`;
            try {
                const res = await fetch(url, { method: 'GET' });
                const data = await res.json();
                if (!res.ok || !data.success) throw new Error(data.message);

                totalPages = Math.max(1, Number(data.total_pages || 1));
                renderItems(data.items || []);
                mediaMeta.textContent = `${Number(data.total || 0)} file`;
                pageInfo.textContent = `${currentPage}/${totalPages}`;
                renderPagination();
                updateUI();
            } catch (err) {
                mediaMeta.textContent = 'Lỗi';
            }
        }

        async function performBulkDelete() {
            if (selectedIds.size === 0) return;
            if (!confirm(`Xóa vĩnh viễn ${selectedIds.size} file đã chọn?`)) return;

            const ids = Array.from(selectedIds);
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            ids.forEach(id => formData.append('ids[]', id));

            try {
                const res = await fetch('../ajax/media-delete.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    clearSelection();
                    loadMedia(currentPage, currentQuery, currentDate);
                    if (data.failed_items && data.failed_items.length > 0) {
                        alert(`Không thể xóa ${data.failed_items.length} file đang được sử dụng.`);
                    }
                } else {
                    alert(data.message);
                }
            } catch (err) { alert(err.message); }
        }

        // --- Listeners ---
        btnSelectFiles.addEventListener('click', () => mediaInput.click());
        mediaInput.addEventListener('change', () => uploadFiles(mediaInput.files));

        // Drag
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
            dropZone.addEventListener(ev, (e) => {
                e.preventDefault();
                if (ev === 'dragenter' || ev === 'dragover') dropZone.classList.add('active');
                else dropZone.classList.remove('active');
            });
        });
        dropZone.addEventListener('drop', (e) => uploadFiles(e.dataTransfer ? e.dataTransfer.files : null));

        // Filter
        let searchTimeout;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => loadMedia(1, searchInput.value.trim(), filterDate.value), 400);
        });
        filterDate.addEventListener('change', () => loadMedia(1, searchInput.value.trim(), filterDate.value));

        // Nav
        pageNumbers.addEventListener('click', (e) => {
            const button = e.target.closest('[data-page]');
            if (!button) return;
            loadMedia(Number(button.dataset.page), currentQuery, currentDate);
        });

        // Grid Click
        mediaGrid.addEventListener('click', async (e) => {
            const card = e.target.closest('.media-card');
            if (!card) return;

            // Buttons
            if (e.target.closest('.btn-copy-url')) {
                e.stopPropagation();
                try { await navigator.clipboard.writeText(card.dataset.url); showPopup('Đã copy URL', 'success'); } catch (e) { }
                return;
            }
            if (e.target.closest('.btn-copy-path')) {
                e.stopPropagation();
                try { await navigator.clipboard.writeText(card.dataset.filePath); showPopup('Đã copy Path', 'success'); } catch (e) { }
                return;
            }

            if (e.target.closest('.media-check')) {
                toggleSelection(Number(card.dataset.id));
                return;
            }

            openPreview({
                url: card.dataset.url,
                original_name: card.dataset.name,
                extension: card.dataset.extension,
                file_size_text: formatSize(Number(card.dataset.size || 0)),
                dimension_text: card.dataset.width && card.dataset.height ? `${card.dataset.width}x${card.dataset.height}` : ''
            });
        });

        btnDeselectAll.addEventListener('click', clearSelection);
        btnBulkDelete.addEventListener('click', performBulkDelete);

        function showPopup(msg, type) {
            if (window.AdminPopup) window.AdminPopup.show(msg, type);
        }

        // --- Cleanup Orphans ---
        const btnCleanup = document.getElementById('btnCleanupOrphans');
        btnCleanup.addEventListener('click', async () => {
            if (!confirm('Quét và xóa các record media mồ côi (file không tồn tại trên server)?')) return;
            const origHTML = btnCleanup.innerHTML;
            btnCleanup.disabled = true;
            btnCleanup.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang quét...';

            try {
                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                const res = await fetch('../ajax/media-cleanup.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    let msg = data.message + '\n\nTổng quét: ' + data.total_scanned + ' | OK: ' + data.ok_count + ' | Orphan: ' + data.orphan_count;
                    if (data.orphans && data.orphans.length > 0) {
                        msg += '\n\nCác file đã xóa:';
                        data.orphans.slice(0, 20).forEach(o => {
                            msg += '\n  - ' + o.name + ' (' + o.path + ')';
                        });
                        if (data.orphan_count > 20) msg += '\n  ... và ' + (data.orphan_count - 20) + ' file khác';
                    }
                    alert(msg);
                    if (data.orphan_count > 0) {
                        loadMedia(1, currentQuery, currentDate);
                    }
                } else {
                    alert('Lỗi: ' + (data.message || 'Không xác định'));
                }
            } catch (err) {
                alert('Lỗi kết nối: ' + err.message);
            } finally {
                btnCleanup.disabled = false;
                btnCleanup.innerHTML = origHTML;
            }
        });

        loadMedia(1, '', '');
    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
