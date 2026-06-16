/**
 * Media Selector Logic
 */
const MediaSelector = (function () {
    let modal;
    let triggerButton = null;
    let currentInputId = null;
    let currentPreviewId = null;
    let selectionMode = 'single'; // 'single' or 'multiple'
    let selectedImages = [];
    let currentPage = 1;
    let currentSearch = '';
    const apiListUrl = BASE_URL + 'admin/ajax/media-list.php';
    const apiUploadUrl = BASE_URL + 'admin/ajax/media-upload.php';

    // Init function
    function init() {
        const modalEl = document.getElementById('mediaSelectorModal');
        if (!modalEl) return;
        modal = new bootstrap.Modal(modalEl);

        // Listeners for triggering modal
        document.body.addEventListener('click', function (e) {
            const btn = e.target.closest('.init-media-selector');
            if (btn) {
                triggerButton = btn;
                openModal(btn.dataset.input, btn.dataset.preview, btn.dataset.mode || 'single');
            }
        });

        // Search listener
        const searchInput = document.getElementById('media-modal-search');
        if (searchInput) {
            searchInput.addEventListener('keyup', debounce(function (e) {
                currentSearch = e.target.value.trim();
                currentPage = 1;
                loadImages();
            }, 500));
        }

        // Refresh button
        const refreshBtn = document.getElementById('media-modal-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                currentPage = 1;
                loadImages();
            });
        }

        // Upload handling
        initUpload();

        // Selection confirmation
        const selectBtn = document.getElementById('media-modal-select-btn');
        if (selectBtn) {
            selectBtn.addEventListener('click', confirmSelection);
        }
    }

    function openModal(inputId, previewId, mode) {
        currentInputId = inputId;
        currentPreviewId = previewId;
        selectionMode = mode;
        selectedImages = [];
        const selectBtn = document.getElementById('media-modal-select-btn');
        if (selectBtn) {
            selectBtn.innerHTML = selectionMode === 'multiple'
                ? '<i class="bi bi-check-lg me-1"></i> Thêm ảnh đã chọn'
                : '<i class="bi bi-check-lg me-1"></i> Chọn ảnh';
        }
        updateSelectButton();

        // Switch to library tab
        const triggerTab = new bootstrap.Tab(document.querySelector('#mediaTabs #library-tab'));
        triggerTab.show();

        modal.show();

        // Load initial data if empty or stale
        loadImages();
    }

    async function loadImages() {
        const grid = document.getElementById('media-modal-grid');
        grid.innerHTML = '<div class="text-center w-100 py-5"><div class="spinner-border text-primary"></div></div>';

        try {
            const url = `${apiListUrl}?page=${currentPage}&q=${encodeURIComponent(currentSearch)}&per_page=18`;
            const res = await fetch(url);
            const data = await res.json();

            if (data.success) {
                renderGrid(data.items);
                renderPagination(data.total_pages, data.page);
            } else {
                grid.innerHTML = `<div class="col-12 text-center text-danger py-4">${data.message || 'Lỗi tải dữ liệu'}</div>`;
            }
        } catch (err) {
            console.error(err);
            grid.innerHTML = `<div class="col-12 text-center text-danger py-4">Lỗi kết nối server</div>`;
        }
    }

    function renderGrid(items) {
        const grid = document.getElementById('media-modal-grid');
        grid.innerHTML = '';

        if (items.length === 0) {
            grid.innerHTML = '<div class="col-12 text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Không tìm thấy ảnh nào</div>';
            return;
        }

        const formatSize = (bytes) => {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(0) + ' MB';
        };

        items.forEach(item => {
            const col = document.createElement('div');
            // Mixed small grid: 3 items on mobile, 4 on sm, 6 on md, 12 on xl (very dense)
            col.className = 'col-4 col-sm-3 col-md-2 col-xl-1';

            const isSelected = selectedImages.some(img => img.path === item.file_path);
            const selectedClass = isSelected ? 'selected' : '';

            col.innerHTML = `
                <div class="card h-100 media-item-selector border-0 shadow-sm position-relative ${selectedClass}" 
                     data-path="${item.file_path}" data-url="${item.url}" style="cursor: pointer;">
                    <div class="ratio ratio-1x1 bg-light rounded overflow-hidden position-relative">
                        <img src="${item.url}" class="object-fit-cover w-100 h-100" loading="lazy" alt="${item.original_name}">
                        
                        <!-- Check icon overlay -->
                        <div class="selected-overlay position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-white" 
                             style="background: rgba(13, 110, 253, 0.4); opacity: ${isSelected ? 1 : 0}; transition: opacity 0.2s;">
                            <i class="bi bi-check-circle-fill fs-2"></i>
                        </div>

                        <!-- Info overlay (hover) -->
                        <div class="info-overlay position-absolute bottom-0 start-0 w-100 p-1 text-white bg-dark bg-opacity-75" 
                             style="font-size: 9px; opacity: 0; transition: opacity 0.2s; pointer-events: none;">
                            <div class="text-truncate">${item.original_name}</div>
                            <div class="opacity-75">${formatSize(item.file_size)}</div>
                        </div>
                    </div>
                </div>
            `;

            const card = col.querySelector('.media-item-selector');

            // Hover effects
            card.addEventListener('mouseenter', () => {
                card.querySelector('.info-overlay').style.opacity = '1';
                card.style.transform = 'translateY(-2px)';
                card.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
                card.querySelector('.selected-overlay').style.zIndex = '5'; // Keep check above info if needed
            });
            card.addEventListener('mouseleave', () => {
                card.querySelector('.info-overlay').style.opacity = '0';
                card.style.transform = 'translateY(0)';
                card.style.boxShadow = '';
            });

            // Click handling
            card.addEventListener('click', function () {
                const path = this.dataset.path;
                const url = this.dataset.url;
                const overlay = this.querySelector('.selected-overlay');

                if (selectionMode === 'single') {
                    // Deselect all others
                    grid.querySelectorAll('.media-item-selector').forEach(el => {
                        el.classList.remove('selected');
                        el.querySelector('.selected-overlay').style.opacity = '0';
                    });

                    // Select current
                    this.classList.add('selected');
                    overlay.style.opacity = '1';
                    selectedImages = [{ path, url }];
                } else {
                    // Toggle
                    if (this.classList.contains('selected')) {
                        this.classList.remove('selected');
                        overlay.style.opacity = '0';
                        selectedImages = selectedImages.filter(img => img.path !== path);
                    } else {
                        this.classList.add('selected');
                        overlay.style.opacity = '1';
                        selectedImages.push({ path, url });
                    }
                }
                updateSelectButton();
            });

            grid.appendChild(col);
        });
    }

    function renderPagination(totalPages, currentPageNum) {
        const container = document.getElementById('media-modal-pagination');
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '<nav><ul class="pagination pagination-sm">';

        // Prev
        html += `<li class="page-item ${currentPageNum <= 1 ? 'disabled' : ''}">
                    <button class="page-link" data-page="${currentPageNum - 1}">Trước</button>
                 </li>`;

        // Simple pagination logic (show range around current)
        const start = Math.max(1, currentPageNum - 2);
        const end = Math.min(totalPages, currentPageNum + 2);

        if (start > 1) {
            html += `<li class="page-item"><button class="page-link" data-page="1">1</button></li>`;
            if (start > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }

        for (let i = start; i <= end; i++) {
            html += `<li class="page-item ${i === currentPageNum ? 'active' : ''}">
                        <button class="page-link" data-page="${i}">${i}</button>
                     </li>`;
        }

        if (end < totalPages) {
            if (end < totalPages - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            html += `<li class="page-item"><button class="page-link" data-page="${totalPages}">${totalPages}</button></li>`;
        }

        // Next
        html += `<li class="page-item ${currentPageNum >= totalPages ? 'disabled' : ''}">
                    <button class="page-link" data-page="${currentPageNum + 1}">Sau</button>
                 </li>`;

        html += '</ul></nav>';
        container.innerHTML = html;

        // Add listeners
        container.querySelectorAll('button.page-link').forEach(btn => {
            btn.addEventListener('click', function () {
                currentPage = parseInt(this.dataset.page);
                loadImages();
            });
        });
    }

    function updateSelectButton() {
        const btn = document.getElementById('media-modal-select-btn');
        const status = document.getElementById('media-modal-status');
        const count = selectedImages.length;

        if (count > 0) {
            btn.classList.remove('disabled');
            status.textContent = `Đã chọn ${count} ảnh`;
        } else {
            btn.classList.add('disabled');
            status.textContent = 'Chưa chọn ảnh nào';
        }
    }

    function initUpload() {
        const dropzone = document.getElementById('media-modal-dropzone');
        const input = document.getElementById('media-modal-input');

        if (!dropzone || !input) return;

        // Drag events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.remove('dragover'), false);
        });

        dropzone.addEventListener('drop', handleDrop, false);
        input.addEventListener('change', function () {
            handleFiles(this.files);
        });

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }

        function handleFiles(files) {
            if (files.length === 0) return;
            uploadFiles(files);
        }
    }

    async function uploadFiles(files) {
        const progressBox = document.getElementById('media-modal-upload-progress');
        const progressBar = progressBox.querySelector('.progress-bar');
        const resultBox = document.getElementById('media-modal-upload-result');

        progressBox.classList.remove('d-none');
        progressBar.style.width = '0%';
        resultBox.innerHTML = '';

        const formData = new FormData();
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        // We will try to get it from a hidden input if meta fails, usually present in admin forms
        if (csrfToken) formData.append('csrf_token', csrfToken);
        const tokenInput = document.querySelector('input[name="csrf_token"]') || document.getElementById('csrfToken');
        if (!csrfToken && tokenInput) formData.append('csrf_token', tokenInput.value);

        Array.from(files).forEach(file => {
            formData.append('files[]', file);
        });

        try {
            // Simulated progress for fetch (not real XHR progress)
            progressBar.style.width = '50%';

            const res = await fetch(apiUploadUrl, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            progressBar.style.width = '100%';
            setTimeout(() => progressBox.classList.add('d-none'), 500);

            if (data.success) {
                const uploaded = Array.isArray(data.uploaded) ? data.uploaded : [];
                const failed = Array.isArray(data.failed) ? data.failed : [];
                const converted = uploaded.filter(item => item?.conversion?.converted === true);
                const notConverted = uploaded.filter(item => item?.conversion?.attempted === true && item?.conversion?.converted !== true);

                let html = '<div class="alert alert-success mt-2">Upload thành công.</div>';
                html += '<ul class="mb-2 small">';
                html += `<li>Đã tải lên: ${uploaded.length} file</li>`;
                html += `<li>Đã chuyển WebP: ${converted.length} file</li>`;
                if (notConverted.length > 0) {
                    html += `<li>Không chuyển được WebP: ${notConverted.length} file</li>`;
                }
                if (failed.length > 0) {
                    html += `<li>Upload thất bại: ${failed.length} file</li>`;
                }
                html += '</ul>';

                if (notConverted.length > 0) {
                    html += '<div class="small text-warning">Chi tiết file không chuyển được:</div>';
                    html += '<ul class="mb-2 small">';
                    notConverted.slice(0, 8).forEach(item => {
                        const reason = item?.conversion?.message || item?.conversion?.reason || 'unknown';
                        html += `<li>${item.original_name}: ${reason}</li>`;
                    });
                    html += '</ul>';
                }

                if (failed.length > 0) {
                    html += '<div class="small text-danger">Chi tiết file upload thất bại:</div>';
                    html += '<ul class="mb-0 small">';
                    failed.slice(0, 8).forEach(f => {
                        html += `<li>${f.name}: ${f.message}</li>`;
                    });
                    html += '</ul>';
                }

                resultBox.innerHTML = html;

                setTimeout(() => {
                    const libraryTab = new bootstrap.Tab(document.querySelector('#mediaTabs #library-tab'));
                    libraryTab.show();
                    currentPage = 1;
                    loadImages();
                    resultBox.innerHTML = '';
                }, 1500);
            } else {
                let html = '<div class="alert alert-danger mt-2">Lỗi: ' + (data.message || 'Upload thất bại') + '</div>';
                if (Array.isArray(data.failed) && data.failed.length > 0) {
                    html += '<ul class="mb-0 small">';
                    data.failed.slice(0, 8).forEach(f => {
                        html += `<li>${f.name}: ${f.message}</li>`;
                    });
                    html += '</ul>';
                }
                resultBox.innerHTML = html;
            }

        } catch (err) {
            progressBox.classList.add('d-none');
            resultBox.innerHTML = '<div class="alert alert-danger mt-2">Lỗi kết nối server</div>';
            console.error(err);
        }
    }

    function confirmSelection() {
        if (selectedImages.length === 0) return;

        // Custom event for complex handling
        const event = new CustomEvent('media-selected', {
            detail: {
                images: selectedImages,
                mode: selectionMode,
                inputId: currentInputId,
                previewId: currentPreviewId
            }
        });

        if (triggerButton) {
            triggerButton.dispatchEvent(event);
        }

        // Standard Single Mode Handling
        if (selectionMode === 'single' && selectedImages.length > 0) {
            const img = selectedImages[0];
            if (currentInputId) {
                const input = document.getElementById(currentInputId);
                if (input) {
                    input.value = img.path;
                    input.dispatchEvent(new Event('change'));
                }
            }

            if (currentPreviewId) {
                const preview = document.getElementById(currentPreviewId);
                if (preview) {
                    preview.src = img.url;
                    preview.parentElement.classList.remove('d-none');
                }
            }
        }

        // Close modal
        modal.hide();
    }

    // Utility for debounce
    function debounce(func, wait) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    return {
        init: init
    };
})();

// Auto init on DOM Ready
document.addEventListener('DOMContentLoaded', function () {
    MediaSelector.init();
});

