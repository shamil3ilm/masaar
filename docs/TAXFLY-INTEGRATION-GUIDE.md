# TaxFly + Masaar Integration Guide

Complete guide for integrating Masaar ZATCA E-Invoicing API into TaxFly's existing system.

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

---

## 1. Overview

### Current TaxFly Status
- **Phase 1**: Partial (QR generation exists but incomplete TLV encoding)
- **Phase 2**: Not Ready (missing XAdES-BES signing, ZATCA API integration)

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
Replace TaxFly's partial ZATCA implementation with Masaar API calls while keeping TaxFly's existing invoice management UI and business logic.

> **Advanced Scenarios**: For multi-business, multi-branch, and cross-border transactions, see [TAXFLY-ADVANCED-SCENARIOS.md](./TAXFLY-ADVANCED-SCENARIOS.md).

---

## 2. Prerequisites

### System Requirements
- PHP 7.4+ (TaxFly uses Laravel 8)
- OpenSSL extension
- cURL extension
- JSON extension

### Required Credentials
1. **Masaar API Key** - Obtain from Masaar registration
2. **ZATCA OTP** - From ZATCA Fatoora Portal for onboarding
3. **Organization VAT Number** - Valid Saudi VAT registration

### Masaar Endpoints

| Environment | Base URL |
|-------------|----------|
| Sandbox | `https://sandbox.masaar.sa` |
| Simulation | `https://simulation.masaar.sa` |
| Production | `https://api.masaar.sa` |

---

## 3. Installation & Setup

### Step 1: Install HTTP Client

TaxFly already has Guzzle, but ensure it's up to date:

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

    'base_url' => env('MASAAR_BASE_URL', 'https://sandbox.masaar.sa'),

    'api_key' => env('MASAAR_API_KEY'),

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
    | Map TaxFly company IDs to Masaar organization IDs
    */

    'organization_map' => [
        // 'taxfly_company_id' => 'masaar_organization_id',
    ],
];
```

### Step 3: Add Environment Variables

Add to `.env`:

```env
# Masaar Configuration
MASAAR_BASE_URL=https://sandbox.masaar.sa
MASAAR_API_KEY=your_api_key_here
MASAAR_WEBHOOK_SECRET=your_webhook_secret_here
MASAAR_TIMEOUT=30
```

---

## 4. API Authentication

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
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('masaar.base_url');
        $this->apiKey = config('masaar.api_key');

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('masaar.timeout', 30),
            'headers' => [
                'X-API-Key' => $this->apiKey,
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
                $errorBody['message'] ?? $lastException->getMessage(),
                $lastException->getResponse()->getStatusCode(),
                $errorBody['errors'] ?? []
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
        // Step 1: Map TaxFly invoice to Masaar format
        $invoiceData = $this->mapInvoiceData($invoice, $company);

        // Step 2: Create invoice in Masaar
        $created = $this->client->post('/api/invoices', $invoiceData);

        Log::info('Invoice created in Masaar', [
            'taxfly_invoice_id' => $invoice->id,
            'masaar_invoice_id' => $created['id'],
        ]);

        // Step 3: Generate compliance data (XML, hash, QR)
        $compliance = $this->client->post(
            "/api/compliance/zatca/generate/{$created['id']}"
        );

        // Step 4: Submit to ZATCA
        $submission = $this->client->post(
            "/api/compliance/zatca/submit/{$created['id']}"
        );

        return [
            'masaar_id' => $created['id'],
            'zatca_status' => $submission['status'],
            'clearance_status' => $submission['clearance_status'] ?? null,
            'reporting_status' => $submission['reporting_status'] ?? null,
            'xml' => $compliance['xml'],
            'hash' => $compliance['hash'],
            'qr_code' => $compliance['qr_code'],
            'warnings' => $submission['warnings'] ?? [],
            'errors' => $submission['errors'] ?? [],
        ];
    }

    /**
     * Map TaxFly invoice format to Masaar format
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

            // Totals (calculated by Masaar, but can be provided)
            'subtotal' => (float) $invoice->subtotal,
            'discount_amount' => (float) ($invoice->discount ?? 0),
            'tax_amount' => (float) $invoice->vat_amount,
            'total' => (float) $invoice->total,

            // Payment
            'payment_means_code' => $this->mapPaymentMethod($invoice),

            // References (for credit/debit notes)
            'billing_reference_id' => $invoice->original_invoice_number,

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
        // TaxFly uses invoice_type field
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
        // Check TaxFly's document type field
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

        // TaxFly may store address as string or structured
        if (is_string($invoice->customer_address)) {
            return [
                'street' => $invoice->customer_address,
                'city' => $invoice->customer_city ?? 'Riyadh',
                'postal_code' => $invoice->customer_postal_code ?? '00000',
                'country_code' => 'SA',
            ];
        }

        return [
            'street' => $invoice->customer_address['street'] ?? '',
            'building_number' => $invoice->customer_address['building'] ?? '',
            'additional_number' => $invoice->customer_address['additional'] ?? '',
            'district' => $invoice->customer_address['district'] ?? '',
            'city' => $invoice->customer_address['city'] ?? 'Riyadh',
            'postal_code' => $invoice->customer_address['postal_code'] ?? '00000',
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
                'unit_code' => $item->unit ?? 'PCE',
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => (float) ($item->vat_rate ?? 15),
                'tax_category' => $this->mapTaxCategory($item),
                'tax_exemption_code' => $item->exemption_code,
                'tax_exemption_reason' => $item->exemption_reason,
                'discount' => (float) ($item->discount ?? 0),
            ];
        })->toArray();
    }

    /**
     * Map tax category
     */
    private function mapTaxCategory($item): string
    {
        $rate = (float) ($item->vat_rate ?? 15);

        if ($rate > 0) {
            return 'S'; // Standard rated
        }

        // Check for exemption codes
        if (!empty($item->exemption_code)) {
            if (str_starts_with($item->exemption_code, 'VATEX-SA-OOS')) {
                return 'O'; // Out of scope
            }
            if (str_contains($item->exemption_code, 'HEA') ||
                str_contains($item->exemption_code, 'EDU')) {
                return 'Z'; // Zero-rated
            }
            return 'E'; // Exempt
        }

        return 'Z'; // Default zero-rated for 0%
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
        return $this->client->get("/api/compliance/zatca/status/{$masaarId}");
    }

    /**
     * Validate invoice before submission
     */
    public function validate(string $masaarId): array
    {
        return $this->client->post("/api/compliance/zatca/validate/{$masaarId}");
    }
}
```

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
        return $this->client->post('/api/organizations', [
            'name' => $company->name,
            'name_ar' => $company->name_ar ?? $company->name,
            'vat_number' => $company->vat_number,
            'cr_number' => $company->cr_number,
            'address' => [
                'street' => $company->street,
                'building_number' => $company->building_number,
                'additional_number' => $company->additional_number,
                'district' => $company->district,
                'city' => $company->city,
                'postal_code' => $company->postal_code,
                'country_code' => 'SA',
            ],
        ]);
    }

    /**
     * Start ZATCA onboarding process
     */
    public function startOnboarding(string $organizationId, string $otp): array
    {
        return $this->client->post("/api/organizations/{$organizationId}/onboarding", [
            'otp' => $otp,
        ]);
    }

    /**
     * Complete compliance check
     */
    public function completeCompliance(string $organizationId): array
    {
        return $this->client->post(
            "/api/organizations/{$organizationId}/onboarding/complete-compliance"
        );
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

        // Create invoice in TaxFly database
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
        $signature = $request->header('X-Masaar-Signature');
        $secret = config('masaar.webhook.secret');

        if (!$signature || !$secret) {
            return false;
        }

        $expectedSignature = hash_hmac(
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
curl -X POST https://api.masaar.sa/api/webhooks \
  -H "X-API-Key: your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://your-taxfly-domain.com/api/webhooks/masaar",
    "events": ["invoice.cleared", "invoice.reported", "invoice.rejected", "certificate.expiring"],
    "secret": "your_webhook_secret"
  }'
```

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
   MASAAR_BASE_URL=https://sandbox.masaar.sa
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

#### 1. "Invalid API Key" Error
```
Error: Authentication failed
```
**Solution**: Verify API key in `.env` and ensure no extra spaces.

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

- **Masaar Technical Support**: support@masaar.sa
- **Documentation**: https://docs.masaar.sa
- **API Status**: https://status.masaar.sa

---

## Appendix A: API Reference

### Invoices

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/invoices` | POST | Create invoice |
| `/api/invoices/{id}` | GET | Get invoice |
| `/api/invoices` | GET | List invoices |
| `/api/compliance/zatca/generate/{id}` | POST | Generate compliance data |
| `/api/compliance/zatca/validate/{id}` | POST | Validate invoice |
| `/api/compliance/zatca/submit/{id}` | POST | Submit to ZATCA |
| `/api/compliance/zatca/status/{id}` | GET | Check status |

### Organizations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/organizations` | POST | Register organization |
| `/api/organizations/{id}` | GET | Get organization |
| `/api/organizations/{id}/onboarding` | POST | Start onboarding |
| `/api/organizations/{id}/onboarding/complete-compliance` | POST | Complete compliance |

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
| `exchange_rate_date` | Recommended | Date of exchange rate |

**ZATCA Requirements:**
- `DocumentCurrencyCode` (BT-5): Can be foreign currency
- `TaxCurrencyCode` (BT-6): Always SAR for VAT reporting
- Two `TaxTotal` elements: BT-110 (doc currency), BT-111 (SAR)

**Example:**
```php
$invoiceData = [
    'currency' => 'USD',
    'exchange_rate' => 3.75,
    'subtotal' => 1000.00,  // USD
    'tax_amount' => 150.00, // USD - converted to 562.50 SAR for VAT
    'total' => 1150.00,
];
```

---

## Appendix C: ZATCA Error Codes

| Code | Description | Resolution |
|------|-------------|------------|
| BR-KSA-01 | Invalid VAT number | Ensure 15 digits, starts with 3 |
| BR-KSA-02 | Missing seller address | Provide complete address |
| BR-KSA-08 | Invalid ICV | ICV must be sequential |
| BR-KSA-31 | Tax calculation error | Verify line item totals |
| BR-KSA-40 | Invalid QR code | Re-generate through Masaar |
| BR-KSA-52 | Certificate expired | Renew ZATCA certificate |

---

*Document Version: 1.1*
*Last Updated: 2026-02-03*
*Masaar API Version: v1*

**Changelog:**
- v1.1: Added multi-branch EGS support, foreign currency invoices, branch API endpoints
