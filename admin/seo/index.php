<?php
// admin/seo/index.php - SEO Settings Management
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$current_page = 'seo';
$page_title = 'Cấu hình SEO';

// Auth check MUST be here, before POST processing.
require_admin_login();

// Detect page SEO schema compatibility (support robots flag optional)
$seo_settings_has_robots_index = false;
try {
    $seo_settings_has_robots_index = (bool) $pdo->query("SHOW COLUMNS FROM seo_settings LIKE 'robots_index'")->rowCount();

    if (!$seo_settings_has_robots_index) {
        try {
            $pdo->exec("ALTER TABLE seo_settings ADD COLUMN robots_index TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0: noindex,1:index'");
            $seo_settings_has_robots_index = true;
        } catch (PDOException $e) {
            $seo_settings_has_robots_index = false;
        }
    }
} catch (PDOException $e) {
    $seo_settings_has_robots_index = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();

    try {
        $global_settings = [
            'seo_title_separator' => $_POST['seo_title_separator'] ?? ' | ',
            'seo_default_og_image' => $_POST['seo_default_og_image'] ?? '',
            'google_analytics_id' => $_POST['google_analytics_id'] ?? '',
            'google_site_verification' => $_POST['google_site_verification'] ?? '',
            'seo_global_robots_index' => !empty($_POST['seo_global_robots_index']) ? '1' : '0'
        ];

        foreach ($global_settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group)
                                   VALUES (?, ?, 'seo')
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }

        // Update Groq API key only when new value is provided.
        if (!empty(trim($_POST['seo_groq_api_key'] ?? ''))) {
            $new_key = trim($_POST['seo_groq_api_key']);
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_group)
                                   VALUES ('seo_groq_api_key', ?, 'seo')
                                   ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$new_key, $new_key]);
        }

        if (!empty($_POST['page_seo']) && is_array($_POST['page_seo'])) {
            foreach ($_POST['page_seo'] as $page_key => $seo_data) {
                if ($seo_settings_has_robots_index) {
                    $stmt = $pdo->prepare("INSERT INTO seo_settings (page_key, meta_title, meta_description, meta_keywords, og_image, robots_index)
                                           VALUES (?, ?, ?, ?, ?, ?)
                                           ON DUPLICATE KEY UPDATE
                                               meta_title = VALUES(meta_title),
                                               meta_description = VALUES(meta_description),
                                               meta_keywords = VALUES(meta_keywords),
                                               og_image = VALUES(og_image),
                                               robots_index = VALUES(robots_index)");
                    $stmt->execute([
                        $page_key,
                        $seo_data['meta_title'] ?? '',
                        $seo_data['meta_description'] ?? '',
                        $seo_data['meta_keywords'] ?? '',
                        $seo_data['og_image'] ?? '',
                        empty($seo_data['robots_index']) ? 0 : 1
                    ]);
                    continue;
                }

                $stmt = $pdo->prepare("INSERT INTO seo_settings (page_key, meta_title, meta_description, meta_keywords, og_image)
                                       VALUES (?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE
                                           meta_title = VALUES(meta_title),
                                           meta_description = VALUES(meta_description),
                                           meta_keywords = VALUES(meta_keywords),
                                           og_image = VALUES(og_image)");
                $stmt->execute([
                    $page_key,
                    $seo_data['meta_title'] ?? '',
                    $seo_data['meta_description'] ?? '',
                    $seo_data['meta_keywords'] ?? '',
                    $seo_data['og_image'] ?? ''
                ]);
            }
        }

        $success = 'Đã lưu cấu hình SEO thành công!';
    } catch (PDOException $e) {
        $error = 'Lỗi: ' . $e->getMessage();
    }
}

$seo_title_separator = get_setting('seo_title_separator', ' | ');
$seo_default_og_image = get_setting('seo_default_og_image', '');
$google_analytics_id = get_setting('google_analytics_id', '');
$google_site_verification = get_setting('google_site_verification', '');
$seo_global_robots_index = (int) get_setting('seo_global_robots_index', 1);
$seo_groq_has_key = !empty(trim((string) get_setting('seo_groq_api_key', '')));
$site_name = get_setting('site_name', defined('SITE_NAME') ? SITE_NAME : 'Store');

try {
    $stmt = $pdo->query("SELECT * FROM seo_settings ORDER BY page_key");
    $page_seo_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $page_seo_settings = [];
}

$page_seo = [];
foreach ($page_seo_settings as $setting) {
    $page_seo[$setting['page_key']] = $setting;
}

$seo_pages = [
    'home' => 'Trang chủ',
    'contact' => 'Liên hệ'
];

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-search me-2"></i><?php echo e($page_title); ?></h2>
        <div class="d-flex gap-2">
            <a href="redirects.php" class="btn btn-warning">
                <i class="bi bi-arrow-left-right me-1"></i> Quản lý Redirect 301
            </a>
            <a href="<?php echo BASE_URL; ?>sitemap.php" target="_blank" class="btn btn-outline-primary">
                <i class="bi bi-diagram-3 me-1"></i> Xem Sitemap
            </a>
            <a href="<?php echo BASE_URL; ?>robots.txt" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-file-text me-1"></i> Xem Robots.txt
            </a>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-globe me-2"></i>Cấu hình SEO chung</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Title Separator</label>
                        <input type="text" class="form-control" name="seo_title_separator" value="<?php echo e($seo_title_separator); ?>" placeholder=" | ">
                        <small class="text-muted">Ký tự phân cách giữa page title và site name (mặc định: " | ")</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Default OG Image <small class="text-muted fw-normal">(fallback toàn site)</small></label>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control" id="seo_default_og_image" name="seo_default_og_image" value="<?php echo e($seo_default_og_image); ?>" placeholder="assets/images/og-default.jpg" readonly>
                            <button type="button" class="btn btn-outline-primary init-media-selector" data-input="seo_default_og_image" data-preview="seo_default_og_image_preview">
                                <i class="bi bi-images me-1"></i> Chọn ảnh
                            </button>
                        </div>
                        <?php if (!empty($seo_default_og_image)): ?>
                        <div id="seo_default_og_image_preview_wrap" class="mb-1">
                            <img id="seo_default_og_image_preview" src="<?php echo (strpos($seo_default_og_image,'http')===0?'':BASE_URL) . e($seo_default_og_image); ?>" class="img-thumbnail" style="max-height:80px;max-width:160px;object-fit:cover;">
                        </div>
                        <?php else: ?>
                        <div id="seo_default_og_image_preview_wrap" class="mb-1 d-none">
                            <img id="seo_default_og_image_preview" src="" class="img-thumbnail" style="max-height:80px;max-width:160px;object-fit:cover;">
                        </div>
                        <?php endif; ?>
                        <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Khuyến nghị: <strong>1200×630px</strong>. Ảnh này hiển thị khi share lên Facebook/Zalo nếu trang không có ảnh riêng.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Google Analytics ID</label>
                        <input type="text" class="form-control" name="google_analytics_id" value="<?php echo e($google_analytics_id); ?>" placeholder="G-XXXXXXXXXX">
                        <small class="text-muted">ID từ Google Analytics 4 (để trống nếu không sử dụng)</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Google Site Verification</label>
                        <input type="text" class="form-control" name="google_site_verification" value="<?php echo e($google_site_verification); ?>" placeholder="xxxxxxxxxxxxxx">
                        <small class="text-muted">Meta verification từ Google Search Console</small>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="seoGlobalRobots" name="seo_global_robots_index" value="1" <?php echo $seo_global_robots_index === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="seoGlobalRobots">Cho phép index toàn site</label>
                            <div class="form-text text-muted">Bỏ chọn để đặt toàn site là noindex, chặn toàn bộ index của robots.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php $llm_configured = trim((string) get_setting('llm_api_key', '')) !== ''; ?>
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header text-white d-flex align-items-center gap-2" style="background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);">
                <i class="bi bi-robot fs-5"></i>
                <h5 class="mb-0">Auto SEO bằng AI</h5>
                <?php if ($llm_configured): ?>
                    <span class="badge bg-white text-success ms-auto"><i class="bi bi-check-circle-fill me-1"></i>Đã kích hoạt</span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark ms-auto"><i class="bi bi-exclamation-triangle me-1"></i>Chưa cấu hình</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <div class="alert alert-info d-flex gap-2 align-items-start mb-0 border-0" style="background:#ede9fe;color:#4c1d95;border-radius:10px;">
                    <i class="bi bi-info-circle-fill mt-1"></i>
                    <div>
                        Nút <strong>Auto SEO</strong> (tự tạo Meta Title, Description, từ khóa) dùng chung cấu hình LLM với tính năng viết lại nội dung sản phẩm.
                        <?php if ($llm_configured): ?>
                            Cấu hình hiện đang bật. Chỉnh sửa tại
                        <?php else: ?>
                            Bạn cần cấu hình LLM trước khi dùng. Thiết lập tại
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>admin/settings/index.php?tab=ai" class="fw-bold" style="color:#4c1d95;"><i class="bi bi-gear me-1"></i>Cấu hình → tab AI / LLM</a>.
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>SEO theo trang</h5>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs" role="tablist">
                    <?php $first = true; foreach ($seo_pages as $key => $label): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $first ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-<?php echo $key; ?>" type="button">
                                <?php echo e($label); ?>
                            </button>
                        </li>
                    <?php $first = false; endforeach; ?>
                </ul>
                <div class="tab-content p-3 border border-top-0">
                    <?php $first = true; foreach ($seo_pages as $key => $label): $current_seo = $page_seo[$key] ?? []; ?>
                        <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" id="tab-<?php echo $key; ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                     <label class="form-label fw-bold">Meta Title <span class="text-muted">(tối đa 70 ký tự)</span></label>
                                    <input type="text" class="form-control seo-title-input" name="page_seo[<?php echo $key; ?>][meta_title]" value="<?php echo e($current_seo['meta_title'] ?? ''); ?>" maxlength="70">
                                    <small class="char-counter text-muted">0/70</small>
                                </div>
                                <div class="col-12">
                                     <label class="form-label fw-bold">Meta Description <span class="text-muted">(tối đa 160 ký tự)</span></label>
                                    <textarea class="form-control seo-desc-input" rows="2" name="page_seo[<?php echo $key; ?>][meta_description]" maxlength="160"><?php echo e($current_seo['meta_description'] ?? ''); ?></textarea>
                                    <small class="char-counter text-muted">0/160</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Meta Keywords</label>
                                    <div data-tag-input data-placeholder="Nhập từ khóa rồi nhấn Enter...">
                                        <input type="hidden" name="page_seo[<?php echo $key; ?>][meta_keywords]" value="<?php echo e($current_seo['meta_keywords'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">OG Image <small class="text-muted fw-normal">(thumbnail mạng xã hội)</small></label>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control" id="og_image_<?php echo $key; ?>" name="page_seo[<?php echo $key; ?>][og_image]" value="<?php echo e($current_seo['og_image'] ?? ''); ?>" placeholder="Để trống = dùng ảnh mặc định site" readonly>
                                        <button type="button" class="btn btn-outline-primary init-media-selector" data-input="og_image_<?php echo $key; ?>" data-preview="og_image_preview_<?php echo $key; ?>">
                                            <i class="bi bi-images me-1"></i> Chọn ảnh
                                        </button>
                                    </div>
                                    <?php $cur_og = $current_seo['og_image'] ?? ''; ?>
                                    <div id="og_image_preview_<?php echo $key; ?>_wrap" class="mb-1<?php echo empty($cur_og) ? ' d-none' : ''; ?>">
                                        <img id="og_image_preview_<?php echo $key; ?>" src="<?php echo !empty($cur_og) ? ((strpos($cur_og,'http')===0?'':BASE_URL).e($cur_og)) : ''; ?>" class="img-thumbnail" style="max-height:80px;max-width:160px;object-fit:cover;">
                                    </div>
                                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i>1200×630px. Ảnh riêng cho trang <strong><?php echo e($label); ?></strong>.</small>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="robotsIndex<?php echo $key; ?>" name="page_seo[<?php echo $key; ?>][robots_index]" value="1" <?php echo isset($current_seo['robots_index']) && (int)$current_seo['robots_index'] === 0 ? '' : 'checked'; ?>>
                                        <label class="form-check-label fw-bold" for="robotsIndex<?php echo $key; ?>">
                                            Cho phép index bởi công cụ tìm kiếm
                                        </label>
                                        <div class="form-text text-muted">Bỏ chọn để chặn trang này khỏi index của robots.</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">Xem trước trên Google</label>
                                    <div class="p-3 bg-light rounded border">
                                        <div class="google-preview">
                                            <div class="preview-title text-primary" style="font-size: 18px; text-decoration: underline;">
                                                <?php echo e($current_seo['meta_title'] ?? $label); ?> <?php echo e($seo_title_separator . $site_name); ?>
                                            </div>
                                            <div class="preview-url text-success" style="font-size: 14px;">
                                                <?php echo BASE_URL . ($key === 'home' ? '' : $key . '.php'); ?>
                                            </div>
                                            <div class="preview-desc text-muted" style="font-size: 14px;">
                                                <?php echo e($current_seo['meta_description'] ?? 'Mô tả trang sẽ hiển thị ở đây...'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Liên kết SEO</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center p-3 bg-light rounded">
                            <i class="bi bi-diagram-3 fs-3 text-primary me-3"></i>
                            <div>
                                <strong>Sitemap</strong><br>
                                <a href="<?php echo BASE_URL; ?>sitemap.php" target="_blank" class="text-decoration-none"><?php echo BASE_URL; ?>sitemap.php</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center p-3 bg-light rounded">
                            <i class="bi bi-file-text fs-3 text-success me-3"></i>
                            <div>
                                <strong>Robots.txt</strong><br>
                                <a href="<?php echo BASE_URL; ?>robots.txt" target="_blank" class="text-decoration-none"><?php echo BASE_URL; ?>robots.txt</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center p-3 bg-light rounded">
                            <i class="bi bi-google fs-3 text-danger me-3"></i>
                            <div>
                                <strong>Google Search Console</strong><br>
                                <a href="https://search.google.com/search-console" target="_blank" class="text-decoration-none">Truy cập GSC</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-check-lg me-2"></i>Lưu cấu hình
            </button>
        </div>
    </form>
</div>

<style>
    .google-preview {
        font-family: Arial, sans-serif;
    }

    .char-counter {
        float: right;
    }

    .seo-title-input,
    .seo-desc-input {
        font-family: Arial, sans-serif;
    }
</style>

<script>
    const toggleGroqKey = document.getElementById('toggleGroqKey');
    const groqKeyInput = document.getElementById('seo_groq_api_key_input');
    if (toggleGroqKey && groqKeyInput) {
        toggleGroqKey.addEventListener('click', function () {
            const isHidden = groqKeyInput.type === 'password';
            groqKeyInput.type = isHidden ? 'text' : 'password';
            this.querySelector('i').className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    }

    const groqTestBtn = document.getElementById('btn_test_groq');
    const groqTestResult = document.getElementById('groq_test_result');
    if (groqTestBtn) {
        groqTestBtn.addEventListener('click', function () {
            groqTestBtn.disabled = true;
            groqTestBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang test...';
            if (groqTestResult) groqTestResult.textContent = '';

            const body = new URLSearchParams();
            if (window.AdminSecurity) {
                AdminSecurity.applyCsrf(body);
            } else {
                body.set('csrf_token', '<?php echo e(generate_csrf_token()); ?>');
            }

            fetch('../ajax/groq-test.php', {
                method: 'POST',
                headers: window.AdminSecurity
                    ? AdminSecurity.headers({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' })
                    : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body
            })
                .then(r => r.json())
                .then(data => {
                    groqTestBtn.disabled = false;
                    groqTestBtn.innerHTML = '<i class="bi bi-lightning-charge-fill"></i> Test kết nối Groq';
                    if (groqTestResult) {
                        groqTestResult.className = 'form-text ' + (data.success ? 'text-success fw-bold' : 'text-danger');
                        groqTestResult.textContent = data.message;
                    }
                })
                .catch(() => {
                    groqTestBtn.disabled = false;
                    groqTestBtn.innerHTML = '<i class="bi bi-lightning-charge-fill"></i> Test kết nối Groq';
                    if (groqTestResult) {
                        groqTestResult.className = 'form-text text-danger';
                        groqTestResult.textContent = 'Không thể kết nối server.';
                    }
                });
        });
    }

    document.querySelectorAll('.seo-title-input, .seo-desc-input').forEach(input => {
        const counter = input.parentElement.querySelector('.char-counter');
        const maxLen = input.getAttribute('maxlength');

        function updateCounter() {
            const len = input.value.length;
            counter.textContent = len + '/' + maxLen;
            counter.classList.toggle('text-danger', len > maxLen * 0.9);
        }

        input.addEventListener('input', updateCounter);
        updateCounter();
    });

    document.querySelectorAll('.seo-title-input').forEach(input => {
        input.addEventListener('input', function () {
            const preview = this.closest('.tab-pane').querySelector('.preview-title');
            if (preview) {
                preview.textContent = (this.value || 'Tiêu đề trang') + ' <?php echo e($seo_title_separator . $site_name); ?>';
            }
        });
    });

    document.querySelectorAll('.seo-desc-input').forEach(input => {
        input.addEventListener('input', function () {
            const preview = this.closest('.tab-pane').querySelector('.preview-desc');
            if (preview) {
                preview.textContent = this.value || 'Mô tả trang sẽ hiển thị ở đây...';
            }
        });
    });

    // OG Image preview: show wrap when image selected via media library
    document.body.addEventListener('change', function (e) {
        const input = e.target;
        if (!input.id) return;
        const wrap = document.getElementById(input.id + '_preview_wrap') || document.getElementById(input.id.replace('og_image_', 'og_image_preview_') + '_wrap');
        const preview = document.getElementById(input.id + '_preview') || document.getElementById(input.id.replace('og_image_', 'og_image_preview_'));
        // Handle seo_default_og_image specifically
        if (input.id === 'seo_default_og_image') {
            const w = document.getElementById('seo_default_og_image_preview_wrap');
            const p = document.getElementById('seo_default_og_image_preview');
            if (w && p && input.value) {
                p.src = (input.value.startsWith('http') ? '' : BASE_URL) + input.value;
                w.classList.remove('d-none');
            }
        }
        // Handle per-page og_image_<key>
        if (input.id && input.id.startsWith('og_image_') && !input.id.startsWith('og_image_preview_')) {
            const key = input.id.replace('og_image_', '');
            const w = document.getElementById('og_image_preview_' + key + '_wrap');
            const p = document.getElementById('og_image_preview_' + key);
            if (w && p && input.value) {
                p.src = (input.value.startsWith('http') ? '' : BASE_URL) + input.value;
                w.classList.remove('d-none');
            }
        }
    });

</script>

<script src="<?php echo BASE_URL; ?>assets/js/tag-input.js"></script>

<?php require_once '../includes/footer.php'; ?>
