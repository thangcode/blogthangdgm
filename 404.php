<?php
// 404.php - Premium Error Page
if (!headers_sent()) {
    http_response_code(404);
}
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Set SEO for 404 page
$page_title = "404 - Không tìm thấy trang | " . get_setting('site_name');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $page_title; ?>
    </title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #6366f1;
            --secondary-color: #8b5cf6;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: #0f172a;
            color: #f8fafc;
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        /* Abstract Background Elements */
        .bg-glow {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, rgba(15, 23, 42, 0) 70%);
            border-radius: 50%;
            z-index: -1;
            filter: blur(50px);
        }

        .glow-1 {
            top: -10%;
            left: -10%;
        }

        .glow-2 {
            bottom: -10%;
            right: -10%;
            animation: pulse 8s infinite alternate;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 0.5;
            }

            100% {
                transform: scale(1.2);
                opacity: 0.8;
            }
        }

        /* Glass Container */
        .error-card {
            background: var(--glass-bg);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border: 1px solid var(--glass-border);
            border-radius: 30px;
            padding: 4rem 2rem;
            text-align: center;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-code {
            font-size: 8rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 10px 20px rgba(99, 102, 241, 0.2);
            animation: float 4s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-15px);
            }
        }

        .error-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: #fff;
        }

        .error-text {
            color: #94a3b8;
            margin-bottom: 2.5rem;
            font-size: 1.1rem;
        }

        /* Essential Buttons */
        .btn-premium {
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: none;
        }

        .btn-home {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
        }

        .btn-home:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(99, 102, 241, 0.4);
            color: white;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            border: 1px solid var(--glass-border);
            margin-left: 1rem;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            transform: translateY(-3px);
        }

        /* Quick Links */
        .quick-links {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--glass-border);
        }

        .quick-links-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 2px;
            margin-bottom: 1.5rem;
        }

        .nav-links {
            display: flex;
            justify-content: center;
            gap: 25px;
            flex-wrap: wrap;
        }

        .nav-links a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-links a i {
            font-size: 1.1rem;
            color: var(--primary-color);
        }

        .nav-links a:hover {
            color: #fff;
        }

        @media (max-width: 576px) {
            .error-code {
                font-size: 6rem;
            }

            .btn-back {
                margin-left: 0;
                margin-top: 1rem;
            }

            .error-card {
                padding: 3rem 1.5rem;
            }

            .nav-links {
                gap: 15px;
            }
        }
    </style>
</head>

<body>

    <div class="bg-glow glow-1"></div>
    <div class="bg-glow glow-2"></div>

    <div class="error-card">
        <div class="error-code">404</div>
        <h1 class="error-title">Oops! Không tìm thấy trang</h1>
        <p class="error-text">
            Trang bạn đang tìm kiếm có thể đã bị xóa, thay đổi tên <br class="d-none d-md-block">
            hoặc tạm thời không khả dụng.
        </p>

        <div class="actions">
            <a href="<?php echo BASE_URL; ?>" class="btn btn-premium btn-home">
                <i class="bi bi-house-door-fill"></i> Về trang chủ
            </a>
            <button onclick="history.back()" class="btn btn-premium btn-back">
                <i class="bi bi-arrow-left"></i> Quay lại
            </button>
        </div>

    </div>

    <!-- JS for localized smooth experience -->
    <script>
        // Subtle Mouse Follow effect for Glows
        document.addEventListener('mousemove', (e) => {
            const glow1 = document.querySelector('.glow-1');
            const x = e.clientX / window.innerWidth;
            const y = e.clientY / window.innerHeight;
            glow1.style.transform = `translate(${x * 50}px, ${y * 50}px)`;
        });
    </script>

</body>

</html>