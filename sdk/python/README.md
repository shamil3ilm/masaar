# CompliPay Python SDK

ZATCA-compliant e-invoicing API client for Python 3.7+

## Installation

```bash
pip install complipay
```

## Quick Start

```python
from complipay import CompliPayClient, InvoiceLine

# Initialize client
client = CompliPayClient(
    base_url="https://api.complipay.com",
    api_key="your_api_key"
)

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
# views.py
from django.http import JsonResponse
from complipay import CompliPayClient

client = CompliPayClient(
    base_url=settings.COMPLIPAY_URL,
    api_key=settings.COMPLIPAY_API_KEY
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
from flask import Flask, request, jsonify
from complipay import CompliPayClient

app = Flask(__name__)
client = CompliPayClient(base_url="...", api_key="...")

@app.route("/invoices", methods=["POST"])
def create_invoice():
    data = request.json
    invoice = client.invoices.create(**data)
    return jsonify(invoice)
```

### FastAPI

```python
from fastapi import FastAPI
from complipay import CompliPayClient, InvoiceLine

app = FastAPI()
client = CompliPayClient(base_url="...", api_key="...")

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
from complipay import WebhooksResource

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

## License

MIT
