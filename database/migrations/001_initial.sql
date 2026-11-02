SET NAMES utf8mb4;

CREATE TABLE `admins` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `site_name` VARCHAR(190) NOT NULL,
    `site_url` VARCHAR(2048) NOT NULL,
    `wp_username` VARCHAR(190) NOT NULL,
    `encrypted_wp_app_password` TEXT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `frequency_days` SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    `post_time` TIME NOT NULL DEFAULT '03:00:00',
    `publish_mode` ENUM('draft','publish') NOT NULL DEFAULT 'draft',
    `ai_provider` ENUM('openai','anthropic','qwen') NOT NULL DEFAULT 'openai',
    `ai_model` VARCHAR(120) NOT NULL DEFAULT 'gpt-5-mini',
    `encrypted_ai_api_key` TEXT NULL,
    `topics` MEDIUMTEXT NULL,
    `system_prompt` MEDIUMTEXT NULL,
    `category_id` BIGINT UNSIGNED NULL,
    `featured_media_id` BIGINT UNSIGNED NULL,
    `content_mode` ENUM('woocommerce','server_folder') NOT NULL DEFAULT 'woocommerce',
    `folder_source_slug` VARCHAR(64) NULL,
    `blog_branding_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `blog_logo_path` VARCHAR(500) NULL,
    `blog_logo_size_pct` TINYINT UNSIGNED NOT NULL DEFAULT 12,
    `blog_logo_position` VARCHAR(20) NOT NULL DEFAULT 'top-right',
    `blog_brand_text` VARCHAR(300) NULL,
    `blog_text_color` CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    `blog_text_background_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `blog_text_background_color` CHAR(7) NOT NULL DEFAULT '#050F19',
    `blog_text_position` VARCHAR(20) NOT NULL DEFAULT 'bottom-center',
    `blog_text_opacity` TINYINT UNSIGNED NOT NULL DEFAULT 90,
    `blog_text_size_pct` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `last_run_on` DATE NULL,
    `last_success_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_blog_settings_url` (`site_url`(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_articles` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `blog_site_id` BIGINT UNSIGNED NOT NULL,
    `topic` VARCHAR(500) NOT NULL,
    `focus_keyphrase` VARCHAR(190) NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `slug` VARCHAR(250) NOT NULL,
    `excerpt` TEXT NULL,
    `content_html` MEDIUMTEXT NOT NULL,
    `seo_title` VARCHAR(500) NULL,
    `meta_description` VARCHAR(500) NULL,
    `image_alt` VARCHAR(500) NULL,
    `featured_media_id` BIGINT UNSIGNED NULL,
    `sources_json` MEDIUMTEXT NULL,
    `internal_links_json` MEDIUMTEXT NULL,
    `tags_json` TEXT NULL,
    `wp_post_id` BIGINT UNSIGNED NULL,
    `wp_post_url` VARCHAR(2048) NULL,
    `wp_status` VARCHAR(30) NULL,
    `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `seo_checks_json` MEDIUMTEXT NULL,
    `status` ENUM('draft','published','failed') NOT NULL DEFAULT 'draft',
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `published_at` DATETIME NULL,
    UNIQUE KEY `uq_blog_site_focus` (`blog_site_id`,`focus_keyphrase`),
    KEY `idx_blog_articles_site_status` (`blog_site_id`,`status`),
    KEY `idx_blog_articles_created` (`created_at`),
    CONSTRAINT `fk_blog_article_site` FOREIGN KEY (`blog_site_id`) REFERENCES `blog_settings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blog_runs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `blog_site_id` BIGINT UNSIGNED NOT NULL,
    `result` ENUM('success','skipped','failed') NOT NULL,
    `message` TEXT NULL,
    `article_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_blog_runs_site_created` (`blog_site_id`,`created_at`),
    CONSTRAINT `fk_blog_run_site` FOREIGN KEY (`blog_site_id`) REFERENCES `blog_settings` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_blog_run_article` FOREIGN KEY (`article_id`) REFERENCES `blog_articles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(100) NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `details` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
