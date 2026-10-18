# Partner Integration Guide

Complete guide for integrating the Masaar ZATCA E-Invoicing API into a partner's existing invoicing system.

## Table of Contents

1. [Overview](#1-overview)
2. [Prerequisites](#2-prerequisites)
3. [Installation & Setup](#3-installation--setup)
4. [API Authentication](#4-api-authentication)
5. [Service Integration](#5-service-integration)
6. [Database Changes](#6-database-changes)
7. [Invoice Submission Flow](#7-invoice-submission-flow)
8. [Webhook Configuration](#8-webhook-configuration)
9. [Testing & Validation](#9-testing--validation)
10. [Migration Checklist](#10-migration-checklist)
11. [Troubleshooting](#11-troubleshooting)
12. [Changes for integrators](#changes-for-integrators)

---

## 1. Overview

### Typical Starting Point
A partner system often already issues invoices and may produce a Phase 1 QR code, but lacks what Phase 2 requires: XAdES-BES signing and the ZATCA clearance and reporting APIs.

### What Masaar Provides
- Complete ZATCA Phase 2 compliance
- UBL 2.1 XML generation with all required elements
- XAdES-BES digital signatures (ECDSA secp256k1)
- QR code generation (9 tags for Phase 2)
- ZATCA API integration (clearance & reporting)
- Webhook notifications for status updates
- **Multi-branch EGS support** (separate credentials per branch)
- **Foreign currency invoices** (USD, EUR, etc. with SAR VAT reporting)

### Integration Strategy
Replace the partner system's own ZATCA handling with Masaar API calls while keeping its existing invoice management UI and business logic.

> **Advanced Scenarios**: For multi-business, multi-branch, and cross-border transactions, see [PARTNER-ADVANCED-SCENARIOS.md](./PARTNER-ADVANCED-SCENARIOS.md).

---

## 2. Prerequisites

### System Requirements
- PHP 8.0+ with Laravel 8 or later (the examples use `match` and the nullsafe operator)
- OpenSSL extension
- cURL extension
- JSON extension

### Required Credentials
1. **Masaar access token** - A JWT from `POST /api/auth/login` for a user of the organization (an administrator, for onboarding); renew it with `POST /api/auth/refresh`
2. **ZATCA OTP** - From ZATCA Fatoora Portal for onboarding
3. **Organization VAT Number** - Valid Saudi VAT registration

### Masaar Endpoints

| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://sandbox.example.com` |
| Simulation | `https://simulation.example.com` |
| Production | `https://api.example.com` |

---

## 3. Installation & Setup

### Step 1: Install HTTP Client

If Guzzle is already installed, ensure it is up to date:

```bash
composer require guzzlehttp/guzzle:^7.0
```

### Step 2: Add Configuration

Create `config/masaar.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Masaar API Configuration
    |--------------------------------------------------------------------------
    */

    'base_url' => env('MASAAR_BASE_URL', 'https://sandbox.example.com'),

    'token' => env('MASAAR_API_TOKEN'),

    'timeout' => env('MASAAR_TIMEOUT', 30),

    'retry' => [
        'times' => 3,
        'sleep' => 1000, // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'secret' => env('MASAAR_WEBHOOK_SECRET'),
        'events' => [
            'invoice.cleared',
            'invoice.reported',
            'invoice.rejected',
            'certificate.expiring',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Organization Mapping
    |--------------------------------------------------------------------------
    | Map partner company IDs to Masaar organization IDs
    */

    'organization_map' => [
        // 'partner_company_id' => 'masaar_organization_id',
    ],
];
```

### Step 3: Add Environment Variables

Add to `.env`:

```env
# Masaar Configuration
MASAAR_BASE_URL=https://sandbox.example.com
MASAAR_API_TOKEN=your_access_token_here
MASAAR_WEBHOOK_SECRET=your_webhook_secret_here
MASAAR_TIMEOUT=30
```

---

## 4. API Authentication

The endpoints under `/api` act for the organization of the JWT they are called
with, sent as `Authorization: Bearer <token>`. A system holding a licence key
and secret instead sends them as `X-API-Key` and `X-API-Secret` to the
versioned endpoints under `/api/v1`: `/api/v1/invoices`,
`/api/v1/compliance/generate/{invoiceId}`, `/api/v1/compliance/validate/{invoiceId}`,
`/api/v1/compliance/submit/{invoiceId}`, `/api/v1/compliance/status/{invoiceId}`
and `/api/v1/pipeline/submit`, which drafts, issues and submits in one call.
The invoice body is the same on both. Onboarding and organization registration
are under `/api` only.

### Masaar API Client

Create `app/Services/Masaar/MasaarClient.php`:

```php
<?php

namespace App\Services\Masaar;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class MasaarClient
{
    private Client $http;
    private string $baseUrl;
    private string $token;

    /**
     * The token decides the organization every call acts for.
     */
    public function __construct(?string $token = null)
    {
        $this->baseUrl = config('masaar.base_url');
        $this->token = $token ?? config('masaar.token');

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('masaar.timeout', 30),
            'headers' => [
                'Authorization' => 'Bearer '.$this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Make authenticated GET request
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $query]);
    }

    /**
     * Make authenticated POST request
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    /**
     * Make authenticated PUT request
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    /**
     * Make authenticated DELETE request
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Execute HTTP request with retry logic
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $retryTimes = config('masaar.retry.times', 3);
        $retrySleep = config('masaar.retry.sleep', 1000);
        $lastException = null;

        for ($attempt = 1; $attempt <= $retryTimes; $attempt++) {
            try {
                $response = $this->http->request($method, $endpoint, $options);

                return json_decode($response->getBody()->getContents(), true) ?? [];

            } catch (RequestException $e) {
                $lastException = $e;

                // Don't retry client errors (4xx)
                if ($e->hasResponse() && $e->getResponse()->getStatusCode() < 500) {
                    break;
                }

                Log::warning("Masaar API request failed (attempt {$attempt}/{$retryTimes})", [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $retryTimes) {
                    usleep($retrySleep * 1000);
                }
            }
        }

        // Parse error response
        if ($lastException instanceof RequestException && $lastException->hasResponse()) {
            $errorBody = json_decode(
                $lastException->getResponse()->getBody()->getContents(),
                true
            );

            throw new MasaarException(
                $errorBody['error']['message'] ?? $lastException->getMessage(),
                $lastException->getResponse()->getStatusCode(),
                $errorBody['error']['details'] ?? []
            );
        }

        throw new MasaarException(
            $lastException?->getMessage() ?? 'Unknown API error',
            500
        );
    }
}
```

### Exception Class

Create `app/Services/Masaar/MasaarException.php`:

```php
<?php

namespace App\Services\Masaar;

use Exception;

class MasaarException extends Exception
{
    private array $errors;

    public function __construct(string $message, int $code = 0, array $errors = [])
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

---

## 5. Service Integration

### Invoice Service

Create `app/Services/Masaar/InvoiceService.php`:

```php
<?php

namespace App\Services\Masaar;

use App\Models\Invoice;
use App\Models\Company;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    private MasaarClient $client;

    public function __construct(MasaarClient $client)
    {
        $this->client = $client;
    }

    /**
     * Create invoice in Masaar and get ZATCA compliance data
     */
    public function createAndSubmit(Invoice $invoice, Company $company): array
    {
        // Step 1: Map the partner invoice to Masaar format
        $invoiceData = $this->mapInvoiceData($invoice, $company);

        // Step 2: Create invoice in Masaar
        $created = $this->client->post('/api/invoices', $invoiceData);
        $masaarId = $created['data']['invoice']['id'];

        Log::info('Invoice created in Masaar', [
            'partner_invoice_id' => $invoice->id,
            'masaar_invoice_id' => $masaarId,
        ]);

        // Step 3: Issue it: number, sign, hash and QR
        $compliance = $this->client->post("/api/compliance/sa/generate/{$masaarId}")['data'];

        // Step 4: Submit to ZATCA (clearance for standard, reporting for simplified)
        $submission = $this->client->post("/api/compliance/sa/submit/{$masaarId}")['data'];
        $standard = $invoiceData['type'] === 'standard';

        return [
            'masaar_id' => $masaarId,
            'zatca_status' => $submission['status'],
            'clearance_status' => $standard ? $submission['status'] : null,
            'reporting_status' => $standard ? null : $submission['status'],
            'xml' => $compliance['signed_xml'],
            'hash' => $compliance['hash'],
            'qr_code' => $compliance['qr_code'],
            'warnings' => $submission['warnings'] ?? [],
            'errors' => [], // a refusal is thrown as a MasaarException
        ];
    }

    /**
     * Map the partner invoice format to Masaar format
     */
    private function mapInvoiceData(Invoice $invoice, Company $company): array
    {
        return [
            // Invoice identification
            'invoice_number' => $invoice->invoice_number,
            'type' => $this->mapInvoiceType($invoice),
            'document_type' => $this->mapDocumentType($invoice),

            // Dates
            'issue_date' => $invoice->invoice_date->format('Y-m-d'),
            'supply_date' => $invoice->supply_date?->format('Y-m-d'),

            // Currency
            'currency' => $invoice->currency ?? 'SAR',

            // Buyer information
            'buyer_name' => $invoice->customer_name,
            'buyer_vat_number' => $invoice->customer_vat_number,
            'buyer_address' => $this->mapAddress($invoice),

            // Line items
            'lines' => $this->mapLineItems($invoice),

            // A discount on the whole invoice. The subtotal, VAT and total
            // are computed by Masaar from the lines; they are not sent.
            'discount_amount' => (float) ($invoice->discount ?? 0),

            // Payment
            'payment_means_code' => $this->mapPaymentMethod($invoice),

            // Credit and debit notes: the invoice corrected, and why (BR-KSA-17)
            'billing_ref' => $invoice->original_invoice_number,
            'adjustment_reason' => $invoice->adjustment_reason,

            // Invoice sub-types
            'is_export' => (bool) ($invoice->is_export ?? false),
            'is_third_party' => (bool) ($invoice->is_third_party ?? false),
            'is_summary' => (bool) ($invoice->is_summary ?? false),
            'is_self_billed' => (bool) ($invoice->is_self_billed ?? false),
        ];
    }

    /**
     * Map invoice type (standard/simplified)
     */
    private function mapInvoiceType(Invoice $invoice): string
    {
        // The partner model uses an invoice_type field
        // B2B = standard (requires clearance)
        // B2C = simplified (reporting only)

        if ($invoice->invoice_type === 'B2B' || !empty($invoice->customer_vat_number)) {
            return 'standard';
        }

        return 'simplified';
    }

    /**
     * Map document type (invoice/credit_note/debit_note)
     */
    private function mapDocumentType(Invoice $invoice): string
    {
        // Check the partner model's document type field
        return match ($invoice->document_type ?? 'invoice') {
            'credit_note', 'CN' => 'credit_note',
            'debit_note', 'DN' => 'debit_note',
            default => 'invoice',
        };
    }

    /**
     * Map buyer address
     */
    private function mapAddress(Invoice $invoice): ?array
    {
        if (empty($invoice->customer_address)) {
            return null;
        }

        // The partner model may store the address as a string or structured.
        // Send what the buyer's address says and nothing invented: a Saudi
        // buyer's postal code is its real five digits (BR-KSA-67), and a
        // standard invoice needs the street, building number and city.
        if (is_string($invoice->customer_address)) {
            return [
                'street' => $invoice->customer_address,
                'city' => $invoice->customer_city,
                'postal_code' => $invoice->customer_postal_code,
                'country_code' => 'SA',
            ];
        }

        return [
            'street' => $invoice->customer_address['street'] ?? null,
            'building_number' => $invoice->customer_address['building'] ?? null,
            'district' => $invoice->customer_address['district'] ?? null,
            'city' => $invoice->customer_address['city'] ?? null,
            'postal_code' => $invoice->customer_address['postal_code'] ?? null,
            'country_code' => $invoice->customer_address['country'] ?? 'SA',
        ];
    }

    /**
     * Map line items
     */
    private function mapLineItems(Invoice $invoice): array
    {
        return $invoice->items->map(function ($item) {
            return [
                'description' => $item->description ?? $item->item_name,
                'quantity' => (float) $item->quantity,
                'unit_code' => $item->unit ?? 'H87', // UN/ECE Rec 20: H87 is "piece"
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => (float) ($item->vat_rate ?? 15),
                'tax_category' => $this->mapTaxCategory($item),
                'exempt_code' => $item->exemption_code,
                'exempt_reason' => $item->exemption_reason,
                'discount_amount' => (float) ($item->discount ?? 0),
            ];
        })->toArray();
    }

    /**
     * Map tax category
     *
     * ZATCA decides the category by the exemption code (BR-KSA-CL-04), and
     * refuses a code under another category's.
     */
    private function mapTaxCategory($item): string
    {
        $rate = (float) ($item->vat_rate ?? 15);

        if ($rate > 0) {
            return 'S'; // Standard rated
        }

        return match ($item->exemption_code) {
            'VATEX-SA-29', 'VATEX-SA-29-7', 'VATEX-SA-30' => 'E', // Exempt: financial services, life insurance, real estate
            'VATEX-SA-OOS' => 'O', // Out of scope
            // Every other code is zero-rated: exports (VATEX-SA-32, -33),
            // international transport (-34-1 to -34-5), medicines (-35),
            // qualifying metals (-36), private education and healthcare
            // (EDU, HEA) and the rest. A zero-rated line without a code is
            // refused: every Z, E or O line names one.
            default => 'Z',
        };
    }

    /**
     * Map payment method to ZATCA code
     */
    private function mapPaymentMethod(Invoice $invoice): string
    {
        return match ($invoice->payment_method ?? 'cash') {
            'cash' => '10',
            'check', 'cheque' => '20',
            'bank_transfer', 'transfer' => '30',
            'card', 'credit_card' => '48',
            default => '10',
        };
    }

    /**
     * Get invoice status from Masaar
     */
    public function getStatus(string $masaarId): array
    {
        return $this->client->get("/api/compliance/sa/status/{$masaarId}")['data'];
    }

    /**
     * Validate an issued invoice with ZATCA without submitting it
     */
    public function validate(string $masaarId): array
    {
        return $this->client->post("/api/compliance/sa/validate/{$masaarId}")['data'];
    }
}
```

For a standard invoice to a VAT-registered buyer, `mapInvoiceData()` sends a
request like this one to `POST /api/invoices`:

<!-- example: POST /api/invoices sa -->
```json
{
  "invoice_number": "INV-1001",
  "type": "standard",
  "document_type": "invoice",
  "issue_date": "2026-10-15",
  "supply_date": "2026-10-15",
  "currency": "SAR",
  "buyer_name": "Beta Industries",
  "buyer_vat_number": "399999999800003",
  "buyer_address": {
    "street": "Olaya Street",
    "building_number": "4321",
    "district": "Al Murooj",
    "city": "Riyadh",
    "postal_code": "11564",
    "country_code": "SA"
  },
  "lines": [
    {
      "description": "Office chair",
      "quantity": 2,
      "unit_code": "H87",
      "unit_price": 450.00,
      "tax_rate": 15,
      "tax_category": "S",
      "exempt_code": null,
      "exempt_reason": null,
      "discount_amount": 50.00
    }
  ],
  "discount_amount": 0,
  "payment_means_code": "30",
  "billing_ref": null,
  "adjustment_reason": null,
  "is_export": false,
  "is_third_party": false,
  "is_summary": false,
  "is_self_billed": false
}
```

Masaar answers `201` with the draft in `data.invoice`, its subtotal, VAT and
total computed from the lines.

### Organization Service

Create `app/Services/Masaar/OrganizationService.php`:

```php
<?php

namespace App\Services\Masaar;

use App\Models\Company;

class OrganizationService
{
    private MasaarClient $client;

    public function __construct(MasaarClient $client)
    {
        $this->client = $client;
    }

    /**
     * Register company as organization in Masaar
     */
    public function register(Company $company): array
    {
        // The address fields sit beside the others, not under an "address" key.
        return $this->client->post('/api/organizations', [
            'country' => 'SA',
            'name' => $company->name,
            'name_ar' => $company->name_ar ?? $company->name,
            'vat_number' => $company->vat_number,
            'cr_number' => $company->cr_number,
            'street' => $company->street,
            'building_number' => $company->building_number,
            'district' => $company->district,
            'city' => $company->city,
            'postal_code' => $company->postal_code,
        ]);
    }

    /*
     * Onboarding acts for the organization of the token it is called with,
     * and needs an administrator's.
     */

    /**
     * Step 1: request the compliance CSID with the OTP from the Fatoora portal
     */
    public function startOnboarding(string $otp, string $deviceName, string $serialNumber): array
    {
        return $this->client->post('/api/compliance/onboarding/ccsid', [
            'otp' => $otp,
            'common_name' => $deviceName,
            'serial_number' => $serialNumber, // 1-<solution>|2-<model>|3-<serial>
        ]);
    }

    /**
     * Step 2: run the compliance checks; step 3: request the production CSID
     */
    public function completeCompliance(): array
    {
        $checks = $this->client->post('/api/compliance/onboarding/compliance-check');

        if (! ($checks['data']['passed'] ?? false)) {
            return $checks;
        }

        return $this->client->post('/api/compliance/onboarding/pcsid');
    }

    /**
     * Get organization status
     */
    public function getStatus(string $organizationId): array
    {
        return $this->client->get("/api/organizations/{$organizationId}");
    }
}
```

### Service Provider

Create `app/Providers/MasaarServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\Masaar\MasaarClient;
use App\Services\Masaar\InvoiceService;
use App\Services\Masaar\OrganizationService;
use Illuminate\Support\ServiceProvider;

class MasaarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MasaarClient::class, function ($app) {
            return new MasaarClient();
        });

        $this->app->singleton(InvoiceService::class, function ($app) {
            return new InvoiceService($app->make(MasaarClient::class));
        });

        $this->app->singleton(OrganizationService::class, function ($app) {
            return new OrganizationService($app->make(MasaarClient::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/masaar.php' => config_path('masaar.php'),
        ], 'masaar-config');
    }
}
```

Register in `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\MasaarServiceProvider::class,
],
```

---

## 6. Database Changes

### Migration for Masaar Integration

Create migration:

```bash
php artisan make:migration add_masaar_fields_to_invoices_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Masaar reference
            $table->string('masaar_id')->nullable()->after('id');
            $table->index('masaar_id');

            // ZATCA compliance data
            $table->string('zatca_status')->default('pending')->after('status');
            $table->string('zatca_clearance_status')->nullable();
            $table->string('zatca_reporting_status')->nullable();
            $table->string('zatca_invoice_hash')->nullable();
            $table->text('zatca_qr_code')->nullable();
            $table->longText('zatca_signed_xml')->nullable();

            // ZATCA response tracking
            $table->json('zatca_warnings')->nullable();
            $table->json('zatca_errors')->nullable();
            $table->timestamp('zatca_submitted_at')->nullable();
            $table->timestamp('zatca_cleared_at')->nullable();
        });

        // Add Masaar organization mapping to companies
        Schema::table('companies', function (Blueprint $table) {
            $table->string('masaar_org_id')->nullable()->after('id');
            $table->string('masaar_onboarding_status')->default('pending');
            $table->index('masaar_org_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
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
                'zatca_cleared_at',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['masaar_org_id', 'masaar_onboarding_status']);
        });
    }
};
```

Run migration:

```bash
php artisan migrate
```

---

## 7. Invoice Submission Flow

### Controller Integration

Update `app/Http/Controllers/InvoiceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Masaar\InvoiceService;
use App\Services\Masaar\MasaarException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    private InvoiceService $masaarInvoice;

    public function __construct(InvoiceService $masaarInvoice)
    {
        $this->masaarInvoice = $masaarInvoice;
    }

    /**
     * Create invoice and submit to ZATCA
     */
    public function store(Request $request)
    {
        // Existing validation...
        $validated = $request->validate([
            'invoice_number' => 'required|string',
            'customer_name' => 'required|string',
            'items' => 'required|array|min:1',
            // ... other validation rules
        ]);

        // Create invoice in the partner database
        $invoice = Invoice::create($validated);
        $invoice->load('items', 'company');

        // Submit to Masaar for ZATCA compliance
        try {
            $result = $this->masaarInvoice->createAndSubmit(
                $invoice,
                $invoice->company
            );

            // Update invoice with Masaar data
            $invoice->update([
                'masaar_id' => $result['masaar_id'],
                'zatca_status' => $result['zatca_status'],
                'zatca_clearance_status' => $result['clearance_status'],
                'zatca_reporting_status' => $result['reporting_status'],
                'zatca_invoice_hash' => $result['hash'],
                'zatca_qr_code' => $result['qr_code'],
                'zatca_signed_xml' => $result['xml'],
                'zatca_warnings' => $result['warnings'],
                'zatca_submitted_at' => now(),
            ]);

            if ($result['zatca_status'] === 'cleared') {
                $invoice->update(['zatca_cleared_at' => now()]);
            }

            return response()->json([
                'success' => true,
                'invoice' => $invoice,
                'zatca' => [
                    'status' => $result['zatca_status'],
                    'qr_code' => $result['qr_code'],
                    'warnings' => $result['warnings'],
                ],
            ], 201);

        } catch (MasaarException $e) {
            Log::error('ZATCA submission failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ]);

            // Store error but keep invoice
            $invoice->update([
                'zatca_status' => 'failed',
                'zatca_errors' => $e->getErrors(),
            ]);

            return response()->json([
                'success' => false,
                'invoice' => $invoice,
                'error' => $e->getMessage(),
                'zatca_errors' => $e->getErrors(),
            ], 422);
        }
    }

    /**
     * Retry failed ZATCA submission
     */
    public function retryZatca(Invoice $invoice)
    {
        if (!in_array($invoice->zatca_status, ['failed', 'pending'])) {
            return response()->json([
                'error' => 'Invoice already processed',
            ], 400);
        }

        try {
            $result = $this->masaarInvoice->createAndSubmit(
                $invoice,
                $invoice->company
            );

            $invoice->update([
                'masaar_id' => $result['masaar_id'],
                'zatca_status' => $result['zatca_status'],
                'zatca_clearance_status' => $result['clearance_status'],
                'zatca_invoice_hash' => $result['hash'],
                'zatca_qr_code' => $result['qr_code'],
                'zatca_signed_xml' => $result['xml'],
                'zatca_warnings' => $result['warnings'],
                'zatca_errors' => null,
                'zatca_submitted_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'invoice' => $invoice->fresh(),
            ]);

        } catch (MasaarException $e) {
            $invoice->update([
                'zatca_status' => 'failed',
                'zatca_errors' => $e->getErrors(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get ZATCA status
     */
    public function zatcaStatus(Invoice $invoice)
    {
        if (!$invoice->masaar_id) {
            return response()->json([
                'status' => 'not_submitted',
            ]);
        }

        try {
            $status = $this->masaarInvoice->getStatus($invoice->masaar_id);

            // Update local status if changed
            if ($status['status'] !== $invoice->zatca_status) {
                $invoice->update([
                    'zatca_status' => $status['status'],
                    'zatca_clearance_status' => $status['clearance_status'] ?? null,
                ]);
            }

            return response()->json($status);

        } catch (MasaarException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
```

### Queue Job for Async Submission

Create `app/Jobs/SubmitInvoiceToZatca.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Masaar\InvoiceService;
use App\Services\Masaar\MasaarException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SubmitInvoiceToZatca implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function handle(InvoiceService $invoiceService): void
    {
        try {
            $result = $invoiceService->createAndSubmit(
                $this->invoice,
                $this->invoice->company
            );

            $this->invoice->update([
                'masaar_id' => $result['masaar_id'],
                'zatca_status' => $result['zatca_status'],
                'zatca_clearance_status' => $result['clearance_status'],
                'zatca_reporting_status' => $result['reporting_status'],
                'zatca_invoice_hash' => $result['hash'],
                'zatca_qr_code' => $result['qr_code'],
                'zatca_signed_xml' => $result['xml'],
                'zatca_warnings' => $result['warnings'],
                'zatca_submitted_at' => now(),
            ]);

            Log::info('Invoice submitted to ZATCA successfully', [
                'invoice_id' => $this->invoice->id,
                'zatca_status' => $result['zatca_status'],
            ]);

        } catch (MasaarException $e) {
            Log::error('ZATCA submission failed', [
                'invoice_id' => $this->invoice->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            $this->invoice->update([
                'zatca_status' => 'failed',
                'zatca_errors' => $e->getErrors(),
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }
}
```

Usage in controller:

```php
// Async submission
SubmitInvoiceToZatca::dispatch($invoice);

return response()->json([
    'invoice' => $invoice,
    'message' => 'Invoice created. ZATCA submission in progress.',
]);
```

---

## 8. Webhook Configuration

### Webhook Controller

Create `app/Http/Controllers/MasaarWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MasaarWebhookController extends Controller
{
    /**
     * Handle Masaar webhooks
     */
    public function handle(Request $request)
    {
        // Verify webhook signature
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid Masaar webhook signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        $event = $payload['event'] ?? null;

        Log::info('Masaar webhook received', [
            'event' => $event,
            'invoice_id' => $payload['data']['invoice_id'] ?? null,
        ]);

        return match ($event) {
            'invoice.cleared' => $this->handleInvoiceCleared($payload),
            'invoice.reported' => $this->handleInvoiceReported($payload),
            'invoice.rejected' => $this->handleInvoiceRejected($payload),
            'certificate.expiring' => $this->handleCertificateExpiring($payload),
            default => response()->json(['message' => 'Event ignored']),
        };
    }

    /**
     * Verify HMAC signature
     */
    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Webhook-Signature');
        $secret = config('masaar.webhook.secret');

        if (!$signature || !$secret) {
            return false;
        }

        // The header carries the algorithm as a prefix: sha256=<hex>.
        $expectedSignature = 'sha256='.hash_hmac(
            'sha256',
            $request->getContent(),
            $secret
        );

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Handle invoice cleared event
     */
    private function handleInvoiceCleared(array $payload)
    {
        $masaarId = $payload['data']['invoice_id'];

        $invoice = Invoice::where('masaar_id', $masaarId)->first();

        if ($invoice) {
            $invoice->update([
                'zatca_status' => 'cleared',
                'zatca_clearance_status' => $payload['data']['clearance_status'] ?? 'CLEARED',
                'zatca_cleared_at' => now(),
            ]);

            Log::info('Invoice cleared by ZATCA', [
                'invoice_id' => $invoice->id,
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle invoice reported event
     */
    private function handleInvoiceReported(array $payload)
    {
        $masaarId = $payload['data']['invoice_id'];

        $invoice = Invoice::where('masaar_id', $masaarId)->first();

        if ($invoice) {
            $invoice->update([
                'zatca_status' => 'reported',
                'zatca_reporting_status' => $payload['data']['reporting_status'] ?? 'REPORTED',
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle invoice rejected event
     */
    private function handleInvoiceRejected(array $payload)
    {
        $masaarId = $payload['data']['invoice_id'];

        $invoice = Invoice::where('masaar_id', $masaarId)->first();

        if ($invoice) {
            $invoice->update([
                'zatca_status' => 'rejected',
                'zatca_errors' => $payload['data']['errors'] ?? [],
            ]);

            // TODO: Notify user about rejection
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle certificate expiring event
     */
    private function handleCertificateExpiring(array $payload)
    {
        $organizationId = $payload['data']['organization_id'];
        $expiryDate = $payload['data']['expiry_date'];
        $daysRemaining = $payload['data']['days_remaining'];

        Log::warning('ZATCA certificate expiring soon', [
            'organization_id' => $organizationId,
            'expiry_date' => $expiryDate,
            'days_remaining' => $daysRemaining,
        ]);

        // TODO: Notify admin about expiring certificate

        return response()->json(['success' => true]);
    }
}
```

### Routes

Add to `routes/api.php`:

```php
use App\Http\Controllers\MasaarWebhookController;

// Masaar webhooks (no auth required, verified by signature)
Route::post('/webhooks/masaar', [MasaarWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth', 'throttle']);
```

### Register Webhook with Masaar

After deployment, register webhook URL:

```bash
curl -X POST https://api.example.com/api/webhooks \
  -H "Authorization: Bearer your_access_token" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://partner.example.com/api/webhooks/masaar",
    "events": ["invoice.cleared", "invoice.reported", "invoice.rejected", "certificate.expiring"]
  }'
```

The answer carries the webhook's `secret` in `data.webhook.secret`, once; store
it as `MASAAR_WEBHOOK_SECRET`.

---

## 9. Testing & Validation

### Test Configuration

Create `tests/Feature/MasaarIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Company;
use App\Services\Masaar\InvoiceService;
use App\Services\Masaar\MasaarClient;
use Tests\TestCase;

class MasaarIntegrationTest extends TestCase
{
    /**
     * Test invoice creation and submission
     */
    public function test_can_create_and_submit_invoice()
    {
        $company = Company::factory()->create([
            'vat_number' => '300000000000003',
        ]);

        $invoice = Invoice::factory()
            ->for($company)
            ->hasItems(2)
            ->create();

        $service = app(InvoiceService::class);

        $result = $service->createAndSubmit($invoice, $company);

        $this->assertArrayHasKey('masaar_id', $result);
        $this->assertArrayHasKey('zatca_status', $result);
        $this->assertArrayHasKey('qr_code', $result);
        $this->assertNotEmpty($result['qr_code']);
    }

    /**
     * Test data mapping
     */
    public function test_invoice_data_mapping()
    {
        $company = Company::factory()->create();
        $invoice = Invoice::factory()
            ->for($company)
            ->hasItems(1)
            ->create([
                'invoice_type' => 'B2B',
                'document_type' => 'invoice',
            ]);

        $service = app(InvoiceService::class);

        // Use reflection to test private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapInvoiceData');
        $method->setAccessible(true);

        $mapped = $method->invoke($service, $invoice, $company);

        $this->assertEquals('standard', $mapped['type']);
        $this->assertEquals('invoice', $mapped['document_type']);
        $this->assertArrayHasKey('lines', $mapped);
    }
}
```

### Sandbox Testing Checklist

1. **Environment Setup**
   ```bash
   # Ensure sandbox configuration
   MASAAR_BASE_URL=https://sandbox.example.com
   ```

2. **Test Standard Invoice (B2B)**
   ```php
   $invoice = Invoice::create([
       'invoice_type' => 'B2B',
       'customer_vat_number' => '300000000000003',
       // ... other fields
   ]);
   ```

3. **Test Simplified Invoice (B2C)**
   ```php
   $invoice = Invoice::create([
       'invoice_type' => 'B2C',
       'customer_vat_number' => null,
       // ... other fields
   ]);
   ```

4. **Test Credit Note**
   ```php
   $invoice = Invoice::create([
       'document_type' => 'credit_note',
       'original_invoice_number' => 'INV-001',
       // ... other fields
   ]);
   ```

5. **Test Zero-Rated Invoice**
   ```php
   $item = [
       'vat_rate' => 0,
       'exemption_code' => 'VATEX-SA-HEA',
       'exemption_reason' => 'Healthcare services',
   ];
   ```

---

## 10. Migration Checklist

### Phase 1: Setup (Day 1-2)
- [ ] Install Masaar SDK/dependencies
- [ ] Create configuration file
- [ ] Set environment variables
- [ ] Run database migrations
- [ ] Register service provider

### Phase 2: Development (Day 3-7)
- [ ] Create MasaarClient
- [ ] Create InvoiceService
- [ ] Create OrganizationService
- [ ] Update Invoice model
- [ ] Update InvoiceController

### Phase 3: Onboarding (Day 8-10)
- [ ] Register organization in Masaar
- [ ] Obtain ZATCA OTP from Fatoora Portal
- [ ] Complete CCSID request
- [ ] Submit compliance test invoices
- [ ] Obtain PCSID for production

### Phase 4: Testing (Day 11-14)
- [ ] Test sandbox invoice creation
- [ ] Test B2B clearance flow
- [ ] Test B2C reporting flow
- [ ] Test credit/debit notes
- [ ] Test webhook handling
- [ ] Test retry mechanisms

### Phase 5: Production (Day 15+)
- [ ] Switch to production URL
- [ ] Configure production webhook
- [ ] Monitor first production invoices
- [ ] Set up error alerts

---

## 11. Troubleshooting

### Common Issues

#### 1. "Unauthorized" Error
```
HTTP 401  {"error": {"code": "UNAUTHORIZED"}}
```
**Solution**: Verify the token in `.env` and ensure no extra spaces. A token expires: renew it with `POST /api/auth/refresh`, or sign in again.

#### 2. "Organization not found"
```
Error: Organization {id} not found
```
**Solution**: Register company with Masaar first using `OrganizationService::register()`.

#### 3. "Certificate not configured"
```
Error: No valid ZATCA certificate
```
**Solution**: Complete ZATCA onboarding process via Masaar.

#### 4. "Invoice validation failed"
```
Error: BR-KSA-* validation errors
```
**Solution**: Check invoice data against ZATCA requirements:
- VAT number format (15 digits starting with 3)
- Address completeness
- Line item tax calculations

#### 5. Webhook not receiving events
**Solution**:
1. Verify webhook URL is publicly accessible
2. Check webhook secret matches
3. Verify HTTPS certificate is valid
4. Check firewall allows Masaar IPs
5. Check the licence includes `webhook_notifications` (see 6)

#### 6. "Feature not available in your license tier"
```
HTTP 403  {"error": {"code": "LICENSE_FEATURE_UNAVAILABLE", "details": {"feature": "standard_invoices"}}}
```
A licence grants the features it lists, and the API refuses anything else.
`details.feature` names the one that is missing.

| Feature | Needed to |
|---|---|
| `simplified_invoices` | create, generate or submit a simplified (B2C) invoice |
| `standard_invoices` | create, generate or submit a standard (B2B) invoice |
| `credit_notes` | issue a credit note (as well as its invoice type) |
| `debit_notes` | issue a debit note (as well as its invoice type) |
| `webhook_notifications` | manage webhooks |

By default a Starter licence includes simplified invoices only; Professional
adds standard invoices, credit and debit notes and webhooks; Enterprise adds
the rest. `GET /api/license/features/{feature}` answers whether the current
licence has one.

**Solution**: ask the licence provider to raise the tier or add the feature.

### Logging

Enable detailed logging in `.env`:

```env
LOG_LEVEL=debug
```

Check logs:
```bash
tail -f storage/logs/laravel.log | grep -i masaar
```

### Support Contacts

- **Issues**: https://github.com/shamil3ilm/masaar/issues
- **Security vulnerabilities**: see [SECURITY.md](../SECURITY.md); do not open a public issue

---

## Changes for integrators

The API now follows the official rules more closely. What it refuses, and
what to send instead:

| Refused | Rule | Send instead |
|---|---|---|
| A Saudi payment means outside ZATCA's list, such as `99` | BR-KSA-16 | A listed code; the error names them all |
| A Saudi currency outside ZATCA's ISO 4217:2005 list, such as `VES` | BR-KSA-CL-01 | A listed code; the error names them all |
| A buyer VAT number on a Saudi export | BR-KSA-46 | `buyer_id` with `buyer_id_scheme`, such as `OTH` |
| A Saudi government invoice without its contract | BR-KSA-92 | `is_government: true` with `contract_reference` |
| A UAE payment means outside PINT AE's list, such as `42` or `48` | IBR-CL-16 | One of `1`, `10`, `20`, `21`, `30`, `49`, `54`, `55`, `68`: `30` for a transfer to a bank account, `54` or `55` for a card |
| A UAE currency outside PINT AE's ISO 4217 list | IBR-CL-04 | A listed code; the error names them all |
| A till sale time more than 30 seconds in the future | BR-KSA-04 | The till's time with its UTC offset |

What is no longer refused:

- The lines of a Saudi export may carry any category and exemption code
  ZATCA accepts, such as international transport (`VATEX-SA-34-1` to
  `34-5`) and standard-rated services, not only `VATEX-SA-32` and `33`.
- A till's late sale is issued however late it is sent. A Saudi invoice is
  dated when Masaar issues it; the sale time is its supply date. Send the
  sale time as `sold_at`; `issued_at` is still accepted with the same meaning.
- Batch submission (`POST /api/v1/pipeline/batch`) needs no particular
  licence tier.

Send `H87` for a piece. `PCE` is still accepted and sent as `H87`.

---

## Appendix A: API Reference

### Invoices

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/invoices` | POST | Create invoice |
| `/api/invoices/{id}` | GET | Get invoice |
| `/api/invoices` | GET | List invoices |
| `/api/compliance/sa/generate/{invoiceId}` | POST | Issue: number, sign, hash and QR |
| `/api/compliance/sa/validate/{invoiceId}` | POST | Validate an issued invoice with ZATCA |
| `/api/compliance/sa/submit/{invoiceId}` | POST | Submit to ZATCA |
| `/api/compliance/sa/status/{invoiceId}` | GET | Check status |

### Organizations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/organizations` | POST | Register organization |
| `/api/organizations/{id}` | GET | Get organization |
| `/api/compliance/onboarding/ccsid` | POST | Request the compliance CSID (OTP) |
| `/api/compliance/onboarding/compliance-check` | POST | Run the compliance checks |
| `/api/compliance/onboarding/pcsid` | POST | Request the production CSID |
| `/api/compliance/onboarding/status` | GET | Onboarding status |

### Branches (EGS Units)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/organizations/branches` | GET | List branches |
| `/api/organizations/branches` | POST | Create branch |
| `/api/organizations/branches/{id}` | GET | Get branch |
| `/api/organizations/branches/{id}` | PUT | Update branch |
| `/api/organizations/branches/{id}` | DELETE | Delete branch |
| `/api/organizations/branches/{id}/set-default` | POST | Set as default |
| `/api/organizations/branches/{id}/onboarding/ccsid` | POST | Request CCSID |
| `/api/organizations/branches/{id}/onboarding/compliance-check` | POST | Run compliance check |
| `/api/organizations/branches/{id}/onboarding/pcsid` | POST | Request PCSID |
| `/api/organizations/branches/{id}/onboarding/reset` | POST | Reset onboarding |

### Webhooks

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/webhooks` | POST | Register webhook |
| `/api/webhooks` | GET | List webhooks |
| `/api/webhooks/{id}` | DELETE | Delete webhook |

---

## Appendix B: Multi-Currency Support

Per ZATCA official guidelines, foreign currency invoices are supported:

| Field | Required | Description |
|-------|----------|-------------|
| `currency` | Yes | Document currency (SAR, USD, EUR, etc.) |
| `exchange_rate` | If non-SAR | Exchange rate to SAR |

**ZATCA Requirements:**
- `DocumentCurrencyCode` (BT-5): Can be foreign currency
- `TaxCurrencyCode` (BT-6): Always SAR for VAT reporting
- Two `TaxTotal` elements: BT-110 (doc currency), BT-111 (SAR)

**Example:**
```php
$invoiceData = [
    'currency' => 'USD',
    'exchange_rate' => 3.75,
    'lines' => [['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000.00, 'tax_rate' => 15]],
    // Computed by Masaar: subtotal 1000.00 USD, VAT 150.00 USD (562.50 SAR), total 1150.00 USD.
];
```

---

## Appendix C: ZATCA Error Codes

Some of the rules ZATCA's validator applies, as its Schematron states them.
Masaar refuses most of them before an invoice is drafted, naming the rule in
`error.details`.

| Code | Rule | Resolution |
|------|------|------------|
| BR-KSA-04 | The issue date is not after today | Masaar dates an invoice when it issues it |
| BR-KSA-07 | An export is not self-billed | Send `is_export` or `is_self_billed`, not both |
| BR-KSA-16 | The payment means is a UNTDID 4461 code ZATCA accepts | Send one of 1-68, 70, 74-78, 91-97 or ZZZ, e.g. `10` cash, `30` credit transfer |
| BR-KSA-17 | A credit or debit note gives its reason | Send `adjustment_reason` |
| BR-KSA-31 | A simplified invoice is not self-billed or an export | Send exports and self-billed invoices as `standard` |
| BR-KSA-40 | The seller VAT number is 15 digits, starting and ending with 3 | Correct the organization's VAT number |
| BR-KSA-46 | An export names no buyer VAT number | Identify the buyer by `buyer_id` with `buyer_id_scheme`, e.g. `OTH` |
| BR-KSA-92 | A government (B2G) invoice names its contract | Send `contract_reference` with `is_government` |
| BR-KSA-CL-01 | The currency is an ISO 4217:2005 code | The error lists the codes ZATCA takes |

---

## Appendix D: Prices Including VAT

Unit prices can be sent with or without VAT in them.

| Field | Where | Description |
|-------|-------|-------------|
| `prices_include_vat` | Organization (`PUT /api/organizations/{id}`) | The default for its invoices. `false` unless set. |
| `prices_include_vat` | Invoice | Overrides the organization's default for this invoice. Applies to `unit_price` and `discount_amount`. |

With VAT included, the customer's figures are the ones kept exact: each line's total and the invoice total are what was sent. The net ZATCA requires is backed out of them, per tax category.

```php
// Three at 10.00, VAT included: the customer pays 30.00.
'prices_include_vat' => true,
'lines' => [['description' => 'Widget', 'quantity' => 3, 'unit_price' => 10.00, 'tax_rate' => 15]],
// Stored and returned: subtotal 26.09, tax_amount 3.91, total 30.00.
// The line's unit_price is the net (8.70) and net_amount the exact net (26.09).
```

The VAT is the fraction 15/115 of the VAT-inclusive amount, as in ZATCA's Guideline for Tax Invoicing and Records (section 4.2.4, Example 6): 1,255.00 including VAT is 163.70 VAT on 1,091.30.

Occasionally no rounded net and rounded VAT add up to the gross. A single item at 10.00 is one case: a net of 8.70 with its own VAT is 10.01. The total stays 10.00 and the VAT is 1.30. That halalah is within the tolerance ZATCA's validation allows for a category's VAT (one halalah per invoice line), so the invoice is accepted without errors or warnings.

On a printed receipt, an invoice priced this way heads its price column "Unit price (incl. VAT)", as in the model simplified invoice ZATCA publishes.

---

*Document Version: 1.1*
*Masaar API Version: v1*

**Changelog:**
- v1.1: Added multi-branch EGS support, foreign currency invoices, branch API endpoints
