-- =============================================================================
-- CompliPay Database Schema Reference
-- =============================================================================
-- Generated from Laravel migrations
-- For reference only - use migrations for actual deployment
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Users
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    status VARCHAR(50) DEFAULT 'active',           -- active, suspended, deleted
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- -----------------------------------------------------------------------------
-- Password Reset Tokens
-- -----------------------------------------------------------------------------
CREATE TABLE password_reset_tokens (
    email VARCHAR(255) PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL
);

-- -----------------------------------------------------------------------------
-- Sessions
-- -----------------------------------------------------------------------------
CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id CHAR(36) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    INDEX sessions_user_id_index (user_id),
    INDEX sessions_last_activity_index (last_activity)
);

-- -----------------------------------------------------------------------------
-- Organizations (Tenants)
-- -----------------------------------------------------------------------------
-- Each organization is a separate tenant with its own ZATCA compliance settings
CREATE TABLE organizations (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    country CHAR(2) DEFAULT 'SA',                  -- ISO country code
    status VARCHAR(50) DEFAULT 'active',           -- active, suspended, deleted
    compliance_profile JSON NULL,                  -- ZATCA configuration
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- -----------------------------------------------------------------------------
-- Organization Memberships (Pivot)
-- -----------------------------------------------------------------------------
-- Links users to organizations with role-based access
CREATE TABLE organization_user (
    user_id CHAR(36) NOT NULL,
    organization_id CHAR(36) NOT NULL,
    role VARCHAR(50) DEFAULT 'member',             -- admin, member
    status VARCHAR(50) DEFAULT 'active',           -- active, invited, removed
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (user_id, organization_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- -----------------------------------------------------------------------------
-- Cache
-- -----------------------------------------------------------------------------
CREATE TABLE cache (
    `key` VARCHAR(255) PRIMARY KEY,
    value MEDIUMTEXT NOT NULL,
    expiration INT NOT NULL
);

CREATE TABLE cache_locks (
    `key` VARCHAR(255) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration INT NOT NULL
);

-- -----------------------------------------------------------------------------
-- Jobs (Queue)
-- -----------------------------------------------------------------------------
CREATE TABLE jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL,
    reserved_at INT UNSIGNED NULL,
    available_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX jobs_queue_index (queue)
);

CREATE TABLE job_batches (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INT NOT NULL,
    pending_jobs INT NOT NULL,
    failed_jobs INT NOT NULL,
    failed_job_ids LONGTEXT NOT NULL,
    options MEDIUMTEXT NULL,
    cancelled_at INT NULL,
    created_at INT NOT NULL,
    finished_at INT NULL
);

CREATE TABLE failed_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload LONGTEXT NOT NULL,
    exception LONGTEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- -----------------------------------------------------------------------------
-- Invoices
-- -----------------------------------------------------------------------------
-- Core business entity for ZATCA compliance
-- Immutable after status changes from 'draft'
CREATE TABLE invoices (
    id CHAR(36) PRIMARY KEY,
    organization_id CHAR(36) NOT NULL,

    -- Identification
    invoice_number VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,                      -- standard (B2B), simplified (B2C)
    status VARCHAR(50) NOT NULL,                    -- draft, issued, submitted, accepted, rejected

    -- Dates
    issue_date DATE NOT NULL,
    supply_date DATE NULL,

    -- Buyer info
    currency CHAR(3) DEFAULT 'SAR',
    buyer_name VARCHAR(255) NOT NULL,
    buyer_vat_number VARCHAR(50) NULL,
    buyer_address TEXT NULL,

    -- Amounts
    subtotal DECIMAL(12,2) DEFAULT 0,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    total DECIMAL(12,2) DEFAULT 0,

    -- ZATCA compliance fields
    hash VARCHAR(255) NULL,                         -- Invoice hash for compliance
    qr_code TEXT NULL,                              -- Base64 QR code
    zatca_response JSON NULL,                       -- ZATCA API response

    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX invoices_org_status_index (organization_id, status),
    INDEX invoices_number_index (invoice_number)
);

-- -----------------------------------------------------------------------------
-- Invoice Lines
-- -----------------------------------------------------------------------------
CREATE TABLE invoice_lines (
    id CHAR(36) PRIMARY KEY,
    invoice_id CHAR(36) NOT NULL,

    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    tax_rate DECIMAL(5,2) DEFAULT 15.00,            -- Default VAT 15%
    tax_amount DECIMAL(12,2) DEFAULT 0,
    line_total DECIMAL(12,2) DEFAULT 0,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
