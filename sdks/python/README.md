# Masaar Python SDK

ZATCA-compliant e-invoicing API client for Python 3.7+

> **Important**: By using this SDK, you agree to the Masaar [Terms of Use](../../TERMS.md) and [License](../../LICENSE). Commercial use requires [registration](../../README.md#registration).

## Installation

```bash
pip install masaar
```

## Server URLs

| Environment | Base URL |
|-------------|----------|
| **Local Development** | `http://localhost:8000` |
| **Local (Laragon)** | `http://zatca.test` |
| **Production** | `https://{YOUR_DOMAIN}` |

> **Note:** Replace `{YOUR_DOMAIN}` with your actual domain when deploying to production.

## Quick Start

```python
from masaar import MasaarClient, InvoiceLine

# Initialize client (local development)
client = MasaarClient(
    base_url="http://localhost:8000",  # Your server URL
    api_key="your_api_key"
)

# For production, use your deployed server URL:
# client = MasaarClient(
#     base_url="https://your-domain.com",
#     api_key="your_api_key"
# )

# Create an invoice
invoice = client.invoices.create(
    invoice_number="INV-001",
    buyer_name="Acme Corporation",
    buyer_vat_number="300000000000003",
    lines=[
        InvoiceLine(
            description="Consulting Services",
            quantity=10,
            unit_price=100.00,
            tax_rate=15.0
        )
    ]
)

# Generate compliance data (hash, QR code)
client.compliance.generate(invoice["data"]["id"])

# Submit to ZATCA
result = client.compliance.submit(invoice["data"]["id"])
print(f"ZATCA Status: {result['status']}")
```

## Framework Examples

### Django

```python
# settings.py
MASAAR_URL = "http://localhost:8000"  # or your production URL
MASAAR_API_KEY = "your_api_key"

# views.py
from django.conf import settings
from django.http import JsonResponse
from masaar import MasaarClient

client = MasaarClient(
    base_url=settings.MASAAR_URL,
    api_key=settings.MASAAR_API_KEY
)

def create_invoice(request):
    invoice = client.invoices.create(
        invoice_number=request.POST["invoice_number"],
        buyer_name=request.POST["buyer_name"],
        lines=request.POST.getlist("lines")
    )
    return JsonResponse(invoice)
```

### Flask

```python
import os
from flask import Flask, request, jsonify
from masaar import MasaarClient

app = Flask(__name__)

# Use environment variables for configuration
client = MasaarClient(
    base_url=os.environ.get("MASAAR_URL", "http://localhost:8000"),
    api_key=os.environ.get("MASAAR_API_KEY", "your_api_key")
)

@app.route("/invoices", methods=["POST"])
def create_invoice():
    data = request.json
    invoice = client.invoices.create(**data)
    return jsonify(invoice)
```

### FastAPI

```python
import os
from fastapi import FastAPI
from masaar import MasaarClient, InvoiceLine

app = FastAPI()

# Configure with environment variables
client = MasaarClient(
    base_url=os.environ.get("MASAAR_URL", "http://localhost:8000"),
    api_key=os.environ.get("MASAAR_API_KEY", "your_api_key")
)

@app.post("/invoices")
async def create_invoice(invoice_number: str, buyer_name: str):
    return client.invoices.create(
        invoice_number=invoice_number,
        buyer_name=buyer_name,
        lines=[...]
    )
```

## Webhooks

```python
from flask import Flask, request
from masaar import WebhooksResource

@app.route("/webhook", methods=["POST"])
def handle_webhook():
    signature = request.headers.get("X-Signature")

    if WebhooksResource.verify_signature(
        request.data,
        signature,
        "your_webhook_secret"
    ):
        event = request.json
        # Handle event
        return "OK", 200

    return "Invalid signature", 401
```

## Legal

By using this SDK, you agree to:

- [Terms of Use](../../TERMS.md) - Acceptable use policy
- [License](../../LICENSE) - Controlled Open Source License (COSL)
- [Security Policy](../../SECURITY.md) - Security requirements

**Commercial use requires registration.** See [Registration](../../README.md#registration).

## License

Controlled Open Source License (COSL) - See [LICENSE](../../LICENSE)
