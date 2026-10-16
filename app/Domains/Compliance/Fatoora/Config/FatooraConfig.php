<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Config;

/**
 * Centralized ZATCA Configuration.
 *
 * All ZATCA-related constants, namespaces, and configurable values
 * are defined here to avoid scattered hardcoding.
 *
 * This addresses:
 * - Regulatory extremes (schema versioning, feature flags)
 * - Configuration centralization
 * - Easy updates when ZATCA changes requirements
 */
final class FatooraConfig
{
    // ============================================================
    // XML NAMESPACES (ZATCA UBL 2.1 Specification)
    // ============================================================
    public const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const EXT_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public const SIG_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    public const SBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    // ============================================================
    // DIGITAL SIGNATURE NAMESPACES
    // ============================================================
    public const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    public const XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';

    public const C14N_NS = 'http://www.w3.org/2006/12/xml-c14n11';

    public const C14N_EXCLUSIVE_NS = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    // ============================================================
    // ALGORITHM URIs
    // ============================================================
    public const ALGO_ECDSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256';

    public const ALGO_RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    public const ALGO_SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public const ALGO_ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public const ALGO_XPATH = 'http://www.w3.org/TR/1999/REC-xpath-19991116';

    // ============================================================
    // INVOICE TYPE CODES (KSA-specific per ZATCA)
    // ============================================================
    public const INVOICE_TYPE_STANDARD = '388';     // Standard (B2B)

    public const INVOICE_TYPE_SIMPLIFIED = '388';   // Simplified (B2C) - same code, different subtype

    public const INVOICE_TYPE_DEBIT_NOTE = '383';

    public const INVOICE_TYPE_CREDIT_NOTE = '381';

    // BT-3 subtype flags are built by InvoiceXmlData::getInvoiceTypeName() from
    // the invoice's own columns. Six constants here named the same values and
    // disagreed with it — third party as 0100001 against 0110000, nominal and
    // summary likewise transposed — while nothing read them. A second, wrong
    // statement of which bit means what is how a document ends up filed as
    // something it is not.

    // ============================================================
    // TAX CATEGORIES (UN/CEFACT 5305)
    // ============================================================
    public const TAX_CATEGORY_STANDARD = 'S';       // Standard rate (15%)

    public const TAX_CATEGORY_ZERO_RATED = 'Z';     // Zero rated

    public const TAX_CATEGORY_EXEMPT = 'E';         // Exempt

    public const TAX_CATEGORY_OUT_OF_SCOPE = 'O';   // Out of scope

    /**
     * Exemptions that make a simplified invoice name its buyer.
     *
     * BR-KSA-25: on a simplified invoice the buyer name (BT-44) is
     * mandatory only when the exemption reason is one of these - private
     * education or private healthcare supplied to a Saudi citizen, where the
     * exemption depends on who the buyer is. Otherwise it is optional, and a
     * walk-in sale carries none: ZATCA's own sample simplified invoice has
     * an empty cac:AccountingCustomerParty.
     *
     * One list, read by both CreateInvoiceRequest and InvoiceValidator, so
     * the form and the pre-submission check cannot disagree about when a
     * name is owed.
     *
     * @var list<string>
     */
    public const NAMED_BUYER_EXEMPTIONS = ['VATEX-SA-EDU', 'VATEX-SA-HEA'];

    /**
     * BT-121, the exemption reason codes ZATCA accepts, by the category each
     * one puts the line in.
     *
     * Read out of the BR-KSA-CL-04 assertion in
     * Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl of
     * ZATCA's own e-invoicing SDK (Java 238, R3.4.8), which does not publish
     * one list of codes. It publishes a list per category and refuses a code
     * used under the wrong one:
     *
     *   'Z'  VATEX-SA-32, -33, -34-1 .. -34-5, -35, -36, EDU, HEA, MLTRY,
     *        DIPLOMAT, DUTYFREE  (and ROYALDECREE, see below)
     *   'E'  VATEX-SA-29, -29-7, -30
     *   'O'  VATEX-SA-OOS
     *
     * A code => category map rather than a flat list plus a separate mapper,
     * because the partition is what the authority actually publishes, and
     * keeping the two apart is what let them disagree.
     *
     * Not here, deliberately:
     *
     * VATEX-SA-ROYALDECREE is in the 'Z' list of the invoice-level assertion
     * and absent from the otherwise identical line-level one, within the same
     * rule file. DocumentBuilder writes the code on the line, so including it
     * would draw the very warning this map exists to avoid. That same list
     * also carries a literal 'VATEX-SA-32(bis)', which is a typo in the rule
     * rather than a code.
     *
     * VATEX-SA-31, which an earlier copy of this list accepted, appears in no
     * assertion at all - the authority does not recognise it.
     *
     * The suffixed variants - VATEX-SA-29F, -30R, -32E, -33E, -34-1T, -34-4S,
     * -34-5A, -35M, -36Q, -29-7L, EDUP, HEAP - are in a separate list in the
     * rule file, for exceptions rather than exemptions, with their own
     * category rules. They stay out until something needs them.
     *
     * @var array<string, string>
     */
    public const CODE_CATEGORIES = [
        // Zero-rated ('Z'). Labelled from the code list in §11.2.4 of the XML
        // Implementation Standard v1.2; the labels here used to be shifted
        // one or two rows, so 30 read "international transport" and 35 "real
        // estate".
        'VATEX-SA-32' => self::TAX_CATEGORY_ZERO_RATED,       // Export of goods
        'VATEX-SA-33' => self::TAX_CATEGORY_ZERO_RATED,       // Export of services
        'VATEX-SA-34-1' => self::TAX_CATEGORY_ZERO_RATED,     // International transport of goods
        'VATEX-SA-34-2' => self::TAX_CATEGORY_ZERO_RATED,     // International transport of passengers
        'VATEX-SA-34-3' => self::TAX_CATEGORY_ZERO_RATED,     // Services incidental to international passenger transport
        'VATEX-SA-34-4' => self::TAX_CATEGORY_ZERO_RATED,     // Supply of a qualifying means of transport
        'VATEX-SA-34-5' => self::TAX_CATEGORY_ZERO_RATED,     // Services relating to goods or passenger transportation (Art. 25)
        'VATEX-SA-35' => self::TAX_CATEGORY_ZERO_RATED,       // Medicines and medical equipment
        'VATEX-SA-36' => self::TAX_CATEGORY_ZERO_RATED,       // Qualifying metals
        'VATEX-SA-EDU' => self::TAX_CATEGORY_ZERO_RATED,      // Private education to citizen
        'VATEX-SA-HEA' => self::TAX_CATEGORY_ZERO_RATED,      // Private healthcare to citizen
        'VATEX-SA-MLTRY' => self::TAX_CATEGORY_ZERO_RATED,    // Supply of qualified military goods
        // These two are in the Schematron's 'Z' list and not in the
        // Standard's code list, which gives them no meaning or text.
        'VATEX-SA-DIPLOMAT' => self::TAX_CATEGORY_ZERO_RATED, // Diplomatic supplies, by its name
        'VATEX-SA-DUTYFREE' => self::TAX_CATEGORY_ZERO_RATED, // Duty-free supplies, by its name

        // Exempt ('E')
        'VATEX-SA-29' => self::TAX_CATEGORY_EXEMPT,           // Financial services (Art. 29)
        'VATEX-SA-29-7' => self::TAX_CATEGORY_EXEMPT,         // Life insurance services (Art. 29)
        'VATEX-SA-30' => self::TAX_CATEGORY_EXEMPT,           // Real estate transactions (Art. 30)

        // Out of scope ('O')
        'VATEX-SA-OOS' => self::TAX_CATEGORY_OUT_OF_SCOPE,
    ];

    /**
     * BT-120 in the authority's Arabic, by exemption code, for the printed
     * copy.
     *
     * Copied from the "Arabic Text" column of the code list in §11.2.4 of
     * ZATCA's Electronic Invoice XML Implementation Standard v1.2 (pages
     * 32-33). The E-Invoice Data Dictionary v1.2 names BT-120 and BT-121 but
     * carries no code list. One correction: the Standard's 34-5 text reads
     * "ضريبة القيامة المضافة", a typo for القيمة, which a buyer would read
     * as the seller's mistake.
     *
     * Not here: VATEX-SA-OOS, whose Arabic is an instruction to the taxpayer
     * to write their own reason rather than a reason; and DIPLOMAT and
     * DUTYFREE, which the Standard does not list. Those print the stored
     * reason alone.
     *
     * @var array<string, string>
     */
    public const EXEMPTION_REASONS_AR = [
        'VATEX-SA-29' => 'الخدمات المالية',
        'VATEX-SA-29-7' => 'عقد تأمين على الحياة',
        'VATEX-SA-30' => 'التوريدات العقارية المعفاة من الضريبة',
        'VATEX-SA-32' => 'صادرات السلع من المملكة',
        'VATEX-SA-33' => 'صادرات الخدمات من المملكة',
        'VATEX-SA-34-1' => 'النقل الدولي للسلع',
        'VATEX-SA-34-2' => 'النقل الدولي للركاب',
        'VATEX-SA-34-3' => 'الخدمات المرتبطة مباشرة أو عرضياً بتوريد النقل الدولي للركاب',
        'VATEX-SA-34-4' => 'توريد وسائل النقل المؤهلة',
        'VATEX-SA-34-5' => 'الخدمات ذات الصلة بنقل السلع أو الركاب، وفقاً للتعريف الوارد بالمادة الخامسة والعشرين من اللائحة التنفيذية لنظام ضريبة القيمة المضافة',
        'VATEX-SA-35' => 'الأدوية والمعدات الطبية',
        'VATEX-SA-36' => 'المعادن المؤهلة',
        'VATEX-SA-EDU' => 'الخدمات التعليمية الخاصة للمواطنين',
        'VATEX-SA-HEA' => 'الخدمات الصحية الخاصة للمواطنين',
        'VATEX-SA-MLTRY' => 'توريد السلع العسكرية المؤهلة',
    ];

    /**
     * BT-46-1, the buyer identifier schemes, as the printed copy names them.
     *
     * English after the scheme list in BR-KSA-14 of the XML Implementation
     * Standard; the Standard gives no Arabic, so these are the registers'
     * own Arabic names.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const BUYER_ID_SCHEME_LABELS = [
        'TIN' => ['Tax identification number', 'الرقم المميز'],
        'CRN' => ['Commercial registration', 'السجل التجاري'],
        'MOM' => ['MOMRAH licence', 'ترخيص وزارة الشؤون البلدية والقروية والإسكان'],
        'MLS' => ['MHRSD licence', 'ترخيص وزارة الموارد البشرية والتنمية الاجتماعية'],
        '700' => ['700 number', 'الرقم الموحد (700)'],
        'SAG' => ['MISA licence', 'ترخيص وزارة الاستثمار'],
        'NAT' => ['National ID', 'الهوية الوطنية'],
        'GCC' => ['GCC ID', 'هوية مواطني دول الخليج'],
        'IQA' => ['Iqama number', 'رقم الإقامة'],
        'PAS' => ['Passport number', 'رقم جواز السفر'],
        'OTH' => ['Other ID', 'رقم تعريف آخر'],
    ];

    /**
     * Share a document-level allowance out across tax categories.
     *
     * A discount on the whole invoice reduces what is taxable, and the
     * reduction has to reach each category in proportion to what it
     * contributed — otherwise one category absorbs the lot and both its base
     * and its neighbour's are wrong. Charging VAT on the amount before the
     * discount overstates the tax the taxpayer owes.
     *
     * The last category takes the rounding, so the shares always sum back to
     * the allowance exactly and TaxExclusiveAmount lands on
     * LineExtensionAmount minus it.
     *
     * @param  array<string, float>  $netsByKey  Undiscounted net per category
     * @return array<string, float> The allowance apportioned to each
     */
    public static function apportionAllowance(array $netsByKey, float $allowance): array
    {
        $total = array_sum($netsByKey);

        if ($allowance <= 0.0 || $total <= 0.0) {
            return array_map(static fn () => 0.0, $netsByKey);
        }

        $shares = [];
        $assigned = 0.0;
        $keys = array_keys($netsByKey);
        $last = end($keys);

        foreach ($netsByKey as $key => $net) {
            if ($key === $last) {
                $shares[$key] = round($allowance - $assigned, 2);

                continue;
            }

            $share = round($allowance * $net / $total, 2);
            $shares[$key] = $share;
            $assigned += $share;
        }

        return $shares;
    }

    /**
     * The tax category a line belongs to, given its exemption code and rate.
     *
     * ZATCA reads the category and the exemption reason together: VATEX-SA-HEA
     * on a line taxed at 15% is a contradiction and the document is refused
     * rather than reconciled. So the code decides, and the rate only answers
     * when there is no code.
     *
     * A lookup in CODE_CATEGORIES, which is ZATCA's own partition. This used
     * to test three prefixes - OOS, HEA, EDU - and send every other
     * VATEX-SA- code to 'E'. BR-KSA-CL-04 puts ten of the codes this accepts
     * in 'Z': VATEX-SA-32, -33, -34-1 through -34-5, -35, -36 and MLTRY were
     * all filed as exempt where the authority requires zero-rated. That is
     * the wrong figure in the taxpayer's return as well as a rule violation.
     * The three the rule does place in 'E' - 29, 29-7 and 30 - were right by
     * accident, because everything landed there.
     *
     * An unrecognised VATEX-SA- code still answers 'E', as before. Validation
     * decides whether a code is acceptable; inventing a category for one this
     * map does not know would be a second opinion with nothing behind it.
     */
    public static function taxCategoryFor(?string $exemptionCode, float $taxRate): string
    {
        if ($exemptionCode === null || $exemptionCode === '') {
            return $taxRate > 0 ? self::TAX_CATEGORY_STANDARD : self::TAX_CATEGORY_ZERO_RATED;
        }

        if (isset(self::CODE_CATEGORIES[$exemptionCode])) {
            return self::CODE_CATEGORIES[$exemptionCode];
        }

        if (str_starts_with($exemptionCode, 'VATEX-SA-')) {
            return self::TAX_CATEGORY_EXEMPT;
        }

        return $taxRate > 0 ? self::TAX_CATEGORY_STANDARD : self::TAX_CATEGORY_ZERO_RATED;
    }

    /**
     * The codes BT-121 accepts, for validation.
     *
     * @return list<string>
     */
    public static function exemptionCodes(): array
    {
        return array_keys(self::CODE_CATEGORIES);
    }

    // ============================================================
    // PAYMENT MEANS CODES (UN/EDIFACT 4461)
    // ============================================================
    public const PAYMENT_CASH = '10';

    public const PAYMENT_CHEQUE = '20';

    public const PAYMENT_CREDIT_TRANSFER = '30';

    public const PAYMENT_BANK_CARD = '48';

    public const PAYMENT_DIRECT_DEBIT = '49';

    // ============================================================
    // RATE LIMITS (Configurable)
    // ============================================================
    public const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;

    public const DEFAULT_RATE_LIMIT_PER_DAY = 10000;

    public const DEFAULT_MAX_CONCURRENT_SUBMISSIONS = 10;

    // ============================================================
    // IDEMPOTENCY (Configurable)
    // ============================================================
    public const DEFAULT_IDEMPOTENCY_WINDOW_HOURS = 24;

    // ============================================================
    // THRESHOLDS (Configurable)
    // ============================================================
    public const LARGE_INVOICE_THRESHOLD = 1000000.00; // SAR

    public const CERTIFICATE_EXPIRY_WARNING_DAYS = 30;

    public const CERTIFICATE_EXPIRY_CRITICAL_DAYS = 7;

    // ============================================================
    // CRYPTOGRAPHY
    //
    // ZATCA mandates these, so they are constants rather than configuration.
    // A curve settable from the environment while key generation is pinned in
    // code lets a deployment sign on a curve its keys were not generated for,
    // and the authority is where that surfaces.
    // ============================================================
    public const EC_CURVE = 'secp256k1';

    /**
     * Field size of EC_CURVE in bytes.
     *
     * QR tag 8 pads both coordinates to this, so it follows the curve rather
     * than being separately settable. It was a config value, which allowed it
     * to disagree with the curve it describes.
     */
    public const EC_COORDINATE_BYTES = 32;

    public const HASH_ALGORITHM = OPENSSL_ALGO_SHA256;

    // ============================================================
    // CLASSIFICATION SCHEMES
    // ============================================================
    public const CLASSIFICATION_UNSPSC = 'UNSPSC';

    public const CLASSIFICATION_HS = 'HS';          // Harmonized System

    // ============================================================
    // UBL EXTENSION IDENTIFIERS
    // ============================================================
    public const EXT_ID_ICV = 'ICV';

    public const EXT_ID_PIH = 'PIH';

    public const EXT_ID_QR = 'QR';

    // ============================================================
    // PIH (Previous Invoice Hash)
    // ============================================================
    /**
     * The PIH a document carries when it is the first in its chain.
     *
     * ZATCA's value is the SHA-256 of the string "0" written as hex and then
     * base64'd, which is the figure its SDK ships in Data/PIH/pih.txt and
     * validates KSA-13 against. It is not the base64 of the digest's own
     * bytes, and it is not thirty-two zero bytes: a document carrying either
     * is refused with "PIH is inValid".
     */
    public const GENESIS_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    // ============================================================
    // COUNTRY CODES
    // ============================================================
    public const COUNTRY_SA = 'SA';

    public const CURRENCY_SAR = 'SAR';

    // ============================================================
    // ALLOWANCE/CHARGE REASON CODES
    // ============================================================
    public const REASON_DISCOUNT = '95';

    public const REASON_SPECIAL_AGREEMENT = '100';

    public const REASON_PRODUCTION_ERROR = '104';

    // Circuit breaker settings live in config/fatoora.php and are read by
    // CircuitBreaker itself. Two getters here asked for circuit_breaker.threshold
    // and .timeout, which that file does not define — it has failure_threshold
    // and timeout_seconds — so both silently returned the constants below
    // instead, and ZATCA_CCB_FAILURE_THRESHOLD moved nothing through this path.

    // ============================================================
    // OFFLINE MODE SETTINGS
    // ============================================================
    public const OFFLINE_QUEUE_MAX_SIZE = 10000;

    public const OFFLINE_RETRY_INTERVAL = 300;      // 5 minutes

    // ============================================================
    // KILL SWITCH FLAGS
    // ============================================================
    public const KILL_SWITCH_CACHE_KEY = 'zatca:kill_switches';

    /**
     * Get configured value with fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return config("fatoora.{$key}", $default);
    }

    /**
     * Get rate limit per minute.
     */
    public static function getRateLimitPerMinute(): int
    {
        return (int) self::get('rate_limits.per_minute', self::DEFAULT_RATE_LIMIT_PER_MINUTE);
    }

    /**
     * Get rate limit per day.
     */
    public static function getRateLimitPerDay(): int
    {
        return (int) self::get('rate_limits.per_day', self::DEFAULT_RATE_LIMIT_PER_DAY);
    }

    /**
     * Get max concurrent submissions.
     */
    public static function getMaxConcurrentSubmissions(): int
    {
        return (int) self::get('rate_limits.max_concurrent', self::DEFAULT_MAX_CONCURRENT_SUBMISSIONS);
    }

    /**
     * Get idempotency window in hours.
     */
    public static function getIdempotencyWindowHours(): int
    {
        return (int) self::get('idempotency.window_hours', self::DEFAULT_IDEMPOTENCY_WINDOW_HOURS);
    }

    /**
     * Get large invoice threshold.
     */
    public static function getLargeInvoiceThreshold(): float
    {
        return (float) self::get('thresholds.large_invoice_amount', self::LARGE_INVOICE_THRESHOLD);
    }

    /**
     * Get certificate expiry warning days.
     */
    public static function getCertificateExpiryWarningDays(): int
    {
        return (int) self::get('certificate.expiry_warning_days', self::CERTIFICATE_EXPIRY_WARNING_DAYS);
    }

    /**
     * Get certificate expiry critical days.
     */
    public static function getCertificateExpiryCriticalDays(): int
    {
        return (int) self::get('certificate.expiry_critical_days', self::CERTIFICATE_EXPIRY_CRITICAL_DAYS);
    }

    /**
     * Get offline queue max size.
     */
    public static function getOfflineQueueMaxSize(): int
    {
        return (int) self::get('offline.queue_max_size', self::OFFLINE_QUEUE_MAX_SIZE);
    }

    /**
     * Get offline retry interval seconds.
     */
    public static function getOfflineRetryInterval(): int
    {
        return (int) self::get('offline.retry_interval', self::OFFLINE_RETRY_INTERVAL);
    }

    /**
     * Get ZATCA base URL for current environment.
     *
     * From config('fatoora.endpoints'), which FatooraClient submits to, so a
     * connectivity check probes the host documents are actually sent to.
     */
    public static function getBaseUrl(): string
    {
        $env = (string) self::get('environment', 'sandbox');
        $url = self::get("endpoints.{$env}");

        // Never the sandbox in place of a name it does not know: that filed
        // every invoice with the developer portal. The boot guard refuses
        // such a name first; this is for anything that runs without booting.
        if (! is_string($url) || $url === '') {
            throw new \RuntimeException("ZATCA_ENVIRONMENT '{$env}' has no endpoint in config/fatoora.php.");
        }

        return $url;
    }

    /**
     * Check if a feature flag is enabled.
     */
    public static function isFeatureEnabled(string $feature): bool
    {
        return (bool) self::get("features.{$feature}", false);
    }

    /**
     * Get invoice type code for document type.
     */
    public static function getInvoiceTypeCode(string $documentType): string
    {
        return match ($documentType) {
            'credit_note' => self::INVOICE_TYPE_CREDIT_NOTE,
            'debit_note' => self::INVOICE_TYPE_DEBIT_NOTE,
            default => self::INVOICE_TYPE_STANDARD,
        };
    }

    /**
     * Get default payment means code.
     */
    public static function getDefaultPaymentMeansCode(): string
    {
        return self::get('default_payment_means_code', self::PAYMENT_CASH);
    }
}
