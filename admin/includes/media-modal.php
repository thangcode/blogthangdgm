<!-- Media Selector Modal -->
<div class="modal fade" id="mediaSelectorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Chọn ảnh từ thư viện</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="nav nav-tabs px-3 pt-3 border-bottom-0" id="mediaTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="library-tab" data-bs-toggle="tab"
                            data-bs-target="#library-pane" type="button" role="tab" aria-selected="true">
                            <i class="bi bi-images me-1"></i> Thư viện
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload-pane"
                            type="button" role="tab" aria-selected="false">
                            <i class="bi bi-cloud-upload me-1"></i> Tải lên
                        </button>
                    </li>
                </ul>
                <div class="tab-content" id="mediaTabContent">
                    <!-- Library Pane -->
                    <div class="tab-pane fade show active" id="library-pane" role="tabpanel"
                        aria-labelledby="library-tab">
                        <div class="d-flex flex-column h-100">
                            <!-- Toolbar -->
                            <div class="p-2 border-bottom sticky-top bg-white index-1">
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <div class="input-group input-group-sm" style="max-width: 300px;">
                                        <span class="input-group-text bg-light border-end-0"><i
                                                class="bi bi-search text-muted"></i></span>
                                        <input type="text" class="form-control bg-light border-start-0 ps-0"
                                            id="media-modal-search" placeholder="Tìm tên file...">
                                    </div>
                                    <button type="button" class="btn btn-light btn-sm border" id="media-modal-refresh"
                                        title="Làm mới">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </div>
                            </div>
                            <!-- Grid -->
                            <div class="p-2 bg-light" style="min-height: 400px;">
                                <div class="row g-2" id="media-modal-grid">
                                    <!-- Items will be loaded here -->
                                    <div class="text-center w-100 py-5">
                                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                        <p class="mt-2 text-muted small">Đang tải dữ liệu...</p>
                                    </div>
                                </div>
                                <!-- Pagination -->
                                <div class="mt-3 d-flex justify-content-center" id="media-modal-pagination"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Pane -->
                    <div class="tab-pane fade" id="upload-pane" role="tabpanel" aria-labelledby="upload-tab">
                        <div class="p-5 text-center">
                            <div class="upload-area border border-2 border-dashed rounded-3 p-5"
                                id="media-modal-dropzone">
                                <i class="bi bi-cloud-arrow-up display-4 text-primary mb-3"></i>
                                <h5>Kéo thả ảnh vào đây hoặc click để chọn</h5>
                                <p class="text-muted">Hỗ trợ JPG, PNG, GIF, WEBP. Tối đa 5MB.</p>
                                <input type="file" id="media-modal-input" class="d-none" multiple accept="image/*">
                                <button type="button" class="btn btn-primary"
                                    onclick="document.getElementById('media-modal-input').click()">
                                    Chọn file
                                </button>
                            </div>
                            <!-- Upload Progress -->
                            <div id="media-modal-upload-progress" class="mt-4 d-none">
                                <div class="progress mb-2" style="height: 5px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated"
                                        role="progressbar" style="width: 0%"></div>
                                </div>
                                <small class="text-muted">Đang tải lên...</small>
                            </div>
                            <!-- Upload Result -->
                            <div id="media-modal-upload-result" class="mt-3"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light justify-content-between py-2">
                <div class="text-muted small" id="media-modal-status">Chưa chọn ảnh nào</div>
                <div>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="btn btn-sm btn-primary disabled" id="media-modal-select-btn">
                        <i class="bi bi-check-lg me-1"></i> Chèn vào bài viết
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .media-item-selector {
        cursor: pointer;
        transition: all 0.2s;
    }

    .media-item-selector.selected {
        border-color: var(--bs-primary) !important;
        box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
    }

    .upload-area {
        transition: background-color 0.2s;
        cursor: pointer;
    }

    .upload-area:hover,
    .upload-area.dragover {
        background-color: #f8f9fa;
        border-color: var(--bs-primary) !important;
    }
</style>