-- TimerAdmin empty database rebuild
-- Import this into the selected Hostinger database using phpMyAdmin.
-- This creates the current schema, records all migrations as applied,
-- and inserts the default admin account.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `password` VARCHAR(255) NOT NULL,
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
    `remember_token` VARCHAR(100) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `news_posts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(120) NOT NULL,
    `body` LONGTEXT NOT NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `news_posts_published_at_index` (`published_at`),
    KEY `news_posts_created_by_foreign` (`created_by`),
    CONSTRAINT `news_posts_created_by_foreign`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `licenses` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(12) NOT NULL,
    `duration` VARCHAR(32) NULL DEFAULT NULL,
    `expires_at` DATE NULL DEFAULT NULL,
    `device_name` VARCHAR(255) NULL DEFAULT NULL,
    `device_id` VARCHAR(255) NULL DEFAULT NULL,
    `machine_id` VARCHAR(255) NULL DEFAULT NULL,
    `device_secret` VARCHAR(64) NULL DEFAULT NULL,
    `consumed_by_license_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `activated_at` TIMESTAMP NULL DEFAULT NULL,
    `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
    `last_seen_ip` VARCHAR(45) NULL DEFAULT NULL,
    `app_version` VARCHAR(50) NULL DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `licenses_code_unique` (`code`),
    UNIQUE KEY `licenses_device_secret_unique` (`device_secret`),
    KEY `licenses_device_name_index` (`device_name`),
    KEY `licenses_device_id_index` (`device_id`),
    KEY `licenses_machine_id_index` (`machine_id`),
    KEY `licenses_last_seen_at_index` (`last_seen_at`),
    KEY `licenses_created_by_foreign` (`created_by`),
    KEY `licenses_consumed_by_license_id_foreign` (`consumed_by_license_id`),
    CONSTRAINT `licenses_created_by_foreign`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `licenses_consumed_by_license_id_foreign`
        FOREIGN KEY (`consumed_by_license_id`) REFERENCES `licenses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_updates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version` VARCHAR(50) NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `external_download_url` VARCHAR(2048) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `uploaded_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `app_updates_version_index` (`version`),
    KEY `app_updates_published_at_index` (`published_at`),
    KEY `app_updates_uploaded_by_foreign` (`uploaded_by`),
    CONSTRAINT `app_updates_uploaded_by_foreign`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dashboard_photos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(120) NULL DEFAULT NULL,
    `image_path` VARCHAR(255) NOT NULL,
    `image_name` VARCHAR(255) NOT NULL,
    `position` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
    `uploaded_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `dashboard_photos_position_index` (`position`),
    KEY `dashboard_photos_is_visible_index` (`is_visible`),
    KEY `dashboard_photos_uploaded_by_foreign` (`uploaded_by`),
    CONSTRAINT `dashboard_photos_uploaded_by_foreign`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('0001_01_01_000000_create_users_table', 1),
('2026_04_21_000001_create_news_posts_table', 1),
('2026_04_21_000002_create_licenses_table', 1),
('2026_04_21_000003_create_app_updates_table', 1),
('2026_04_21_000004_add_machine_id_to_licenses_table', 1),
('2026_04_24_000001_rename_pc_name_to_device_name_on_licenses_table', 1),
('2026_04_24_000002_create_dashboard_photos_table', 1),
('2026_05_09_000001_add_duration_to_licenses_table', 1),
('2026_05_09_000002_add_device_secret_to_licenses_table', 1),
('2026_05_09_000003_add_device_id_to_licenses_table', 1),
('2026_05_10_000001_add_renewal_tracking_to_licenses_table', 1),
('2026_07_13_000001_add_external_download_url_to_app_updates_table', 1);

INSERT INTO `users` (`name`, `email`, `password`, `is_admin`, `created_at`, `updated_at`)
VALUES (
    'Timer Admin',
    'admin@timerapp.local',
    '$2y$12$EX56hSeL41TT8u7QoXnhJ.7V9bQw8BLhG5U9xGb1Ug6KkKJnuS1S6',
    1,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `password` = VALUES(`password`),
    `is_admin` = VALUES(`is_admin`),
    `updated_at` = CURRENT_TIMESTAMP;

INSERT INTO `news_posts` (`title`, `body`, `is_pinned`, `published_at`, `created_by`, `created_at`, `updated_at`)
SELECT
    'TimerAdmin portal is live',
    'Welcome to the new license center. Admins can create activator codes, upload TimerApp updates, and publish news here.',
    1,
    CURRENT_TIMESTAMP,
    `users`.`id`,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `users`
WHERE `users`.`email` = 'admin@timerapp.local'
AND NOT EXISTS (
    SELECT 1 FROM `news_posts` WHERE `title` = 'TimerAdmin portal is live'
);

SET FOREIGN_KEY_CHECKS=1;
