<?php
// admin/includes/admin-bar.php
// Quick Action Bar (WordPress-style)

// Ensure we have access to variables if not already set
if (!isset($site_name))
    $site_name = SITE_NAME;
?>
<!-- Admin Toolbar (WordPress-style) -->
<div id="wpadminbar"
    style="background:#23282d;height:32px;position:fixed;top:0;left:0;right:0;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;font-size:13px;box-shadow:0 1px 3px rgba(0,0,0,.3);">
    <div style="display:flex;align-items:center;height:100%;padding:0 20px;">
        <!-- Left items -->
        <div style="display:flex;align-items:center;gap:0;">
            <!-- Site name / Dashboard -->
            <a href="<?php echo BASE_URL; ?>admin/" title="Dashboard"
                style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:6px;transition:color .1s,background .1s;"
                onmouseover="this.style.color='#00b9eb';this.style.background='#32373c'"
                onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                <i class="bi bi-grid-fill" style="font-size:14px;"></i>
                <span style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($site_name); ?>
                </span>
            </a>

            <span style="color:#555;margin:0 2px;">│</span>

            <?php
            // Contextual edit links based on current page
            // Logic: Check if $service, $category, or $post variables exist and have ID
            if (isset($service) && !empty($service['id'])): ?>
                <a href="<?php echo BASE_URL; ?>admin/services/edit.php?id=<?php echo (int) $service['id']; ?>"
                    title="Chỉnh sửa dịch vụ"
                    style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                    onmouseover="this.style.color='#00b9eb';this.style.background='#32373c'"
                    onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                    <i class="bi bi-pencil-square" style="font-size:12px;"></i> Sửa dịch vụ
                </a>
            <?php endif; ?>

            <?php if (isset($category) && !empty($category['id'])): ?>
                <a href="<?php echo BASE_URL; ?>admin/categories/edit.php?id=<?php echo (int) $category['id']; ?>"
                    title="Chỉnh sửa danh mục"
                    style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                    onmouseover="this.style.color='#00b9eb';this.style.background='#32373c'"
                    onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                    <i class="bi bi-pencil-square" style="font-size:12px;"></i> Sửa danh mục
                </a>
            <?php endif; ?>

            <?php if (isset($post) && !empty($post['id'])): ?>
                <a href="<?php echo BASE_URL; ?>admin/posts/edit.php?id=<?php echo (int) $post['id']; ?>"
                    title="Chỉnh sửa bài viết"
                    style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                    onmouseover="this.style.color='#00b9eb';this.style.background='#32373c'"
                    onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                    <i class="bi bi-pencil-square" style="font-size:12px;"></i> Sửa bài viết
                </a>
            <?php endif; ?>

            <!-- Quick Actions Dropdown -->
            <div id="wp-quick-actions" style="position:relative;display:flex;align-items:center;">
                <a href="#" title="Thao tác nhanh" onclick="event.preventDefault();toggleQuickActions();"
                    style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                    onmouseover="this.style.color='#00b9eb';this.style.background='#32373c'"
                    onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                    <i class="bi bi-grid-3x3-gap-fill" style="font-size:13px;"></i> Quản lý
                    <i class="bi bi-chevron-down" style="font-size:9px;opacity:.7;"></i>
                </a>
                <div id="wp-quick-actions-dropdown"
                    style="display:none;position:absolute;top:32px;left:0;width:220px;background:#2c3338;box-shadow:0 8px 20px rgba(0,0,0,.35);border-radius:0 0 8px 8px;padding:8px;z-index:999999;border:1px solid #3c434a;border-top:2px solid #6366f1;">
                    <a href="<?php echo BASE_URL; ?>admin/" class="wp-qa-item">
                        <i class="bi bi-speedometer2" style="color:#6366f1;"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/services/add.php" class="wp-qa-item">
                        <i class="bi bi-plus-circle" style="color:#8b5cf6;"></i> Thêm dịch vụ
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/posts/add.php" class="wp-qa-item">
                        <i class="bi bi-pencil-square" style="color:#d97706;"></i> Viết bài mới
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/banners/index.php" class="wp-qa-item">
                        <i class="bi bi-images" style="color:#16a34a;"></i> Banner
                    </a>
                    <div style="border-top:1px solid #3c434a;margin:4px 0;"></div>
                    <a href="<?php echo BASE_URL; ?>admin/contacts/index.php?tab=consultation" class="wp-qa-item">
                        <i class="bi bi-people-fill" style="color:#db2777;"></i> Tư vấn dịch vụ
                    </a>
                    <a href="<?php echo BASE_URL; ?>admin/settings/index.php" class="wp-qa-item">
                        <i class="bi bi-gear-fill" style="color:#0284c7;"></i> Cấu hình
                    </a>
                    <div style="border-top:1px solid #3c434a;margin:4px 0;"></div>
                    <a href="<?php echo BASE_URL; ?>" target="_center" class="wp-qa-item">
                        <i class="bi bi-eye" style="color:#94a3b8;"></i> Xem trang chủ
                        <i class="bi bi-box-arrow-up-right" style="font-size:9px;opacity:.4;margin-left:auto;"></i>
                    </a>
                </div>
            </div>
            <style>
                .wp-qa-item {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 7px 10px;
                    border-radius: 6px;
                    color: #c3c4c7;
                    text-decoration: none;
                    font-size: 12.5px;
                    transition: all .15s;
                }

                .wp-qa-item:hover {
                    background: #3c434a;
                    color: #fff;
                }

                .wp-qa-item i:first-child {
                    font-size: 14px;
                    width: 18px;
                    text-align: center;
                }
            </style>
            <script>
                function toggleQuickActions() {
                    var dd = document.getElementById('wp-quick-actions-dropdown');
                    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
                }
                document.addEventListener('click', function (e) {
                    var el = document.getElementById('wp-quick-actions');
                    if (el && !el.contains(e.target)) {
                        document.getElementById('wp-quick-actions-dropdown').style.display = 'none';
                    }
                });

                async function wpClearAdminCache(event) {
                    event.preventDefault();
                    var btn = document.getElementById('wp-clear-cache');
                    if (!btn || btn.dataset.loading === '1') return;

                    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
                    var csrfToken = tokenMeta ? (tokenMeta.getAttribute('content') || '') : '';
                    if (!csrfToken) {
                        alert('Không tìm thấy CSRF token.');
                        return;
                    }

                    var oldHtml = btn.innerHTML;
                    btn.dataset.loading = '1';
                    btn.innerHTML = '<i class="bi bi-arrow-clockwise" style="font-size:13px;animation:spin 1s linear infinite;"></i> Đang xóa...';

                    try {
                        var body = new URLSearchParams();
                        body.append('csrf_token', csrfToken);

                        var response = await fetch('<?php echo BASE_URL; ?>admin/ajax/clear-cache.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                'X-CSRF-Token': csrfToken
                            },
                            body: body.toString()
                        });

                        var data = await response.json();
                        var msg = (data && data.message) ? data.message : 'Không có phản hồi từ server.';

                        if (data && data.success) {
                            if (window.AdminPopup && typeof window.AdminPopup.success === 'function') {
                                window.AdminPopup.success(msg);
                            } else {
                                alert(msg);
                            }
                        } else {
                            if (window.AdminPopup && typeof window.AdminPopup.error === 'function') {
                                window.AdminPopup.error(msg);
                            } else {
                                alert(msg);
                            }
                        }
                    } catch (err) {
                        var failMsg = 'Không gọi được API xóa cache.';
                        if (window.AdminPopup && typeof window.AdminPopup.error === 'function') {
                            window.AdminPopup.error(failMsg);
                        } else {
                            alert(failMsg);
                        }
                    } finally {
                        btn.dataset.loading = '0';
                        btn.innerHTML = oldHtml;
                    }
                }
            </script>

            <!-- Registrations -->
            <div id="wp-registrations-item" style="position:relative;display:flex;align-items:center;">
                <a href="<?php echo BASE_URL; ?>admin/contacts/index.php?tab=consultation" title="Tư vấn dịch vụ"
                    style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                    onmouseover="this.style.color='#00b9eb';this.style.background='#32373c'"
                    onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                    <i class="bi bi-person-lines-fill" style="font-size:13px;"></i> Tư vấn dịch vụ
                    <?php
                    try {
                        // Use global $pdo if available
                        global $pdo;
                        if (isset($pdo)) {
                            $pending_stmt = $pdo->query("SELECT COUNT(*) FROM service_registrations WHERE status = 'pending'");
                            $pending_num = $pending_stmt ? $pending_stmt->fetchColumn() : 0;
                            if ($pending_num > 0): ?>
                                <span
                                    style="background:#d63638;color:#fff;font-size:9px;font-weight:600;padding:0 5px;border-radius:10px;min-width:16px;text-align:center;line-height:16px;">
                                    <?php echo $pending_num; ?>
                                </span>
                            <?php endif;
                        }
                    } catch (Exception $e) {
                    }
                    ?>
                </a>
                <div id="wp-registrations-dropdown"
                    style="display:none;position:absolute;top:32px;left:0;width:300px;background:#23282d;box-shadow:0 8px 15px rgba(0,0,0,0.3);border-radius:0 0 4px 4px;padding:12px;z-index:999999;border:1px solid #3c434a;border-top:none;">
                    <h6
                        style="color:#00b9eb;font-size:11px;text-transform:uppercase;margin:0 0 10px 0;padding-bottom:8px;border-bottom:1px solid #3c434a;display:flex;align-items:center;gap:6px;">
                        <i class="bi bi-clock-history"></i> Tư vấn dịch vụ mới
                    </h6>
                    <div class="text-white small">
                        <a href="<?php echo BASE_URL; ?>admin/contacts/index.php?tab=consultation"
                            class="text-info text-decoration-none">Xem tất cả tư vấn dịch vụ</a>
                    </div>
                </div>
            </div>

            <!-- Clear Cache -->
            <span style="color:#555;margin:0 2px;">│</span>
            <a href="#" id="wp-clear-cache" title="Xóa cache"
                style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                onmouseover="this.style.color='#00b9eb';this.style.background='#32373c'"
                onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'"
                onclick="wpClearAdminCache(event);">
                <i class="bi bi-arrow-clockwise" style="font-size:13px;"></i> Xóa cache
            </a>
        </div>

        <!-- Right items -->
        <div style="margin-left:auto;display:flex;align-items:center;">
            <span style="color:#c3c4c7;padding:0 8px;display:flex;align-items:center;gap:5px;">
                <i class="bi bi-person-circle" style="font-size:14px;"></i>
                <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin'); ?>
            </span>
            <a href="<?php echo BASE_URL; ?>admin/logout.php" title="Đăng xuất"
                style="color:#c3c4c7;text-decoration:none;padding:0 8px;height:32px;display:flex;align-items:center;gap:5px;transition:color .1s,background .1s;"
                onmouseover="this.style.color='#d63638';this.style.background='#32373c'"
                onmouseout="this.style.color='#c3c4c7';this.style.background='transparent'">
                <i class="bi bi-box-arrow-right" style="font-size:13px;"></i>
            </a>
        </div>
    </div>
</div>
<style>
    /* Adjust Admin Layout for Fixed Top Bar */
    body {
        padding-top: 32px !important;
    }

    /* Admin Sidebar Layout Fix */
    .admin-sidebar {
        top: 32px !important;
        height: calc(100vh - 32px) !important;
    }

    /* Mobile Overlay Fix */
    .sidebar-overlay {
        top: 32px !important;
    }

    /* Admin Content Topbar (The white one) */
    .admin-topbar {
        top: 32px !important;
    }

    /* Dropdown hover behavior */
    #wp-registrations-item:hover #wp-registrations-dropdown {
        display: block !important;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
</style>

