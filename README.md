# CompliPay - ZATCA E-Invoicing API Platform

A production-ready ZATCA Phase 2 compliant e-invoicing API platform for Saudi Arabia, built with Laravel 12 and PHP 8.4.

## Features

- **ZATCA Phase 2 Compliance** - Full support for clearance (B2B) and reporting (B2C)
- **UBL 2.1 XML Generation** - Compliant invoice XML with all required elements
- **XAdES-BES Digital Signatures** - ECDSA secp256k1 cryptographic signing
- **QR Code Generation** - TLV-encoded QR codes (9 tags for Phase 2)
- **Multi-tenant Architecture** - Organization-scoped data isolation
- **Dual Authentication** - JWT tokens and API keys for server-to-server
- **Webhook Notifications** - Real-time event notifications with HMAC signatures
- **Comprehensive Validation** - ZATCA business rules (BR-KSA-*) enforcement

## Requirements

- PHP 8.4+
- Laravel 12
- OpenSSL extension (for ECDSA signing)
- MySQL 8.0+ or PostgreSQL 15+

## Installation

```bash
# Clone the repository
git clone https://github.com/your-org/complipay.git
cd complipay

# Install dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Start the server
php artisan serve
```

## Configuration

Add these to your `.env` file:

```env
# ZATCA Environment (sandbox, simulation, production)
ZATCA_ENVIRONMENT=sandbox

# ZATCA API Credentials (obtained after onboarding)
ZATCA_USERNAME=
ZATCA_PASSWORD=

# CORS (for CRM integrations)
CORS_ALLOWED_ORIGINS=https://your-crm.com,https://your-app.com
```

## API Endpoints

### Authentication

```bash
# Register
POST /api/auth/register

# Login (returns JWT token)
POST /api/auth/login

# Get API Key (for server-to-server)
POST /api/api-keys
```

### Invoices

```bash
# Create invoice
POST /api/invoices
Authorization: Bearer {jwt_token}

# List invoices
GET /api/invoices

# Get invoice
GET /api/invoices/{id}
```

### ZATCA Compliance

```bash
# Generate compliance data (hash, QR code)
POST /api/compliance/zatca/generate/{invoiceId}

# Validate with ZATCA (without submission)
POST /api/compliance/zatca/validate/{invoiceId}

# Submit to ZATCA
POST /api/compliance/zatca/submit/{invoiceId}

# Check status
GET /api/compliance/zatca/status/{invoiceId}
```

### ZATCA Onboarding

```bash
# Request Compliance CSID
POST /api/onboarding/ccsid

# Run compliance checks
POST /api/onboarding/compliance-check

# Request Production CSID
POST /api/onboarding/pcsid
```

## CRM Integration

### Using API Keys

```bash
# Create invoice via API key
curl -X POST https://api.complipay.com/v1/invoices \
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

Subscribe to events for real-time notifications:

```bash
POST /api/webhooks
{
  "url": "https://your-crm.com/webhooks/zatca",
  "events": ["invoice.cleared", "invoice.rejected"],
  "secret": "your_webhook_secret"
}
```

Events include:
- `invoice.created`, `invoice.updated`, `invoice.issued`
- `invoice.submitted`, `invoice.cleared`, `invoice.reported`
- `invoice.rejected`, `invoice.cancelled`

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
```

## ZATCA Onboarding Flow

1. **Generate OTP** - Get one-time password from ZATCA Fatoora Portal
2. **Request CCSID** - Submit CSR with OTP to get Compliance CSID
3. **Compliance Check** - Submit test invoices for validation
4. **Request PCSID** - Get Production CSID after passing compliance

```php
// Example: Complete onboarding
$service = app(CsidOnboardingService::class);

$result = $service->completeOnboarding(
    csrData: new CsrData(
        organizationName: 'Your Company',
        vatNumber: '300000000000003',
        // ... other fields
    ),
    otp: '123456',
    testInvoices: $testInvoices
);

// Store PCSID securely
$pcsid = $result['pcsid'];
$secret = $result['secret'];
```

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

## License

Proprietary - All rights reserved.

## Support

For support, contact support@complipay.com or open an issue.
