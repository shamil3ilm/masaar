# CompliPay - ZATCA E-Invoicing API

CompliPay is a production-ready API platform for ZATCA-compliant e-invoicing in Saudi Arabia. It provides complete Phase 2 compliance including invoice clearance, reporting, digital signatures, and QR code generation.

## Features

- **ZATCA Phase 2 Compliance** - Full support for clearance (B2B) and reporting (B2C)
- **UBL 2.1 XML Generation** - Standards-compliant invoice XML
- **Digital Signatures** - XAdES-BES enveloped signatures with ECDSA (secp256k1)
- **QR Code Generation** - TLV-encoded QR codes (9 tags for Phase 2)
- **Invoice Hash Chain** - SHA-256 hash with Previous Invoice Hash (PIH) linking
- **Multi-tenant Architecture** - Organization-scoped data isolation
- **Dual Authentication** - JWT for users, API keys for server-to-server
- **Webhooks** - Real-time notifications for invoice status changes
- **Rate Limiting** - 60 requests/minute with headers

## Requirements

- PHP 8.4+
- Laravel 12
- MySQL 8.0+ / PostgreSQL 13+
- OpenSSL extension
- Redis (optional, for queues/caching)

## Installation

```bash
# Clone repository
git clone https://github.com/your-org/complipay.git
cd complipay

# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Configure database in .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=complipay
DB_USERNAME=root
DB_PASSWORD=

# Run migrations
php artisan migrate

# Generate JWT secret
php artisan jwt:secret
```

## Configuration

### Environment Variables

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.complipay.sa

# ZATCA API
ZATCA_BASE_URL=https://gw-fatoora.zatca.gov.sa/e-invoicing/core
ZATCA_SANDBOX=false

# JWT Authentication
JWT_SECRET=your-256-bit-secret
JWT_TTL=60

# CORS (comma-separated domains)
CORS_ALLOWED_ORIGINS=https://app.client.com,https://crm.client.com

# Queue (for webhooks)
QUEUE_CONNECTION=redis
```

## API Authentication

### Method 1: JWT Token (User Login)

```bash
# Login
curl -X POST https://api.complipay.sa/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "user@company.com", "password": "password"}'

# Response: {"data": {"token": {"access_token": "eyJ..."}}}

# Use token in subsequent requests
curl https://api.complipay.sa/api/invoices \
  -H "Authorization: Bearer eyJ..."
```

### Method 2: API Key (Server-to-Server)

```bash
# Create API key (requires JWT auth first)
curl -X POST https://api.complipay.sa/api/api-keys \
  -H "Authorization: Bearer eyJ..." \
  -H "Content-Type: application/json" \
  -d '{"name": "CRM Integration", "scopes": ["*"]}'

# Response: {"data": {"api_key": {"key": "cpk_abc123_xxxxx..."}}}

# Use API key for server-to-server calls
curl https://api.complipay.sa/v1/invoices \
  -H "X-API-Key: cpk_abc123_xxxxx..."
```

## API Endpoints

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register new user |
| POST | `/api/auth/login` | Login and get JWT |
| POST | `/api/auth/refresh` | Refresh JWT token |
| POST | `/api/auth/logout` | Invalidate token |
| GET | `/api/auth/me` | Get current user |

### Invoices
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/invoices` | List invoices |
| POST | `/api/invoices` | Create invoice |
| GET | `/api/invoices/{id}` | Get invoice |
| PUT | `/api/invoices/{id}` | Update draft invoice |
| DELETE | `/api/invoices/{id}` | Delete draft invoice |

### ZATCA Compliance
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/compliance/zatca/generate/{id}` | Generate XML, hash, QR |
| POST | `/api/compliance/zatca/validate/{id}` | Validate with ZATCA |
| POST | `/api/compliance/zatca/submit/{id}` | Submit to ZATCA |
| GET | `/api/compliance/zatca/status/{id}` | Get submission status |

### ZATCA Onboarding
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/compliance/onboarding/status` | Check onboarding status |
| POST | `/api/compliance/onboarding/ccsid` | Request Compliance CSID |
| POST | `/api/compliance/onboarding/compliance-check` | Run compliance tests |
| POST | `/api/compliance/onboarding/pcsid` | Request Production CSID |

### Webhooks
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/webhooks` | List webhooks |
| POST | `/api/webhooks` | Create webhook |
| GET | `/api/webhooks/events` | List available events |
| POST | `/api/webhooks/{id}/test` | Send test webhook |
| POST | `/api/webhooks/{id}/rotate-secret` | Rotate secret |

### API Keys
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/api-keys` | List API keys |
| POST | `/api/api-keys` | Create API key |
| GET | `/api/api-keys/scopes` | List available scopes |
| DELETE | `/api/api-keys/{id}` | Revoke API key |

## CRM Integration Guide

### Quick Start

```php
<?php
// PHP SDK Example for CRM Integration

class CompliPayClient
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.complipay.sa')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
    }

    public function createInvoice(array $data): array
    {
        return $this->request('POST', '/v1/invoices', $data);
    }

    public function submitToZatca(string $invoiceId): array
    {
        return $this->request('POST', "/v1/compliance/submit/{$invoiceId}");
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', "/v1/invoices/{$invoiceId}");
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}

// Usage
$client = new CompliPayClient('cpk_your_api_key_here');

// Create invoice
$invoice = $client->createInvoice([
    'invoice_number' => 'INV-2026-001',
    'type' => 'standard', // B2B
    'buyer_name' => 'Customer Company',
    'buyer_vat_number' => '300000000000003',
    'issue_date' => '2026-01-30',
    'lines' => [
        [
            'description' => 'Consulting Services',
            'quantity' => 10,
            'unit_price' => 500.00,
            'tax_rate' => 15,
        ],
    ],
]);

// Submit to ZATCA
$result = $client->submitToZatca($invoice['data']['invoice']['id']);

// Get QR code for printing
$invoiceDetails = $client->getInvoice($invoice['data']['invoice']['id']);
$qrCode = $invoiceDetails['data']['invoice']['qr_code'];
```

### Webhook Integration

CompliPay sends webhook notifications when invoice status changes.

```php
// Webhook receiver example
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];
$secret = 'your_webhook_secret';

// Verify signature
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($payload, true);

switch ($event['event']) {
    case 'invoice.cleared':
        // B2B invoice cleared by ZATCA
        updateInvoiceStatus($event['data']['invoice_id'], 'cleared');
        break;

    case 'invoice.reported':
        // B2C invoice reported to ZATCA
        updateInvoiceStatus($event['data']['invoice_id'], 'reported');
        break;

    case 'invoice.rejected':
        // Invoice rejected - handle errors
        notifyAdmin($event['data']['errors']);
        break;
}

http_response_code(200);
```

### Available Webhook Events

| Event | Description |
|-------|-------------|
| `invoice.created` | Invoice created |
| `invoice.updated` | Invoice updated |
| `invoice.issued` | Invoice issued (ready for submission) |
| `invoice.submitted` | Invoice sent to ZATCA |
| `invoice.cleared` | B2B invoice cleared |
| `invoice.reported` | B2C invoice reported |
| `invoice.rejected` | Invoice rejected by ZATCA |

## ZATCA Onboarding Flow

Before submitting invoices, organizations must complete ZATCA onboarding:

```
+-------------------------------------------------------------+
|                    ZATCA Onboarding                         |
+-------------------------------------------------------------+
|                                                             |
|  1. Get OTP from ZATCA Portal (valid 1 hour)               |
|     --> https://fatoora.zatca.gov.sa                       |
|                                                             |
|  2. Request Compliance CSID (CCSID)                        |
|     --> POST /api/compliance/onboarding/ccsid              |
|         Body: { otp, common_name, serial_number }          |
|                                                             |
|  3. Run Compliance Checks                                   |
|     --> POST /api/compliance/onboarding/compliance-check   |
|         (Auto-generates and submits test invoices)         |
|                                                             |
|  4. Request Production CSID (PCSID)                        |
|     --> POST /api/compliance/onboarding/pcsid              |
|                                                             |
|  [OK] Organization is now ZATCA compliant                  |
|                                                             |
+-------------------------------------------------------------+
```

## Invoice Types

| Type | Code | Description | ZATCA Action |
|------|------|-------------|--------------|
| Standard (B2B) | `01` | Business-to-business | Clearance required |
| Simplified (B2C) | `02` | Business-to-consumer | Reporting only |

## Document Types

| Type | Code | Description |
|------|------|-------------|
| Invoice | `388` | Standard tax invoice |
| Debit Note | `383` | Additional charges |
| Credit Note | `381` | Refunds/corrections |

## Response Format

All API responses follow a consistent format:

```json
// Success
{
  "success": true,
  "message": "Operation completed",
  "data": { ... }
}

// Error
{
  "success": false,
  "error": {
    "message": "Validation failed",
    "code": "VALIDATION_ERROR",
    "details": { ... }
  }
}

// Paginated
{
  "success": true,
  "data": [...],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 15,
    "total": 150
  }
}
```

## Rate Limiting

- **Limit**: 60 requests per minute
- **Headers**: `X-RateLimit-Limit`, `X-RateLimit-Remaining`
- **Error**: 429 Too Many Requests

## API Documentation

Full OpenAPI 3.0 specification available at:
- **File**: `/docs/openapi.yaml`
- **Swagger UI**: Import the YAML into https://editor.swagger.io

## Production Deployment

```bash
# Optimize for production
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
php artisan migrate --force

# Start queue worker (for webhooks)
php artisan queue:work --daemon
```

### Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name api.complipay.sa;
    root /var/www/complipay/public;

    ssl_certificate /etc/ssl/certs/complipay.crt;
    ssl_certificate_key /etc/ssl/private/complipay.key;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Testing

```bash
# Run tests
php artisan test

# Test specific domain
php artisan test --filter=ZatcaComplianceTest
```

## Architecture

```
app/
├── Domains/
│   ├── Auth/              # Authentication (JWT, API Keys)
│   ├── Compliance/
│   │   └── Zatca/         # ZATCA compliance services
│   │       ├── Client/    # ZATCA API client
│   │       ├── DTOs/      # Data transfer objects
│   │       ├── Services/  # Business logic
│   │       └── Exceptions/
│   ├── Invoice/           # Invoice management
│   ├── Organization/      # Multi-tenant organizations
│   └── Webhook/           # Webhook subscriptions
├── Http/
│   ├── Controllers/Api/   # API controllers
│   ├── Middleware/        # JWT, API Key, Rate limiting
│   └── Responses/         # Standardized responses
```

## Support

- **Documentation**: https://docs.complipay.sa
- **API Status**: https://status.complipay.sa
- **Email**: support@complipay.sa

## License

MIT License - see [LICENSE](LICENSE) for details.
