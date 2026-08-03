# TaxFly Advanced Integration Scenarios

## Complete Guide for Multi-Business, Multi-Branch, and Cross-Border ZATCA Compliance

This document supplements the main [TAXFLY-INTEGRATION-GUIDE.md](./TAXFLY-INTEGRATION-GUIDE.md) with advanced scenarios.

---

## Table of Contents

1. [User Data Requirements for ZATCA Compliance](#1-user-data-requirements-for-zatca-compliance)
2. [Multi-Business Scenarios](#2-multi-business-scenarios)
3. [Branch/Location Management](#3-branchlocation-management)
4. [Different Business Types](#4-different-business-types)
5. [Cross-Border Transactions](#5-cross-border-transactions)
6. [Required TaxFly Changes](#6-required-taxfly-changes)
7. [Data Collection Forms](#7-data-collection-forms)
8. [Onboarding Workflow](#8-onboarding-workflow)

---

## 1. User Data Requirements for ZATCA Compliance

### 1.1 Mandatory Organization Data

For a TaxFly user to become ZATCA-compliant, collect the following:

#### Basic Information (Required)

| Field | Description | Format | Example |
|-------|-------------|--------|---------|
| `legal_name` | Official business name (English) | String, max 255 | "Al Faisal Trading Co." |
| `legal_name_ar` | Official business name (Arabic) | String, max 255 | "شركة الفيصل للتجارة" |
| `vat_number` | Saudi VAT Registration Number | 15 digits, starts & ends with 3 | `300012345600003` |
| `cr_number` | Commercial Registration Number | 10 digits | `1010012345` |
| `business_category` | MCC/Industry Code | String | `5411` (Grocery Stores) |

#### Address Information (All Fields Mandatory for ZATCA)

| Field | Description | Format |
|-------|-------------|--------|
| `street` | Street name | String, max 255 |
| `building_number` | Building/structure number | 4 digits |
| `additional_number` | Additional/secondary number | 4 digits |
| `district` | District/neighborhood name | String, max 100 |
| `city` | City name | String, max 100 |
| `postal_code` | Saudi postal code | 5 digits |
| `country_code` | ISO country code | 2 chars (`SA`) |

#### Contact Information

| Field | Description | Required |
|-------|-------------|----------|
| `email` | Business email | Yes |
| `phone` | Business phone | Yes |
| `contact_person` | Primary contact name | Yes |

### 1.2 ZATCA-Specific Data

| Field | Description | Source |
|-------|-------------|--------|
| `otp` | One-Time Password | ZATCA Fatoora Portal |
| `device_serial` | EGS Device Serial | Auto-generated or provided |
| `solution_name` | E-invoicing solution name | "Masaar" |

### 1.3 Optional Enhancement Data

| Field | Description | Use Case |
|-------|-------------|----------|
| `logo_url` | Company logo | Invoice branding |
| `website` | Company website | Invoice display |
| `payment_terms` | Default payment terms | Auto-fill invoices |
| `bank_details` | Bank account info | Payment instructions |

---

## 2. Multi-Business Scenarios

### 2.1 Architecture Overview

```
TaxFly User (Account)
    ├── Business 1 (Organization)
    │   ├── Branch A (EGS Device)
    │   └── Branch B (EGS Device)
    ├── Business 2 (Organization)
    │   └── Main Office (EGS Device)
    └── Business 3 (Organization)
        ├── Store 1 (EGS Device)
        ├── Store 2 (EGS Device)
        └── Store 3 (EGS Device)
```

### 2.2 Masaar Multi-Organization Support

Each business needs **separate ZATCA onboarding**:

```php
// TaxFly: User with multiple businesses
class User {
    public function businesses() {
        return $this->hasMany(Business::class);
    }
}

class Business {
    // Each business has its own Masaar organization
    protected $fillable = [
        'user_id',
        'name',
        'vat_number',
        'cr_number',
        'masaar_org_id',        // Unique per business
        'zatca_onboarding_status', // Independent status
    ];

    public function branches() {
        return $this->hasMany(Branch::class);
    }
}
```

### 2.3 Registration Flow for Multiple Businesses

```php
namespace App\Services\Masaar;

class MultiBusinessService
{
    private OrganizationService $orgService;

    /**
     * Register multiple businesses for a TaxFly user
     */
    public function registerUserBusinesses(User $user): array
    {
        $results = [];

        foreach ($user->businesses as $business) {
            // Skip if already registered
            if ($business->masaar_org_id) {
                $results[$business->id] = [
                    'status' => 'already_registered',
                    'org_id' => $business->masaar_org_id,
                ];
                continue;
            }

            // Register each business as separate organization
            $org = $this->orgService->register([
                'name' => $business->name,
                'name_ar' => $business->name_ar,
                'vat_number' => $business->vat_number,
                'cr_number' => $business->cr_number,
                'address' => $this->mapAddress($business),
            ]);

            $business->update([
                'masaar_org_id' => $org['id'],
                'zatca_onboarding_status' => 'pending',
            ]);

            $results[$business->id] = [
                'status' => 'registered',
                'org_id' => $org['id'],
            ];
        }

        return $results;
    }

    /**
     * Get onboarding status for all user businesses
     */
    public function getOnboardingStatus(User $user): array
    {
        return $user->businesses->map(function ($business) {
            return [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'vat_number' => $business->vat_number,
                'masaar_org_id' => $business->masaar_org_id,
                'onboarding_status' => $business->zatca_onboarding_status,
                'can_submit_invoices' => $business->zatca_onboarding_status === 'completed',
            ];
        })->toArray();
    }
}
```

### 2.4 Invoice Routing by Business

```php
class InvoiceService
{
    /**
     * Submit invoice to correct business organization
     */
    public function submit(Invoice $invoice): array
    {
        // Get the business for this invoice
        $business = $invoice->business;

        if (!$business->masaar_org_id) {
            throw new Exception("Business {$business->name} not registered with Masaar");
        }

        if ($business->zatca_onboarding_status !== 'completed') {
            throw new Exception("Business {$business->name} ZATCA onboarding incomplete");
        }

        // Submit to Masaar with organization context
        return $this->client->post('/api/invoices', [
            'organization_id' => $business->masaar_org_id,
            'invoice_data' => $this->mapInvoiceData($invoice),
        ]);
    }
}
```

---

## 3. Branch/Location Management

### 3.1 ZATCA Branch Requirements

**ZATCA requires each physical location (EGS - Electronic Generation Solution) to have:**
- Unique device serial number
- Separate invoice counter (ICV)
- Independent hash chain (PIH)

### 3.2 Branch Data Model

```php
// Migration
Schema::create('branches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('name_ar')->nullable();

    // Location details
    $table->string('street');
    $table->string('building_number', 4);
    $table->string('additional_number', 4)->nullable();
    $table->string('district');
    $table->string('city');
    $table->string('postal_code', 5);

    // ZATCA EGS identification
    $table->string('device_serial')->unique();
    $table->string('masaar_egs_id')->nullable();
    $table->boolean('is_active')->default(true);

    // Invoice counters (managed by Masaar, but track locally)
    $table->unsignedBigInteger('last_icv')->default(0);

    $table->timestamps();
});

// Add branch to invoices
Schema::table('invoices', function (Blueprint $table) {
    $table->foreignId('branch_id')->nullable()->constrained();
});
```

### 3.3 Branch Registration with Masaar

```php
class BranchService
{
    private MasaarClient $client;

    /**
     * Register branch as EGS device
     */
    public function registerBranch(Branch $branch): array
    {
        $business = $branch->business;

        if (!$business->masaar_org_id) {
            throw new Exception("Business must be registered first");
        }

        $response = $this->client->post(
            "/api/organizations/{$business->masaar_org_id}/egs-units",
            [
                'device_serial' => $branch->device_serial,
                'branch_name' => $branch->name,
                'branch_industry' => $business->business_category,
                'address' => [
                    'street' => $branch->street,
                    'building_number' => $branch->building_number,
                    'additional_number' => $branch->additional_number,
                    'district' => $branch->district,
                    'city' => $branch->city,
                    'postal_code' => $branch->postal_code,
                    'country_code' => 'SA',
                ],
            ]
        );

        $branch->update([
            'masaar_egs_id' => $response['egs_id'],
        ]);

        return $response;
    }

    /**
     * Generate unique device serial for new branch
     */
    public function generateDeviceSerial(Business $business): string
    {
        $branchCount = $business->branches()->count() + 1;

        // Format: 1-{VAT}|2-{BRANCH_SEQ}|3-{SOLUTION}
        return sprintf(
            "1-%s|2-%03d|3-TAXFLY",
            $business->vat_number,
            $branchCount
        );
    }
}
```

### 3.4 Invoice with Branch Context

```php
class InvoiceService
{
    /**
     * Create invoice for specific branch
     */
    public function createForBranch(array $data, Branch $branch): Invoice
    {
        $invoice = Invoice::create(array_merge($data, [
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
        ]));

        // Submit with branch context
        return $this->submit($invoice, $branch);
    }

    /**
     * Submit invoice with branch EGS unit
     */
    private function submit(Invoice $invoice, Branch $branch): array
    {
        return $this->client->post('/api/invoices', [
            'organization_id' => $branch->business->masaar_org_id,
            'egs_unit_id' => $branch->masaar_egs_id,
            'invoice_data' => $this->mapInvoiceData($invoice),
        ]);
    }
}
```

---

## 4. Different Business Types

### 4.1 Business Type Configurations

ZATCA treats different business types differently:

| Business Type | Invoice Type | VAT Treatment | Special Rules |
|---------------|--------------|---------------|---------------|
| **Retail (B2C)** | Simplified | Standard 15% | Report within 24 hours |
| **Wholesale (B2B)** | Standard | Standard 15% | Clearance required |
| **Healthcare** | Mixed | Zero-rated (VATEX-SA-HEA) | Exemption codes required |
| **Education** | Mixed | Zero-rated (VATEX-SA-EDU) | Exemption codes required |
| **Export** | Standard | Zero-rated (VATEX-SA-OOS-2) | Export documentation |
| **Real Estate** | Standard | Exempt (VATEX-SA-33) | Property details required |
| **Financial Services** | Standard | Exempt (VATEX-SA-34-1) | Service classification |

### 4.2 Business Type Configuration Model

```php
// TaxFly business model extension
class Business extends Model
{
    protected $fillable = [
        'name',
        'vat_number',
        'cr_number',
        'business_type',           // retail, wholesale, healthcare, etc.
        'default_invoice_type',    // simplified, standard
        'default_tax_category',    // S, Z, E, O
        'exemption_codes',         // JSON array of applicable codes
    ];

    protected $casts = [
        'exemption_codes' => 'array',
    ];

    /**
     * Get default tax treatment for this business
     */
    public function getDefaultTaxTreatment(): array
    {
        return match ($this->business_type) {
            'retail' => [
                'invoice_type' => 'simplified',
                'tax_category' => 'S',
                'tax_rate' => 15,
            ],
            'wholesale' => [
                'invoice_type' => 'standard',
                'tax_category' => 'S',
                'tax_rate' => 15,
            ],
            'healthcare' => [
                'invoice_type' => 'standard',
                'tax_category' => 'Z',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-HEA',
                'exemption_reason' => 'Healthcare services',
            ],
            'education' => [
                'invoice_type' => 'standard',
                'tax_category' => 'Z',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-EDU',
                'exemption_reason' => 'Qualifying education services',
            ],
            'export' => [
                'invoice_type' => 'standard',
                'tax_category' => 'O',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-OOS-2',
                'exemption_reason' => 'Export of goods',
            ],
            'real_estate' => [
                'invoice_type' => 'standard',
                'tax_category' => 'E',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-33',
                'exemption_reason' => 'Real estate transaction',
            ],
            default => [
                'invoice_type' => 'simplified',
                'tax_category' => 'S',
                'tax_rate' => 15,
            ],
        };
    }
}
```

### 4.3 Mixed Business Types (Multiple Activities)

```php
class MixedBusinessService
{
    /**
     * Determine tax treatment based on line item
     */
    public function determineTaxTreatment(
        Business $business,
        array $lineItem,
        ?Customer $customer = null
    ): array {
        // Check if item has specific category
        if (isset($lineItem['item_category'])) {
            return $this->getTreatmentByCategory($lineItem['item_category']);
        }

        // Check if customer is B2B or B2C
        if ($customer && $customer->vat_number) {
            return [
                'invoice_type' => 'standard',
                'tax_category' => $lineItem['tax_category'] ?? 'S',
                'tax_rate' => $lineItem['tax_rate'] ?? 15,
            ];
        }

        // Default to business settings
        return $business->getDefaultTaxTreatment();
    }

    /**
     * Get treatment by item category
     */
    private function getTreatmentByCategory(string $category): array
    {
        return match ($category) {
            'medicine' => [
                'tax_category' => 'Z',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-30',
                'exemption_reason' => 'Qualifying medicines',
            ],
            'medical_equipment' => [
                'tax_category' => 'Z',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-31',
                'exemption_reason' => 'Qualifying medical equipment',
            ],
            'gold_silver' => [
                'tax_category' => 'E',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-36',
                'exemption_reason' => 'Qualifying metals (gold, silver, platinum)',
            ],
            default => [
                'tax_category' => 'S',
                'tax_rate' => 15,
            ],
        };
    }
}
```

---

## 5. Cross-Border Transactions

### 5.1 Cross-Border Scenarios

| Scenario | Seller Location | Buyer Location | Tax Treatment |
|----------|----------------|----------------|---------------|
| **Domestic B2B** | Saudi Arabia | Saudi Arabia | 15% VAT, clearance |
| **Domestic B2C** | Saudi Arabia | Saudi Arabia | 15% VAT, reporting |
| **Export to GCC** | Saudi Arabia | GCC Country | 0% (VATEX-SA-OOS) |
| **Export outside GCC** | Saudi Arabia | Non-GCC | 0% (VATEX-SA-OOS-2) |
| **Reverse Charge** | Outside SA | Saudi Arabia | Buyer accounts for VAT |
| **International Services** | Saudi Arabia | Outside SA | 0% (VATEX-SA-OOS-1) |

### 5.2 Cross-Border Invoice Configuration

```php
class CrossBorderInvoiceService
{
    /**
     * Valid country codes for cross-border transactions
     */
    private const GCC_COUNTRIES = ['SA', 'AE', 'BH', 'KW', 'OM', 'QA'];

    /**
     * Determine tax treatment for cross-border transaction
     */
    public function determineCrossBorderTreatment(
        string $sellerCountry,
        string $buyerCountry,
        string $transactionType // 'goods' or 'services'
    ): array {
        // Domestic transaction
        if ($sellerCountry === 'SA' && $buyerCountry === 'SA') {
            return [
                'is_export' => false,
                'tax_category' => 'S',
                'tax_rate' => 15,
                'exemption_code' => null,
            ];
        }

        // Export from Saudi Arabia
        if ($sellerCountry === 'SA' && $buyerCountry !== 'SA') {
            if ($transactionType === 'goods') {
                return [
                    'is_export' => true,
                    'tax_category' => 'O',
                    'tax_rate' => 0,
                    'exemption_code' => 'VATEX-SA-OOS-2',
                    'exemption_reason' => 'Export of goods',
                ];
            }

            return [
                'is_export' => true,
                'tax_category' => 'O',
                'tax_rate' => 0,
                'exemption_code' => 'VATEX-SA-OOS-1',
                'exemption_reason' => 'Services to non-GCC customers',
            ];
        }

        // Services from outside SA (reverse charge scenario)
        if ($sellerCountry !== 'SA' && $buyerCountry === 'SA') {
            return [
                'is_import' => true,
                'reverse_charge' => true,
                'tax_category' => 'S',
                'tax_rate' => 15, // Buyer accounts for VAT
                'note' => 'Reverse charge applies - buyer to account for VAT',
            ];
        }

        return [
            'tax_category' => 'O',
            'tax_rate' => 0,
            'exemption_code' => 'VATEX-SA-OOS',
            'exemption_reason' => 'Out of scope transaction',
        ];
    }

    /**
     * Create export invoice
     */
    public function createExportInvoice(Invoice $invoice): array
    {
        $buyer = $invoice->customer;
        $treatment = $this->determineCrossBorderTreatment(
            'SA',
            $buyer->country_code ?? 'SA',
            $this->getTransactionType($invoice)
        );

        return [
            'type' => 'standard', // Exports always standard
            'is_export' => true,
            'tax_category' => $treatment['tax_category'],
            'tax_rate' => $treatment['tax_rate'],
            'exemption_code' => $treatment['exemption_code'],
            'exemption_reason' => $treatment['exemption_reason'],

            // Export-specific fields
            'export_documentation' => [
                'customs_declaration' => $invoice->customs_declaration_number,
                'shipping_date' => $invoice->shipping_date,
                'destination_country' => $buyer->country_code,
                'incoterms' => $invoice->incoterms ?? 'FOB',
            ],
        ];
    }

    /**
     * Validate export documentation
     */
    public function validateExportDocumentation(Invoice $invoice): array
    {
        $errors = [];

        if (!$invoice->customer->country_code) {
            $errors[] = 'Buyer country code is required for export invoices';
        }

        if ($invoice->customer->country_code === 'SA') {
            $errors[] = 'Export invoices cannot have Saudi Arabia as destination';
        }

        // Customs declaration recommended for goods
        if ($this->isGoodsInvoice($invoice) && !$invoice->customs_declaration_number) {
            // Warning, not error
            $warnings[] = 'Customs declaration number recommended for export of goods';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings ?? [],
        ];
    }
}
```

### 5.3 Multi-Currency Support

**ZATCA Official Requirements (per E-Invoicing Guidelines):**

Per ZATCA specification, foreign currency invoices ARE supported with these requirements:

1. **DocumentCurrencyCode (BT-5)**: Can be foreign currency (USD, EUR, GBP, etc.)
2. **TaxCurrencyCode (BT-6)**: MUST be SAR (Saudi Riyal) for VAT reporting
3. **Two TaxTotal elements required:**
   - First TaxTotal: Document currency amount with subtotals (BT-110)
   - Second TaxTotal: SAR amount only for VAT accounting (BT-111)

**Masaar Implementation:**

When submitting a foreign currency invoice:

```php
// Invoice in USD
$invoiceData = [
    'currency' => 'USD',
    'exchange_rate' => 3.75,        // USD to SAR rate
    'exchange_rate_date' => '2024-01-15',
    'subtotal' => 1000.00,          // In USD
    'tax_amount' => 150.00,         // In USD
    'total' => 1150.00,             // In USD
    // ... other fields
];

// Masaar will:
// 1. Set DocumentCurrencyCode = USD
// 2. Set TaxCurrencyCode = SAR
// 3. Generate TaxTotal in USD with subtotals
// 4. Generate second TaxTotal in SAR (150 × 3.75 = 562.50 SAR)
```

**TaxFly Integration:**

```php
class MultiCurrencyService
{
    /**
     * Supported currencies for cross-border
     */
    private const SUPPORTED_CURRENCIES = [
        'SAR' => 1.0000,        // Base currency
        'USD' => 3.7500,        // US Dollar
        'EUR' => 4.0500,        // Euro
        'GBP' => 4.7000,        // British Pound
        'AED' => 1.0200,        // UAE Dirham
        'KWD' => 12.2500,       // Kuwaiti Dinar
        'BHD' => 9.9500,        // Bahraini Dinar
        'OMR' => 9.7400,        // Omani Rial
        'QAR' => 1.0300,        // Qatari Riyal
    ];

    /**
     * Prepare invoice with foreign currency
     */
    public function prepareForSubmission(Invoice $invoice): array
    {
        $currency = $invoice->currency ?? 'SAR';

        if ($currency === 'SAR') {
            return [
                'currency' => 'SAR',
                'exchange_rate' => null,
                'amounts_in_sar' => null,
            ];
        }

        // Get exchange rate (from config or API)
        $rate = $this->getExchangeRate($currency);

        // Calculate SAR equivalents (ZATCA requires SAR for tax purposes)
        return [
            'currency' => $currency,
            'tax_currency' => 'SAR', // Tax always in SAR
            'exchange_rate' => $rate,
            'amounts_in_sar' => [
                'subtotal' => round($invoice->subtotal * $rate, 2),
                'tax_amount' => round($invoice->vat_amount * $rate, 2),
                'total' => round($invoice->total * $rate, 2),
            ],
        ];
    }

    private function getExchangeRate(string $currency): float
    {
        // Use configured rate or fetch from API
        return config("currencies.rates.{$currency}")
            ?? self::SUPPORTED_CURRENCIES[$currency]
            ?? 1.0;
    }
}
```

---

## 6. Required TaxFly Changes

### 6.1 Database Schema Changes

```php
// Migration: Add Masaar integration fields

// 1. Users table (no changes needed)

// 2. Businesses table
Schema::table('businesses', function (Blueprint $table) {
    // Masaar organization mapping
    $table->string('masaar_org_id')->nullable()->index();
    $table->enum('zatca_onboarding_status', [
        'pending', 'csr_generated', 'compliance_passed', 'completed', 'failed'
    ])->default('pending');

    // Business type for tax treatment
    $table->string('business_type')->default('retail');
    $table->json('exemption_codes')->nullable();

    // Arabic name (required by ZATCA)
    $table->string('name_ar')->nullable();

    // Additional ZATCA fields
    $table->string('building_number', 4)->nullable();
    $table->string('additional_number', 4)->nullable();
});

// 3. Branches table (new)
Schema::create('branches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('name_ar')->nullable();
    $table->string('device_serial')->unique();
    $table->string('masaar_egs_id')->nullable();

    // Address fields
    $table->string('street');
    $table->string('building_number', 4);
    $table->string('additional_number', 4)->nullable();
    $table->string('district');
    $table->string('city');
    $table->string('postal_code', 5);

    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

// 4. Invoices table
Schema::table('invoices', function (Blueprint $table) {
    // Branch reference
    $table->foreignId('branch_id')->nullable()->constrained();

    // Masaar tracking
    $table->string('masaar_id')->nullable()->index();

    // ZATCA compliance data
    $table->string('zatca_status')->default('pending');
    $table->string('zatca_clearance_status')->nullable();
    $table->string('zatca_reporting_status')->nullable();
    $table->string('zatca_invoice_hash', 64)->nullable();
    $table->text('zatca_qr_code')->nullable();
    $table->longText('zatca_signed_xml')->nullable();
    $table->json('zatca_warnings')->nullable();
    $table->json('zatca_errors')->nullable();
    $table->timestamp('zatca_submitted_at')->nullable();

    // Cross-border fields
    $table->boolean('is_export')->default(false);
    $table->string('destination_country', 2)->nullable();
    $table->string('customs_declaration_number')->nullable();
    $table->decimal('exchange_rate', 10, 6)->nullable();
});

// 5. Invoice Items table
Schema::table('invoice_items', function (Blueprint $table) {
    // Per-line tax exemption
    $table->string('tax_exemption_code')->nullable();
    $table->string('tax_exemption_reason')->nullable();
    $table->string('item_category')->nullable();
});

// 6. Customers table
Schema::table('customers', function (Blueprint $table) {
    // Cross-border support
    $table->string('country_code', 2)->default('SA');
    $table->string('building_number', 4)->nullable();
    $table->string('additional_number', 4)->nullable();
    $table->string('district')->nullable();
});
```

### 6.2 Model Changes

```php
// app/Models/Business.php
class Business extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'name_ar',
        'vat_number',
        'cr_number',
        'business_type',
        'street',
        'building_number',
        'additional_number',
        'district',
        'city',
        'postal_code',
        'masaar_org_id',
        'zatca_onboarding_status',
        'exemption_codes',
    ];

    protected $casts = [
        'exemption_codes' => 'array',
    ];

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function isZatcaReady(): bool
    {
        return $this->zatca_onboarding_status === 'completed';
    }
}

// app/Models/Branch.php
class Branch extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'name_ar',
        'device_serial',
        'masaar_egs_id',
        'street',
        'building_number',
        'additional_number',
        'district',
        'city',
        'postal_code',
        'is_active',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}

// app/Models/Invoice.php (updated)
class Invoice extends Model
{
    protected $fillable = [
        // ... existing fields
        'branch_id',
        'masaar_id',
        'zatca_status',
        'zatca_clearance_status',
        'zatca_reporting_status',
        'zatca_invoice_hash',
        'zatca_qr_code',
        'zatca_signed_xml',
        'zatca_warnings',
        'zatca_errors',
        'zatca_submitted_at',
        'is_export',
        'destination_country',
        'customs_declaration_number',
        'exchange_rate',
    ];

    protected $casts = [
        'zatca_warnings' => 'array',
        'zatca_errors' => 'array',
        'zatca_submitted_at' => 'datetime',
        'is_export' => 'boolean',
        'exchange_rate' => 'decimal:6',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isZatcaSubmitted(): bool
    {
        return in_array($this->zatca_status, ['cleared', 'reported']);
    }
}
```

### 6.3 API Endpoint Changes

```php
// routes/api.php additions

Route::prefix('zatca')->middleware('auth:api')->group(function () {
    // Organization onboarding
    Route::post('/organizations/{business}/register', [ZatcaController::class, 'registerOrganization']);
    Route::post('/organizations/{business}/onboarding/start', [ZatcaController::class, 'startOnboarding']);
    Route::post('/organizations/{business}/onboarding/compliance', [ZatcaController::class, 'completeCompliance']);
    Route::get('/organizations/{business}/status', [ZatcaController::class, 'getOrganizationStatus']);

    // Branch management
    Route::post('/businesses/{business}/branches', [BranchController::class, 'store']);
    Route::get('/businesses/{business}/branches', [BranchController::class, 'index']);
    Route::post('/branches/{branch}/register-egs', [BranchController::class, 'registerEgs']);

    // Invoice submission
    Route::post('/invoices/{invoice}/submit', [ZatcaController::class, 'submitInvoice']);
    Route::post('/invoices/{invoice}/retry', [ZatcaController::class, 'retrySubmission']);
    Route::get('/invoices/{invoice}/zatca-status', [ZatcaController::class, 'getInvoiceStatus']);

    // Bulk operations
    Route::post('/invoices/bulk-submit', [ZatcaController::class, 'bulkSubmit']);
});

// Webhooks (no auth)
Route::post('/webhooks/masaar', [MasaarWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth', 'throttle']);
```

### 6.4 UI Changes Required

#### Business Registration Form

```blade
{{-- resources/views/businesses/create.blade.php --}}

<form action="{{ route('businesses.store') }}" method="POST">
    @csrf

    {{-- Basic Information --}}
    <div class="card mb-4">
        <div class="card-header">Business Information</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <label>Business Name (English) *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="col-md-6">
                    <label>Business Name (Arabic) *</label>
                    <input type="text" name="name_ar" dir="rtl" required>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-6">
                    <label>VAT Registration Number *</label>
                    <input type="text" name="vat_number" pattern="3\d{13}3"
                           title="15 digits starting and ending with 3" required>
                    <small>Format: 3XXXXXXXXXXXXX3 (15 digits)</small>
                </div>
                <div class="col-md-6">
                    <label>Commercial Registration Number *</label>
                    <input type="text" name="cr_number" pattern="\d{10}"
                           title="10 digit CR number" required>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-6">
                    <label>Business Type *</label>
                    <select name="business_type" required>
                        <option value="retail">Retail (B2C)</option>
                        <option value="wholesale">Wholesale (B2B)</option>
                        <option value="healthcare">Healthcare</option>
                        <option value="education">Education</option>
                        <option value="export">Export</option>
                        <option value="real_estate">Real Estate</option>
                        <option value="financial_services">Financial Services</option>
                        <option value="mixed">Mixed/Multiple Activities</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Address (ZATCA Required) --}}
    <div class="card mb-4">
        <div class="card-header">Business Address (Required for ZATCA)</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <label>Street Name *</label>
                    <input type="text" name="street" required>
                </div>
                <div class="col-md-3">
                    <label>Building Number *</label>
                    <input type="text" name="building_number" pattern="\d{4}"
                           title="4 digit building number" required>
                </div>
                <div class="col-md-3">
                    <label>Additional Number</label>
                    <input type="text" name="additional_number" pattern="\d{4}"
                           title="4 digit additional number">
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-4">
                    <label>District *</label>
                    <input type="text" name="district" required>
                </div>
                <div class="col-md-4">
                    <label>City *</label>
                    <input type="text" name="city" required>
                </div>
                <div class="col-md-4">
                    <label>Postal Code *</label>
                    <input type="text" name="postal_code" pattern="\d{5}"
                           title="5 digit postal code" required>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Create Business</button>
</form>
```

#### ZATCA Onboarding Wizard

```blade
{{-- resources/views/zatca/onboarding.blade.php --}}

<div class="zatca-onboarding-wizard">
    {{-- Step indicator --}}
    <div class="steps">
        <div class="step {{ $step >= 1 ? 'completed' : '' }}">
            1. Register with Masaar
        </div>
        <div class="step {{ $step >= 2 ? 'completed' : '' }}">
            2. Get ZATCA OTP
        </div>
        <div class="step {{ $step >= 3 ? 'completed' : '' }}">
            3. Generate CSR
        </div>
        <div class="step {{ $step >= 4 ? 'completed' : '' }}">
            4. Compliance Check
        </div>
        <div class="step {{ $step >= 5 ? 'completed' : '' }}">
            5. Production Ready
        </div>
    </div>

    {{-- Step content --}}
    @if($step === 1)
        <div class="step-content">
            <h3>Step 1: Register with Masaar</h3>
            <p>We'll register your business with our ZATCA compliance service.</p>

            <button onclick="registerWithMasaar()" class="btn btn-primary">
                Register Business
            </button>
        </div>
    @elseif($step === 2)
        <div class="step-content">
            <h3>Step 2: Get ZATCA OTP</h3>
            <p>Log in to <a href="https://fatoora.zatca.gov.sa" target="_blank">ZATCA Fatoora Portal</a>
               and generate a One-Time Password (OTP) for this business.</p>

            <form action="{{ route('zatca.start-onboarding', $business) }}" method="POST">
                @csrf
                <label>Enter OTP from Fatoora Portal</label>
                <input type="text" name="otp" pattern="\d{6}" required>
                <small>6-digit code from ZATCA portal</small>

                <button type="submit" class="btn btn-primary">
                    Continue with OTP
                </button>
            </form>
        </div>
    @elseif($step === 3)
        <div class="step-content">
            <h3>Step 3: CSR Generated</h3>
            <p>Certificate Signing Request has been generated and submitted to ZATCA.</p>
            <p>CCSID (Compliance CSID) received.</p>

            <button onclick="runComplianceCheck()" class="btn btn-primary">
                Run Compliance Check
            </button>
        </div>
    @elseif($step === 4)
        <div class="step-content">
            <h3>Step 4: Compliance Check</h3>
            <p>Submitting test invoices to verify ZATCA compliance...</p>

            <div class="compliance-results">
                @foreach($complianceResults as $test)
                    <div class="test-result {{ $test['passed'] ? 'passed' : 'failed' }}">
                        {{ $test['name'] }}: {{ $test['passed'] ? '✓' : '✗' }}
                    </div>
                @endforeach
            </div>

            @if($allPassed)
                <button onclick="completeOnboarding()" class="btn btn-success">
                    Complete Onboarding
                </button>
            @endif
        </div>
    @elseif($step === 5)
        <div class="step-content">
            <h3>✓ ZATCA Onboarding Complete!</h3>
            <p>Your business is now ready to submit invoices to ZATCA.</p>

            <div class="alert alert-success">
                <strong>Production CSID (PCSID) Obtained</strong><br>
                You can now submit real invoices for clearance and reporting.
            </div>

            <a href="{{ route('invoices.create') }}" class="btn btn-primary">
                Create First Invoice
            </a>
        </div>
    @endif
</div>
```

---

## 7. Data Collection Forms

### 7.1 Complete Onboarding Form

```php
// Form validation rules for complete ZATCA onboarding

class ZatcaOnboardingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Business identification
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'vat_number' => [
                'required',
                'string',
                'size:15',
                'regex:/^3\d{13}3$/',
            ],
            'cr_number' => [
                'required',
                'string',
                'size:10',
                'regex:/^\d{10}$/',
            ],

            // Business type
            'business_type' => [
                'required',
                Rule::in([
                    'retail', 'wholesale', 'healthcare', 'education',
                    'export', 'real_estate', 'financial_services', 'mixed'
                ]),
            ],

            // Address (all required for ZATCA)
            'street' => ['required', 'string', 'max:255'],
            'building_number' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'additional_number' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'size:5', 'regex:/^\d{5}$/'],

            // Contact
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'contact_person' => ['required', 'string', 'max:255'],

            // ZATCA specific
            'otp' => ['sometimes', 'required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'vat_number.regex' => 'VAT number must be 15 digits starting and ending with 3',
            'cr_number.regex' => 'CR number must be exactly 10 digits',
            'building_number.regex' => 'Building number must be exactly 4 digits',
            'postal_code.regex' => 'Postal code must be exactly 5 digits',
            'otp.regex' => 'OTP must be exactly 6 digits',
        ];
    }
}
```

### 7.2 Branch Registration Form

```php
class BranchRegistrationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],

            // Address (can be different from main business)
            'street' => ['required', 'string', 'max:255'],
            'building_number' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'additional_number' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'size:5', 'regex:/^\d{5}$/'],

            // Device serial (auto-generated if not provided)
            'device_serial' => ['nullable', 'string', 'unique:branches,device_serial'],
        ];
    }
}
```

---

## 8. Onboarding Workflow

### 8.1 Complete Onboarding Steps

```
┌─────────────────────────────────────────────────────────────────────┐
│                    TaxFly ZATCA Onboarding Flow                     │
└─────────────────────────────────────────────────────────────────────┘

User creates account in TaxFly
           │
           ▼
┌─────────────────────────────────────────────────────────────────────┐
│ STEP 1: Business Registration                                       │
│ - User enters business details (name, VAT, CR, address)             │
│ - TaxFly validates format locally                                   │
│ - TaxFly calls Masaar API to register organization               │
│ - Masaar validates VAT with ZATCA                                │
└─────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────┐
│ STEP 2: Get ZATCA OTP                                               │
│ - User logs into https://fatoora.zatca.gov.sa                       │
│ - User navigates to "Onboard Solution Unit"                         │
│ - User generates OTP (valid for 1 hour)                             │
│ - User enters OTP in TaxFly                                         │
└─────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────┐
│ STEP 3: CSR Generation (Automatic)                                  │
│ - Masaar generates ECDSA secp256k1 key pair                      │
│ - Masaar creates CSR with business details                       │
│ - Masaar submits CSR to ZATCA with OTP                           │
│ - ZATCA returns CCSID (Compliance Certificate)                      │
└─────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────┐
│ STEP 4: Compliance Check                                            │
│ - Masaar submits 6 test invoices to ZATCA:                       │
│   • Standard invoice (B2B)                                          │
│   • Simplified invoice (B2C)                                        │
│   • Standard credit note                                            │
│   • Simplified credit note                                          │
│   • Standard debit note                                             │
│   • Simplified debit note                                           │
│ - All must pass ZATCA validation                                    │
└─────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────┐
│ STEP 5: Production CSID                                             │
│ - Masaar requests PCSID from ZATCA                               │
│ - ZATCA issues production certificate                               │
│ - Masaar stores credentials securely                             │
│ - Business is now ZATCA-ready                                       │
└─────────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────────┐
│ READY: Invoice Submission                                           │
│ - User creates invoices in TaxFly                                   │
│ - TaxFly sends to Masaar                                         │
│ - Masaar generates XML, signs, submits to ZATCA                  │
│ - ZATCA clears/reports invoice                                      │
│ - Masaar returns QR code and status to TaxFly                    │
└─────────────────────────────────────────────────────────────────────┘
```

### 8.2 Onboarding Service Implementation

```php
class ZatcaOnboardingService
{
    public function __construct(
        private OrganizationService $orgService,
        private MasaarClient $client,
    ) {}

    /**
     * Complete onboarding workflow
     */
    public function onboard(Business $business, string $otp): array
    {
        // Step 1: Register if not already
        if (!$business->masaar_org_id) {
            $org = $this->orgService->register($business);
            $business->update([
                'masaar_org_id' => $org['id'],
                'zatca_onboarding_status' => 'pending',
            ]);
        }

        // Step 2: Start onboarding with OTP
        $csr = $this->client->post(
            "/api/organizations/{$business->masaar_org_id}/onboarding",
            ['otp' => $otp]
        );

        $business->update(['zatca_onboarding_status' => 'csr_generated']);

        // Step 3: Run compliance checks
        $compliance = $this->client->post(
            "/api/organizations/{$business->masaar_org_id}/onboarding/compliance-check"
        );

        if (!$compliance['all_passed']) {
            $business->update(['zatca_onboarding_status' => 'failed']);
            return [
                'success' => false,
                'step' => 'compliance',
                'errors' => $compliance['failures'],
            ];
        }

        $business->update(['zatca_onboarding_status' => 'compliance_passed']);

        // Step 4: Get production CSID
        $pcsid = $this->client->post(
            "/api/organizations/{$business->masaar_org_id}/onboarding/complete"
        );

        $business->update(['zatca_onboarding_status' => 'completed']);

        return [
            'success' => true,
            'organization_id' => $business->masaar_org_id,
            'status' => 'completed',
            'message' => 'Business is now ZATCA-compliant and ready for invoice submission',
        ];
    }

    /**
     * Get current onboarding status with next action
     */
    public function getStatus(Business $business): array
    {
        $status = $business->zatca_onboarding_status;

        return [
            'status' => $status,
            'step' => $this->getStepNumber($status),
            'next_action' => $this->getNextAction($status),
            'can_submit_invoices' => $status === 'completed',
        ];
    }

    private function getStepNumber(string $status): int
    {
        return match ($status) {
            'pending' => 1,
            'csr_generated' => 3,
            'compliance_passed' => 4,
            'completed' => 5,
            'failed' => 0,
            default => 1,
        };
    }

    private function getNextAction(string $status): string
    {
        return match ($status) {
            'pending' => 'Get OTP from ZATCA Fatoora Portal',
            'csr_generated' => 'Running compliance checks...',
            'compliance_passed' => 'Obtaining production certificate...',
            'completed' => 'Ready! Start creating invoices',
            'failed' => 'Contact support to resolve issues',
            default => 'Complete business registration',
        };
    }
}
```

---

## Summary Checklist

### For TaxFly Development Team

- [ ] Add `masaar_org_id` to businesses table
- [ ] Add `zatca_*` fields to invoices table
- [ ] Create branches table with EGS device support
- [ ] Add Arabic name fields where required
- [ ] Add complete address fields (building_number, additional_number, district)
- [ ] Add `country_code` to customers for cross-border
- [ ] Add per-line tax exemption fields
- [ ] Create MasaarClient service
- [ ] Create InvoiceService for mapping
- [ ] Create OrganizationService for onboarding
- [ ] Create webhook handler
- [ ] Create onboarding wizard UI
- [ ] Add ZATCA status display on invoices
- [ ] Add QR code display on invoice PDF

### For User Data Collection

- [ ] Business name (English + Arabic)
- [ ] VAT number (15 digits, starts/ends with 3)
- [ ] CR number (10 digits)
- [ ] Complete address (street, building, district, city, postal)
- [ ] Business type selection
- [ ] Contact information
- [ ] OTP from ZATCA portal (during onboarding)

---

*Document Version: 1.0*
*Last Updated: 2026-02-03*
*For use with Masaar API v1*
