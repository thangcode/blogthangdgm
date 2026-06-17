<?php
// includes/blocks/hero.php
// Hero Section Block - Swiper Slider

// Fetch Active Banners
try {
    $stmt = $pdo->query("SELECT * FROM banners WHERE status = 1 ORDER BY sort_order ASC, id DESC");
    $banners = $stmt->fetchAll();
} catch (PDOException $e) {
    $banners = [];
}
$hero_boxed = (string) get_setting('hero_width', 'full') === 'boxed';
?>

<?php if (!empty($banners)): ?>
    <!-- Hero Slider -->

    <section class="hero-section p-0 <?php echo $hero_boxed ? 'hero-section--boxed' : 'bg-white'; ?>">
        <?php if ($hero_boxed): ?><div class="container"><?php endif; ?>
        <div class="swiper heroSwiper <?php echo $hero_boxed ? 'hero-boxed' : ''; ?>">
            <div class="swiper-wrapper">
                <?php foreach ($banners as $index => $banner): 
                    // Optimize LCP: First banner loads eagerly, others lazy
                    $loading_attr = ($index === 0) ? 'fetchpriority="high"' : 'loading="lazy"';
                ?>
                    <div class="swiper-slide">
                        <?php if (!empty($banner['link_url'])): ?>
                            <a href="<?php echo e($banner['link_url']); ?>" class="d-block w-100">
                        <?php else: ?>
                            <div class="d-block w-100">
                        <?php endif; ?>

                        <?php if (!empty($banner['mobile_image_path'])): ?>
                            <?php 
                                $mobile_path = (string) $banner['mobile_image_path'];
                                $desktop_path = (string) ($banner['image_path'] ?? '');
                                $mobile_src = app_resized_image_url($mobile_path, 768);
                                $desktop_src = app_resized_image_url($desktop_path, 1600);
                                $mobile_srcset = app_image_srcset($mobile_path, [360, 576, 768]);
                                $desktop_srcset = app_image_srcset($desktop_path, [960, 1280, 1600]);
                            ?>
                            <picture>
                                <source media="(max-width: 768px)" srcset="<?php echo $mobile_srcset; ?>" sizes="100vw">
                                <img src="<?php echo e($desktop_src); ?>" srcset="<?php echo $desktop_srcset; ?>" sizes="100vw" alt="<?php echo e($banner['title']); ?>" class="img-fluid w-100" width="1920" height="560" <?php echo $loading_attr; ?> decoding="async">
                            </picture>
                        <?php else: ?>
                            <?php 
                                $desktop_path = (string) ($banner['image_path'] ?? '');
                                $desktop_src = app_resized_image_url($desktop_path, 1600);
                                $desktop_srcset = app_image_srcset($desktop_path, [640, 960, 1280, 1600]);
                            ?>
                            <img src="<?php echo e($desktop_src); ?>" srcset="<?php echo $desktop_srcset; ?>" sizes="100vw" alt="<?php echo e($banner['title']); ?>" class="img-fluid w-100" width="1920" height="560" <?php echo $loading_attr; ?> decoding="async">
                        <?php endif; ?>

                        <?php if (!empty($banner['link_url'])): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (count($banners) > 1): ?>
                <div class="swiper-pagination"></div>
                <div class="swiper-button-next d-none d-md-flex"></div>
                <div class="swiper-button-prev d-none d-md-flex"></div>
            <?php endif; ?>
        </div>
        <?php if ($hero_boxed): ?></div><?php endif; ?>
    </section>

    <style>
        .hero-section {
            position: relative;
        }
        .hero-section--boxed { padding-top: 1.5rem !important; padding-bottom: .25rem; }
        .hero-boxed { border-radius: 18px; overflow: hidden; box-shadow: 0 .75rem 2rem rgba(15,23,42,.14); }
        .heroSwiper .swiper-button-next,
        .heroSwiper .swiper-button-prev {
            color: white;
            background: rgba(0,0,0,0.3);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        .heroSwiper .swiper-button-next:after,
        .heroSwiper .swiper-button-prev:after {
            font-size: 18px;
            font-weight: bold;
        }
        .heroSwiper .swiper-button-next:hover,
        .heroSwiper .swiper-button-prev:hover {
            background: rgba(99, 102, 241, 0.8);
        }
        .heroSwiper .swiper-pagination-bullet {
            width: 10px;
            height: 10px;
            background: white;
            opacity: 0.6;
        }
        .heroSwiper .swiper-pagination-bullet-active {
            opacity: 1;
            background: #6366f1;
            width: 25px;
            border-radius: 5px;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var heroSwiper = new Swiper(".heroSwiper", {
                loop: <?php echo count($banners) > 1 ? 'true' : 'false'; ?>,
                autoplay: {
                    delay: 5000,
                    disableOnInteraction: false,
                },
                pagination: {
                    el: ".swiper-pagination",
                    clickable: true,
                },
                navigation: {
                    nextEl: ".swiper-button-next",
                    prevEl: ".swiper-button-prev",
                },
                speed: 800,
            });
        });
    </script>

<?php endif; ?>
