<?php
/**
 * SEO Fields Component — Premium SEO Analyzer
 * Include in admin edit forms for categories, services, posts
 *
 * Usage:
 * $seo_data = ['meta_title' => '', 'meta_description' => '', 'meta_keywords' => ''];
 * include 'includes/seo-fields.php';
 */

if (!isset($seo_data))
    $seo_data = [];

$meta_title = $seo_data['meta_title'] ?? '';
$meta_description = $seo_data['meta_description'] ?? '';
$meta_keywords = $seo_data['meta_keywords'] ?? '';
$preview_title = $seo_data['preview_title'] ?? 'Tiêu đề trang';
$preview_url = $seo_data['preview_url'] ?? BASE_URL;

// LLM config — Auto SEO bật khi đã cấu hình llm_api_key (backend groq-seo.php dùng key này).
$_seo_groq_enabled = false;
if (isset($pdo)) {
    $_seo_groq_enabled = trim((string) get_setting('llm_api_key', '')) !== '';
}
?>

<!-- SEO Meta Box -->
<div class="seo-meta-box card mt-4">
    <div class="card-header bg-white p-0">
        <div class="d-flex align-items-center justify-content-between">
            <!-- Toggle area (only this part triggers collapse) -->
            <div class="d-flex align-items-center gap-2 flex-grow-1 px-3 py-2" data-bs-toggle="collapse"
                data-bs-target="#seoFieldsCollapse" style="cursor:pointer; min-height:52px;" aria-expanded="true">
                <h5 class="mb-0 d-flex align-items-center gap-2">
                    <span class="seo-header-icon"><i class="bi bi-graph-up-arrow"></i></span>
                    <span class="fw-bold">SEO Settings</span>
                    <div class="seo-score-pill" id="seoScorePill">
                        <span class="score-dot"></span>
                        <span id="seoScoreLabel">Đang tính...</span>
                    </div>
                </h5>
                <i class="bi bi-chevron-down collapse-icon ms-auto"></i>
            </div>
            <!-- Auto SEO button (outside toggle area) -->
            <div class="px-3 flex-shrink-0">
                <button type="button" class="btn btn-auto-seo" id="btnAutoSeo" onclick="autoSEO()"
                    title="Tự động điền SEO tối ưu">
                    <i class="bi bi-magic"></i> Auto SEO
                    <?php if ($_seo_groq_enabled): ?><span class="badge bg-warning text-dark ms-1"
                            style="font-size:9px;vertical-align:middle">AI</span><?php endif; ?>
                </button>
            </div>
        </div>
    </div>

    <div class="collapse show" id="seoFieldsCollapse">
        <div class="card-body p-4">

            <!-- SEO Score Dashboard -->
            <div class="seo-score-dashboard mb-4">
                <div class="score-main">
                    <div class="score-ring-wrap">
                        <svg class="score-ring" viewBox="0 0 80 80">
                            <circle class="ring-bg" cx="40" cy="40" r="32" />
                            <circle class="ring-fill" cx="40" cy="40" r="32" id="scoreRingFill" />
                        </svg>
                        <div class="score-center">
                            <span class="score-num" id="seoScoreNum">–</span>
                            <span class="score-max">/100</span>
                        </div>
                    </div>
                    <div class="score-info">
                        <div class="score-status" id="seoScoreStatus">Chưa phân tích</div>
                        <div class="score-hint" id="seoScoreHint">Nhập Focus Keyphrase để bắt đầu</div>
                    </div>
                </div>
                <!-- Checklist -->
                <div class="score-checklist" id="seoChecklist">
                    <div class="check-item" id="chk-keyword"><i class="bi bi-circle"></i> Focus Keyphrase</div>
                    <div class="check-item" id="chk-title"><i class="bi bi-circle"></i> Meta Title (50–65 ký tự)</div>
                    <div class="check-item" id="chk-title-kw"><i class="bi bi-circle"></i> Từ khóa trong Title</div>
                    <div class="check-item" id="chk-desc"><i class="bi bi-circle"></i> Meta Description (120–160 ký tự)
                    </div>
                    <div class="check-item" id="chk-desc-kw"><i class="bi bi-circle"></i> Từ khóa trong Description
                    </div>
                    <div class="check-item" id="chk-keywords"><i class="bi bi-circle"></i> Meta Keywords</div>
                </div>
            </div>

            <!-- Google Preview -->
            <div class="google-preview mb-4">
                <div class="gp-label"><i class="bi bi-google me-1"></i> Xem trước trên Google</div>
                <div class="gp-box">
                    <div class="gp-favicon"><i class="bi bi-globe2"></i></div>
                    <div class="gp-content">
                        <div class="gp-url" id="seoPreviewUrl"><?php echo e($preview_url); ?></div>
                        <?php 
                            $_seo_site_name = get_setting('site_name', 'ShopSieuSale');
                            $_seo_sep = get_setting('seo_title_separator', ' | ');
                        ?>
                        <div class="gp-title" id="seoPreviewTitle"><?php echo e($meta_title ?: $preview_title); ?><?php echo e($_seo_sep . $_seo_site_name); ?></div>
                        <div class="gp-desc" id="seoPreviewDesc">
                            <?php echo e($meta_description ?: 'Mô tả SEO sẽ hiển thị ở đây. Hãy viết mô tả hấp dẫn để thu hút người dùng click vào kết quả tìm kiếm.'); ?>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Focus Keyphrase Tag Input -->
            <div class="mb-3">
                <label class="form-label fw-bold">
                    <i class="bi bi-key-fill me-1 text-warning"></i> Focus Keyphrase
                    <span class="badge bg-warning text-dark ms-1">Quan trọng</span>
                    <span class="badge bg-light text-secondary border ms-1" id="fkCount">0 từ khóa</span>
                </label>
                <input type="hidden" name="focus_keyword" id="focusKeywordHidden"
                    value="<?php echo e($seo_data['focus_keyword'] ?? ''); ?>">
                <div class="kw-tag-box" id="fkTagBox">
                    <div class="kw-tags" id="fkTags"></div>
                    <input type="text" class="kw-input" id="fkInput" placeholder="Nhập từ khóa rồi nhấn Enter..."
                        autocomplete="off">
                </div>
                <small class="text-muted">Từ khóa chính bạn muốn xếp hạng — dùng để chấm điểm SEO</small>
            </div>

            <!-- Meta Title -->
            <div class="mb-3">
                <label class="form-label fw-bold">
                    <i class="bi bi-fonts me-1 text-info"></i> Meta Title
                    <span class="float-end small text-muted"><span id="metaTitleCount">0</span>/65</span>
                </label>
                <input type="text" class="form-control seo-field" name="meta_title" id="metaTitle"
                    value="<?php echo e($meta_title); ?>" maxlength="70"
                    placeholder="Tiêu đề SEO (để trống dùng tiêu đề mặc định)">
                <div class="seo-progress-wrap mt-1">
                    <div class="seo-progress-bar" id="metaTitleProgress" style="width:0%"></div>
                </div>
                <small class="text-muted">Tối ưu: 50–65 ký tự. Nên chứa từ khóa chính, giữ số bài/phần nếu là series.</small>
            </div>

            <!-- Meta Description -->
            <div class="mb-3">
                <label class="form-label fw-bold">
                    <i class="bi bi-card-text me-1 text-success"></i> Meta Description
                    <span class="float-end small text-muted"><span id="metaDescCount">0</span>/160</span>
                </label>
                <textarea class="form-control seo-field" name="meta_description" id="metaDescription" rows="3"
                    maxlength="200"
                    placeholder="Mô tả SEO (để trống dùng mô tả mặc định)"><?php echo e($meta_description); ?></textarea>
                <div class="seo-progress-wrap mt-1">
                    <div class="seo-progress-bar" id="metaDescProgress" style="width:0%"></div>
                </div>
                <small class="text-muted">Tối ưu: 120–160 ký tự. Viết hấp dẫn để tăng CTR.</small>
            </div>

            <!-- Meta Keywords Tag Input -->
            <div class="mb-0">
                <label class="form-label fw-bold">
                    <i class="bi bi-tags-fill me-1 text-danger"></i> Meta Keywords
                    <span class="badge bg-light text-secondary border ms-1" id="kwCount">0 từ khóa</span>
                </label>
                <!-- Hidden input thực sự được submit -->
                <input type="hidden" name="meta_keywords" id="metaKeywordsHidden"
                    value="<?php echo e($meta_keywords); ?>">
                <!-- Tag UI -->
                <div class="kw-tag-box" id="kwTagBox">
                    <div class="kw-tags" id="kwTags"></div>
                    <input type="text" class="kw-input" id="kwInput" placeholder="Nhập từ khóa rồi nhấn Enter..."
                        autocomplete="off">
                </div>
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Nhấn <kbd>Enter</kbd> hoặc <kbd>,</kbd>
                    để thêm từ khóa. Click <kbd>✕</kbd> để xóa.</small>
            </div>

            <!-- OG Image (Social Thumbnail) -->
            <?php
            $_og_image_val     = $seo_data['og_image'] ?? '';
            $_og_image_default = $seo_data['og_image_default'] ?? ''; // e.g. product's thumbnail path
            $_og_image_show    = !empty($_og_image_val) ? $_og_image_val : $_og_image_default;
            $_og_img_url       = '';
            if (!empty($_og_image_show)) {
                $_og_img_url = (strpos($_og_image_show, 'http') === 0) ? $_og_image_show : BASE_URL . $_og_image_show;
            }
            ?>
            <div class="mt-3 pt-3 border-top">
                <label class="form-label fw-bold">
                    <i class="bi bi-card-image me-1 text-primary"></i> OG Image
                    <span class="badge bg-light text-secondary border ms-1">Thumbnail mạng xã hội</span>
                </label>
                <div class="input-group mb-2">
                    <input type="text" class="form-control" name="og_image" id="seoOgImageInput"
                           value="<?php echo e($_og_image_val); ?>"
                           placeholder="<?php echo !empty($_og_image_default) ? 'Mặc định: ' . e($_og_image_default) : 'Để trống = dùng ảnh mặc định site'; ?>"
                           readonly>
                    <button type="button" class="btn btn-outline-primary init-media-selector"
                            data-input="seoOgImageInput" data-preview="seoOgImagePreview">
                        <i class="bi bi-images me-1"></i> Chọn ảnh
                    </button>
                    <?php if (!empty($_og_image_val)): ?>
                    <button type="button" class="btn btn-outline-danger" id="seoOgImageClear" title="Xóa, dùng ảnh mặc định">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <!-- Preview -->
                <div id="seoOgImagePreviewWrap" class="mb-2<?php echo empty($_og_image_show) ? ' d-none' : ''; ?>">
                    <div class="position-relative d-inline-block">
                        <img id="seoOgImagePreview" src="<?php echo $_og_img_url; ?>"
                             class="rounded border" style="max-height:90px; max-width:180px; object-fit:cover;">
                        <?php if (!empty($_og_image_default) && empty($_og_image_val)): ?>
                        <span class="badge bg-secondary position-absolute bottom-0 start-0 m-1" style="font-size:10px;">Mặc định</span>
                        <?php endif; ?>
                    </div>
                </div>
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Khuyến nghị <strong>1200×630px</strong>. Hiển thị khi share lên Facebook/Zalo. Để trống = tự dùng ảnh đại diện.</small>
            </div>

        </div>
    </div>
</div>

<style>
    /* ── SEO Box ── */
    .seo-meta-box {
        border-left: 4px solid #6366f1;
        transition: box-shadow .3s;
    }

    .seo-meta-box:hover {
        box-shadow: 0 4px 16px rgba(99, 102, 241, .15);
    }

    /* ── Auto SEO Button ── */
    .btn-auto-seo {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: .8rem;
        font-weight: 600;
        color: #fff;
        background: linear-gradient(135deg, #6366f1, #a855f7);
        border: none;
        box-shadow: 0 3px 12px rgba(99, 102, 241, .35);
        transition: transform .15s, box-shadow .15s, opacity .15s;
        white-space: nowrap;
    }

    .btn-auto-seo:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(99, 102, 241, .45);
        color: #fff;
    }

    .btn-auto-seo:active {
        transform: scale(.96);
    }

    .btn-auto-seo.loading {
        opacity: .7;
        pointer-events: none;
    }

    @keyframes spinIcon {
        to {
            transform: rotate(360deg);
        }
    }

    .btn-auto-seo.loading i {
        animation: spinIcon .7s linear infinite;
    }

    .seo-meta-box .card-header:hover {
        background: #f8f9fa !important;
    }

    .collapse-icon {
        transition: transform .3s;
    }

    .card-header[aria-expanded="false"] .collapse-icon {
        transform: rotate(-90deg);
    }

    .seo-header-icon {
        width: 28px;
        height: 28px;
        background: linear-gradient(135deg, #6366f1, #818cf8);
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: .9rem;
    }

    /* ── Score Pill in header ── */
    .seo-score-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        background: #f3f4f6;
        font-size: .78rem;
        font-weight: 600;
        transition: all .4s;
    }

    .seo-score-pill .score-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #9ca3af;
        transition: background .4s;
    }

    .seo-score-pill.good {
        background: #dcfce7;
        color: #166534;
    }

    .seo-score-pill.good .score-dot {
        background: #22c55e;
    }

    .seo-score-pill.okay {
        background: #fef9c3;
        color: #854d0e;
    }

    .seo-score-pill.okay .score-dot {
        background: #eab308;
    }

    .seo-score-pill.poor {
        background: #fee2e2;
        color: #991b1b;
    }

    .seo-score-pill.poor .score-dot {
        background: #ef4444;
    }

    /* ── Score Dashboard ── */
    .seo-score-dashboard {
        display: flex;
        gap: 20px;
        align-items: flex-start;
        background: linear-gradient(135deg, #f8f9ff 0%, #f0f0ff 100%);
        border: 1px solid #e0e0ff;
        border-radius: 14px;
        padding: 18px;
    }

    .score-main {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 220px;
    }

    /* Ring */
    .score-ring-wrap {
        position: relative;
        width: 80px;
        height: 80px;
        flex-shrink: 0;
    }

    .score-ring {
        width: 80px;
        height: 80px;
        transform: rotate(-90deg);
    }

    .ring-bg {
        fill: none;
        stroke: #e5e7eb;
        stroke-width: 8;
    }

    .ring-fill {
        fill: none;
        stroke: #6366f1;
        stroke-width: 8;
        stroke-linecap: round;
        stroke-dasharray: 201;
        stroke-dashoffset: 201;
        transition: stroke-dashoffset .7s cubic-bezier(.4, 0, .2, 1), stroke .4s;
    }

    .score-center {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }

    .score-num {
        font-size: 1.4rem;
        font-weight: 800;
        color: #1e1b4b;
        line-height: 1;
    }

    .score-max {
        font-size: .55rem;
        color: #9ca3af;
        font-weight: 500;
    }

    .score-info {
        flex: 1;
    }

    .score-status {
        font-weight: 700;
        font-size: .95rem;
        color: #1e1b4b;
        margin-bottom: 4px;
    }

    .score-hint {
        font-size: .78rem;
        color: #6b7280;
    }

    /* Checklist */
    .score-checklist {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .check-item {
        font-size: .8rem;
        color: #6b7280;
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 5px 8px;
        border-radius: 6px;
        transition: background .2s;
    }

    .check-item.pass {
        color: #16a34a;
        background: #f0fdf4;
    }

    .check-item.pass i::before {
        content: "\F272";
    }

    /* bi-check-circle-fill */
    .check-item.fail {
        color: #dc2626;
        background: #fef2f2;
    }

    .check-item.fail i::before {
        content: "\F623";
    }

    /* bi-x-circle-fill */
    .check-item.empty {
        color: #9ca3af;
    }

    /* ── Google Preview ── */
    .google-preview {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }

    .gp-label {
        background: #f8fafc;
        padding: 8px 14px;
        font-size: .78rem;
        color: #64748b;
        border-bottom: 1px solid #e2e8f0;
    }

    .gp-box {
        display: flex;
        gap: 10px;
        padding: 14px;
    }

    .gp-favicon {
        width: 26px;
        height: 26px;
        background: #f1f5f9;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .8rem;
        color: #6366f1;
        flex-shrink: 0;
    }

    .gp-content {
        flex: 1;
        min-width: 0;
    }

    .gp-url {
        font-size: .75rem;
        color: #188038;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .gp-title {
        font-size: 1.05rem;
        color: #1a0dab;
        font-weight: 500;
        margin-bottom: 4px;
        cursor: pointer;
    }

    .gp-title:hover {
        text-decoration: underline;
    }

    .gp-desc {
        font-size: .82rem;
        color: #545454;
        line-height: 1.45;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* ── Progress bars ── */
    .seo-progress-wrap {
        height: 4px;
        background: #e5e7eb;
        border-radius: 2px;
        overflow: hidden;
    }

    .seo-progress-bar {
        height: 100%;
        border-radius: 2px;
        transition: width .3s, background .3s;
        background: #6366f1;
    }

    .seo-field:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .12);
    }

    /* ── Keyword Tag Input ── */
    .kw-tag-box {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        border: 1px solid #dee2e6;
        border-radius: .375rem;
        background: #fff;
        min-height: 48px;
        cursor: text;
        transition: border-color .2s, box-shadow .2s;
    }

    .kw-tag-box:focus-within {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .12);
    }

    .kw-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }


    .kw-tag {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: linear-gradient(135deg, #6366f1, #818cf8);
        color: #fff;
        border-radius: 20px;
        padding: 5px 13px 5px 15px;
        font-size: .8rem;
        font-weight: 500;
        letter-spacing: .01em;
        animation: tagIn .2s ease;
    }

    @keyframes tagIn {
        from {
            opacity: 0;
            transform: scale(.8);
        }

        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    .kw-tag-remove {
        background: rgba(255, 255, 255, .3);
        border: none;
        border-radius: 50%;
        width: 16px;
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .65rem;
        cursor: pointer;
        padding: 0;
        color: #fff;
        line-height: 1;
        transition: background .2s;
    }

    .kw-tag-remove:hover {
        background: rgba(255, 255, 255, .55);
    }

    .kw-input {
        border: none;
        outline: none;
        flex: 1;
        min-width: 140px;
        font-size: .875rem;
        background: transparent;
        padding: 2px 0;
    }

    @media(max-width:640px) {
        .seo-score-dashboard {
            flex-direction: column;
        }

        .score-checklist {
            padding-top: 10px;
            border-top: 1px solid #e0e0ff;
        }
    }
</style>

<script>
    (function () {
        /* ── helpers ── */
        const $ = id => document.getElementById(id);

        const WEIGHTS = {
            keyword: 20, // focus keyword set
            title: 20, // title length ok
            titleKw: 20, // keyword in title
            desc: 20, // description length ok
            descKw: 10, // keyword in description
            keywords: 10  // meta keywords set
        };

        function calcScore(data) {
            let score = 0;
            const results = {};
            const kw = data.keyword.trim();
            results.keyword = kw.length > 0;
            // Normalize: xoá ký tự đặc biệt để so sánh linh hoạt
            const normSeo = s => s.toLowerCase().replace(/[-–:&+().,!?"'/\\]/g, ' ').replace(/\s+/g, ' ').trim();
            const kw_low = kw.toLowerCase();
            const kw_norm = normSeo(kw);
            if (results.keyword) score += WEIGHTS.keyword;

            const tl = data.title.trim().length;
            results.title = tl >= 40 && tl <= 65; // Relaxed
            if (results.title) score += WEIGHTS.title;

            results.titleKw = kw.length > 0 && (
                data.title.toLowerCase().includes(kw_low) ||
                normSeo(data.title).includes(kw_norm)
            );
            if (results.titleKw) score += WEIGHTS.titleKw;

            const dl = data.desc.trim().length;
            results.desc = dl >= 120 && dl <= 170; // Relaxed
            if (results.desc) score += WEIGHTS.desc;

            results.descKw = kw.length > 0 && (
                data.desc.toLowerCase().includes(kw_low) ||
                normSeo(data.desc).includes(kw_norm)
            );
            if (results.descKw) score += WEIGHTS.descKw;

            results.keywords = data.keywords.trim().length > 0;
            if (results.keywords) score += WEIGHTS.keywords;

            return { score, results };
        }

        function applyCheck(id, pass) {
            const el = $(id);
            if (!el) return;
            el.className = 'check-item ' + (pass ? 'pass' : 'fail');
        }

        function renderScore(score, results) {
            const numEl = $('seoScoreNum');
            const ringEl = $('scoreRingFill');
            const statusEl = $('seoScoreStatus');
            const hintEl = $('seoScoreHint');
            const pillEl = $('seoScorePill');
            const labelEl = $('seoScoreLabel');

            if (numEl) numEl.textContent = score;

            if (ringEl) {
                const offset = 201 - (201 * score / 100);
                ringEl.style.strokeDashoffset = offset;
                ringEl.style.stroke = score >= 75 ? '#22c55e' : score >= 45 ? '#eab308' : '#ef4444';
            }

            let status, hint, cls;
            if (score >= 80) {
                status = '🎉 SEO Tốt'; hint = 'Tuyệt vời! Trang của bạn được tối ưu tốt.'; cls = 'good';
            } else if (score >= 50) {
                status = '⚡ SEO Khá'; hint = 'Cần cải thiện thêm một số tiêu chí.'; cls = 'okay';
            } else {
                status = '⚠️ Cần cải thiện'; hint = 'Hãy điền đầy đủ các trường SEO để tăng điểm.'; cls = 'poor';
            }

            if (statusEl) statusEl.textContent = status;
            if (hintEl) hintEl.textContent = hint;
            if (pillEl) pillEl.className = 'seo-score-pill ' + cls;
            if (labelEl) labelEl.textContent = score + '/100';

            applyCheck('chk-keyword', results.keyword);
            applyCheck('chk-title', results.title);
            applyCheck('chk-title-kw', results.titleKw);
            applyCheck('chk-desc', results.desc);
            applyCheck('chk-desc-kw', results.descKw);
            applyCheck('chk-keywords', results.keywords);
        }

        function updateProgressBar(barId, len, min, max) {
            const bar = $(barId);
            if (!bar) return;
            const pct = Math.min((len / max) * 100, 100);
            bar.style.width = pct + '%';
            bar.style.background = len === 0 ? '#9ca3af'
                : len < min ? '#eab308'
                    : len <= max ? '#22c55e' : '#ef4444';
        }

        function updatePreview(metaTitle, metaDesc) {
            const pt = $('seoPreviewTitle');
            const pd = $('seoPreviewDesc');
            const mainTitle = $('name') || $('title');
            const mainDesc = $('description') || $('summary');

            if (pt) pt.textContent = (metaTitle || (mainTitle ? mainTitle.value : '') || 'Tiêu đề trang') + ' ' + (typeof SEO_SEP !== 'undefined' ? SEO_SEP : ' | ') + (typeof SEO_SITE_NAME !== 'undefined' ? SEO_SITE_NAME : 'ShopSieuSale');
            if (pd) pd.textContent = (metaDesc || (mainDesc ? mainDesc.value : '') || 'Mô tả SEO sẽ hiển thị ở đây...').substring(0, 160);
        }

        function refreshAll() {
            const kw = ($('focusKeywordHidden') || { value: '' }).value.split(',')[0].trim();
            const title = ($('metaTitle') || { value: '' }).value;
            const desc = ($('metaDescription') || { value: '' }).value;
            const keys = ($('metaKeywordsHidden') || { value: '' }).value;

            updateProgressBar('metaTitleProgress', title.trim().length, 50, 65);
            updateProgressBar('metaDescProgress', desc.trim().length, 120, 160);
            $('metaTitleCount') && ($('metaTitleCount').textContent = title.length);
            $('metaDescCount') && ($('metaDescCount').textContent = desc.length);

            updatePreview(title, desc);

            const { score, results } = calcScore({ keyword: kw, title, desc, keywords: keys });
            renderScore(score, results);
        }

        /* ── Generic Tag Input ── */
        function initTagInput({ hiddenId, tagsId, inputId, boxId, countId, onChange }) {
            const hidden = $(hiddenId);
            const tagsEl = $(tagsId);
            const input = $(inputId);
            const box = $(boxId);
            const countEl = countId ? $(countId) : null;
            if (!hidden || !tagsEl || !input) return;

            let tags = hidden.value
                ? hidden.value.split(',').map(s => s.trim()).filter(Boolean)
                : [];

            function sync() {
                hidden.value = tags.join(', ');
                if (countEl) countEl.textContent = tags.length + ' từ khóa';
                if (onChange) onChange(tags);
            }

            function renderTags() {
                tagsEl.innerHTML = '';
                tags.forEach((tag, i) => {
                    const chip = document.createElement('span');
                    chip.className = 'kw-tag';
                    chip.innerHTML = `${tag} <button type="button" class="kw-tag-remove" title="Xóa">×</button>`;
                    chip.querySelector('.kw-tag-remove').addEventListener('click', (e) => {
                        e.stopPropagation();
                        tags.splice(i, 1);
                        renderTags();
                        sync();
                    });
                    tagsEl.appendChild(chip);
                });
                sync();
            }

            function addTag(val) {
                const cleaned = val.trim().replace(/,+$/, '');
                if (!cleaned || tags.includes(cleaned)) return;
                tags.push(cleaned);
                renderTags();
            }

            // Đồng bộ state khi AI điền dữ liệu (lắng nghe event tags:set)
            hidden.addEventListener('tags:set', function (e) {
                tags = (e.detail.values || []).map(v => v.trim()).filter(Boolean);
                renderTags();
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
                    e.preventDefault();
                    addTag(this.value);
                    this.value = '';
                } else if (e.key === 'Backspace' && this.value === '' && tags.length) {
                    tags.pop();
                    renderTags();
                }
            });

            input.addEventListener('blur', function () {
                if (this.value.trim()) { addTag(this.value); this.value = ''; }
            });

            if (box) box.addEventListener('click', () => input.focus());
            renderTags();
        }

        function init() {
            ['metaTitle', 'metaDescription'].forEach(id => {
                const el = $(id);
                if (el) el.addEventListener('input', refreshAll);
            });
            ['name', 'title', 'description', 'summary'].forEach(id => {
                const el = $(id);
                if (el) el.addEventListener('input', refreshAll);
            });

            initTagInput({
                hiddenId: 'focusKeywordHidden',
                tagsId: 'fkTags',
                inputId: 'fkInput',
                boxId: 'fkTagBox',
                countId: 'fkCount',
                onChange: refreshAll
            });

            initTagInput({
                hiddenId: 'metaKeywordsHidden',
                tagsId: 'kwTags',
                inputId: 'kwInput',
                boxId: 'kwTagBox',
                countId: 'kwCount',
                onChange: refreshAll
            });

            refreshAll();

            const btnAutoSeo = document.getElementById('btnAutoSeo');
            if (btnAutoSeo) {
                btnAutoSeo.addEventListener('click', function (e) {
                    e.stopPropagation(); // Chỉ ngăn collapse toggle, không gọi autoSEO() vì onclick đã có
                });
            }
        }

        document.readyState === 'loading'
            ? document.addEventListener('DOMContentLoaded', init)
            : init();
    })();

    /* ═══════════════════════════════════════════════
       AUTO SEO — Groq AI Only
       ═══════════════════════════════════════════════ */
    const GROQ_ENABLED = <?php echo $_seo_groq_enabled ? 'true' : 'false'; ?>;
    const GROQ_SEO_URL = '<?php echo rtrim(BASE_URL, '/') . '/admin/ajax/groq-seo.php'; ?>';
    const SEO_SITE_NAME = <?php echo json_encode(get_setting('site_name', 'ShopSieuSale')); ?>;
    const SEO_SEP = <?php echo json_encode(get_setting('seo_title_separator', ' | ')); ?>;

    function getCsrfToken() {
        if (window.AdminSecurity && typeof window.AdminSecurity.csrfToken === 'function') {
            return window.AdminSecurity.csrfToken() || '';
        }
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function autoSEO() {
        const btn = document.getElementById('btnAutoSeo');
        if (!GROQ_ENABLED) {
            if (window.AdminPopup) {
                AdminPopup.error('<strong>Chưa cấu hình Groq API</strong><br>Vào <a href="../seo/" class="alert-link text-white text-decoration-underline">Cấu hình SEO</a> để thêm API key miễn phí.');
            } else {
                alert('⚡ Tính năng Auto SEO cần Groq AI. Vui lòng cấu hình trong Settings.');
            }
            return;
        }

        const getVal = id => (document.getElementById(id) || {}).value || '';
        const rawTitle = getVal('name') || getVal('title');
        if (!rawTitle) {
            if (window.AdminPopup) AdminPopup.error('Vui lòng nhập tên / tiêu đề trước!');
            else alert('Vui lòng nhập tên / tiêu đề trước!');
            return;
        }
        const rawDesc = getVal('description') || getVal('summary');
        const rawContent = (() => {
            if (window.tinymce && tinymce.get('content'))
                return tinymce.get('content').getContent({ format: 'text' }).replace(/\s+/g, ' ').trim();
            return getVal('content').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        })();

        if (btn) {
            btn.disabled = true;
            btn.classList.add('loading');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> AI đang tạo...';
        }

        const fd = new FormData();
        fd.append('title', rawTitle);
        fd.append('description', rawDesc);
        fd.append('content', rawContent.substring(0, 2000));
        const csrfToken = getCsrfToken();
        if (csrfToken) {
            fd.append('csrf_token', csrfToken);
        }

        fetch(GROQ_SEO_URL, {
            method: 'POST',
            body: fd,
            headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
        })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || data.error || 'Lỗi API');

                const titleEl = document.getElementById('metaTitle');
                if (titleEl) { titleEl.value = data.meta_title; titleEl.dispatchEvent(new Event('input')); }

                const descEl = document.getElementById('metaDescription');
                if (descEl) { descEl.value = data.meta_description; descEl.dispatchEvent(new Event('input')); }

                setTagInputValues('focusKeywordHidden', [data.focus_keyword]);
                setTagInputValues('metaKeywordsHidden', data.meta_keywords);

                if (typeof refreshAll === 'function') refreshAll();

                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="bi bi-check-lg"></i> Đã tối ưu!';
                    btn.style.background = 'linear-gradient(135deg,#10b981,#059669)';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="bi bi-magic"></i> Auto SEO <span class="badge bg-warning text-dark ms-1" style="font-size:9px;vertical-align:middle">AI</span>';
                        btn.style.background = '';
                    }, 2500);
                }
            })
            .catch(err => {
                console.error('Groq SEO error:', err);
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="bi bi-magic"></i> Auto SEO <span class="badge bg-warning text-dark ms-1" style="font-size:9px;vertical-align:middle">AI</span>';
                }
                AdminPopup.error('Auto SEO thất bại: ' + (err.message || 'Lỗi kết nối Groq API'));
            });
    }

    /**
     * Helper: set values vào tag input system (AI và bên ngoài call)
     */
    function setTagInputValues(hiddenId, values) {
        const hidden = document.getElementById(hiddenId);
        if (!hidden) return;
        const vals = Array.isArray(values) ? values : [values];
        const event = new CustomEvent('tags:set', { detail: { values: vals } });
        hidden.dispatchEvent(event);
    }

    /* ── OG Image preview & clear ── */
    (function () {
        const ogInput   = document.getElementById('seoOgImageInput');
        const ogPreview = document.getElementById('seoOgImagePreview');
        const ogWrap    = document.getElementById('seoOgImagePreviewWrap');
        const ogClear   = document.getElementById('seoOgImageClear');

        if (ogInput && ogPreview && ogWrap) {
            // Listen for change event (fired by MediaSelector on selection)
            ogInput.addEventListener('change', function () {
                if (this.value) {
                    const src = this.value.startsWith('http') ? this.value : (typeof BASE_URL !== 'undefined' ? BASE_URL : '') + this.value;
                    ogPreview.src = src;
                    ogWrap.classList.remove('d-none');
                }
            });
        }

        if (ogClear && ogInput && ogWrap) {
            ogClear.addEventListener('click', function () {
                ogInput.value = '';
                ogWrap.classList.add('d-none');
                if (ogPreview) ogPreview.src = '';
                // Show default thumbnail if available (from placeholder attr)
                const placeholder = ogInput.getAttribute('placeholder') || '';
                if (placeholder.startsWith('Mặc định: ') && ogPreview && ogWrap) {
                    const defPath = placeholder.replace('Mặc định: ', '').trim();
                    const defSrc = defPath.startsWith('http') ? defPath : (typeof BASE_URL !== 'undefined' ? BASE_URL : '') + defPath;
                    ogPreview.src = defSrc;
                    ogWrap.classList.remove('d-none');
                }
            });
        }
    })();
</script>
