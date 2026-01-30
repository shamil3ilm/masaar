-- ============================================================================
-- CompliPay Database Schema Reference
-- MySQL/MariaDB SQL equivalent of Laravel migrations
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Users Table
-- Stores user accounts for authentication
-- ----------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `email_verified_at` TIMESTAMP NULL,
    `password` VARCHAR(255) NOT NULL,
    `status` VARCHAR(255) NOT NULL DEFAULT 'active',  -- active, suspended, deleted
    `remember_token` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Password Reset Tokens
-- ----------------------------------------------------------------------------
CREATE TABLE `password_reset_tokens` (
    `email` VARCHAR(255) NOT NULL PRIMARY KEY,
    `token` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Sessions Table
-- ----------------------------------------------------------------------------
CREATE TABLE `sessions` (
    `id` VARCHAR(255) NOT NULL PRIMARY KEY,
    `user_id` CHAR(36) NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `payload` LONGTEXT NOT NULL,
    `last_activity` INT NOT NULL,
    INDEX `sessions_user_id_index` (`user_id`),
    INDEX `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Organizations Table
-- Multi-tenant organization entities
-- ----------------------------------------------------------------------------
CREATE TABLE `organizations` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `country` CHAR(2) NOT NULL DEFAULT 'SA',          -- ISO country code
    `status` VARCHAR(255) NOT NULL DEFAULT 'active',  -- active, suspended, deleted
    `compliance_profile` JSON NULL,                    -- ZATCA settings (vat_number, etc.)
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Organization-User Pivot Table
-- Maps users to organizations with roles
-- ----------------------------------------------------------------------------
CREATE TABLE `organization_user` (
    `user_id` CHAR(36) NOT NULL,
    `organization_id` CHAR(36) NOT NULL,
    `role` VARCHAR(255) NOT NULL DEFAULT 'member',    -- admin, member
    `status` VARCHAR(255) NOT NULL DEFAULT 'active',  -- active, invited, removed
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`user_id`, `organization_id`),
    CONSTRAINT `organization_user_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `organization_user_organization_id_foreign`
        FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Invoices Table
-- Core invoice data with ZATCA compliance fields
-- ----------------------------------------------------------------------------
CREATE TABLE `invoices` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `organization_id` CHAR(36) NOT NULL,

    -- Invoice identification
    `invoice_number` VARCHAR(255) NOT NULL,
    `type` VARCHAR(255) NOT NULL,                     -- standard (B2B), simplified (B2C)
    `status` VARCHAR(255) NOT NULL,                   -- draft, issued, submitted, accepted, rejected

    -- Dates
    `issue_date` DATE NOT NULL,
    `supply_date` DATE NULL,

    -- Buyer info
    `currency` CHAR(3) NOT NULL DEFAULT 'SAR',
    `buyer_name` VARCHAR(255) NOT NULL,
    `buyer_vat_number` VARCHAR(255) NULL,
    `buyer_address` TEXT NULL,

    -- Amounts
    `subtotal` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,

    -- ZATCA compliance fields
    `hash` VARCHAR(255) NULL,                         -- Invoice hash for compliance
    `qr_code` TEXT NULL,                              -- Base64 encoded QR code
    `zatca_response` JSON NULL,                       -- ZATCA API response

    `notes` TEXT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,

    INDEX `invoices_organization_id_status_index` (`organization_id`, `status`),
    INDEX `invoices_invoice_number_index` (`invoice_number`),
    CONSTRAINT `invoices_organization_id_foreign`
        FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Invoice Lines Table
-- Line items for invoices
-- ----------------------------------------------------------------------------
CREATE TABLE `invoice_lines` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `invoice_id` CHAR(36) NOT NULL,

    `description` VARCHAR(255) NOT NULL,
    `quantity` DECIMAL(12, 3) NOT NULL,
    `unit_price` DECIMAL(12, 2) NOT NULL,
    `tax_rate` DECIMAL(5, 2) NOT NULL DEFAULT 15.00,  -- Default VAT 15%
    `tax_amount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    `line_total` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,

    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,

    CONSTRAINT `invoice_lines_invoice_id_foreign`
        FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Audit Logs Table
-- Compliance audit trail for all critical operations
-- ----------------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `organization_id` CHAR(36) NULL,
    `user_id` CHAR(36) NULL,

    `action` VARCHAR(255) NOT NULL,                   -- invoice.created, zatca.submission.success, etc.
    `entity_type` VARCHAR(255) NOT NULL,              -- Invoice, Organization, User
    `entity_id` CHAR(36) NULL,

    `old_values` JSON NULL,                           -- Previous state for updates
    `new_values` JSON NULL,                           -- New state for creates/updates

    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `metadata` JSON NULL,                             -- Additional context (ZATCA responses, etc.)

    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,

    INDEX `audit_logs_organization_id_created_at_index` (`organization_id`, `created_at`),
    INDEX `audit_logs_entity_type_entity_id_index` (`entity_type`, `entity_id`),
    INDEX `audit_logs_action_index` (`action`),
    CONSTRAINT `audit_logs_organization_id_foreign`
        FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
    CONSTRAINT `audit_logs_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Cache Table (Laravel)
-- ----------------------------------------------------------------------------
CREATE TABLE `cache` (
    `key` VARCHAR(255) NOT NULL PRIMARY KEY,
    `value` MEDIUMTEXT NOT NULL,
    `expiration` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
    `key` VARCHAR(255) NOT NULL PRIMARY KEY,
    `owner` VARCHAR(255) NOT NULL,
    `expiration` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Jobs Tables (Laravel Queue)
-- ----------------------------------------------------------------------------
CREATE TABLE `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL,
    `reserved_at` INT UNSIGNED NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    INDEX `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
    `id` VARCHAR(255) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `total_jobs` INT NOT NULL,
    `pending_jobs` INT NOT NULL,
    `failed_jobs` INT NOT NULL,
    `failed_job_ids` LONGTEXT NOT NULL,
    `options` MEDIUMTEXT NULL,
    `cancelled_at` INT NULL,
    `created_at` INT NOT NULL,
    `finished_at` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(255) NOT NULL UNIQUE,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
