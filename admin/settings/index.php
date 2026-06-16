<?php
// admin/settings/index.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/url-helper.php';

$current_page = 'settings';

// Auth check MUST be here, before POST processing.
// header.php also calls require_admin_login(), but POST exits via redirect
// before header.php is included — so expired sessions could save as "Guest".
require_admin_login();

$allowed_tabs = ['general', 'appearance', 'contact', 'footer', 'email', 'url', 'performance', 'ai', 'deal_bubble', 'sidebar'];

function normalize_tab($tab, $allowed_tabs)
{
    $tab = trim((string) $tab);
    return in_array($tab, $allowed_tabs, true) ? $tab : 'general';
}

function setting_group_from_key($key)
{
    if (strpos($key, 'contact_') === 0) {
        return 'contact';
    }
    if (strpos($key, 'site_theme_') === 0) {
        return 'appearance';
    }
    if (strpos($key, 'footer_') === 0) {
        return 'footer';
    }
    if (strpos($key, 'smtp_') === 0) {
        return 'email';
    }
    if (strpos($key, 'url_') === 0) {
        return 'url';
    }
    if (strpos($key, 'perf_') === 0) {
        return 'performance';
    }
    if (strpos($key, 'seo_') === 0) {
        return 'seo';
    }
    if (strpos($key, 'llm_') === 0) {
        return 'ai';
    }
    if (strpos($key, 'deal_today_bubble_') === 0) {
        return 'deal_bubble';
    }
    if (strpos($key, 'sidebar_') === 0) {
        return 'sidebar';
    }
    return 'general';
}

function upsert_setting($pdo, $key, $value)
{
    $stmt = $pdo->prepare('SELECT id FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);

    if ($stmt->fetch()) {
        $stmt = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $stmt->execute([$value, $key]);
        return;
    }

    $group = setting_group_from_key($key);
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)');
    $stmt->execute([$key, $value, $group]);
}

$active_tab = normalize_tab($_GET['tab'] ?? 'general', $allowed_tabs);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf_token();

    // Hành động "Dọn ngay" dữ liệu traffic (chạy thủ công, bỏ qua throttle).
    if (($_POST['action'] ?? '') === 'prune_traffic_now') {
        $res = function_exists('run_traffic_prune_now') ? run_traffic_prune_now($pdo) : [];
        $total = 0;
        foreach ($res as $n) {
            if ((int) $n > 0) {
                $total += (int) $n;
            }
        }
        $_SESSION['settings_flash_success'] = 'Đã dọn dữ liệu cũ: xóa ' . number_format($total) . ' dòng.';
        header('Location: ?tab=performance');
        exit;
    }

    $post_settings = $_POST['settings'] ?? [];
    if (!is_array($post_settings)) {
        $post_settings = [];
    }

    $active_tab = normalize_tab($_POST['active_tab'] ?? $active_tab, $allowed_tabs);

    // Checkbox fields: not present in POST when unchecked.
    $post_settings['smtp_enabled'] = isset($_POST['settings']['smtp_enabled']) ? '1' : '0';
    $post_settings['preloader_enabled'] = isset($_POST['settings']['preloader_enabled']) ? '1' : '0';
    $post_settings['perf_gzip_enabled'] = isset($_POST['settings']['perf_gzip_enabled']) ? '1' : '0';
    $post_settings['perf_cache_enabled'] = isset($_POST['settings']['perf_cache_enabled']) ? '1' : '0';
    $post_settings['perf_webp_enabled'] = isset($_POST['settings']['perf_webp_enabled']) ? '1' : '0';
    $post_settings['page_cache_enabled'] = isset($_POST['settings']['page_cache_enabled']) ? '1' : '0';
    $post_settings['perf_traffic_prune_enabled'] = isset($_POST['settings']['perf_traffic_prune_enabled']) ? '1' : '0';
    $post_settings['deal_today_bubble_enabled'] = isset($_POST['settings']['deal_today_bubble_enabled']) ? '1' : '0';
    if ($active_tab === 'sidebar') {
        $post_settings['sidebar_enabled'] = isset($_POST['settings']['sidebar_enabled']) ? '1' : '0';
    }
    // Keep current Groq API key if left blank
    if (isset($post_settings['seo_groq_api_key']) && $post_settings['seo_groq_api_key'] === '') {
        unset($post_settings['seo_groq_api_key']);
    }
    // Keep current LLM API key if left blank
    if (isset($post_settings['llm_api_key']) && trim((string) $post_settings['llm_api_key']) === '') {
        unset($post_settings['llm_api_key']);
    }
    $current_settings = [];
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    while ($row = $stmt->fetch()) {
        $current_settings[$row['setting_key']] = (string) $row['setting_value'];
    }

    try {
        $pdo->beginTransaction();
        $smtp_password_updated = false;
        $url_prefix_changed = false;
        $appearance_changed = false;

        foreach ($post_settings as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($key === '') {
                continue;
            }

            // Keep current SMTP password if admin leaves it empty.
            if ($key === 'smtp_pass' && $value === '') {
                continue;
            }

            if (strpos($key, 'url_') === 0) {
                $old_value = isset($current_settings[$key]) ? (string) $current_settings[$key] : '';
                if ($old_value !== $value) {
                    $url_prefix_changed = true;
                }
            }
            if (strpos($key, 'site_theme_') === 0) {
                $old_value = isset($current_settings[$key]) ? (string) $current_settings[$key] : '';
                if ($old_value !== $value) {
                    $appearance_changed = true;
                }
            }

            upsert_setting($pdo, $key, $value);

            if ($key === 'smtp_pass') {
                $smtp_password_updated = true;
            }
        }

        if ($smtp_password_updated) {
            upsert_setting($pdo, 'smtp_pass_updated_at', date('Y-m-d H:i:s'));
        }
        if ($appearance_changed) {
            upsert_setting($pdo, 'cache_version', (string) time());
        }

        // Lưu override sidebar theo trang (bảng page_sidebar_settings)
        if (isset($_POST['page_sidebar']) && is_array($_POST['page_sidebar'])) {
            $allowed_modes = ['default', 'show', 'hide'];
            $allowed_pos = ['default', 'left', 'right'];
            $ps = $pdo->prepare("UPDATE page_sidebar_settings SET sidebar_mode = ?, sidebar_position = ? WHERE page_key = ?");
            foreach ($_POST['page_sidebar'] as $pageKey => $cfg) {
                $m = in_array($cfg['mode'] ?? '', $allowed_modes, true) ? $cfg['mode'] : 'default';
                $p = in_array($cfg['position'] ?? '', $allowed_pos, true) ? $cfg['position'] : 'default';
                $ps->execute([$m, $p, (string) $pageKey]);
            }
        }

        $upload_dir = ROOT_PATH . 'assets/uploads/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (isset($_FILES['site_logo']) && (int) $_FILES['site_logo']['error'] === 0) {
            $uploaded_logo = upload_file($_FILES['site_logo'], $upload_dir);
            if ($uploaded_logo) {
                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'site_logo'");
                $stmt->execute(['assets/uploads/' . $uploaded_logo]);
            }
        }

        if (isset($_FILES['site_favicon']) && (int) $_FILES['site_favicon']['error'] === 0) {
            $uploaded_favicon = upload_file($_FILES['site_favicon'], $upload_dir);
            if ($uploaded_favicon) {
                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'site_favicon'");
                $stmt->execute(['assets/uploads/' . $uploaded_favicon]);
            }
        }

        if (isset($_FILES['preloader_logo']) && (int) $_FILES['preloader_logo']['error'] === 0) {
            $uploaded_preloader_logo = upload_file($_FILES['preloader_logo'], $upload_dir);
            if ($uploaded_preloader_logo) {
                upsert_setting($pdo, 'preloader_logo', 'assets/uploads/' . $uploaded_preloader_logo);
            }
        }

        $pdo->commit();
        if ($appearance_changed) {
            require_once '../../includes/page-cache.php';
            PageCache::flush();
        }

        if ($url_prefix_changed) {
            clearUrlPrefixCache();
            $htaccess_ok = saveHtaccess();
            if ($htaccess_ok) {
                $_SESSION['settings_flash_success'] = 'Cập nhật cấu hình thành công! File .htaccess đã được cập nhật.';
            } else {
                $_SESSION['settings_flash_success'] = 'Cập nhật cấu hình thành công! (Lưu ý: Không thể cập nhật .htaccess, vui lòng kiểm tra quyền ghi file).';
            }
        } else {
            $_SESSION['settings_flash_success'] = 'Cập nhật cấu hình thành công!';
        }

        if (function_exists('log_activity')) {
            $logDetail = "Cập nhật cấu hình: " . ucfirst($active_tab);
            if ($url_prefix_changed)
                $logDetail .= " (Thay đổi URL Prefix)";
            if ($smtp_password_updated)
                $logDetail .= " (Đổi mật khẩu SMTP)";
            log_activity('update', 'settings', null, $logDetail);
        }

        // Save performance .htaccess block if on performance tab
        if ($active_tab === 'performance') {
            $gzip = ($post_settings['perf_gzip_enabled'] ?? '0') === '1';
            $cache = ($post_settings['perf_cache_enabled'] ?? '0') === '1';
            $perf_ok = savePerformanceHtaccess($gzip, $cache);
            if ($perf_ok) {
                $_SESSION['settings_flash_success'] = 'Cập nhật cấu hình hiệu năng thành công! File .htaccess đã được cập nhật.';
            } else {
                $_SESSION['settings_flash_success'] = 'Cập nhật cấu hình thành công! (Lưu ý: Không thể cập nhật .htaccess).';
            }
            if (function_exists('log_activity')) {
                log_activity('update', 'settings', null, "Cập nhật cấu hình Hiệu năng (Gzip: " . ($gzip ? 'Bật' : 'Tắt') . ", Cache: " . ($cache ? 'Bật' : 'Tắt') . ")");
            }
            
            // Auto flush page cache when performance settings are updated
            if (class_exists('PageCache')) {
                PageCache::flush();
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['settings_flash_error'] = 'Lỗi: ' . $e->getMessage();
    }

    header('Location: index.php?tab=' . urlencode($active_tab));
    exit;
}

$error = $_SESSION['settings_flash_error'] ?? '';
$success = $_SESSION['settings_flash_success'] ?? '';
unset($_SESSION['settings_flash_error'], $_SESSION['settings_flash_success']);

$settings = [];
$stmt = $pdo->query('SELECT * FROM settings');
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$theme_palettes = site_theme_palettes();
$current_theme_palette = (string) ($settings['site_theme_palette'] ?? 'violet');
if (!isset($theme_palettes[$current_theme_palette])) {
    $current_theme_palette = 'violet';
}

// Sidebar: cấu hình tổng + override theo trang
$sidebar_enabled = ($settings['sidebar_enabled'] ?? '1') === '1';
$sidebar_position = ($settings['sidebar_position'] ?? 'right') === 'left' ? 'left' : 'right';
try {
    $page_sidebar_rows = $pdo->query("SELECT * FROM page_sidebar_settings ORDER BY id ASC")->fetchAll();
} catch (Throwable $e) {
    $page_sidebar_rows = [];
}
try {
    $sidebar_widget_count = (int) $pdo->query("SELECT COUNT(*) FROM widgets WHERE is_active = 1")->fetchColumn();
} catch (Throwable $e) {
    $sidebar_widget_count = 0;
}

$is_smtp_enabled = in_array(strtolower(trim((string) ($settings['smtp_enabled'] ?? '0'))), ['1', 'true', 'yes', 'on'], true);
$smtp_secure = strtolower(trim((string) ($settings['smtp_secure'] ?? 'tls')));
if (!in_array($smtp_secure, ['tls', 'ssl', 'none'], true)) {
    $smtp_secure = 'tls';
}
$phpmailer_autoload_exists = file_exists(ROOT_PATH . 'vendor/autoload.php');
$smtp_has_password = trim((string) ($settings['smtp_pass'] ?? '')) !== '';
$smtp_pass_updated_at = trim((string) ($settings['smtp_pass_updated_at'] ?? ''));

require_once '../includes/header.php';
?>

<div class="container-fluid">
    <div
        class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Cấu hình hệ thống</h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?php echo e($error); ?>
        </div>
        <?php
    endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success">
            <?php echo e($success); ?>
        </div>
        <?php
    endif; ?>

    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo e(generate_csrf_token()); ?>">
        <input type="hidden" name="active_tab" id="active_tab" value="<?php echo e($active_tab); ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <ul class="nav nav-tabs card-header-tabs" id="settingTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'general' ? 'active' : ''; ?>" id="general-tab"
                            data-bs-toggle="tab" data-tab-key="general" href="#general" role="tab">Chung</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'appearance' ? 'active' : ''; ?>" id="appearance-tab"
                            data-bs-toggle="tab" data-tab-key="appearance" href="#appearance" role="tab">
                            <i class="bi bi-palette me-1"></i>Giao di&#7879;n
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'contact' ? 'active' : ''; ?>" id="contact-tab"
                            data-bs-toggle="tab" data-tab-key="contact" href="#contact" role="tab">Liên hệ</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'footer' ? 'active' : ''; ?>" id="footer-tab"
                            data-bs-toggle="tab" data-tab-key="footer" href="#footer" role="tab">Footer</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'email' ? 'active' : ''; ?>" id="email-tab"
                            data-bs-toggle="tab" data-tab-key="email" href="#email" role="tab">Email SMTP</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'url' ? 'active' : ''; ?>" id="url-tab"
                            data-bs-toggle="tab" data-tab-key="url" href="#url" role="tab">URL</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'performance' ? 'active' : ''; ?>"
                            id="performance-tab" data-bs-toggle="tab" data-tab-key="performance" href="#performance"
                            role="tab">Hiệu năng</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'ai' ? 'active' : ''; ?>"
                            id="ai-tab" data-bs-toggle="tab" data-tab-key="ai" href="#ai"
                            role="tab"><i class="bi bi-robot me-1"></i>AI / LLM</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'deal_bubble' ? 'active' : ''; ?>"
                            id="deal_bubble-tab" data-bs-toggle="tab" data-tab-key="deal_bubble" href="#deal_bubble"
                            role="tab"><i class="bi bi-chat-dots me-1"></i>Bong bóng Deal</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'sidebar' ? 'active' : ''; ?>"
                            id="sidebar-tab" data-bs-toggle="tab" data-tab-key="sidebar" href="#sidebar"
                            role="tab"><i class="bi bi-layout-sidebar-inset-reverse me-1"></i>Sidebar</a>
                    </li>

                </ul>
            </div>

            <div class="card-body">
                <div class="tab-content" id="settingTabsContent">
                    <div class="tab-pane fade <?php echo $active_tab === 'general' ? 'show active' : ''; ?>"
                        id="general" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label">Tên website</label>
                            <input type="text" class="form-control" name="settings[site_name]"
                                value="<?php echo e($settings['site_name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Logo</label>
                            <?php if (!empty($settings['site_logo'])): ?>
                                <?php 
                                    $logo_src = (strpos($settings['site_logo'], 'http') === 0 || strpos($settings['site_logo'], '//') === 0) ? $settings['site_logo'] : BASE_URL . $settings['site_logo'];
                                ?>
                                <div class="mb-2"><img src="<?php echo $logo_src; ?>" height="50"
                                        alt="Logo"></div>
                                <?php
                            endif; ?>
                            <input type="file" class="form-control" name="site_logo">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Favicon</label>
                            <?php if (!empty($settings['site_favicon'])): ?>
                                <?php 
                                    $fav_src = (strpos($settings['site_favicon'], 'http') === 0 || strpos($settings['site_favicon'], '//') === 0) ? $settings['site_favicon'] : BASE_URL . $settings['site_favicon'];
                                ?>
                                <div class="mb-2"><img src="<?php echo $fav_src; ?>" height="32"
                                        alt="Favicon"></div>
                                <?php
                            endif; ?>
                            <input type="file" class="form-control" name="site_favicon">
                        </div>
                        <div class="card border-0 shadow-sm bg-light">
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="preloader_enabled"
                                        name="settings[preloader_enabled]" value="1"
                                        <?php echo ($settings['preloader_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="preloader_enabled">Bật loading đầu trang</label>
                                </div>
                                <label class="form-label">Logo loading</label>
                                <?php
                                    $current_preloader_logo = trim((string) ($settings['preloader_logo'] ?? ''));
                                    $preloader_preview = $current_preloader_logo !== ''
                                        ? ((strpos($current_preloader_logo, 'http') === 0 || strpos($current_preloader_logo, '//') === 0) ? $current_preloader_logo : BASE_URL . $current_preloader_logo)
                                        : '';
                                ?>
                                <?php if ($preloader_preview !== ''): ?>
                                    <div class="mb-2"><img src="<?php echo $preloader_preview; ?>" height="50" alt="Loading logo"></div>
                                <?php elseif (!empty($settings['site_logo'])): ?>
                                    <div class="form-text mb-2">Chưa có logo loading riêng, hệ thống đang dùng logo website hiện tại.</div>
                                <?php endif; ?>
                                <input type="file" class="form-control" name="preloader_logo">
                                <div class="form-text">Để trống nếu muốn loading dùng chung logo website.</div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'appearance' ? 'show active' : ''; ?>"
                        id="appearance" role="tabpanel">
                        <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                            <i class="bi bi-palette-fill fs-5 mt-1 text-primary"></i>
                            <div>
                                <strong>Ch&#7885;n tone m&#224;u ch&#7911; &#273;&#7841;o cho website.</strong>
                                C&#225;c m&#224;u n&#224;y &#273;&#432;&#7907;c &#225;p d&#7909;ng cho header, n&#250;t, link, card blog, sidebar, badge v&#224; footer.
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php foreach ($theme_palettes as $key => $palette): ?>
                                <?php $checked = $current_theme_palette === $key; ?>
                                <div class="col-md-6 col-xl-3">
                                    <label class="theme-choice h-100 <?php echo $checked ? 'is-active' : ''; ?>">
                                        <input type="radio" name="settings[site_theme_palette]" value="<?php echo e($key); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                        <span class="theme-choice__swatches">
                                            <span style="background:<?php echo e($palette['primary']); ?>"></span>
                                            <span style="background:<?php echo e($palette['dark']); ?>"></span>
                                            <span style="background:<?php echo e($palette['accent']); ?>"></span>
                                            <span style="background:<?php echo e($palette['soft']); ?>"></span>
                                        </span>
                                        <span class="theme-choice__name"><?php echo e($palette['label']); ?></span>
                                        <span class="theme-choice__sample" style="<?php echo e(site_theme_css_vars($key)); ?>">
                                            <span class="sample-card">
                                                <span class="sample-title"></span>
                                                <span class="sample-line"></span>
                                                <span class="sample-pill">Aa</span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-text mt-3">
                            Sau khi l&#432;u, h&#7879; th&#7889;ng s&#7869; t&#7921; x&#243;a page cache v&#224; &#273;&#7893;i phi&#234;n b&#7843;n CSS/JS &#273;&#7875; kh&#225;ch truy c&#7853;p th&#7845;y m&#224;u m&#7899;i ngay.
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'contact' ? 'show active' : ''; ?>"
                        id="contact" role="tabpanel">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email li&#234;n h&#7879;</label>
                                <input type="email" class="form-control" name="settings[contact_email]"
                                    value="<?php echo e($settings['contact_email'] ?? ''); ?>">
                                <div class="form-text">Email hi&#7875;n th&#7883; tr&#234;n trang li&#234;n h&#7879; v&#224; footer.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Hotline</label>
                                <input type="text" class="form-control" name="settings[contact_phone]"
                                    value="<?php echo e($settings['contact_phone'] ?? ''); ?>">
                                <div class="form-text">S&#7889; &#273;i&#7879;n tho&#7841;i hi&#7875;n th&#7883; tr&#234;n n&#250;t g&#7885;i nhanh.</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">&#272;&#7883;a ch&#7881;</label>
                            <input type="text" class="form-control" name="settings[contact_address]"
                                value="<?php echo e($settings['contact_address'] ?? ''); ?>">
                            <div class="form-text">&#272;&#7883;a ch&#7881; c&#244;ng ty/v&#259;n ph&#242;ng hi&#7875;n th&#7883; tr&#234;n trang li&#234;n h&#7879;.</div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Zalo (S&#272;T)</label>
                                <input type="text" class="form-control" name="settings[contact_zalo]"
                                    value="<?php echo e($settings['contact_zalo'] ?? ''); ?>">
                                <div class="form-text">S&#7889; &#273;i&#7879;n tho&#7841;i &#273;&#259;ng k&#253; Zalo.</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Messenger (username)</label>
                                <input type="text" class="form-control" name="settings[contact_messenger]"
                                    value="<?php echo e($settings['contact_messenger'] ?? ''); ?>">
                                <div class="form-text">V&#237; d&#7909;: fptstore.</div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Telegram</label>
                                <input type="text" class="form-control" name="settings[contact_telegram]"
                                    value="<?php echo e($settings['contact_telegram'] ?? ''); ?>">
                                <div class="form-text">Nh&#7853;p username ho&#7863;c link Telegram, v&#237; d&#7909;: @fptstore ho&#7863;c https://t.me/fptstore.</div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'footer' ? 'show active' : ''; ?>" id="footer"
                        role="tabpanel">

                        <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                            <i class="bi bi-info-circle-fill mt-1"></i>
                            <div>
                                <strong>H&#432;&#7899;ng d&#7851;n:</strong> M&#7895;i c&#7897;t h&#7895; tr&#7907; HTML. D&#249;ng <code>&lt;h3&gt;</code> cho ti&#234;u &#273;&#7873;,
                                <code>&lt;ul&gt;&lt;li&gt;</code> cho danh s&#225;ch, <code>&lt;p&gt;</code> cho &#273;o&#7841;n v&#259;n.
                                <br><strong>C&#7897;t 2 - D&#7883;ch v&#7909;:</strong> Danh m&#7909;c d&#7883;ch v&#7909; s&#7869; <strong>t&#7921; &#273;&#7897;ng load</strong>
                                t&#7915; database b&#234;n d&#432;&#7899;i ti&#234;u &#273;&#7873;. B&#7841;n ch&#7881; c&#7847;n nh&#7853;p ti&#234;u &#273;&#7873;, v&#237; d&#7909;:
                                <code>&lt;h3&gt;D&#7883;ch v&#7909;&lt;/h3&gt;</code>.
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Màu chữ footer</label>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="color" class="form-control form-control-color"
                                        name="settings[footer_text_color]"
                                        value="<?php echo e($settings['footer_text_color'] ?? '#adb5bd'); ?>"
                                        title="Chọn màu chữ footer">
                                    <input type="text" class="form-control"
                                        value="<?php echo e($settings['footer_text_color'] ?? '#adb5bd'); ?>"
                                        oninput="this.previousElementSibling.value=this.value"
                                        placeholder="#adb5bd">
                                </div>
                                <div class="form-text">Áp dụng cho nội dung chữ ở 4 cột footer.</div>
                            </div>
                        </div>

                        <div class="row">
                            <?php
                            $footer_cols = [
                                ['key' => 'footer_col1', 'label' => 'Cột 1 — Giới thiệu', 'icon' => 'bi-building', 'hint' => 'Thông tin công ty, mô tả ngắn.'],
                                ['key' => 'footer_col2', 'label' => 'Cột 2 — Dịch vụ', 'icon' => 'bi-grid', 'hint' => 'Chỉ cần tiêu đề. Danh mục auto-load từ DB.'],
                                ['key' => 'footer_col3', 'label' => 'Cột 3 — Hỗ trợ', 'icon' => 'bi-life-preserver', 'hint' => 'Liên kết hỗ trợ, thanh toán, bảo hành...'],
                                ['key' => 'footer_col4', 'label' => 'Cột 4 — Liên hệ', 'icon' => 'bi-telephone', 'hint' => 'Hotline, email, địa chỉ liên hệ.'],
                            ];
                            foreach ($footer_cols as $col): ?>
                                <div class="col-md-6 mb-4">
                                    <div class="card border h-100">
                                        <div class="card-header bg-light d-flex align-items-center gap-2 py-2">
                                            <i class="bi <?php echo $col['icon']; ?> text-primary"></i>
                                            <strong>
                                                <?php echo $col['label']; ?>
                                            </strong>
                                        </div>
                                        <div class="card-body p-3">
                                            <textarea class="footer-tinymce" name="settings[<?php echo $col['key']; ?>]"
                                                id="<?php echo $col['key']; ?>_editor"><?php echo e($settings[$col['key']] ?? ''); ?></textarea>
                                            <div class="form-text mt-1">
                                                <i class="bi bi-lightbulb me-1"></i>
                                                <?php echo $col['hint']; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            endforeach; ?>
                        </div>

                        <div class="card border mb-4">
                            <div class="card-header bg-light d-flex align-items-center gap-2 py-2">
                                <i class="bi bi-link-45deg text-primary"></i>
                                <strong>Liên kết hệ sinh thái (Ecosystem Links)</strong>
                            </div>
                            <div class="card-body p-3">
                                <textarea class="form-control font-monospace" name="settings[footer_ecosystem]"
                                    id="footer_ecosystem" rows="4" placeholder="Ví dụ:&#10;Dự án 1|https://duan1.com&#10;Dự án 2|https://duan2.com"><?php echo e($settings['footer_ecosystem'] ?? ''); ?></textarea>
                                <div class="form-text mt-2">
                                    <i class="bi bi-info-circle me-1"></i> Nhập mỗi liên kết trên 1 dòng với định dạng: <code>Tên hiển thị|Đường dẫn (URL)</code>. Cấu hình này sẽ hiển thị thành một khối ngay phía trên dòng Bản quyền footer.
                                </div>
                            </div>
                        </div>

                        <?php
                        // Show auto-loaded categories preview
                        $preview_cats = get_footer_categories();
                        if (!empty($preview_cats)): ?>
                            <div class="card border-success mb-3">
                                <div class="card-header bg-success-subtle d-flex align-items-center gap-2 py-2">
                                    <i class="bi bi-database-check text-success"></i>
                                    <strong class="text-success-emphasis">Danh mục tự động (Cột 2)</strong>
                                    <span class="badge bg-success ms-auto">
                                        <?php echo count($preview_cats); ?> mục
                                    </span>
                                </div>
                                <div class="card-body py-2">
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($preview_cats as $cat): ?>
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-tag me-1"></i>
                                                <?php echo e($cat['name']); ?>
                                            </span>
                                            <?php
                                        endforeach; ?>
                                    </div>
                                    <div class="form-text mt-2">Các danh mục này tự động hiển thị bên dưới tiêu đề Cột 2
                                        trong footer. Quản lý tại <a href="../categories/">Danh mục</a>.</div>
                                </div>
                            </div>
                            <?php
                        endif; ?>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'email' ? 'show active' : ''; ?>" id="email"
                        role="tabpanel">
                        <?php if (!$phpmailer_autoload_exists): ?>
                            <div class="p-3 rounded border border-danger-subtle bg-danger-subtle text-danger-emphasis mb-3">
                                <strong>PHPMailer chưa sẵn sàng:</strong> chưa tìm thấy <code>vendor/autoload.php</code>.
                                Vui lòng cài trên server: <code>composer require phpmailer/phpmailer</code>
                            </div>
                            <?php
                        endif; ?>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="smtp_enabled"
                                name="settings[smtp_enabled]" value="1" <?php echo $is_smtp_enabled ? 'checked' : '';
                                ?>>
                            <label class="form-check-label" for="smtp_enabled">Bật gửi email SMTP</label>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">SMTP Host</label>
                                <input type="text" class="form-control" name="settings[smtp_host]"
                                    value="<?php echo e($settings['smtp_host'] ?? 'email-smtp.ap-southeast-1.amazonaws.com'); ?>"
                                    placeholder="email-smtp.ap-southeast-1.amazonaws.com">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">SMTP Port</label>
                                <input type="number" class="form-control" name="settings[smtp_port]"
                                    value="<?php echo e($settings['smtp_port'] ?? '587'); ?>" min="1" max="65535">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Bảo mật</label>
                                <select class="form-select" name="settings[smtp_secure]">
                                    <option value="tls" <?php echo $smtp_secure === 'tls' ? 'selected' : ''; ?>>TLS
                                        (khuyến nghị)</option>
                                    <option value="ssl" <?php echo $smtp_secure === 'ssl' ? 'selected' : ''; ?>>SSL
                                    </option>
                                    <option value="none" <?php echo $smtp_secure === 'none' ? 'selected' : ''; ?>>None
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">SMTP Username</label>
                                <input type="text" class="form-control" name="settings[smtp_user]"
                                    value="<?php echo e($settings['smtp_user'] ?? ''); ?>"
                                    placeholder="SES SMTP username">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-flex justify-content-between align-items-center">
                                    <span>SMTP Password</span>
                                    <?php if ($smtp_has_password): ?>
                                        <span class="badge bg-success">Đã lưu</span>
                                        <?php
                                    else: ?>
                                        <span class="badge bg-secondary">Chưa lưu</span>
                                        <?php
                                    endif; ?>
                                </label>
                                <input type="password" class="form-control" name="settings[smtp_pass]" value=""
                                    placeholder="<?php echo $smtp_has_password ? '********' : 'Nhập SMTP password'; ?>"
                                    autocomplete="new-password">
                                <div class="form-text">Mật khẩu hiện tại sẽ được giữ nếu để trống.</div>
                                <?php if ($smtp_has_password): ?>
                                    <div class="form-text text-success">Đã có mật khẩu SMTP trong hệ thống.</div>
                                    <?php if ($smtp_pass_updated_at !== ''): ?>
                                        <div class="form-text text-muted">Cập nhật lần cuối:
                                            <?php echo e($smtp_pass_updated_at); ?>
                                        </div>
                                        <?php
                                    endif; ?>
                                    <?php
                                else: ?>
                                    <div class="form-text text-warning">Chưa có mật khẩu SMTP được lưu.</div>
                                    <?php
                                endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">From Email</label>
                                <input type="email" class="form-control" name="settings[smtp_from_email]"
                                    value="<?php echo e($settings['smtp_from_email'] ?? ($settings['contact_email'] ?? '')); ?>"
                                    placeholder="no-reply@yourdomain.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">From Name</label>
                                <input type="text" class="form-control" name="settings[smtp_from_name]"
                                    value="<?php echo e($settings['smtp_from_name'] ?? ($settings['site_name'] ?? 'ShopSieuSale')); ?>"
                                    placeholder="FPT Store">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Email nhận thông báo</label>
                                <div class="mb-2">
                                    <input type="email" class="form-control" name="settings[smtp_to_email]"
                                        value="<?php echo e($settings['smtp_to_email'] ?? ($settings['contact_email'] ?? '')); ?>"
                                        placeholder="Email chính (bắt buộc)">
                                </div>
                                <div class="mb-2">
                                    <input type="email" class="form-control" name="settings[smtp_to_email_2]"
                                        value="<?php echo e($settings['smtp_to_email_2'] ?? ''); ?>"
                                        placeholder="Email phụ 2 (tuỳ chọn)">
                                </div>
                                <div class="mb-1">
                                    <input type="email" class="form-control" name="settings[smtp_to_email_3]"
                                        value="<?php echo e($settings['smtp_to_email_3'] ?? ''); ?>"
                                        placeholder="Email phụ 3 (tuỳ chọn)">
                                </div>
                                <div class="form-text">Tất cả email trên đây sẽ nhận thông báo khi khách submit form.
                                </div>
                            </div>

                            <div class="col-md-12">
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-outline-primary" id="send_test_email_btn">
                                        <i class="bi bi-send me-1"></i> Gửi email test
                                    </button>
                                    <small class="text-muted">Lưu cấu hình trước, rồi bấm test để kiểm tra SMTP thực
                                        tế.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'url' ? 'show active' : ''; ?>" id="url"
                        role="tabpanel">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Cấu hình URL thân thiện. Thay đổi sẽ tự động cập nhật file .htaccess.
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Prefix danh mục</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input type="text" class="form-control" name="settings[url_category_prefix]"
                                        value="<?php echo e($settings['url_category_prefix'] ?? 'danh-muc'); ?>"
                                        pattern="[a-z0-9-]+" required>
                                    <span class="input-group-text">/slug</span>
                                </div>
                                <div class="form-text">VD: /danh-muc/internet-cap-quang</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Prefix bài viết</label>
                                <div class="input-group">
                                    <span class="input-group-text">/</span>
                                    <input type="text" class="form-control" name="settings[url_post_prefix]"
                                        value="<?php echo e($settings['url_post_prefix'] ?? 'tin-tuc'); ?>"
                                        pattern="[a-z0-9-]+" required>
                                    <span class="input-group-text">/slug</span>
                                </div>
                                <div class="form-text">VD: /tin-tuc/huong-dan-dang-ky</div>
                            </div>
                        </div>
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>Lưu ý:</strong> Sau khi đổi URL prefix, các liên kết cũ có thể không còn hoạt động.
                            Cân nhắc redirect 301 nếu cần.
                        </div>
                    </div>
                    <div class="tab-pane fade <?php echo $active_tab === 'performance' ? 'show active' : ''; ?>"
                        id="performance" role="tabpanel">
                        <div class="alert alert-info">
                            <i class="bi bi-speedometer2 me-2"></i>
                            Tối ưu hiệu năng website bằng nén GZIP và bộ nhớ đệm trình duyệt.
                            Thay đổi sẽ tự động cập nhật file .htaccess.
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-file-zip fs-4 text-primary"></i>
                                                <h6 class="fw-bold mb-0">Nén GZIP</h6>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="perf_gzip"
                                                    name="settings[perf_gzip_enabled]" value="1" <?php echo
                                                        ($settings['perf_gzip_enabled'] ?? '0') === '1' ? 'checked' : '';
                                                    ?>>
                                            </div>
                                        </div>
                                        <p class="text-muted small mb-0">Nén HTML, CSS, JS và fonts trước khi gửi đến
                                            trình duyệt.
                                            Giảm dung lượng truyền tải 60-80%, tăng tốc độ tải trang đáng kể.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-clock-history fs-4 text-success"></i>
                                                <h6 class="fw-bold mb-0">Cache Headers</h6>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="perf_cache"
                                                    name="settings[perf_cache_enabled]" value="1" <?php echo
                                                        ($settings['perf_cache_enabled'] ?? '0') === '1' ? 'checked' : '';
                                                    ?>>
                                            </div>
                                        </div>
                                        <p class="text-muted small mb-0">Cho phép trình duyệt lưu cache ảnh, CSS, JS,
                                            fonts.
                                            Lần truy cập tiếp theo sẽ tải nhanh hơn rất nhiều.
                                            HTML được cache 1 giờ, tài nguyên tĩnh cache 1 năm.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card h-100 border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-image fs-4 text-info"></i>
                                                <h6 class="fw-bold mb-0">Nén ảnh WebP</h6>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="perf_webp"
                                                    name="settings[perf_webp_enabled]" value="1" <?php echo
                                                        ($settings['perf_webp_enabled'] ?? '1') === '1' ? 'checked' : '';
                                                    ?>>
                                            </div>
                                        </div>
                                        <p class="text-muted small mb-0">Tự động chuyển đổi ảnh JPG/PNG sang định dạng
                                            WebP khi upload qua Media Library.
                                            Giảm dung lượng ảnh 25-35% mà không mất chất lượng đáng kể.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <?php
                        // Page Cache stats
                        require_once '../../includes/page-cache.php';
                        $pc_stats  = PageCache::stats();
                        $pc_enabled = ($settings['page_cache_enabled'] ?? '0') === '1';
                        ?>
                        <div class="row g-4 mb-4">
                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center justify-content-between mb-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-hdd-stack fs-4 text-warning"></i>
                                                <div>
                                                    <h6 class="fw-bold mb-0">Page Cache (PHP)</h6>
                                                    <small class="text-muted">Cache HTML trang chủ — không cần cấu hình server</small>
                                                </div>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="page_cache_toggle"
                                                    name="settings[page_cache_enabled]" value="1"
                                                    <?php echo $pc_enabled ? 'checked' : ''; ?>>
                                            </div>
                                        </div>
                                        <p class="text-muted small mb-3">
                                            Lưu HTML trang chủ vào file (TTL: 5 phút). Request tiếp theo serve từ file,
                                            không cần query DB. Kiểm tra qua <strong>View Source</strong>:
                                            <code><!-- Page Cache: HIT/MISS --></code>
                                        </p>
                                        <?php if ($pc_stats['count'] > 0): ?>
                                            <div class="d-flex gap-3 flex-wrap">
                                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                                    <i class="bi bi-files me-1"></i><?php echo $pc_stats['count']; ?> file
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border">
                                                    <i class="bi bi-hdd me-1"></i><?php echo $pc_stats['size_kb']; ?> KB
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border">
                                                    <i class="bi bi-clock me-1"></i>Cache cũ nhất: <?php echo $pc_stats['oldest_age']; ?>s trước
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border"><i class="bi bi-inbox me-1"></i>Chưa có file cache</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <?php
                        $tp_enabled = (($settings['perf_traffic_prune_enabled'] ?? '1') === '1');
                        $tp_days = (int) ($settings['perf_traffic_prune_days'] ?? 90);
                        if ($tp_days < 7) { $tp_days = 90; }
                        $tp_last = (int) get_setting('last_traffic_prune_at', '0');
                        $tp_lastResult = json_decode((string) get_setting('last_traffic_prune_result', ''), true);
                        $tp_hist = json_decode((string) get_setting('traffic_prune_history', '[]'), true);
                        if (!is_array($tp_hist)) { $tp_hist = []; }
                        ?>
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-database-fx fs-4 text-danger"></i>
                                        <div>
                                            <h6 class="fw-bold mb-0">Tự dọn dữ liệu traffic cũ</h6>
                                            <small class="text-muted">Xóa lượt xem / log cũ để DB không phình. Chạy tối đa 1 lần/24h khi vào Dashboard.</small>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="perf_traffic_prune_enabled"
                                            name="settings[perf_traffic_prune_enabled]" value="1" <?php echo $tp_enabled ? 'checked' : ''; ?>>
                                    </div>
                                </div>

                                <div class="row g-3 align-items-end mb-3">
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-bold mb-1">Giữ lại dữ liệu (ngày)</label>
                                        <input type="number" min="7" max="3650" class="form-control" name="settings[perf_traffic_prune_days]" value="<?php echo $tp_days; ?>">
                                        <div class="form-text">Tối thiểu 7 ngày. Dữ liệu cũ hơn sẽ bị xóa.</div>
                                    </div>
                                    <div class="col-sm-8 text-sm-end">
                                        <button type="submit" name="action" value="prune_traffic_now" formnovalidate class="btn btn-outline-danger">
                                            <i class="bi bi-trash3 me-1"></i>Dọn ngay
                                        </button>
                                    </div>
                                </div>

                                <div class="small text-muted mb-2">
                                    <i class="bi bi-clock-history me-1"></i>Lần dọn gần nhất:
                                    <?php if ($tp_last > 0): ?>
                                        <strong><?php echo date('d/m/Y H:i', $tp_last); ?></strong>
                                        <?php if (is_array($tp_lastResult) && isset($tp_lastResult['total'])): ?>
                                            — đã xóa <strong><?php echo number_format((int) $tp_lastResult['total'], 0, ',', '.'); ?></strong> dòng (giữ <?php echo (int) ($tp_lastResult['days'] ?? $tp_days); ?> ngày)
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <em>chưa chạy lần nào</em>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($tp_hist)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Thời điểm</th>
                                                    <th>Giữ (ngày)</th>
                                                    <th class="text-end">Số dòng đã xóa</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($tp_hist, 0, 10) as $h): ?>
                                                    <tr>
                                                        <td class="small"><?php echo date('d/m/Y H:i', (int) ($h['at'] ?? 0)); ?></td>
                                                        <td class="small"><?php echo (int) ($h['days'] ?? 0); ?></td>
                                                        <td class="text-end small"><?php echo number_format((int) ($h['total'] ?? 0), 0, ',', '.'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <hr class="my-4">

                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="fw-bold mb-1"><i class="bi bi-trash3 me-2"></i>Xóa cache</h6>
                                <p class="text-muted small mb-0">Ép trình duyệt tải lại tất cả CSS, JS, ảnh.
                                    Phiên bản hiện tại:
                                    <code>v<?php echo e($settings['cache_version'] ?? '1'); ?></code>
                                </p>
                            </div>
                            <button type="button" class="btn btn-outline-danger" id="clear_cache_btn">
                                <i class="bi bi-arrow-clockwise me-1"></i> Xóa cache
                            </button>
                        </div>

                        <div class="alert alert-warning mt-4 mb-0">
                            <strong>Lưu ý:</strong> Cần server hỗ trợ <code>mod_deflate</code> (GZIP) và
                            <code>mod_expires</code> (Cache).
                            Hầu hết hosting đều bật sẵn các module này.
                        </div>
                    </div>

                    <!-- AI / LLM Tab -->
                    <?php $llm_has_key = trim((string) ($settings['llm_api_key'] ?? '')) !== ''; ?>
                    <div class="tab-pane fade <?php echo $active_tab === 'ai' ? 'show active' : ''; ?>" id="ai" role="tabpanel">
                        <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                            <i class="bi bi-robot fs-5 mt-1"></i>
                            <div>
                                <strong>Cấu hình LLM dùng chung.</strong> Một cấu hình này phục vụ cả <strong>Auto SEO</strong>, <strong>viết lại tiêu đề/nội dung sản phẩm</strong> và viết bài về sau.
                                Hỗ trợ mọi nhà cung cấp tương thích chuẩn OpenAI: Groq, OpenAI, CLIProxy, Together AI...
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold"><i class="bi bi-hdd-network me-1 text-primary"></i>Endpoint (Base URL)</label>
                                <input type="text" class="form-control" name="settings[llm_endpoint]" value="<?php echo e($settings['llm_endpoint'] ?? ''); ?>" placeholder="https://cli.thangdgm.io.vn/v1">
                                <div class="form-text">Base URL kết thúc bằng <code>/v1</code>. Hệ thống tự gọi <code>/chat/completions</code>. VD Groq: <code>https://api.groq.com/openai/v1</code>.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold d-flex justify-content-between">
                                    <span><i class="bi bi-key me-1 text-primary"></i>API Key</span>
                                    <?php if ($llm_has_key): ?><span class="badge bg-success-subtle text-success rounded-pill px-2"><i class="bi bi-check-circle-fill me-1"></i>Đã có</span><?php endif; ?>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="settings[llm_api_key]" id="llm_api_key_input" autocomplete="new-password" placeholder="<?php echo $llm_has_key ? '***********************' : 'Nhập API key...'; ?>">
                                    <button class="btn btn-outline-secondary" type="button" id="toggleLlmKey" title="Hiện/ẩn"><i class="bi bi-eye"></i></button>
                                </div>
                                <div class="form-text"><?php echo $llm_has_key ? 'Để trống để giữ key hiện tại.' : 'Dán key để kích hoạt AI.'; ?></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><i class="bi bi-cpu me-1 text-primary"></i>Model chính</label>
                                <input type="text" class="form-control" name="settings[llm_model]" value="<?php echo e($settings['llm_model'] ?? ''); ?>" placeholder="VD: llama-3.3-70b-versatile">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><i class="bi bi-cpu me-1 text-secondary"></i>Model dự phòng</label>
                                <input type="text" class="form-control" name="settings[llm_model_fallback]" value="<?php echo e($settings['llm_model_fallback'] ?? ''); ?>" placeholder="Dùng khi model chính lỗi/quá tải">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Temperature</label>
                                <input type="number" step="0.1" min="0" max="2" class="form-control" name="settings[llm_temperature]" value="<?php echo e($settings['llm_temperature'] ?? '0.6'); ?>">
                                <div class="form-text">0 = chính xác, cao = sáng tạo.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Max tokens</label>
                                <input type="number" min="100" max="8000" class="form-control" name="settings[llm_max_tokens]" value="<?php echo e($settings['llm_max_tokens'] ?? '1200'); ?>">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" id="btn_test_llm" class="btn btn-outline-primary w-100"><i class="bi bi-lightning-charge-fill me-1"></i>Test kết nối</button>
                            </div>
                            <div class="col-12">
                                <div class="form-text" id="llm_test_result"><?php echo $llm_has_key ? '' : 'Lưu cấu hình trước khi test.'; ?></div>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-bold mb-2"><i class="bi bi-puzzle me-1 text-primary"></i>Kết nối Extension Crawl Shopee</h6>
                        <p class="text-muted small">Dán 2 thông tin này vào nút ⚙ Cấu hình trong extension để gửi sản phẩm crawl về site.</p>
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label fw-bold">Endpoint nhận dữ liệu</label>
                                <div class="input-group">
                                    <input type="text" class="form-control bg-light" id="import_endpoint_display" readonly value="<?php echo e(rtrim(BASE_URL, '/')); ?>/admin/api/shopee-import.php">
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyImportField('import_endpoint_display')">Copy</button>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold d-flex justify-content-between">
                                    <span>API Key (X-Import-Key)</span>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" id="regenImportKeyBtn"><i class="bi bi-arrow-repeat me-1"></i>Tạo key mới</button>
                                </label>
                                <div class="input-group">
                                    <input type="text" class="form-control bg-light" id="import_api_key_display" readonly value="<?php echo e((string) get_setting('shopee_import_api_key', '')); ?>">
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyImportField('import_api_key_display')">Copy</button>
                                </div>
                                <div class="form-text text-danger" id="regen_import_result">Tạo key mới sẽ làm key cũ trong extension hết hiệu lực.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Deal Bubble Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'deal_bubble' ? 'show active' : ''; ?>" id="deal_bubble" role="tabpanel">
                        <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                            <i class="bi bi-chat-dots fs-5 mt-1 text-primary"></i>
                            <div>
                                <strong>Cấu hình Bong bóng Deal Hot.</strong> Bong bóng Deal Hot hiển thị ở góc phải màn hình sau khoảng thời gian trì hoãn và tự động xoay chuyển ngẫu nhiên các sản phẩm hời được chọn lọc từ hệ thống.
                            </div>
                        </div>

                        <div class="card border shadow-sm bg-light">
                            <div class="card-body">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="deal_today_bubble_enabled"
                                        name="settings[deal_today_bubble_enabled]" value="1"
                                        <?php echo ($settings['deal_today_bubble_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="deal_today_bubble_enabled">Kích hoạt hiển thị bong bóng nổi</label>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold" for="deal_today_bubble_delay">Thời gian chờ xuất hiện (giây)</label>
                                        <input type="number" class="form-control" id="deal_today_bubble_delay"
                                            name="settings[deal_today_bubble_delay]" min="1" max="3600"
                                            value="<?php echo e($settings['deal_today_bubble_delay'] ?? '30'); ?>">
                                        <div class="form-text">Thời gian từ lúc khách hàng vào trang tới lúc bong bóng bắt đầu bay lên (mặc định: 30 giây).</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold" for="deal_today_bubble_interval">Tự động chuyển sản phẩm (giây)</label>
                                        <input type="number" class="form-control" id="deal_today_bubble_interval"
                                            name="settings[deal_today_bubble_interval]" min="0" max="3600"
                                            value="<?php echo e($settings['deal_today_bubble_interval'] ?? '10'); ?>">
                                        <div class="form-text">Tự động xoay chuyển sang ưu đãi khác sau mỗi X giây (Nhập 0 để đứng im không xoay, mặc định: 10 giây).</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Tab -->
                    <div class="tab-pane fade <?php echo $active_tab === 'sidebar' ? 'show active' : ''; ?>" id="sidebar" role="tabpanel">
                        <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
                            <i class="bi bi-layout-sidebar-inset-reverse fs-5 mt-1 text-primary"></i>
                            <div>
                                <strong>Cấu hình thanh bên (Sidebar).</strong> Đặt mặc định chung cho toàn site, sau đó ghi đè riêng cho từng trang nếu cần. Bài viết có thể ghi đè riêng trong trang sửa bài. Quản lý nội dung widget tại <a href="<?php echo BASE_URL; ?>admin/widgets/index.php">Widget Sidebar</a>.
                            </div>
                        </div>

                        <div class="card border shadow-sm bg-light mb-4">
                            <div class="card-body">
                                <h6 class="fw-bold mb-3"><i class="bi bi-globe me-1 text-primary"></i>Mặc định toàn site</h6>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="sidebar_enabled"
                                        name="settings[sidebar_enabled]" value="1"
                                        <?php echo ($settings['sidebar_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="sidebar_enabled">Bật sidebar mặc định</label>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Vị trí mặc định</label>
                                        <?php $g_pos = ($settings['sidebar_position'] ?? 'right') === 'left' ? 'left' : 'right'; ?>
                                        <select class="form-select" name="settings[sidebar_position]">
                                            <option value="right" <?php echo $g_pos === 'right' ? 'selected' : ''; ?>>Bên phải</option>
                                            <option value="left" <?php echo $g_pos === 'left' ? 'selected' : ''; ?>>Bên trái</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border shadow-sm">
                            <div class="card-header bg-white">
                                <h6 class="fw-bold mb-0"><i class="bi bi-files me-1 text-primary"></i>Ghi đè theo từng trang</h6>
                                <small class="text-muted">Để "Theo mặc định" nếu muốn trang dùng cài đặt chung ở trên.</small>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Trang</th>
                                                <th style="width:220px;">Hiển thị sidebar</th>
                                                <th style="width:220px;">Vị trí</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($page_sidebar_rows as $pr):
                                                $pk = $pr['page_key']; ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo e($pr['page_label'] ?: $pk); ?></td>
                                                    <td>
                                                        <select class="form-select form-select-sm" name="page_sidebar[<?php echo e($pk); ?>][mode]">
                                                            <option value="default" <?php echo $pr['sidebar_mode'] === 'default' ? 'selected' : ''; ?>>Theo mặc định</option>
                                                            <option value="show" <?php echo $pr['sidebar_mode'] === 'show' ? 'selected' : ''; ?>>Bật</option>
                                                            <option value="hide" <?php echo $pr['sidebar_mode'] === 'hide' ? 'selected' : ''; ?>>Tắt</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-select form-select-sm" name="page_sidebar[<?php echo e($pk); ?>][position]">
                                                            <option value="default" <?php echo $pr['sidebar_position'] === 'default' ? 'selected' : ''; ?>>Theo mặc định</option>
                                                            <option value="right" <?php echo $pr['sidebar_position'] === 'right' ? 'selected' : ''; ?>>Bên phải</option>
                                                            <option value="left" <?php echo $pr['sidebar_position'] === 'left' ? 'selected' : ''; ?>>Bên trái</option>
                                                        </select>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($page_sidebar_rows)): ?>
                                                <tr><td colspan="3" class="text-center text-muted">Chưa có cấu hình trang.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary">Lưu cấu hình</button>
            </div>
        </div>
    </form>
</div>

<style>
.theme-choice{display:flex;flex-direction:column;gap:.85rem;border:1px solid #e5e7eb;border-radius:14px;padding:1rem;background:#fff;cursor:pointer;transition:border-color .18s,box-shadow .18s,transform .18s;}
.theme-choice:hover{transform:translateY(-2px);box-shadow:0 .75rem 1.5rem rgba(15,23,42,.08);}
.theme-choice.is-active{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12);}
.theme-choice input{position:absolute;opacity:0;pointer-events:none;}
.theme-choice__swatches{display:flex;gap:.35rem;}
.theme-choice__swatches span{width:34px;height:34px;border-radius:10px;border:1px solid rgba(15,23,42,.08);}
.theme-choice__name{font-weight:800;color:#0f172a;}
.theme-choice__sample{display:block;}
.sample-card{display:block;border-radius:12px;background:var(--primary-soft);border:1px solid var(--primary-line);padding:.8rem;}
.sample-title{display:block;width:70%;height:10px;border-radius:99px;background:var(--primary-color);margin-bottom:.5rem;}
.sample-line{display:block;width:100%;height:8px;border-radius:99px;background:rgba(15,23,42,.14);margin-bottom:.65rem;}
.sample-pill{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:24px;border-radius:999px;background:var(--primary-gradient);color:#fff;font-size:.75rem;font-weight:800;}
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const hiddenTabInput = document.getElementById('active_tab');
        const tabLinks = document.querySelectorAll('#settingTabs a[data-bs-toggle="tab"]');

        tabLinks.forEach(function (link) {
            link.addEventListener('shown.bs.tab', function (event) {
                const key = event.target.getAttribute('data-tab-key') || 'general';
                hiddenTabInput.value = key;

                const url = new URL(window.location.href);
                url.searchParams.set('tab', key);
                url.hash = event.target.getAttribute('href') || '';
                window.history.replaceState({}, '', url.toString());
            });
        });

        const current = hiddenTabInput.value || 'general';
        const activeLink = document.querySelector('#settingTabs a[data-tab-key="' + current + '"]');
        if (activeLink && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(activeLink).show();
        }

        document.querySelectorAll('.theme-choice input[name="settings[site_theme_palette]"]').forEach(function (input) {
            input.addEventListener('change', function () {
                document.querySelectorAll('.theme-choice').forEach(function (choice) {
                    choice.classList.toggle('is-active', choice.contains(input));
                });
            });
        });

        const testBtn = document.getElementById('send_test_email_btn');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                const body = new URLSearchParams();
                if (window.AdminSecurity) {
                    AdminSecurity.applyCsrf(body);
                } else {
                    body.set('csrf_token', '<?php echo e(generate_csrf_token()); ?>');
                }
                fetch('test-email.php', {
                    method: 'POST',
                    headers: window.AdminSecurity
                        ? AdminSecurity.headers({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' })
                        : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (window.AdminPopup) {
                                AdminPopup.success(data.message + (data.target ? ' -> ' + data.target : ''));
                            } else {
                                alert(data.message);
                            }
                            return;
                        }
                        const debugMessage = (data.debug || '').trim();
                        if (debugMessage) {
                            console.error('SMTP debug:', debugMessage);
                        }
                        const failMessage = data.message || 'Gửi email test thất bại.';
                        const shortDebug = debugMessage ? ('\nChi tiet: ' + debugMessage.split('\n')[0]) : '';
                        if (window.AdminPopup) {
                            AdminPopup.error(failMessage + shortDebug);
                        } else {
                            alert(failMessage + shortDebug);
                        }
                    })
                    .catch(() => {
                        if (window.AdminPopup) {
                            AdminPopup.error('Không gọi được API test email.');
                        } else {
                            alert('Không gọi được API test email.');
                        }
                    });
            });
        }

        // TinyMCE for footer editors (loaded only when footer tab is active)
        if (document.querySelector('.footer-tinymce')) {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js';
            s.referrerPolicy = 'origin';
            s.onload = function () {
                tinymce.init({
                    selector: '.footer-tinymce',
                    height: 200,
                    menubar: false,
                    plugins: 'link lists code',
                    toolbar: 'bold italic forecolor | link blockquote | bullist numlist | removeformat code',
                    branding: false,
                    promotion: false,
                    license_key: 'gpl',
                    image_dimensions: false,
                    statusbar: false,
                    convert_urls: false,
                    content_style: 'body { font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 16px; overflow-x: hidden; } img, video, iframe { max-width: 100% !important; height: auto !important; } figure, figure.image { max-width: 100% !important; width: auto !important; margin: 1rem auto !important; } figure img, figure.image img { display: block; max-width: 100% !important; height: auto !important; } figcaption { max-width: 100%; overflow-wrap: anywhere; } table { max-width: 100%; }',
                    setup: (editor) => {
                        const normalizeEditorMedia = () => {
                            const body = editor.getBody();
                            if (!body) return;

                            body.querySelectorAll('img, video, iframe').forEach((node) => {
                                node.removeAttribute('width');
                                node.removeAttribute('height');
                                node.style.width = '';
                                node.style.height = '';
                                node.style.maxWidth = '100%';
                                node.style.height = 'auto';
                            });

                            body.querySelectorAll('figure, figure.image').forEach((node) => {
                                node.removeAttribute('width');
                                node.removeAttribute('height');
                                node.style.width = '';
                                node.style.height = '';
                                node.style.maxWidth = '100%';
                            });
                        };

                        editor.on('init SetContent change Undo Redo Paste PostProcess', normalizeEditorMedia);
                    }
                });
            };
            document.head.appendChild(s);
        }



        // ===== AI / LLM tab: toggle key + test connection =====
        const toggleLlmKey = document.getElementById('toggleLlmKey');
        const llmKeyInput = document.getElementById('llm_api_key_input');
        if (toggleLlmKey && llmKeyInput) {
            toggleLlmKey.addEventListener('click', function () {
                const hidden = llmKeyInput.type === 'password';
                llmKeyInput.type = hidden ? 'text' : 'password';
                this.querySelector('i').className = hidden ? 'bi bi-eye-slash' : 'bi bi-eye';
            });
        }
        const llmTestBtn = document.getElementById('btn_test_llm');
        const llmTestResult = document.getElementById('llm_test_result');
        if (llmTestBtn) {
            llmTestBtn.addEventListener('click', function () {
                llmTestBtn.disabled = true;
                llmTestBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang test...';
                if (llmTestResult) { llmTestResult.className = 'form-text'; llmTestResult.textContent = ''; }
                const body = new URLSearchParams();
                if (window.AdminSecurity) { AdminSecurity.applyCsrf(body); }
                else { body.set('csrf_token', '<?php echo e(generate_csrf_token()); ?>'); }
                // Gửi giá trị đang nhập (chưa lưu) để test trực tiếp
                body.set('endpoint', document.querySelector('input[name="settings[llm_endpoint]"]').value);
                body.set('model', document.querySelector('input[name="settings[llm_model]"]').value);
                if (llmKeyInput && llmKeyInput.value.trim() !== '') { body.set('api_key', llmKeyInput.value.trim()); }
                fetch('../ajax/llm-test.php', {
                    method: 'POST',
                    headers: window.AdminSecurity
                        ? AdminSecurity.headers({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' })
                        : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body
                })
                    .then(r => r.json())
                    .then(data => {
                        llmTestBtn.disabled = false;
                        llmTestBtn.innerHTML = '<i class="bi bi-lightning-charge-fill me-1"></i>Test kết nối';
                        if (llmTestResult) {
                            llmTestResult.className = 'form-text ' + (data.success ? 'text-success fw-bold' : 'text-danger');
                            llmTestResult.textContent = data.message;
                        }
                    })
                    .catch(() => {
                        llmTestBtn.disabled = false;
                        llmTestBtn.innerHTML = '<i class="bi bi-lightning-charge-fill me-1"></i>Test kết nối';
                        if (llmTestResult) { llmTestResult.className = 'form-text text-danger'; llmTestResult.textContent = 'Không gọi được server.'; }
                    });
            });
        }

        // ===== Import Shopee: copy + regenerate key =====
        window.copyImportField = function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            navigator.clipboard.writeText(el.value).then(function () {
                if (window.AdminPopup) { AdminPopup.success('Đã copy.'); }
            });
        };
        const regenImportKeyBtn = document.getElementById('regenImportKeyBtn');
        const regenImportResult = document.getElementById('regen_import_result');
        if (regenImportKeyBtn) {
            regenImportKeyBtn.addEventListener('click', function () {
                if (!confirm('Tạo key mới? Key cũ đang dùng trong extension sẽ hết hiệu lực và bạn phải cập nhật lại.')) return;
                regenImportKeyBtn.disabled = true;
                const body = new URLSearchParams();
                if (window.AdminSecurity) { AdminSecurity.applyCsrf(body); }
                else { body.set('csrf_token', '<?php echo e(generate_csrf_token()); ?>'); }
                fetch('../ajax/regen-import-key.php', {
                    method: 'POST',
                    headers: window.AdminSecurity
                        ? AdminSecurity.headers({ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' })
                        : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body
                })
                    .then(r => r.json())
                    .then(data => {
                        regenImportKeyBtn.disabled = false;
                        if (data.success) {
                            document.getElementById('import_api_key_display').value = data.key;
                            if (regenImportResult) { regenImportResult.className = 'form-text text-success'; regenImportResult.textContent = 'Đã tạo key mới. Cập nhật lại vào extension.'; }
                        } else {
                            if (regenImportResult) { regenImportResult.className = 'form-text text-danger'; regenImportResult.textContent = data.message || 'Lỗi tạo key.'; }
                        }
                    })
                    .catch(() => {
                        regenImportKeyBtn.disabled = false;
                        if (regenImportResult) { regenImportResult.className = 'form-text text-danger'; regenImportResult.textContent = 'Không gọi được server.'; }
                    });
            });
        }

        const clearBtn = document.getElementById('clear_cache_btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                clearBtn.disabled = true;
                clearBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang xóa...';
                fetch('../ajax/clear-cache.php', {
                    method: 'POST',
                    headers: Object.assign(
                        { 'X-Requested-With': 'XMLHttpRequest' },
                        window.AdminSecurity ? AdminSecurity.headers() : {}
                    ),
                    body: window.AdminSecurity ? AdminSecurity.applyCsrf(new URLSearchParams()).toString() : ''
                })
                    .then(r => r.json())
                    .then(data => {
                        clearBtn.disabled = false;
                        clearBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Xóa cache';
                        if (data.success) {
                            if (window.AdminPopup) {
                                AdminPopup.success(data.message);
                            } else {
                                alert(data.message);
                            }
                            // Update version display
                            const code = clearBtn.closest('.tab-pane').querySelector('code');
                            if (code && data.version) code.textContent = 'v' + data.version;
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            if (window.AdminPopup) {
                                AdminPopup.error(data.message || 'Lỗi xóa cache');
                            } else {
                                alert(data.message || 'Lỗi xóa cache');
                            }
                        }
                    })
                    .catch(() => {
                        clearBtn.disabled = false;
                        clearBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Xóa cache';
                        if (window.AdminPopup) {
                            AdminPopup.error('Không thể kết nối server.');
                        } else {
                            alert('Không thể kết nối server.');
                        }
                    });
            });
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>


