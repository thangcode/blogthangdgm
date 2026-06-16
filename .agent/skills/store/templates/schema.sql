-- Database Schema for FPTSTORE
-- Generated for Skill Documentation

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `resource_type` varchar(50) NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `banners`
--

CREATE TABLE `banners` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `mobile_image_path` varchar(255) DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `status` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `meta_title` varchar(70) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `status` tinyint(4) DEFAULT 1 COMMENT '1: Active, 0: Inactive',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `parent_id` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` tinyint(4) DEFAULT 0 COMMENT '0: New, 1: Processed',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversion_logs`
--

CREATE TABLE `conversion_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` text DEFAULT NULL,
  `page_url` varchar(500) DEFAULT '',
  `referrer` varchar(500) DEFAULT '',
  `form_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`form_data`)),
  `gtm_event` varchar(100) DEFAULT '',
  `device_type` varchar(20) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faqs`
--

CREATE TABLE `faqs` (
  `id` int(11) NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `status` tinyint(4) DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `homepage_blocks`
--

CREATE TABLE `homepage_blocks` (
  `id` int(11) NOT NULL,
  `block_key` varchar(50) NOT NULL,
  `block_name` varchar(100) NOT NULL,
  `block_icon` varchar(50) DEFAULT 'bi-square',
  `sort_order` int(11) DEFAULT 0,
  `is_visible` tinyint(1) DEFAULT 1,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `homepage_blocks` (`id`, `block_key`, `block_name`, `block_icon`, `sort_order`, `is_visible`, `settings`) VALUES
(1, 'hero', 'Banner chính', 'bi-house', 1, 1, NULL),
(2, 'categories', 'Danh Mục Dịch Vụ', 'bi-grid-3x3-gap', 2, 1, NULL),
(3, 'services', 'Dịch Vụ Nổi Bật', 'bi-star', 4, 1, NULL),
(4, 'news', 'Tin Tức Mới Nhất', 'bi-newspaper', 7, 1, NULL),
(5, 'faq', 'Câu Hỏi Thường Gặp', 'bi-question-circle', 6, 1, NULL),
(6, 'internet', 'Gói Cước Internet', 'bi-wifi', 5, 1, NULL),
(7, 'consultation_form', 'Contact', 'bi-square', 3, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `media_library`
--

CREATE TABLE `media_library` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `extension` varchar(20) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `width` int(10) UNSIGNED DEFAULT NULL,
  `height` int(10) UNSIGNED DEFAULT NULL,
  `sha256_hash` char(64) DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menus`
--

CREATE TABLE `menus` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `url` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `position` enum('header','footer') DEFAULT 'header',
  `status` tinyint(4) DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `posts`
--

CREATE TABLE `posts` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `summary` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `meta_title` varchar(70) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `focus_keyword` varchar(255) DEFAULT NULL,
  `type` enum('news','info') DEFAULT 'news',
  `status` tinyint(4) DEFAULT 1,
  `thumbnail` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seo_settings`
--

CREATE TABLE `seo_settings` (
  `id` int(11) NOT NULL,
  `page_key` varchar(50) NOT NULL COMMENT 'Page identifier: home, news, contact, etc.',
  `meta_title` varchar(70) DEFAULT NULL COMMENT 'SEO Title (max 70 chars)',
  `meta_description` text DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL COMMENT 'SEO Keywords',
  `og_image` varchar(255) DEFAULT NULL COMMENT 'Open Graph Image Path',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `meta_title` varchar(70) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `focus_keyword` varchar(255) DEFAULT NULL,
  `features` text DEFAULT NULL COMMENT 'JSON array of features',
  `price_city` decimal(15,2) DEFAULT 0.00 COMMENT 'Giá HN & TP.HCM',
  `price_province` decimal(15,2) DEFAULT 0.00 COMMENT 'Giá Tỉnh/TP khác',
  `status` tinyint(4) DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_featured` tinyint(4) DEFAULT 0 COMMENT '1: Featured, 0: Normal',
  `image` varchar(255) DEFAULT NULL,
  `gallery` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gallery`)),
  `views` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_registrations`
--

CREATE TABLE `service_registrations` (
  `id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `service_name` varchar(255) DEFAULT NULL,
  `fullname` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `province` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('pending','contacted','completed','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_group` varchar(50) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin') DEFAULT 'admin',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

ALTER TABLE `audit_logs` ADD PRIMARY KEY (`id`), ADD KEY `user_id` (`user_id`), ADD KEY `action` (`action`), ADD KEY `resource_type` (`resource_type`), ADD KEY `created_at` (`created_at`);
ALTER TABLE `banners` ADD PRIMARY KEY (`id`);
ALTER TABLE `categories` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `slug` (`slug`), ADD KEY `fk_parent` (`parent_id`);
ALTER TABLE `contacts` ADD PRIMARY KEY (`id`);
ALTER TABLE `conversion_logs` ADD PRIMARY KEY (`id`), ADD KEY `idx_type` (`type`), ADD KEY `idx_created_at` (`created_at`), ADD KEY `idx_ip` (`ip_address`);
ALTER TABLE `faqs` ADD PRIMARY KEY (`id`);
ALTER TABLE `homepage_blocks` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `block_key` (`block_key`);
ALTER TABLE `media_library` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `uk_sha256_hash` (`sha256_hash`), ADD KEY `idx_created_at` (`created_at`), ADD KEY `idx_uploaded_by` (`uploaded_by`), ADD KEY `idx_mime_type` (`mime_type`);
ALTER TABLE `menus` ADD PRIMARY KEY (`id`);
ALTER TABLE `posts` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `slug` (`slug`) USING HASH;
ALTER TABLE `seo_settings` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `page_key` (`page_key`);
ALTER TABLE `services` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `slug` (`slug`) USING HASH, ADD KEY `category_id` (`category_id`);
ALTER TABLE `service_registrations` ADD PRIMARY KEY (`id`), ADD KEY `idx_phone_created` (`phone`,`created_at`), ADD KEY `idx_status_created` (`status`,`created_at`);
ALTER TABLE `settings` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `setting_key` (`setting_key`);
ALTER TABLE `users` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

ALTER TABLE `audit_logs` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `banners` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `categories` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `contacts` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `conversion_logs` MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `faqs` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `homepage_blocks` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `media_library` MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `menus` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `posts` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `seo_settings` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `services` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `service_registrations` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `settings` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `users` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

COMMIT;
