# CompliPay JavaScript SDK

ZATCA-compliant e-invoicing API client for JavaScript (Node.js and browsers).

> **Note**: For TypeScript support with full type definitions, see the [TypeScript SDK](../typescript/).

## Installation

```bash
npm install complipay
# or
yarn add complipay
```

## Quick Start

```javascript
const { CompliPayClient } = require('complipay');

// Initialize client
const client = new CompliPayClient({
  baseUrl: 'http://localhost:8000',  // Your server URL
  apiKey: 'your_api_key',
  apiSecret: 'your_api_secret'
});

// Create an invoice
async function createInvoice() {
  const invoice = await client.invoices.create({
    invoiceNumber: 'INV-2026-001',
    buyerName: 'Acme Corporation',
    buyerVatNumber: '300000000000003',
    lines: [
      {
        description: 'Consulting Services',
        quantity: 10,
        unitPrice: 100.00,
        taxRate: 15.0
      }
    ]
  });

  console.log('Invoice created:', invoice.id);

  // Submit to ZATCA
  const result = await client.compliance.submit(invoice.id);

  if (result.cleared) {
    console.log('Invoice cleared by ZATCA!');
    console.log('QR Code:', result.qrCode);
  }
}

createInvoice().catch(console.error);
```

## ES Modules

```javascript
import { CompliPayClient } from 'complipay';

const client = new CompliPayClient({
  baseUrl: 'http://localhost:8000',
  apiKey: 'your_api_key'
});
```

## Features

### Invoice Management

```javascript
// List invoices
const invoices = await client.invoices.list({
  page: 1,
  limit: 20,
  status: 'cleared'
});

// Get single invoice
const invoice = await client.invoices.get('invoice-uuid');

// Create standard invoice (B2B)
const b2bInvoice = await client.invoices.create({
  invoiceNumber: 'INV-001',
  type: 'standard',
  buyerName: 'Business Customer',
  buyerVatNumber: '300000000000003',
  lines: [{ description: 'Service', quantity: 1, unitPrice: 1000.00 }]
});

// Create simplified invoice (B2C)
const b2cInvoice = await client.invoices.create({
  invoiceNumber: 'SINV-001',
  type: 'simplified',
  buyerName: 'Walk-in Customer',
  lines: [{ description: 'Product', quantity: 2, unitPrice: 50.00 }]
});

// Create credit note
const creditNote = await client.invoices.createCreditNote({
  creditNoteNumber: 'CN-001',
  originalInvoiceId: 'original-invoice-uuid',
  lines: [{ description: 'Returned Item', quantity: 1, unitPrice: 100.00 }]
});
```

### ZATCA Compliance

```javascript
// Generate compliance data
const generated = await client.compliance.generate(invoiceId);
console.log('Hash:', generated.hash);
console.log('QR Code:', generated.qrCode);

// Validate before submission
const validation = await client.compliance.validate(invoiceId);
validation.warnings.forEach(w => console.log('Warning:', w));

// Submit to ZATCA
const result = await client.compliance.submit(invoiceId);

// Check status
const status = await client.compliance.status(invoiceId);
```

### Webhooks

```javascript
// Subscribe to events
const webhook = await client.webhooks.create({
  url: 'https://your-app.com/webhooks/complipay',
  events: ['invoice.cleared', 'invoice.rejected'],
  secret: 'your-webhook-secret'
});

// Express.js webhook handler
const express = require('express');
const { verifyWebhookSignature } = require('complipay');

app.post('/webhooks/complipay', express.raw({ type: 'application/json' }), (req, res) => {
  const payload = req.body.toString();
  const signature = req.headers['x-complipay-signature'];

  if (!verifyWebhookSignature(payload, signature, process.env.WEBHOOK_SECRET)) {
    return res.status(401).send('Invalid signature');
  }

  const event = JSON.parse(payload);

  switch (event.type) {
    case 'invoice.cleared':
      handleInvoiceCleared(event);
      break;
    case 'invoice.rejected':
      handleInvoiceRejected(event);
      break;
  }

  res.status(200).send('OK');
});
```

## Error Handling

```javascript
try {
  const invoice = await client.invoices.create(request);
} catch (error) {
  if (error.code === 'AUTH_INVALID_KEY') {
    console.error('Auth failed:', error.message);
  } else if (error.code?.startsWith('VAL_')) {
    console.error('Validation failed:', error.message);
    error.errors?.forEach(e => console.error('  -', e));
  } else if (error.code?.startsWith('ZATCA_')) {
    console.error('ZATCA error:', error.message);
  } else if (error.code === 'RATE_LIMIT') {
    console.error('Rate limited - retry after', error.retryAfter, 'seconds');
  } else {
    console.error('Error:', error.message);
  }
}
```

## Browser Usage

```html
<script src="https://unpkg.com/complipay/dist/complipay.min.js"></script>
<script>
  const client = new CompliPay.Client({
    baseUrl: 'http://localhost:8000',
    apiKey: 'your_api_key'
  });

  client.invoices.list().then(invoices => {
    console.log('Invoices:', invoices);
  });
</script>
```

## Requirements

- Node.js 14+ or modern browser
- No external dependencies (uses native fetch)

## License

MIT License - see LICENSE file for details.
