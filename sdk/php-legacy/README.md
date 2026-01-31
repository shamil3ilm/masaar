# CompliPay PHP SDK

ZATCA-compliant e-invoicing API client for PHP 7.4+

Compatible with Laravel 8, 9, 10, 11, 12 and any PHP application.

> **Important**: By using this SDK, you agree to the CompliPay [Terms of Use](../../TERMS.md) and [License](../../LICENSE). Commercial use requires [registration](../../README.md#registration).

## Installation

```bash
composer require complipay/complipay-php
```

## Server URLs

| Environment | Base URL |
|-------------|----------|
| **Local Development** | `http://localhost:8000` |
| **Local (Laragon)** | `http://zatca.test` |
| **Production** | `https://{YOUR_DOMAIN}` |

> **Note:** Replace `{YOUR_DOMAIN}` with your actual domain when deploying to production.

## Quick Start

```php
<?php

use CompliPay\CompliPayClient;
use CompliPay\InvoiceLine;

// For local development
$client = new CompliPayClient([
    'base_url' => 'http://localhost:8000',  // Your server URL
    'api_key' => 'your_api_key',
]);

// For production, use your deployed server URL:
// $client = new CompliPayClient([
//     'base_url' => 'https://your-domain.com',
//     'api_key' => 'your_api_key',
// ]);

// Create an invoice
$invoice = $client->invoices->create(
    'INV-001',                          // Invoice number
    'Acme Corporation',                 // Buyer name
    [                                   // Line items
        new InvoiceLine(
            'Consulting Services',      // Description
            10,                         // Quantity
            100.00,                     // Unit price
            15.0                        // Tax rate (15% VAT)
        ),
    ],
    'standard',                         // Type
    '300000000000003'                   // Buyer VAT number
);

// Generate compliance data (hash, QR code)
$client->compliance->generate($invoice['data']['id']);

// Submit to ZATCA
$result = $client->compliance->submit($invoice['data']['id']);
echo "ZATCA Status: " . $result['data']['status'];
```

## Laravel 8+ Integration

### Configuration

Add to your `config/services.php`:

```php
// config/services.php
'complipay' => [
    'url' => env('COMPLIPAY_URL', 'http://localhost:8000'),
    'key' => env('COMPLIPAY_API_KEY'),
],
```

Add to your `.env`:

```env
# Local development
COMPLIPAY_URL=http://localhost:8000
COMPLIPAY_API_KEY=your_api_key

# Production (update when deployed)
# COMPLIPAY_URL=https://your-domain.com
# COMPLIPAY_API_KEY=your_production_api_key
```

### Service Provider (Optional)

```php
// app/Providers/CompliPayServiceProvider.php
<?php

namespace App\Providers;

use CompliPay\CompliPayClient;
use Illuminate\Support\ServiceProvider;

class CompliPayServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(CompliPayClient::class, function ($app) {
            return new CompliPayClient([
                'base_url' => config('services.complipay.url'),
                'api_key' => config('services.complipay.key'),
            ]);
        });
    }
}
```

### Controller Usage

```php
<?php

namespace App\Http\Controllers;

use CompliPay\CompliPayClient;
use CompliPay\InvoiceLine;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    private $complipay;

    public function __construct(CompliPayClient $complipay)
    {
        $this->complipay = $complipay;
    }

    public function store(Request $request)
    {
        $invoice = $this->complipay->invoices->create(
            $request->input('invoice_number'),
            $request->input('buyer_name'),
            array_map(function ($line) {
                return new InvoiceLine(
                    $line['description'],
                    $line['quantity'],
                    $line['unit_price'],
                    $line['tax_rate'] ?? 15.0
                );
            }, $request->input('lines')),
            $request->input('type', 'standard'),
            $request->input('buyer_vat_number')
        );

        // Generate and submit to ZATCA
        $this->complipay->compliance->generate($invoice['data']['id']);
        $this->complipay->compliance->submit($invoice['data']['id']);

        return response()->json($invoice);
    }
}
```

## Tax Categories

```php
// Standard rated (15% VAT)
new InvoiceLine('Service', 1, 100.0, 15.0, 'S');

// Zero rated (requires exemption reason)
new InvoiceLine('Export Service', 1, 100.0, 0.0, 'Z', 'PCE', 'VATEX-SA-32', 'Export of services');

// Exempt (requires exemption reason)
new InvoiceLine('Healthcare', 1, 100.0, 0.0, 'E', 'PCE', 'VATEX-SA-HEA', 'Healthcare service');

// Out of scope
new InvoiceLine('Out of scope item', 1, 100.0, 0.0, 'O', 'PCE', 'VATEX-SA-OOS', 'Out of VAT scope');
```

## Credit Notes

```php
$creditNote = $client->invoices->createCreditNote(
    'CN-001',                           // Credit note number
    'Acme Corporation',                 // Buyer name
    [new InvoiceLine('Refund', 1, 50.0)],
    'original-invoice-uuid',            // Reference to original invoice
    'Partial refund for returned item', // Reason
    '300000000000003'                   // Buyer VAT
);
```

## Webhooks

```php
// Register webhook
$webhook = $client->webhooks->create(
    'https://your-app.com/webhooks/zatca',
    ['invoice.cleared', 'invoice.rejected'],
    'your_webhook_secret'
);

// Verify webhook signature in your controller
use CompliPay\WebhooksResource;

public function handleWebhook(Request $request)
{
    $signature = $request->header('X-Signature');
    $payload = $request->getContent();

    if (!WebhooksResource::verifySignature($payload, $signature, 'your_secret')) {
        return response('Invalid signature', 401);
    }

    $event = $request->json();

    switch ($event['type']) {
        case 'invoice.cleared':
            // Handle cleared invoice
            break;
        case 'invoice.rejected':
            // Handle rejection
            break;
    }

    return response('OK', 200);
}
```

## Error Handling

```php
use CompliPay\AuthenticationException;
use CompliPay\ValidationException;
use CompliPay\ZatcaException;
use CompliPay\CompliPayException;

try {
    $invoice = $client->invoices->create(...);
    $client->compliance->submit($invoice['data']['id']);
} catch (AuthenticationException $e) {
    // Invalid API key
    Log::error('Invalid API key');
} catch (ValidationException $e) {
    // Validation errors
    Log::error('Validation failed', ['errors' => $e->getErrors()]);
} catch (ZatcaException $e) {
    // ZATCA rejected the invoice
    Log::error('ZATCA rejected', ['errors' => $e->getErrors()]);
} catch (CompliPayException $e) {
    // General API error
    Log::error('API error: ' . $e->getMessage());
}
```

## Requirements

- PHP 7.4 or higher
- cURL extension
- JSON extension

## Legal

By using this SDK, you agree to:

- [Terms of Use](../../TERMS.md) - Acceptable use policy
- [License](../../LICENSE) - Controlled Open Source License (COSL)
- [Security Policy](../../SECURITY.md) - Security requirements

**Commercial use requires registration.** See [Registration](../../README.md#registration).

## License

Controlled Open Source License (COSL) - See [LICENSE](../../LICENSE)
