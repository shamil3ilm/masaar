<?php

/**
 * ZATCA E-Invoicing Configuration.
 *
 * Centralized configuration for ZATCA compliance operations.
 * All values can be overridden via environment variables.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | ZATCA Environment
    |--------------------------------------------------------------------------
    |
    | Controls which ZATCA API environment to use.
    | Options: 'sandbox', 'simulation', 'production'
    |
    */
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ],

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | Credentials issued by ZATCA for API authentication.
    |
    */
    'credentials' => [
        'username' => env('ZATCA_USERNAME'),
        'password' => env('ZATCA_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    |
    | CSID (Cryptographic Stamp Identifier) for signing invoices.
    |
    */
    'certificate' => [
        'path' => env('ZATCA_CERTIFICATE_PATH'),
        'private_key_path' => env('ZATCA_PRIVATE_KEY_PATH'),
        'expiry_warning_days' => env('ZATCA_CERT_WARNING_DAYS', 30),
        'expiry_critical_days' => env('ZATCA_CERT_CRITICAL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cryptographic Settings
    |--------------------------------------------------------------------------
    |
    | ECDSA signing configuration per ZATCA specification.
    | ZATCA requires secp256k1 curve with SHA-256 hash algorithm.
    |
    */
    'crypto' => [
        'curve' => env('ZATCA_CRYPTO_CURVE', 'secp256k1'),
        'hash_algorithm' => env('ZATCA_CRYPTO_HASH', OPENSSL_ALGO_SHA256),
        'coordinate_length' => 32, // bytes for secp256k1
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => env('ZATCA_TIMEOUT', 30),
    'connect_timeout' => env('ZATCA_CONNECT_TIMEOUT', 10),
    'retry_attempts' => env('ZATCA_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('ZATCA_RETRY_DELAY', 1000), // milliseconds
    'ssl_verify' => env('ZATCA_SSL_VERIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Protect against excessive API usage and ensure fair resource allocation.
    |
    */
    'rate_limits' => [
        'per_minute' => env('ZATCA_RATE_LIMIT_PER_MINUTE', 60),
        'per_day' => env('ZATCA_RATE_LIMIT_PER_DAY', 10000),
        'max_concurrent' => env('ZATCA_MAX_CONCURRENT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Prevent duplicate submissions during retries.
    |
    */
    'idempotency' => [
        'window_hours' => env('ZATCA_IDEMPOTENCY_HOURS', 24),
        // SCOPE DECLARATION: Idempotency applies per organization + endpoint + idempotency_key
        // Keys are valid for 24 hours from first request. Same key from different
        // organizations or to different endpoints are treated as separate requests.
        'scope' => 'organization + endpoint + key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'large_invoice_amount' => env('ZATCA_LARGE_INVOICE_THRESHOLD', 1000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Automatic protection against cascading failures when ZATCA is unavailable.
    |
    */
    'circuit_breaker' => [
        'enabled' => env('ZATCA_CIRCUIT_BREAKER_ENABLED', true),
        'threshold' => env('ZATCA_CB_THRESHOLD', 5),       // Failures before opening
        'timeout' => env('ZATCA_CB_TIMEOUT', 60),          // Seconds before half-open
        'sample_size' => env('ZATCA_CB_SAMPLE_SIZE', 10),  // Requests to sample
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline Mode
    |--------------------------------------------------------------------------
    |
    | Queue invoices when ZATCA is unavailable (for POS/retail scenarios).
    |
    */
    'offline' => [
        // Enable offline mode capability
        'enabled' => env('ZATCA_OFFLINE_ENABLED', true),

        // Maximum invoices that can be queued per organization
        'queue_max_size' => env('ZATCA_OFFLINE_QUEUE_MAX', 10000),

        // Retry interval for processing offline queue (seconds)
        'retry_interval' => env('ZATCA_OFFLINE_RETRY_INTERVAL', 300),

        // Maximum retry attempts before permanent failure
        'max_attempts' => env('ZATCA_OFFLINE_MAX_ATTEMPTS', 3),

        /*
        |----------------------------------------------------------------------
        | Connectivity Checking
        |----------------------------------------------------------------------
        | Settings for detecting ZATCA API availability and auto-switching
        | to offline mode when connectivity fails.
        |
        */
        'connectivity' => [
            // How often to check connectivity (seconds)
            'check_interval' => env('ZATCA_CONNECTIVITY_CHECK_INTERVAL', 30),

            // Request timeout for connectivity check (seconds)
            'timeout' => env('ZATCA_CONNECTIVITY_TIMEOUT', 10),

            // Number of failures before opening circuit breaker
            'failure_threshold' => env('ZATCA_CONNECTIVITY_FAILURE_THRESHOLD', 3),

            // Duration circuit breaker stays open (seconds)
            'circuit_open_duration' => env('ZATCA_CONNECTIVITY_CIRCUIT_DURATION', 60),
        ],

        /*
        |----------------------------------------------------------------------
        | Local Signing (Offline Capable)
        |----------------------------------------------------------------------
        | When enabled, invoices can be signed locally without server
        | connectivity. The signed invoice is queued for later submission.
        |
        | IMPORTANT: Local signing still requires the organization's
        | certificate and private key to be available locally.
        |
        */
        'local_signing' => [
            // Enable local signing for offline scenarios
            'enabled' => env('ZATCA_LOCAL_SIGNING_ENABLED', true),

            // Generate QR code locally (Phase 1 compatible)
            'generate_qr' => env('ZATCA_LOCAL_QR_ENABLED', true),

            // Store signed XML in local storage if DB is unavailable
            'local_storage_fallback' => env('ZATCA_LOCAL_STORAGE_FALLBACK', true),

            // Path for local storage fallback
            'fallback_storage_path' => env('ZATCA_FALLBACK_PATH', storage_path('app/zatca/offline')),
        ],

        /*
        |----------------------------------------------------------------------
        | Auto-Recovery
        |----------------------------------------------------------------------
        | Settings for automatic processing when connectivity is restored.
        |
        */
        'auto_recovery' => [
            // Automatically process queue when online
            'enabled' => env('ZATCA_AUTO_RECOVERY_ENABLED', true),

            // Maximum items to process per batch
            'batch_size' => env('ZATCA_AUTO_RECOVERY_BATCH', 50),

            // Delay between batches (seconds)
            'batch_delay' => env('ZATCA_AUTO_RECOVERY_DELAY', 5),

            // Process in background (via scheduler) vs synchronous
            'background' => env('ZATCA_AUTO_RECOVERY_BACKGROUND', true),
        ],

        /*
        |----------------------------------------------------------------------
        | POS/Retail Mode
        |----------------------------------------------------------------------
        | Special settings for Point-of-Sale systems that must issue
        | invoices immediately regardless of connectivity.
        |
        */
        'pos_mode' => [
            // Enable POS mode (immediate local completion)
            'enabled' => env('ZATCA_POS_MODE_ENABLED', false),

            // Return QR code immediately (don't wait for clearance)
            'immediate_qr' => env('ZATCA_POS_IMMEDIATE_QR', true),

            // Maximum offline invoice value (SAR) - higher values need warning
            'max_offline_value' => env('ZATCA_POS_MAX_OFFLINE_VALUE', 50000),

            // Warn if offline queue exceeds this size
            'queue_warning_threshold' => env('ZATCA_POS_QUEUE_WARNING', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timestamp Authority (TSA) for XAdES-T
    |--------------------------------------------------------------------------
    |
    | Optional timestamp server for XAdES-T signatures.
    |
    */
    'tsa' => [
        'enabled' => env('ZATCA_TSA_ENABLED', false),
        'url' => env('ZATCA_TSA_URL'),
        'username' => env('ZATCA_TSA_USERNAME'),
        'password' => env('ZATCA_TSA_PASSWORD'),
        'timeout' => env('ZATCA_TSA_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Values
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'payment_means_code' => env('ZATCA_DEFAULT_PAYMENT_MEANS', '10'), // Cash
        'currency' => env('ZATCA_DEFAULT_CURRENCY', 'SAR'),
        'country' => env('ZATCA_DEFAULT_COUNTRY', 'SA'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Configurable validation rules for ZATCA compliance.
    | These can be updated as ZATCA regulations change.
    |
    */
    'validation' => [
        // Allowed VAT rates in Saudi Arabia (percentage values)
        'allowed_tax_rates' => [0, 15],

        // Valid invoice type codes per UBL 2.1 / ZATCA
        // Note: '325' (Proforma) is NOT valid for ZATCA submission
        'invoice_type_codes' => [
            '388' => 'Tax Invoice',
            '381' => 'Credit Note',
            '383' => 'Debit Note',
            '386' => 'Prepayment Invoice',
        ],

        // Invoice types that can be submitted to ZATCA
        // Proforma (325) is explicitly excluded
        'zatca_submittable_types' => ['388', '381', '383', '386'],

        // Valid buyer identification schemes (for non-VAT registered buyers)
        'buyer_id_schemes' => [
            'TIN' => 'Tax Identification Number',
            'CRN' => 'Commercial Registration Number',
            'MOM' => 'Momra License',
            'MLS' => 'MLSD License',
            'SAG' => 'Sagia License',
            'NAT' => 'National ID (Saudis)',
            'GCC' => 'GCC ID',
            'IQA' => 'Iqama Number',
            'PAS' => 'Passport Number',
            'OTH' => 'Other ID',
        ],

        // Valid tax exemption reason codes (VATEX-SA-*)
        // Source: ZATCA E-Invoicing Implementation Guidelines
        'exemption_codes' => [
            // Zero-rated supplies (Z) - Article 32 & 33
            'VATEX-SA-29'   => 'Supply of qualified metals',
            'VATEX-SA-29-7' => 'Supply of eligible goods to SEZ',
            'VATEX-SA-30'   => 'Medicines and medical equipment',
            'VATEX-SA-31'   => 'Transport services for goods/passengers',
            'VATEX-SA-32'   => 'Export of goods',
            'VATEX-SA-33'   => 'Export of services',
            'VATEX-SA-34-1' => 'Intra-GCC supply of goods',
            'VATEX-SA-34-2' => 'Intra-GCC supply of services',
            'VATEX-SA-34-3' => 'Intra-GCC supply to GCC government',
            'VATEX-SA-34-4' => 'Intra-GCC supply of tourist services',
            'VATEX-SA-34-5' => 'Intra-GCC supply via agent',
            'VATEX-SA-35'   => 'First supply of residential real estate within 3 years',
            'VATEX-SA-36'   => 'Transfer of qualifying assets between related parties',

            // Exempt supplies (E) - Article 29 & 30
            'VATEX-SA-EDU'  => 'Private education services',
            'VATEX-SA-HEA'  => 'Private healthcare services',
            'VATEX-SA-29-1' => 'Financial services - margin based',
            'VATEX-SA-29-2' => 'Life insurance services',
            'VATEX-SA-29-3' => 'Real estate lease - residential',

            // Out of scope (O)
            'VATEX-SA-OOS'  => 'Out of scope supply',
        ],

        // Strict mode: reject invoices with unknown exemption codes
        'strict_exemption_codes' => env('ZATCA_STRICT_EXEMPTION_CODES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific features for gradual rollout or testing.
    |
    */
    'features' => [
        'async_submission' => env('ZATCA_FEATURE_ASYNC', true),
        'offline_mode' => env('ZATCA_FEATURE_OFFLINE', true),
        'circuit_breaker' => env('ZATCA_FEATURE_CIRCUIT_BREAKER', true),
        'timestamp_authority' => env('ZATCA_FEATURE_TSA', false),
        'certificate_revocation_check' => env('ZATCA_FEATURE_CRL_CHECK', true),
        'arabic_normalization' => env('ZATCA_FEATURE_ARABIC_NORM', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for async submission queue processing.
    |
    */
    'queue' => [
        'connection' => env('ZATCA_QUEUE_CONNECTION', 'redis'),
        'name' => env('ZATCA_QUEUE_NAME', 'zatca-submissions'),
        'tries' => env('ZATCA_QUEUE_TRIES', 3),
        'timeout' => env('ZATCA_QUEUE_TIMEOUT', 120),
        'backoff' => [10, 60, 300], // seconds between retries
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'channel' => env('ZATCA_LOG_CHANNEL', 'stack'),
        'level' => env('ZATCA_LOG_LEVEL', 'info'),
        'sanitize_xml' => env('ZATCA_LOG_SANITIZE', true), // Remove sensitive data
    ],

    /*
    |--------------------------------------------------------------------------
    | Timestamp Validation
    |--------------------------------------------------------------------------
    |
    | Enforce clock drift tolerance between invoice timestamps and server time.
    | See docs/COMPLIANCE-POLICIES.md Section 7: Timestamp Authority
    |
    */
    'timestamp' => [
        'max_drift_seconds' => env('ZATCA_MAX_DRIFT_SECONDS', 30),
        'warn_erp_drift' => env('ZATCA_WARN_ERP_DRIFT', true),
        'enforce_validation' => env('ZATCA_ENFORCE_TIMESTAMP_VALIDATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Webhook delivery settings for event notifications.
    | See docs/COMPLIANCE-POLICIES.md Section 10: Webhook Replay Protection
    |
    */
    'webhooks' => [
        'enabled' => env('ZATCA_WEBHOOKS_ENABLED', true),
        'max_event_age_seconds' => env('ZATCA_WEBHOOK_MAX_AGE', 300), // 5 minutes
        'signature_algorithm' => 'sha256',
        'retry_attempts' => env('ZATCA_WEBHOOK_RETRY_ATTEMPTS', 3),
        'retry_delay_seconds' => env('ZATCA_WEBHOOK_RETRY_DELAY', 5),
        'timeout_seconds' => env('ZATCA_WEBHOOK_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Variance Tracking
    |--------------------------------------------------------------------------
    |
    | Track sandbox vs production behavioral differences.
    | See docs/COMPLIANCE-POLICIES.md Section 9: Sandbox vs Production Variance
    |
    */
    'variance_tracking' => [
        'enabled' => env('ZATCA_VARIANCE_TRACKING_ENABLED', true),
        'async_logging' => env('ZATCA_VARIANCE_ASYNC', true), // Non-blocking on hot path
        'cache_ttl_hours' => env('ZATCA_VARIANCE_CACHE_TTL', 24),
        'max_retries' => env('ZATCA_VARIANCE_RETRY_MAX', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Health Monitoring
    |--------------------------------------------------------------------------
    |
    | Database index health monitoring thresholds.
    | See docs/PRODUCTION-READINESS.md Section 8: Index Health Monitoring
    |
    */
    'index_health' => [
        'enabled' => env('ZATCA_INDEX_HEALTH_ENABLED', true),
        'check_interval_minutes' => env('ZATCA_INDEX_CHECK_INTERVAL', 15),
        'p95_warning_ms' => env('ZATCA_P95_WARNING_MS', 100),
        'p99_critical_ms' => env('ZATCA_P99_CRITICAL_MS', 500),
        'seq_scan_warning' => env('ZATCA_SEQ_SCAN_WARNING', 1000),
        'alert_on_critical' => env('ZATCA_ALERT_ON_CRITICAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Partition Maintenance
    |--------------------------------------------------------------------------
    |
    | Automatic table partitioning for high-volume tables.
    |
    */
    'partitioning' => [
        'enabled' => env('ZATCA_PARTITIONING_ENABLED', false), // Enable after 10M rows
        'threshold_rows' => env('ZATCA_PARTITION_THRESHOLD', 10000000),
        'months_ahead' => env('ZATCA_PARTITION_MONTHS_AHEAD', 2),
        'archive_threshold_months' => env('ZATCA_ARCHIVE_THRESHOLD_MONTHS', 84), // 7 years
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance Policies
    |--------------------------------------------------------------------------
    |
    | Explicit policies for legal and operational clarity.
    | These policies govern behavior in edge cases and disputes.
    |
    */
    'policies' => [

        /*
        |----------------------------------------------------------------------
        | Time Authority Precedence
        |----------------------------------------------------------------------
        |
        | Defines which timestamp is authoritative when disputes arise.
        | POLICY: If XAdES-T is enabled, TSA timestamp is authoritative.
        |         Otherwise, system UTC timestamp at signing time is authoritative.
        |
        */
        'time_authority' => [
            'precedence' => env('ZATCA_TIME_PRECEDENCE', 'tsa_then_system'),
            // Options: 'tsa_only', 'tsa_then_system', 'system_only'
            'authoritative_source' => 'When XAdES-T enabled: TSA timestamp. Otherwise: System UTC at signing.',
        ],

        /*
        |----------------------------------------------------------------------
        | Certificate Overlap Resolution
        |----------------------------------------------------------------------
        |
        | When old and new certificates are both valid during overlap period.
        | POLICY: Always use newest active certificate once issued, unless
        |         invoice explicitly targets historical reconciliation.
        |
        */
        'certificate_overlap' => [
            'use_newest' => env('ZATCA_CERT_USE_NEWEST', true),
            'allow_historical_signing' => env('ZATCA_ALLOW_HISTORICAL_SIGNING', false),
            'overlap_grace_days' => env('ZATCA_CERT_OVERLAP_GRACE_DAYS', 7),
        ],

        /*
        |----------------------------------------------------------------------
        | Legal Hold
        |----------------------------------------------------------------------
        |
        | POLICY: Legal hold supersedes retention and deletion policies
        |         until explicitly released by authorized personnel.
        |
        */
        'legal_hold' => [
            'enabled' => env('ZATCA_LEGAL_HOLD_ENABLED', true),
            'supersedes_retention' => true, // Always true - documented policy
            'requires_authorization' => true,
            'audit_all_operations' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Data Retention
        |----------------------------------------------------------------------
        |
        | ZATCA requires 7-year retention for tax invoices.
        | Legal hold overrides these policies when active.
        |
        */
        'retention' => [
            'invoices_years' => env('ZATCA_RETENTION_INVOICES_YEARS', 7),
            'audit_logs_years' => env('ZATCA_RETENTION_AUDIT_YEARS', 7),
            'submissions_years' => env('ZATCA_RETENTION_SUBMISSIONS_YEARS', 7),
            'certificates_permanent' => true, // Never delete certificate history
        ],

        /*
        |----------------------------------------------------------------------
        | Disaster Recovery Objectives
        |----------------------------------------------------------------------
        |
        | RPO (Recovery Point Objective): Maximum acceptable data loss
        | RTO (Recovery Time Objective): Maximum acceptable downtime
        |
        | These are operational targets, not guarantees. Actual values depend
        | on infrastructure configuration (database replication, backup frequency).
        |
        */
        'disaster_recovery' => [
            'rpo_minutes' => env('ZATCA_DR_RPO_MINUTES', 5),   // Max 5 min data loss
            'rto_minutes' => env('ZATCA_DR_RTO_MINUTES', 30),  // Max 30 min downtime
            'backup_frequency' => 'continuous',                 // Database replication
            'backup_retention_days' => env('ZATCA_BACKUP_RETENTION_DAYS', 30),
        ],

        /*
        |----------------------------------------------------------------------
        | Data Residency & Sovereignty
        |----------------------------------------------------------------------
        |
        | POLICY: CompliPay supports Saudi Arabia–resident deployments.
        | Data residency is determined by infrastructure configuration,
        | not application logic. The application is jurisdiction-agnostic.
        |
        | For KSA compliance:
        | - Deploy database in SA region (e.g., AWS me-south-1, Azure UAE North)
        | - Store certificates in SA-resident HSM or encrypted storage
        | - Ensure backup replication stays within approved regions
        |
        */
        'data_residency' => [
            'policy' => 'Infrastructure-determined, application-agnostic',
            'supported_regions' => ['SA', 'GCC'], // Governance, not enforcement
            'cross_border_transfer' => env('ZATCA_ALLOW_CROSS_BORDER', false),
            'documentation_url' => 'docs/COMPREHENSIVE-PROJECT-REPORT.md#119-data-residency--sovereignty',
        ],

        /*
        |----------------------------------------------------------------------
        | Human Override Governance
        |----------------------------------------------------------------------
        |
        | POLICY: Critical operations may require dual authorization.
        | This is a governance recommendation, not enforced by application.
        |
        | Operations subject to dual-authorization policy:
        | - Certificate revocation / emergency rotation
        | - Legal hold release
        | - Kill switch extension beyond 24 hours
        | - Bulk data deletion or archival
        | - Production environment configuration changes
        |
        */
        'governance' => [
            'dual_authorization_recommended' => true,
            'critical_operations' => [
                'certificate_revocation',
                'legal_hold_release',
                'kill_switch_extension',
                'bulk_data_deletion',
                'production_config_change',
            ],
            // Enforcement is organizational policy, not application logic
            'enforcement' => 'organizational_policy',
            'audit_all_overrides' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Clock Source Integrity
        |----------------------------------------------------------------------
        |
        | POLICY: System clock must be synchronized via NTP or cloud provider.
        | Timestamp accuracy is critical for ZATCA compliance and audit trails.
        |
        | Recommended configuration:
        | - Use cloud provider time sync (AWS: chrony, Azure: w32time, GCP: NTP)
        | - Monitor clock drift with alerting (> 1 second = warning)
        | - For XAdES-T: TSA provides authoritative timestamp regardless of local clock
        |
        */
        'clock_integrity' => [
            'ntp_required' => true,
            'max_acceptable_drift_ms' => env('ZATCA_MAX_CLOCK_DRIFT_MS', 1000),
            'monitoring_enabled' => env('ZATCA_CLOCK_MONITORING', true),
            'cloud_sync_preferred' => true,
            // Note: XAdES-T signatures provide TSA timestamp independent of local clock
            'tsa_overrides_local' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Health Monitoring
    |--------------------------------------------------------------------------
    |
    | Thresholds for detecting silent failures in the offline queue system.
    |
    */
    'queue_health' => [
        'stuck_item_threshold_minutes' => env('ZATCA_QUEUE_STUCK_THRESHOLD', 30),
        'max_retry_count' => env('ZATCA_QUEUE_MAX_RETRIES', 5),
        'queue_growth_threshold' => env('ZATCA_QUEUE_GROWTH_THRESHOLD', 100),
        'processing_rate_min_per_hour' => env('ZATCA_QUEUE_MIN_RATE', 10),
        'alert_cooldown_minutes' => env('ZATCA_QUEUE_ALERT_COOLDOWN', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cluster Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Distributed circuit breaker settings for multi-node deployments.
    |
    */
    'cluster_circuit_breaker' => [
        'failure_threshold' => env('ZATCA_CCB_FAILURE_THRESHOLD', 5),
        'success_threshold' => env('ZATCA_CCB_SUCCESS_THRESHOLD', 3),
        'timeout_seconds' => env('ZATCA_CCB_TIMEOUT', 60),
        'half_open_max_requests' => env('ZATCA_CCB_HALF_OPEN_REQUESTS', 3),
        'node_stale_seconds' => env('ZATCA_CCB_NODE_STALE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Back Pressure Management
    |--------------------------------------------------------------------------
    |
    | Rate limiting using token bucket algorithm for graceful degradation.
    |
    */
    'back_pressure' => [
        'tokens_per_second' => env('ZATCA_BP_TOKENS_PER_SEC', 10),
        'bucket_size' => env('ZATCA_BP_BUCKET_SIZE', 100),
        'min_tokens' => env('ZATCA_BP_MIN_TOKENS', 1),
        'burst_allowance' => env('ZATCA_BP_BURST_ALLOWANCE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kill Switch
    |--------------------------------------------------------------------------
    |
    | Emergency submission halt configuration.
    |
    */
    'kill_switch' => [
        'max_duration_seconds' => env('ZATCA_KS_MAX_DURATION', 14400), // 4 hours
        'alert_threshold_seconds' => env('ZATCA_KS_ALERT_THRESHOLD', 1800), // 30 min
        'cache_ttl_seconds' => env('ZATCA_KS_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Clearance State Management
    |--------------------------------------------------------------------------
    |
    | Polling configuration for B2B clearance status checks.
    |
    */
    'clearance_state' => [
        'max_check_attempts' => env('ZATCA_CS_MAX_CHECKS', 10),
        'initial_check_delay_seconds' => env('ZATCA_CS_INITIAL_DELAY', 30),
        'max_check_delay_seconds' => env('ZATCA_CS_MAX_DELAY', 3600),
        'backoff_multiplier' => env('ZATCA_CS_BACKOFF_MULTIPLIER', 2),
        'check_job_tries' => env('ZATCA_CS_JOB_TRIES', 3),
        'check_job_backoff' => env('ZATCA_CS_JOB_BACKOFF', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hash Chain Management
    |--------------------------------------------------------------------------
    |
    | Configuration for hash chain locking and sequence management.
    |
    */
    'hash_chain' => [
        'lock_timeout_seconds' => env('ZATCA_HASH_CHAIN_LOCK_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hash Chain Longevity Monitoring
    |--------------------------------------------------------------------------
    |
    | Monitor P95/P99 latency for hash chain queries to detect degradation
    | before it becomes critical. Alert on drift, not failure.
    |
    */
    'hash_chain_monitoring' => [
        'enabled' => env('ZATCA_HASH_CHAIN_MONITORING', true),
        'p95_warning_ms' => env('ZATCA_HASH_CHAIN_P95_WARNING', 50),
        'p99_critical_ms' => env('ZATCA_HASH_CHAIN_P99_CRITICAL', 200),
        'sample_interval_minutes' => env('ZATCA_HASH_CHAIN_SAMPLE_INTERVAL', 5),
        'alert_on_degradation' => env('ZATCA_HASH_CHAIN_ALERT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT Period Tracking
    |--------------------------------------------------------------------------
    |
    | Configuration for VAT period handling and cross-period adjustments.
    | Used by VatPeriodTracker for credit/debit note period determination.
    |
    */
    'vat_period' => [
        // Default VAT filing period type: 'monthly' or 'quarterly'
        'default_period_type' => env('ZATCA_VAT_PERIOD_TYPE', 'monthly'),

        // Day of month when VAT return is due (typically 28th)
        'filing_deadline_day' => env('ZATCA_VAT_FILING_DEADLINE_DAY', 28),

        // Fuzzy match window for duplicate detection (hours)
        'fuzzy_match_window_hours' => env('ZATCA_FUZZY_MATCH_HOURS', 24),

        // Enable cross-period adjustment tracking
        'track_cross_period' => env('ZATCA_TRACK_CROSS_PERIOD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate Detection
    |--------------------------------------------------------------------------
    |
    | Configuration for invoice duplicate detection.
    | Used by DuplicateInvoiceDetector service.
    |
    */
    'duplicate_detection' => [
        // Cache TTL for duplicate check results (minutes)
        'cache_ttl_minutes' => env('ZATCA_DEDUP_CACHE_TTL', 60),

        // Enable fuzzy matching for similar invoices
        'fuzzy_matching_enabled' => env('ZATCA_FUZZY_MATCHING', true),

        // Amount tolerance for fuzzy matching (SAR)
        'fuzzy_amount_tolerance' => env('ZATCA_FUZZY_AMOUNT_TOLERANCE', 1.0),

        // Enable sync conflict detection
        'sync_conflict_detection' => env('ZATCA_SYNC_CONFLICT_DETECTION', true),

        // Lookback window for sync conflict detection (minutes)
        'sync_lookback_minutes' => env('ZATCA_SYNC_LOOKBACK_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Out of Scope Tax Types
    |--------------------------------------------------------------------------
    |
    | IMPORTANT: These tax types are EXPLICITLY NOT handled by ZATCA e-invoicing.
    | They require separate systems, manual processes, or different authorities.
    |
    | This is intentional and per ZATCA regulations.
    |
    */
    'out_of_scope_taxes' => [
        /*
        |----------------------------------------------------------------------
        | Import VAT / Customs VAT
        |----------------------------------------------------------------------
        | Customs VAT is paid at import and handled by Saudi Customs Authority,
        | NOT through the ZATCA e-invoicing system.
        |
        | - Paid at port of entry
        | - Separate customs declaration process
        | - Recoverable through VAT return (input VAT)
        | - Handle in accounting system, not e-invoicing
        |
        */
        'import_vat' => [
            'handled' => false,
            'authority' => 'Saudi Customs Authority',
            'reason' => 'Customs VAT is outside ZATCA e-invoicing scope',
            'recommendation' => 'Record in accounting system as input VAT for recovery',
        ],

        /*
        |----------------------------------------------------------------------
        | Excise Tax
        |----------------------------------------------------------------------
        | Excise tax (on tobacco, energy drinks, sweetened beverages, etc.)
        | is handled by GSTAT separately from VAT e-invoicing.
        |
        | - Different tax authority process
        | - Separate excise tax returns
        | - Not included in e-invoice tax calculations
        |
        */
        'excise_tax' => [
            'handled' => false,
            'authority' => 'ZATCA (separate excise system)',
            'reason' => 'Excise tax has dedicated reporting system',
            'recommendation' => 'Build dedicated excise tax module if needed',
        ],

        /*
        |----------------------------------------------------------------------
        | Deferred VAT
        |----------------------------------------------------------------------
        | Deferred VAT schemes (common in imports, certain sectors) have
        | regulatory-specific treatment that varies by sector.
        |
        | - Import deferral schemes
        | - Cash accounting scheme
        | - Sector-specific treatments
        |
        */
        'deferred_vat' => [
            'handled' => false,
            'authority' => 'ZATCA',
            'reason' => 'Regulatory-specific, varies by sector',
            'recommendation' => 'Add is_deferred flag to invoices if tracking needed',
        ],

        /*
        |----------------------------------------------------------------------
        | VAT Return Filing
        |----------------------------------------------------------------------
        | The actual VAT return submission to ZATCA portal is a separate process.
        | VatPeriodTracker.getPeriodSummary() provides the data but filing is manual.
        |
        | - Portal submission required
        | - Payment processing separate
        | - Audit response handling manual
        |
        */
        'vat_return_filing' => [
            'handled' => false,
            'authority' => 'ZATCA Portal',
            'reason' => 'Portal submission is manual process',
            'recommendation' => 'Use VatPeriodTracker.getPeriodSummary() for data preparation',
            'integration_point' => 'VatPeriodTracker::getPeriodSummary()',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Infrastructure Requirements (CRITICAL)
    |--------------------------------------------------------------------------
    |
    | These settings enforce infrastructure-level requirements for ZATCA
    | compliance. Failures here can cause timestamp disputes and audit issues.
    |
    */
    'infrastructure' => [
        /*
        |----------------------------------------------------------------------
        | Clock & Time Sync (CRITICAL)
        |----------------------------------------------------------------------
        | XAdES-T timestamps and ZATCA API calls require accurate time.
        | Clock drift can cause:
        | - XAdES-T signature disputes
        | - Timestamp rejection by ZATCA
        | - Audit defensibility issues
        |
        | REQUIREMENT: All servers MUST use NTP with drift < 1 second.
        |
        */
        'time_sync' => [
            // Maximum allowed clock drift in seconds before warning
            'max_drift_warning_seconds' => env('ZATCA_MAX_CLOCK_DRIFT_WARNING', 1),

            // Maximum allowed clock drift before blocking submissions
            'max_drift_critical_seconds' => env('ZATCA_MAX_CLOCK_DRIFT_CRITICAL', 5),

            // Enforce UTC timezone at application level
            'enforce_utc' => env('ZATCA_ENFORCE_UTC', true),

            // NTP server to check against (for drift detection)
            'ntp_server' => env('ZATCA_NTP_SERVER', 'pool.ntp.org'),

            // Enable clock drift monitoring
            'monitor_drift' => env('ZATCA_MONITOR_CLOCK_DRIFT', true),
        ],

        /*
        |----------------------------------------------------------------------
        | Database Transaction Isolation (CRITICAL)
        |----------------------------------------------------------------------
        | ICV atomicity and hash chain integrity require proper isolation.
        |
        | REQUIREMENT:
        | - PostgreSQL: READ COMMITTED (default) or SERIALIZABLE
        | - MySQL: READ COMMITTED or SERIALIZABLE
        | - NEVER use READ UNCOMMITTED
        |
        | Race conditions in multi-worker environments can corrupt hash chains.
        |
        */
        'database' => [
            // Required minimum isolation level
            'min_isolation_level' => env('ZATCA_DB_ISOLATION', 'READ COMMITTED'),

            // Verify isolation level on boot
            'verify_on_boot' => env('ZATCA_VERIFY_DB_ISOLATION', true),

            // Block operations if isolation is insufficient
            'block_on_invalid_isolation' => env('ZATCA_BLOCK_INVALID_ISOLATION', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | ZATCA submissions should be processed asynchronously with dedicated
    | workers to prevent webhook backlog from blocking clearance requests.
    |
    */
    'queue' => [
        // Queue name for ZATCA submissions (clearance/reporting)
        'submissions_queue' => env('ZATCA_QUEUE_SUBMISSIONS', 'zatca-submissions'),

        // Queue name for webhook deliveries
        'webhooks_queue' => env('ZATCA_QUEUE_WEBHOOKS', 'webhooks'),

        // Recommended minimum workers per queue
        'recommended_workers' => [
            'zatca-submissions' => 2,  // High priority, dedicated
            'webhooks' => 1,           // Separate to prevent blocking
            'default' => 1,            // Other jobs
        ],

        // Maximum job attempts before failure
        'max_attempts' => env('ZATCA_QUEUE_MAX_ATTEMPTS', 3),

        // Backoff delay between retries (seconds)
        'retry_backoff' => env('ZATCA_QUEUE_RETRY_BACKOFF', 60),

        // Job timeout (seconds) - ZATCA API can be slow
        'job_timeout' => env('ZATCA_QUEUE_JOB_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Expiry Notifications
    |--------------------------------------------------------------------------
    |
    | Proactive notifications to organization admins before certificate
    | expiry to prevent submission failures.
    |
    */
    'certificate_notifications' => [
        // Enable expiry notifications
        'enabled' => env('ZATCA_CERT_NOTIFICATIONS_ENABLED', true),

        // Days before expiry to send notifications
        'notify_at_days' => [30, 14, 7, 3, 1],

        // Notification channels (mail, slack, webhook)
        'channels' => explode(',', env('ZATCA_CERT_NOTIFY_CHANNELS', 'mail,webhook')),

        // Block submissions on expiry (enforced by CertificateService)
        'block_on_expiry' => true,

        // Send daily reminders when < 7 days remaining
        'daily_reminders_threshold_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal Evidence Export
    |--------------------------------------------------------------------------
    |
    | Configuration for exporting audit-ready evidence packages.
    | Useful for enterprise customers during tax audits and disputes.
    |
    */
    'evidence_export' => [
        // Enable evidence export API
        'enabled' => env('ZATCA_EVIDENCE_EXPORT_ENABLED', true),

        // Components to include in export
        'include' => [
            'signed_xml' => true,           // Original signed UBL XML
            'hash_chain_proof' => true,     // PIH chain verification
            'submission_logs' => true,      // API request/response logs
            'timestamp_evidence' => true,   // XAdES-T timestamp proof
            'certificate_info' => true,     // Certificate used for signing
            'qr_code_data' => true,         // TLV-decoded QR data
        ],

        // Export format options
        'formats' => ['json', 'zip'],

        // Retention period for export cache (hours)
        'cache_ttl_hours' => env('ZATCA_EVIDENCE_CACHE_TTL', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Disaster Recovery
    |--------------------------------------------------------------------------
    |
    | Configuration and verification requirements for disaster recovery.
    | ZATCA compliance requires 7-year retention of signed invoices.
    |
    */
    'disaster_recovery' => [
        // Minimum backup frequency
        'backup_frequency' => 'daily',

        // Required retention period (years) per ZATCA regulations
        'retention_years' => 7,

        // Recovery Point Objective (maximum acceptable data loss)
        'rpo_hours' => env('ZATCA_RPO_HOURS', 1),

        // Recovery Time Objective (maximum acceptable downtime)
        'rto_hours' => env('ZATCA_RTO_HOURS', 4),

        // Verification checks to run after recovery
        'recovery_checks' => [
            'hash_chain_integrity' => true,  // Verify PIH chain is intact
            'icv_sequence_gaps' => true,     // Detect ICV gaps
            'certificate_validity' => true,  // Verify certs still valid
            'invoice_count_match' => true,   // Compare with pre-disaster count
        ],

        // Enable automated recovery testing (recommended: monthly)
        'test_recovery_enabled' => env('ZATCA_TEST_RECOVERY_ENABLED', false),
        'test_recovery_schedule' => env('ZATCA_TEST_RECOVERY_SCHEDULE', 'monthly'),
    ],

];
