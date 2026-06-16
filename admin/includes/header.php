<?php
// admin/includes/header.php
require_admin_login();

// Prevent stale admin HTML from browser/proxy caches.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo generate_csrf_token(); ?>">
    <title>Admin Dashboard -
        <?php echo SITE_NAME; ?>
    </title>
    <link rel="icon" type="image/x-icon"
        href="<?php echo BASE_URL . get_setting('site_favicon', 'assets/images/favicon.ico'); ?>">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- jQuery (required for Summernote) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <!-- Define BASE_URL for JavaScript -->
    <script>
        const BASE_URL = '<?php echo BASE_URL; ?>';
    </script>

    <!-- Admin Dashboard CSS -->
    <style>
        /* ===== SEO SUBMENU GROUP ===== */
        .nav-group-toggle {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.75rem 1rem;
            color: #d1d5db;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 4px;
            cursor: pointer;
        }
        .nav-group-toggle:hover {
            background: rgba(255,255,255,0.05);
            color: #fff;
        }
        .nav-group-toggle[aria-expanded="true"] {
            background: rgba(99,102,241,0.12);
            color: #fff;
        }
        .nav-group-toggle i:first-child {
            font-size: 1.15rem;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
        }
        .nav-group-arrow {
            font-size: 0.7rem !important;
            width: auto !important;
            transition: transform 0.25s ease;
        }
        .nav-group-toggle[aria-expanded="true"] .nav-group-arrow {
            transform: rotate(180deg);
        }
        .nav-submenu {
            padding-left: 0.5rem;
            margin-bottom: 4px;
        }
        .nav-link-sub {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.55rem 1rem 0.55rem 2rem;
            color: #9ca3af;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 400;
            transition: all 0.18s ease;
            margin-bottom: 2px;
            border-left: 2px solid rgba(99,102,241,0.2);
        }
        .nav-link-sub i {
            font-size: 0.9rem;
            width: 18px;
            text-align: center;
        }
        .nav-link-sub:hover {
            background: rgba(255,255,255,0.04);
            color: #e5e7eb;
            border-left-color: #6366f1;
        }
        .nav-link-sub.active {
            background: rgba(99,102,241,0.18);
            color: #fff;
            font-weight: 600;
            border-left-color: #818cf8;
        }

        :root {
            --sidebar-bg: #111827;
            --sidebar-hover: rgba(255, 255, 255, 0.06);
            --sidebar-active: rgba(99, 102, 241, 0.15);
            --sidebar-active-border: #6366f1;
            --sidebar-text: #9ca3af;
            --sidebar-text-active: #ffffff;
            --sidebar-width: 260px;
            --content-bg: #f1f5f9;
            --accent: #6366f1;
            --accent-light: #818cf8;
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: var(--content-bg);
            overflow-x: hidden;
        }

        /* ===== SIDEBAR ===== */
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: #111827;
            /* Darker, richer background */
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.2);
        }

        .admin-sidebar::-webkit-scrollbar {
            width: 4px;
        }

        .admin-sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .admin-sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .sidebar-brand {
            padding: 1.5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(0, 0, 0, 0.1);
        }

        .sidebar-brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #6366f1, #818cf8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.25rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .sidebar-brand-text {
            color: #f3f4f6;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .sidebar-brand-text small {
            display: block;
            font-size: 0.75rem;
            color: #9ca3af;
            font-weight: 500;
            margin-top: 2px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 1.25rem 0.75rem;
        }

        .sidebar-label {
            padding: 0.75rem 1rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
        }

        .nav-link-admin {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.75rem 1rem;
            color: #d1d5db;
            text-decoration: none;
            border-radius: 8px;
            /* Slightly softer */
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 4px;
            border-left: none;
            /* Removed border */
            position: relative;
            overflow: hidden;
        }

        .nav-link-admin:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            transform: translateX(4px);
        }

        .nav-link-admin.active {
            background: linear-gradient(to right, #4f46e5, #6366f1);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
            /* Glow effect */
        }

        .nav-link-admin i {
            font-size: 1.15rem;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
            transition: transform 0.2s;
        }

        .nav-link-admin:hover i {
            transform: scale(1.1);
            color: #818cf8;
        }

        .nav-link-admin.active i {
            color: #fff;
            transform: scale(1.1);
        }

        .nav-link-admin .badge {
            font-size: 0.7rem;
            padding: 0.35em 0.65em;
            font-weight: 700;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(0, 0, 0, 0.1);
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 12px;
            color: #d1d5db;
            text-decoration: none;
            transition: background 0.2s ease;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar-user:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.1);
        }

        .sidebar-user img {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-user-info {
            flex: 1;
            min-width: 0;
        }

        .sidebar-user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            font-size: 0.75rem;
            color: #9ca3af;
        }

        /* ===== MAIN CONTENT ===== */
        .admin-main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            background: var(--content-bg);
        }

        .admin-topbar {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        .admin-topbar-title {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 500;
        }

        .admin-topbar-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .main-content {
            padding: 1.5rem;
        }

        .note-editor .note-editing-area {
            overflow: hidden;
        }

        .note-editor .note-editable {
            overflow-x: auto;
            word-break: break-word;
        }

        .note-editor .note-editable img,
        .note-editor .note-editable figure,
        .note-editor .note-editable video,
        .note-editor .note-editable iframe {
            max-width: 100% !important;
        }

        .note-editor .note-editable img,
        .note-editor .note-editable video,
        .note-editor .note-editable iframe {
            height: auto !important;
        }

        .note-editor .note-editable figure {
            width: auto !important;
            margin: 1rem auto !important;
        }

        .note-editor .note-editable figure img {
            display: block;
            margin: 0 auto;
        }

        .note-editor .note-editable figcaption {
            max-width: 100%;
            overflow-wrap: anywhere;
        }

        /* ===== MOBILE ===== */
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #475569;
            cursor: pointer;
            padding: 0.25rem;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1035;
        }

        @media (max-width: 991.98px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }

            .admin-sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .admin-main {
                margin-left: 0;
            }

            .sidebar-toggle {
                display: inline-flex;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/admin-bar.php'; ?>
    <style>
        /* Tránh thanh admin (#wpadminbar cao 32px) che thanh công cụ khi mở rộng editor (TinyMCE fullscreen) */
        .tox.tox-tinymce.tox-fullscreen { top: 32px !important; height: calc(100% - 32px) !important; }
    </style>


    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon">
                <i class="bi bi-grid-1x2-fill"></i>
            </div>
            <div class="sidebar-brand-text">
                Admin Panel
                <small>
                    <?php echo SITE_NAME; ?>
                </small>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="sidebar-label">Tổng quan</div>
            <a href="<?php echo BASE_URL; ?>admin/index.php"
                class="nav-link-admin <?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="<?php echo BASE_URL; ?>admin/media_library/index.php"
                class="nav-link-admin <?php echo ($current_page == 'media') ? 'active' : ''; ?>">
                <i class="bi bi-folder2-open"></i> Media Library
            </a>

            <div class="sidebar-label mt-3">Nội dung</div>
            <a href="<?php echo BASE_URL; ?>admin/categories/index.php"
                class="nav-link-admin <?php echo ($current_page == 'categories') ? 'active' : ''; ?>">
                <i class="bi bi-tags"></i> Danh mục
            </a>
            <a href="<?php echo BASE_URL; ?>admin/posts/index.php"
                class="nav-link-admin <?php echo ($current_page == 'posts') ? 'active' : ''; ?>">
                <i class="bi bi-newspaper"></i> Bài viết
            </a>
            <a href="<?php echo BASE_URL; ?>admin/tags/index.php"
                class="nav-link-admin <?php echo ($current_page == 'tags') ? 'active' : ''; ?>">
                <i class="bi bi-tag"></i> Tags
            </a>
            <a href="<?php echo BASE_URL; ?>admin/pages/index.php"
                class="nav-link-admin <?php echo ($current_page == 'pages') ? 'active' : ''; ?>">
                <i class="bi bi-file-earmark-text"></i> Trang tĩnh
            </a>
            <a href="<?php echo BASE_URL; ?>admin/faq/index.php"
                class="nav-link-admin <?php echo ($current_page == 'faq') ? 'active' : ''; ?>">
                <i class="bi bi-question-circle"></i> FAQ
            </a>

            <div class="sidebar-label mt-3">Giao diện</div>
            <a href="<?php echo BASE_URL; ?>admin/banners/index.php"
                class="nav-link-admin <?php echo ($current_page == 'banners') ? 'active' : ''; ?>">
                <i class="bi bi-images"></i> Banner Slider
            </a>
            <a href="<?php echo BASE_URL; ?>admin/ad-banners/index.php"
                class="nav-link-admin <?php echo ($current_page == 'ad-banners') ? 'active' : ''; ?>">
                <i class="bi bi-badge-ad"></i> Banner quảng cáo
            </a>
            <a href="<?php echo BASE_URL; ?>admin/homepage-blocks.php"
                class="nav-link-admin <?php echo ($current_page == 'homepage-blocks') ? 'active' : ''; ?>">
                <i class="bi bi-layout-text-window-reverse"></i> Block Trang Chủ
            </a>
            <a href="<?php echo BASE_URL; ?>admin/dynamic-blocks/index.php"
                class="nav-link-admin <?php echo ($current_page == 'dynamic-blocks') ? 'active' : ''; ?>">
                <i class="bi bi-grid-3x3-gap"></i> Block Động
            </a>
            <a href="<?php echo BASE_URL; ?>admin/menus/index.php"
                class="nav-link-admin <?php echo ($current_page == 'menus') ? 'active' : ''; ?>">
                <i class="bi bi-list-nested"></i> Menu
            </a>
            <a href="<?php echo BASE_URL; ?>admin/widgets/index.php"
                class="nav-link-admin <?php echo ($current_page == 'widgets') ? 'active' : ''; ?>">
                <i class="bi bi-layout-sidebar-reverse"></i> Widget Sidebar
            </a>

            <div class="sidebar-label mt-3">Khách hàng</div>
            <a href="<?php echo BASE_URL; ?>admin/contacts/index.php?tab=consultation"
                class="nav-link-admin <?php echo ($current_page == 'contacts') ? 'active' : ''; ?>">
                <i class="bi bi-person-lines-fill"></i> Quản lý liên hệ
                <?php
try {
    $stmt_contact = $pdo->query("SELECT COUNT(*) FROM contacts WHERE status = '0'");
    $pending_count = ($stmt_contact ? (int) $stmt_contact->fetchColumn() : 0);
    if ($pending_count > 0):
?>
                <span class="badge bg-danger rounded-pill ms-auto">
                    <?php echo $pending_count; ?>
                </span>
                <?php
    endif;
}
catch (Exception $e) { /* Ignore sidebar error */
}
?>
            </a>
            <a href="<?php echo BASE_URL; ?>admin/document-requests/index.php"
                class="nav-link-admin <?php echo ($current_page == 'document-requests') ? 'active' : ''; ?>">
                <i class="bi bi-file-earmark-arrow-down"></i> Lead nhận tài liệu
            </a>
            <a href="<?php echo BASE_URL; ?>admin/traffic/index.php"
                class="nav-link-admin <?php echo ($current_page == 'traffic') ? 'active' : ''; ?>">
                <i class="bi bi-bar-chart-line"></i> Lưu lượng truy cập
            </a>

            <div class="sidebar-label mt-3">Marketing</div>
            <a href="<?php echo BASE_URL; ?>admin/short-links/index.php"
                class="nav-link-admin <?php echo ($current_page == 'short-links') ? 'active' : ''; ?>">
                <i class="bi bi-link-45deg"></i> Rút gọn Link
            </a>

            <div class="sidebar-label mt-3">Cấu hình</div>
            <a href="<?php echo BASE_URL; ?>admin/settings/index.php"
                class="nav-link-admin <?php echo ($current_page == 'settings') ? 'active' : ''; ?>">
                <i class="bi bi-gear"></i> Cấu hình
            </a>
            <?php $seo_open = in_array($current_page, ['seo', 'seo-redirects']); ?>
            <button class="nav-link-admin nav-group-toggle w-100 text-start border-0 bg-transparent"
                    data-bs-toggle="collapse" data-bs-target="#seoGroup"
                    aria-expanded="<?php echo $seo_open ? 'true' : 'false'; ?>">
                <i class="bi bi-search"></i>
                <span>SEO</span>
                <i class="bi bi-chevron-down nav-group-arrow ms-auto"></i>
            </button>
            <div class="collapse nav-submenu <?php echo $seo_open ? 'show' : ''; ?>" id="seoGroup">
                <a href="<?php echo BASE_URL; ?>admin/seo/index.php"
                   class="nav-link-sub <?php echo ($current_page == 'seo') ? 'active' : ''; ?>">
                    <i class="bi bi-sliders"></i> Cấu hình
                </a>
                <a href="<?php echo BASE_URL; ?>admin/seo/redirects.php"
                   class="nav-link-sub <?php echo ($current_page == 'seo-redirects') ? 'active' : ''; ?>">
                    <i class="bi bi-arrow-left-right"></i> Redirect 301
                </a>
            </div>
            <a href="<?php echo BASE_URL; ?>admin/backup/index.php"
                class="nav-link-admin <?php echo ($current_page == 'backup') ? 'active' : ''; ?>">
                <i class="bi bi-cloud-arrow-up"></i> Sao lưu
            </a>
            <a href="<?php echo BASE_URL; ?>admin/logs/index.php"
                class="nav-link-admin <?php echo ($current_page == 'logs') ? 'active' : ''; ?>">
                <i class="bi bi-clock-history"></i> Nhật ký hoạt động
            </a>
            <a href="<?php echo BASE_URL; ?>admin/users/index.php"
                class="nav-link-admin <?php echo ($current_page == 'users') ? 'active' : ''; ?>">
                <i class="bi bi-people"></i> Quản lý Admin
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="<?php echo BASE_URL; ?>" target="_blank" class="nav-link-admin"
                style="color: var(--sidebar-text);">
                <i class="bi bi-house-door"></i> Về trang chủ
                <i class="bi bi-box-arrow-up-right ms-auto" style="font-size: 0.7rem; opacity: 0.5;"></i>
            </a>
            <div class="dropdown">
                <a href="#" class="sidebar-user dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown"
                    aria-expanded="false">
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['full_name']); ?>&background=6366f1&color=fff&size=72&bold=true&font-size=0.4"
                        alt="">
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name">
                            <?php echo e($_SESSION['full_name'] ?? $_SESSION['username']); ?>
                        </div>
                        <div class="sidebar-user-role">Quản trị viên</div>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser1">
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/users/profile.php"><i
                                class="bi bi-person me-2"></i>Hồ sơ</a></li>
                    <li>
                        <hr class="dropdown-divider">
                    </li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/logout.php"><i
                                class="bi bi-box-arrow-left me-2"></i>Đăng xuất</a></li>
                </ul>
            </div>
        </div>
    </aside>

    <!-- Main Content Wrapper -->
    <div class="admin-main">
        <!-- Topbar -->
        <div class="admin-topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
                <span class="admin-topbar-title">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?php echo date('l, d/m/Y'); ?>
                </span>
            </div>
            <div class="admin-topbar-actions">
                <a href="<?php echo BASE_URL; ?>" target="_blank" class="btn btn-sm btn-light">
                    <i class="bi bi-eye me-1"></i>Xem site
                </a>
            </div>
        </div>

        <!-- Page Content -->
        <div class="main-content">

            <script>
                // Mobile sidebar toggle
                document.addEventListener('DOMContentLoaded', function () {
                    const sidebar = document.getElementById('adminSidebar');
                    const overlay = document.getElementById('sidebarOverlay');
                    const toggle = document.getElementById('sidebarToggle');

                    if (toggle) {
                        toggle.addEventListener('click', function () {
                            sidebar.classList.toggle('show');
                            overlay.classList.toggle('show');
                        });
                    }

                    if (overlay) {
                        overlay.addEventListener('click', function () {
                            sidebar.classList.remove('show');
                            overlay.classList.remove('show');
                        });
                    }
                });
            </script>
