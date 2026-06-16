<?php
// includes/core.php

/**
 * CORE SYSTEM INTEGRITY CHECK
 * This function verifies if the code is running on an allowed domain.
 */
function check_system_integrity()
{
    // Kết quả không đổi trong cùng một request (domain cố định) -> cache lại,
    // tránh tính toán lặp khi get_setting() được gọi rất nhiều lần.
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    // DANH SÁCH DOMAIN ĐƯỢC PHÉP CHẠY
    // Bạn hãy thêm domain của khách hàng vào đây.
    $allowed_domains = [
        'localhost',
        '127.0.0.1',
        'blogthangdgm.test', // Local development domain
        'thang-dgm.com' // Domain production chính thức
    ];

    $current_domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Loại bỏ www. và port (nếu có)
    $current_domain = str_replace('www.', '', $current_domain);
    $current_domain = explode(':', $current_domain)[0];

    $result = in_array($current_domain, $allowed_domains, true);
    return $result;
}

/**
 * Get a setting value from the database
 * HÀM QUAN TRỌNG NHẤT - ĐÃ ĐƯỢC CHUYỂN VÀO ĐÂY ĐỂ BẢO VỆ
 */
function get_setting($key, $default = '')
{
    // 1. Kiểm tra bản quyền trước khi chạy logic
    if (!check_system_integrity()) {
        header('HTTP/1.1 403 Forbidden');
        echo '<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied • License Violation</title>
    <style>
        :root { --bg: #0f172a; --card: #1e293b; --text: #94a3b8; --white: #f8fafc; --accent: #ef4444; }
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { background: var(--card); padding: 3rem; border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); text-align: center; max-width: 480px; width: 90%; position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.05); }
        .container::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #ef4444, #f97316); }
        h1 { color: var(--white); font-size: 1.5rem; margin-bottom: 0.5rem; font-weight: 700; letter-spacing: -0.025em; }
        p { margin-bottom: 1.5rem; line-height: 1.6; font-size: 0.95rem; }
        .domain { background: rgba(0,0,0,0.3); padding: 0.5rem 1rem; border-radius: 0.375rem; font-family: monospace; color: var(--accent); font-weight: 600; display: inline-block; margin-bottom: 1.5rem; border: 1px dashed rgba(239, 68, 68, 0.3); }
        .icon { width: 64px; height: 64px; background: rgba(239, 68, 68, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: var(--accent); }
        .btn { display: inline-block; background: var(--white); color: var(--bg); text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.2s; font-size: 0.9rem; }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); background: #fff; }
        .footer { margin-top: 2rem; font-size: 0.75rem; opacity: 0.5; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
        </div>
        <h1>License Violation</h1>
        <p>Hệ thống phát hiện source code đang chạy trên tên miền không được cấp phép. Vui lòng liên hệ nhà phát triển để kích hoạt bản quyền.</p>
        <div class="domain">' . htmlspecialchars($_SERVER['HTTP_HOST']) . '</div>
        <a href="https://zalo.me/0362360364" class="btn">Liên hệ hỗ trợ</a>
        <div class="footer">
            System Integrity Check &bull; Error Code: LIC_INVALID_DOMAIN
        </div>
    </div>
</body>
</html>';
        exit;
    }

    // 2. Logic gốc của hàm
    global $pdo;
    try {
        // Đảm bảo kết nối DB đã có
        if (!isset($pdo)) {
            return $default;
        }

        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        return $default;
    }
}
?>