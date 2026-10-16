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
        'sandbox' => env('ZATCA_SANDBOX_URL') ?: 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => env('ZATCA_SIMULATION_URL') ?: 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => env('ZATCA_PRODUCTION_URL') ?: 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ],

    // Where a taxpayer signs in to generate the one-time password that
    // onboarding asks for.
    'portal_url' => env('ZATCA_PORTAL_URL') ?: 'https://fatoora.zatca.gov.sa/',

    // The OpenSSL binary the onboarding commands run. Empty: `openssl` on
    // the PATH.
    'openssl_binary' => env('OPENSSL_BIN'),

    /*
    | There is no platform-wide API credential. ZATCA onboards each EGS unit
    | and issues it a token and secret; CredentialStore::device() finds the
    | ones for the invoice being filed. ZATCA_USERNAME and ZATCA_PASSWORD
    | were read here and sent with every tenant's clearance and reporting.
    */

    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    |
    | CSID (Cryptographic Stamp Identifier) for signing invoices.
    |
    */
    'certificate' => [
        'expiry_warning_days' => env('ZATCA_CERT_WARNING_DAYS', 30),
        'expiry_critical_days' => env('ZATCA_CERT_CRITICAL_DAYS', 7),
        // A PEM bundle of ZATCA's CA certificates, from the issuing CA up to
        // the root. Stamped PDFs carry the path to the anchor, as the
        // Security Features standard asks. Empty: the device certificate only.
        'chain_path' => env('ZATCA_CA_CHAIN_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CSID Credential Storage
    |--------------------------------------------------------------------------
    |
    | Where each taxpayer's signing keys are kept. The default keeps them on
    | the container's own filesystem, which confines the platform to a single
    | replica: a tenant onboarded on one instance cannot sign on another.
    |
    | Point this at a shared disk before scaling out. Whatever it names must be
    | private — these are the keys behind every invoice the platform stamps.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Reporting deadline
    |--------------------------------------------------------------------------
    |
    | ZATCA gives a simplified (B2C) invoice twenty-four hours from issuance to
    | be reported. It is a legal obligation, and until now it was not
    | configurable: the places that read fatoora.reporting.deadline_hours each
    | ran on their own hard-coded fallback of 24. Two read it now - the hourly
    | fatoora:overdue-submissions sweep and the admin console's deadline screen
    | - and both report lateness. Nothing refuses a late document: missing the
    | window is a penalty, not a release from reporting, and Submitter's
    | refusal (with its enforce_deadline switch) only guaranteed a late invoice
    | was never reported at all.
    |
    */
    'reporting' => [
        'deadline_hours' => (float) env('ZATCA_REPORTING_DEADLINE_HOURS', 24),
    ],

    'signing' => [
        'disk' => env('ZATCA_CREDENTIAL_DISK', 'local'),

        /*
        | The key these credentials are encrypted under.
        |
        | Empty means APP_KEY, which works but is broad: APP_KEY also protects
        | sessions and cookies, sits in every container and worker, and is on
        | any machine holding a production .env. A signing key is the private
        | half of a taxpayer's non-repudiation, so it is worth a secret that
        | fewer things hold and that routine APP_KEY rotation does not touch.
        |
        | Set it and run masaar:rotate-credential-key, keeping the previous
        | value in previous_keys until that reports no failures.
        */
        'key' => env('ZATCA_CREDENTIAL_KEY', ''),

        'previous_keys' => array_values(array_filter(
            explode(',', (string) env('ZATCA_CREDENTIAL_PREVIOUS_KEYS', ''))
        )),
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
        // Must agree with security.rate_limits.submission, which throttles the
        // same traffic at the edge. Two keys, one thing.
        'per_minute' => env('ZATCA_RATE_LIMIT_PER_MINUTE', 120),
        'per_day' => env('ZATCA_RATE_LIMIT_PER_DAY', 10000),
        'max_concurrent' => env('ZATCA_MAX_CONCURRENT', 10),
    ],

    /*
    | How long a submission may sit in flight - queued, claimed, or sent and
    | awaiting the answer - before fatoora:recover-submissions treats it as
    | lost. Longer than any real round trip, retries included.
    */
    'recovery' => [
        'stale_minutes' => (int) env('ZATCA_STALE_SUBMISSION_MINUTES', 15),
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
    | Offline Mode
    |--------------------------------------------------------------------------
    |
    | Queue invoices when ZATCA is unavailable (for POS/retail scenarios).
    |
    */
    'offline' => [
        // Maximum invoices that can be queued per organization
        'queue_max_size' => env('ZATCA_OFFLINE_QUEUE_MAX', 10000),

        // Retry interval for processing offline queue (seconds)
        'retry_interval' => env('ZATCA_OFFLINE_RETRY_INTERVAL', 300),

        // Maximum retry attempts before permanent failure
        'max_attempts' => env('ZATCA_OFFLINE_MAX_ATTEMPTS', 3),

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
        ],
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
        // ZATCA's Java SDK, unpacked: the directory holding Apps/ and Data/.
        // SdkValidator runs its validator when this is set and Java is present.
        'sdk_path' => env('ZATCA_SDK_PATH') ?: null,

        // A PDF/A validator's command-line launcher. Tests only: when set,
        // PdfaConformanceTest checks that invoice PDFs are PDF/A-3b.
        'pdfa_validator' => env('PDFA_VALIDATOR_PATH') ?: null,

        // Where the ZATCA UBL 2.1 schema set is installed, relative to the
        // project root or absolute. It ships inside ZATCA's SDK, a licensed
        // download that cannot live in this repository, so with nothing here
        // InvoiceValidator checks that a document is well formed and says so
        // rather than implying it checked more.
        //
        // Setting ZATCA_SDK_PATH is enough: the schema sits at a fixed place
        // inside the SDK, and that variable already has to be set for CSR
        // generation. ZATCA_SCHEMA_PATH overrides it for a schema kept
        // somewhere else. Point that at the main document and keep the tree
        // around it — the file imports the common components beside it.
        'schema_path' => env('ZATCA_SCHEMA_PATH') ?: (
            env('ZATCA_SDK_PATH')
                ? rtrim((string) env('ZATCA_SDK_PATH'), '/\\').'/Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd'
                : null
        ),

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
        'offline_mode' => env('ZATCA_FEATURE_OFFLINE', true),
        'circuit_breaker' => env('ZATCA_FEATURE_CIRCUIT_BREAKER', true),
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

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Redis-backed so the breaker opens for every node at once.
    |
    */
    'circuit_breaker' => [
        'failure_threshold' => env('ZATCA_CCB_FAILURE_THRESHOLD', 5),
        'success_threshold' => env('ZATCA_CCB_SUCCESS_THRESHOLD', 3),
        'timeout_seconds' => env('ZATCA_CCB_TIMEOUT', 60),
        'half_open_max_requests' => env('ZATCA_CCB_HALF_OPEN_REQUESTS', 3),
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
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | ZATCA submissions should be processed asynchronously with dedicated
    | workers to prevent webhook backlog from blocking clearance requests.
    |
    */
    'queue' => [
        // Null means the application's own queue connection. This defaulted to
        // 'redis' while QUEUE_CONNECTION is database, and nothing read it — so
        // an operator who set it and ran a redis worker would have watched an
        // empty queue while the jobs went to the database.
        'connection' => env('ZATCA_QUEUE_CONNECTION'),

        // Read by ProcessFatooraSubmission, which dispatches onto this queue.
        // The job hardcoded 'zatca-submissions' and this key was read nowhere,
        // so renaming the queue here moved nothing.
        'name' => env('ZATCA_QUEUE_NAME', 'zatca-submissions'),

        // Read by ProcessFatooraSubmission for its retry behaviour.
        'tries' => (int) env('ZATCA_QUEUE_TRIES', 3),
        'timeout' => (int) env('ZATCA_QUEUE_TIMEOUT', 120),
        'backoff' => [10, 60, 300], // seconds between retries

        // Read by DispatchInvoiceWebhook and DeliverWebhook. Separate from
        // submissions so a slow customer endpoint cannot delay a clearance.
        'webhooks_queue' => env('ZATCA_QUEUE_WEBHOOKS', 'webhooks'),

        // Recommended minimum workers per queue
        'recommended_workers' => [
            'zatca-submissions' => 2,  // High priority, dedicated
            'webhooks' => 1,           // Separate to prevent blocking
            'default' => 1,            // Other jobs
        ],

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
    /*
     * certificate_notifications moved to the settings table.
     *
     * 'enabled', 'notify_at_days' and 'channels' are now editable from
     * /admin/settings, because the schedule a taxpayer wants warning on is an
     * operational decision and these needed a release to change - channels
     * needed an environment variable change, which needed a redeploy.
     *
     * Two keys are gone rather than moved, because nothing ever read either:
     *
     * 'block_on_expiry' was a toggle that toggled nothing. Submissions on an
     * expired certificate are refused by SubmissionGuard, unconditionally,
     * which is right: the authority would reject them anyway, so an operator
     * who could switch that off would only be choosing to find out later.
     *
     * 'daily_reminders_threshold_days' was never consulted by the reminder.
     */

];
