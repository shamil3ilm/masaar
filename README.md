# CompliPay - ZATCA E-Invoicing API Platform

A production-ready ZATCA Phase 2 compliant e-invoicing API platform for Saudi Arabia, built with Laravel 12 and PHP 8.4.

> **Important**: By using this software, you agree to our [Terms of Use](TERMS.md) and [License](LICENSE). Commercial use requires [registration](#registration).

[![License: COSL](https://img.shields.io/badge/License-COSL-blue.svg)](LICENSE)
[![Security Policy](https://img.shields.io/badge/Security-Policy-green.svg)](SECURITY.md)

## Features

- **ZATCA Phase 2 Compliance** - Full support for clearance (B2B) and reporting (B2C)
- **UBL 2.1 XML Generation** - Compliant invoice XML with all required elements
- **XAdES-BES Digital Signatures** - ECDSA secp256k1 cryptographic signing
- **QR Code Generation** - TLV-encoded QR codes (9 tags for Phase 2)
- **Multi-tenant Architecture** - Organization-scoped data isolation
- **Dual Authentication** - JWT tokens and API keys for server-to-server
- **Webhook Notifications** - Real-time event notifications with HMAC signatures
- **Comprehensive Validation** - ZATCA business rules (BR-KSA-*) enforcement
- **Multi-Language SDKs** - Python, TypeScript/JS, PHP, Java, Go, Ruby, .NET, Kotlin, Dart, Swift, Rust
- **Admin Dashboard** - Web-based admin portal for monitoring and management
- **Offline Queue** - Resilient offline queue for POS/retail scenarios

## Requirements

- PHP 8.4+
- Laravel 12
- OpenSSL extension (for ECDSA signing)
- MySQL 8.0+ or PostgreSQL 15+

## Local Development Setup

### 1. Clone and Install

```bash
# Clone the repository
git clone <your-repository-url>
cd Zatca

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 2. Configure Database

Edit `.env` file:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zatca
DB_USERNAME=root
DB_PASSWORD=

# For SQLite (simpler for testing)
# DB_CONNECTION=sqlite
# DB_DATABASE=/absolute/path/to/database.sqlite
```

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Start Development Server

```bash
php artisan serve
```

Your API is now running at: **http://localhost:8000**

### 5. Test the API

```bash
# Health check
curl http://localhost:8000/api/health

# Register a user
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name": "Test User", "email": "test@example.com", "password": "password123"}'
```

## Configuration

Add these to your `.env` file:

```env
# ZATCA Environment (sandbox, simulation, production)
ZATCA_ENVIRONMENT=sandbox

# ZATCA API Credentials (obtained after onboarding)
ZATCA_USERNAME=
ZATCA_PASSWORD=

# CORS - Replace with your client application URLs
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8080
```

## API Base URLs

| Environment | Base URL |
|-------------|----------|
| **Local Development** | `http://localhost:8000` |
| **Local (Laragon)** | `http://zatca.test` |
| **Local (Valet)** | `http://zatca.test` |
| **Production** | `https://{YOUR_DOMAIN}` |

> **Note:** Replace `{YOUR_DOMAIN}` with your actual domain when deploying to production.

## API Endpoints

### Authentication

```bash
# Register
POST {BASE_URL}/api/auth/register

# Login (returns JWT token)
POST {BASE_URL}/api/auth/login

# Get API Key (for server-to-server)
POST {BASE_URL}/api/api-keys
```

### Invoices

```bash
# Create invoice
POST {BASE_URL}/api/invoices
Authorization: Bearer {jwt_token}

# List invoices
GET {BASE_URL}/api/invoices

# Get invoice
GET {BASE_URL}/api/invoices/{id}
```

### ZATCA Compliance

```bash
# Generate compliance data (hash, QR code)
POST {BASE_URL}/api/compliance/zatca/generate/{invoiceId}

# Validate with ZATCA (without submission)
POST {BASE_URL}/api/compliance/zatca/validate/{invoiceId}

# Submit to ZATCA
POST {BASE_URL}/api/compliance/zatca/submit/{invoiceId}

# Check status
GET {BASE_URL}/api/compliance/zatca/status/{invoiceId}
```

## Client Integration

### Using API Keys

```bash
# Replace {BASE_URL} with your server URL
# Local: http://localhost:8000
# Production: https://your-domain.com

curl -X POST {BASE_URL}/v1/invoices \
  -H "X-API-Key: your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_number": "INV-001",
    "type": "standard",
    "buyer_name": "Acme Corp",
    "buyer_vat_number": "300000000000003",
    "lines": [
      {
        "description": "Consulting Services",
        "quantity": 10,
        "unit_price": 100.00,
        "tax_rate": 15
      }
    ]
  }'
```

### Webhooks

```bash
POST {BASE_URL}/api/webhooks
{
  "url": "https://your-app.com/webhooks/zatca",
  "events": ["invoice.cleared", "invoice.rejected"],
  "secret": "your_webhook_secret"
}
```

Events include:
- `invoice.created`, `invoice.updated`, `invoice.issued`
- `invoice.submitted`, `invoice.cleared`, `invoice.reported`
- `invoice.rejected`, `invoice.cancelled`

## Multi-Language SDKs

SDKs are available in the `sdk/` directory for easy integration:

| SDK | Location | Compatibility |
|-----|----------|---------------|
| **Python** | `sdk/python/` | Python 3.7+, Django, Flask, FastAPI |
| **TypeScript/JS** | `sdk/typescript/` | Node.js 14+, React, Vue, Angular |
| **PHP Legacy** | `sdk/php-legacy/` | PHP 7.4+, Laravel 8/9/10/11/12 |
| **Java** | `sdk/java/` | Java 11+, Spring Boot |
| **Go** | `sdk/go/` | Go 1.18+ with generics |
| **Ruby** | `sdk/ruby/` | Ruby 2.7+, Rails |
| **.NET** | `sdk/dotnet/` | C# 12, .NET 8+ |
| **Kotlin** | `sdk/kotlin/` | Kotlin 1.9+, Android |
| **Dart** | `sdk/dart/` | Dart 3+, Flutter |
| **Swift** | `sdk/swift/` | Swift 5.9+, iOS/macOS |
| **Rust** | `sdk/rust/` | Rust 1.70+ |

### Python Example

```python
from complipay import CompliPayClient, InvoiceLine

# For local development
client = CompliPayClient(
    base_url="http://localhost:8000",  # Your server URL
    api_key="your_api_key"
)

invoice = client.invoices.create(
    invoice_number="INV-001",
    buyer_name="Acme Corp",
    lines=[InvoiceLine("Service", 1, 100.0)]
)
```

### TypeScript Example

```typescript
import { CompliPayClient } from 'complipay';

// For local development
const client = new CompliPayClient({
  baseUrl: 'http://localhost:8000',  // Your server URL
  apiKey: 'your_api_key'
});

const invoice = await client.invoices.create({
  invoiceNumber: 'INV-001',
  buyerName: 'Acme Corp',
  lines: [{ description: 'Service', quantity: 1, unitPrice: 100 }]
});
```

### PHP (Laravel 8+) Example

```php
use CompliPay\CompliPayClient;
use CompliPay\InvoiceLine;

// For local development
$client = new CompliPayClient([
    'base_url' => 'http://localhost:8000',  // Your server URL
    'api_key' => 'your_api_key',
]);

$invoice = $client->invoices->create(
    'INV-001',
    'Acme Corp',
    [new InvoiceLine('Service', 1, 100.0)]
);
```

### Other Languages (REST API)

Any language with HTTP support can use the REST API directly:

```java
// Java
HttpRequest request = HttpRequest.newBuilder()
    .uri(URI.create("http://localhost:8000/v1/invoices"))
    .header("X-API-Key", "your_key")
    .header("Content-Type", "application/json")
    .POST(HttpRequest.BodyPublishers.ofString(jsonBody))
    .build();
```

```csharp
// C#
var client = new HttpClient();
client.DefaultRequestHeaders.Add("X-API-Key", "your_key");
var response = await client.PostAsync(
    "http://localhost:8000/v1/invoices",
    new StringContent(json, Encoding.UTF8, "application/json")
);
```

```ruby
# Ruby
require 'net/http'
require 'json'

uri = URI('http://localhost:8000/v1/invoices')
http = Net::HTTP.new(uri.host, uri.port)
request = Net::HTTP::Post.new(uri, {
  'X-API-Key' => 'your_key',
  'Content-Type' => 'application/json'
})
request.body = data.to_json
response = http.request(request)
```

```go
// Go
req, _ := http.NewRequest("POST", "http://localhost:8000/v1/invoices", bytes.NewBuffer(jsonData))
req.Header.Set("X-API-Key", "your_key")
req.Header.Set("Content-Type", "application/json")
client := &http.Client{}
resp, _ := client.Do(req)
```

## Invoice Types

| Type | Code | Description |
|------|------|-------------|
| Standard Invoice | 388 | B2B invoice (requires clearance) |
| Simplified Invoice | 388 | B2C invoice (reporting only) |
| Credit Note | 381 | Adjustment reducing amount |
| Debit Note | 383 | Adjustment increasing amount |

## Tax Categories

| Code | Description | Rate |
|------|-------------|------|
| S | Standard Rate | 15% |
| Z | Zero Rated | 0% |
| E | Exempt | 0% |
| O | Out of Scope | 0% |

For Z, E, O categories, exemption reason codes are required (e.g., `VATEX-SA-29-7`, `VATEX-SA-HEA`).

## Project Structure

```
app/
├── Domains/
│   ├── Compliance/
│   │   └── Zatca/
│   │       ├── Client/          # ZATCA API client
│   │       ├── DTOs/            # Data transfer objects
│   │       ├── Exceptions/      # Custom exceptions
│   │       └── Services/        # Core compliance services
│   ├── Invoice/                 # Invoice domain
│   ├── Organization/            # Multi-tenant support
│   ├── Auth/                    # API key authentication
│   └── Webhook/                 # Webhook notifications
├── Http/
│   ├── Controllers/Api/         # API controllers
│   ├── Middleware/              # Auth & rate limiting
│   └── Requests/                # Form validation
└── Audits/                      # Audit logging

sdk/
├── python/                      # Python SDK
├── typescript/                  # TypeScript/JavaScript SDK
├── php-legacy/                  # PHP 7.4+ SDK for Laravel 8+
├── java/                        # Java SDK
├── go/                          # Go SDK
├── ruby/                        # Ruby SDK
├── dotnet/                      # .NET SDK
├── kotlin/                      # Kotlin SDK
├── dart/                        # Dart/Flutter SDK
├── swift/                       # Swift SDK
└── rust/                        # Rust SDK

resources/views/
├── admin/                       # Admin dashboard views
│   ├── dashboard.blade.php
│   ├── organizations.blade.php
│   ├── organization-detail.blade.php
│   ├── queue.blade.php
│   └── logs.blade.php
└── portal/                      # Customer portal views
```

## ZATCA Onboarding Flow

1. **Generate OTP** - Get one-time password from ZATCA Fatoora Portal
2. **Request CCSID** - Submit CSR with OTP to get Compliance CSID
3. **Compliance Check** - Submit test invoices for validation
4. **Request PCSID** - Get Production CSID after passing compliance

## Security

- All API endpoints require authentication
- API keys are hashed using SHA-256
- Webhooks are signed with HMAC-SHA256
- Private keys are encrypted at rest
- Rate limiting on all endpoints
- Audit logging for compliance actions

## API Documentation

Full OpenAPI 3.0 specification available at `docs/openapi.yaml`.

## Testing

```bash
# Run tests
php artisan test

# Run with coverage
php artisan test --coverage
```

## Registration

**Commercial use requires registration.** This helps us:
- Provide security vulnerability notifications
- Offer technical support
- Ensure compliance with ZATCA regulations
- Track usage for legal compliance

### How to Register

1. **Email**: Send registration details to `registration@{YOUR_DOMAIN}`
2. **Include**:
   - Organization name
   - Contact person and email
   - VAT registration number (if applicable)
   - Intended use case
   - Agreement to [Terms of Use](TERMS.md)

### What Registration Provides

- Production API credentials
- Technical support eligibility
- Security update notifications
- Compliance update notifications

## Legal

### Terms of Use

By using CompliPay, you agree to our [Terms of Use](TERMS.md), which includes:

- **Permitted Uses**: Legitimate business e-invoicing
- **Prohibited Uses**: Tax fraud, financial crimes, system manipulation
- **Compliance Requirements**: Valid ZATCA credentials, accurate data
- **Security Requirements**: Credential protection, access control

### Security Policy

See [SECURITY.md](SECURITY.md) for:

- How to report security vulnerabilities
- Security measures implemented
- Best practices for deployment
- Incident response procedures

### Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines. All contributors must agree to our terms and sign off on their commits.

### Support Scope

See [SUPPORT.md](SUPPORT.md) for detailed information about:

**We Support (Technical Issues):**
- Installation and setup
- API integration and SDK usage
- ZATCA technical compliance (XML, signatures, QR codes)
- Bug reports and feature requests

**Requires Professional Consultation:**
- Tax advice and VAT rate determination
- Legal matters and contract disputes
- ZATCA regulatory processes and appeals
- Accounting and financial reporting
- Business decisions and strategy

> **Important**: CompliPay is a technical tool. We do NOT provide tax, legal, or accounting advice. Consult licensed professionals for these matters.

## License

This project is licensed under the **Controlled Open Source License (COSL)** - see [LICENSE](LICENSE) for details.

Key points:
- Free for development and testing
- Commercial use requires registration
- Must comply with Terms of Use
- Attribution required for derivative works
- Security vulnerabilities must be reported responsibly
