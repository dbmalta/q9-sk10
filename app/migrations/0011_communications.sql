-- Communications: articles, email queue, email log, member email preferences

CREATE TABLE `articles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(300) NOT NULL,
    `slug` VARCHAR(300) NOT NULL,
    `body` LONGTEXT NOT NULL,
    `excerpt` TEXT NULL,
    `visibility` ENUM('public', 'members', 'portal') NOT NULL DEFAULT 'members',
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `published_at` DATETIME NULL,
    `author_id` INT UNSIGNED NULL,
    `node_scope_id` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_article_slug` (`slug`),
    INDEX `idx_article_published` (`is_published`, `published_at` DESC),
    CONSTRAINT `fk_article_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_article_node_scope` FOREIGN KEY (`node_scope_id`) REFERENCES `org_nodes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_queue` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipient_email` VARCHAR(255) NOT NULL,
    `recipient_name` VARCHAR(200) NULL,
    `subject` VARCHAR(300) NOT NULL,
    `body_html` LONGTEXT NOT NULL,
    `body_text` TEXT NULL,
    `status` ENUM('pending', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `last_error` TEXT NULL,
    `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_queue_status_scheduled` (`status`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipient_email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(300) NOT NULL,
    `status` ENUM('sent', 'failed', 'bounced') NOT NULL,
    `sent_at` DATETIME NOT NULL,
    `error_message` TEXT NULL,
    `email_queue_id` INT UNSIGNED NULL,
    CONSTRAINT `fk_email_log_queue` FOREIGN KEY (`email_queue_id`) REFERENCES `email_queue` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_email_preferences` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `email_type` VARCHAR(50) NOT NULL DEFAULT 'general',
    `is_opted_in` TINYINT(1) NOT NULL DEFAULT 1,
    `bounced` TINYINT(1) NOT NULL DEFAULT 0,
    `bounce_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_member_email_type` (`member_id`, `email_type`),
    CONSTRAINT `fk_email_pref_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
