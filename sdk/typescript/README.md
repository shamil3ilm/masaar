# CompliPay TypeScript/JavaScript SDK

ZATCA-compliant e-invoicing API client for TypeScript and JavaScript.

Works with Node.js 14+, React, Vue, Angular, Next.js, or any JavaScript environment.

## Installation

```bash
npm install complipay
# or
yarn add complipay
# or
pnpm add complipay
```

## Server URLs

| Environment | Base URL |
|-------------|----------|
| **Local Development** | `http://localhost:8000` |
| **Local (Laragon)** | `http://zatca.test` |
| **Production** | `https://{YOUR_DOMAIN}` |

> **Note:** Replace `{YOUR_DOMAIN}` with your actual domain when deploying to production.

## Quick Start

### TypeScript

```typescript
import { CompliPayClient, InvoiceLine } from 'complipay';

// For local development
const client = new CompliPayClient({
  baseUrl: 'http://localhost:8000',  // Your server URL
  apiKey: 'your_api_key'
});

// For production, use your deployed server URL:
// const client = new CompliPayClient({
//   baseUrl: 'https://your-domain.com',
//   apiKey: 'your_api_key'
// });

// Create an invoice
const invoice = await client.invoices.create({
  invoiceNumber: 'INV-001',
  buyerName: 'Acme Corporation',
  buyerVatNumber: '300000000000003',
  lines: [
    {
      description: 'Consulting Services',
      quantity: 10,
      unitPrice: 100.00,
      taxRate: 15
    }
  ]
});

// Generate compliance data
await client.compliance.generate(invoice.data.id);

// Submit to ZATCA
const result = await client.compliance.submit(invoice.data.id);
console.log('ZATCA Status:', result.data.status);
```

### JavaScript (CommonJS)

```javascript
const { CompliPayClient } = require('complipay');

// For local development
const client = new CompliPayClient({
  baseUrl: 'http://localhost:8000',  // Your server URL
  apiKey: 'your_api_key'
});

// Create invoice
const invoice = await client.invoices.create({
  invoiceNumber: 'INV-001',
  buyerName: 'Acme Corp',
  lines: [{ description: 'Service', quantity: 1, unitPrice: 100 }]
});
```

## Framework Examples

### React / Next.js

```tsx
import { CompliPayClient } from 'complipay';

// Configure via environment variables
// .env.local:
// NEXT_PUBLIC_COMPLIPAY_URL=http://localhost:8000
// COMPLIPAY_API_KEY=your_api_key

const client = new CompliPayClient({
  baseUrl: process.env.NEXT_PUBLIC_COMPLIPAY_URL!,
  apiKey: process.env.COMPLIPAY_API_KEY!
});

export async function createInvoice(formData: FormData) {
  'use server';

  const invoice = await client.invoices.create({
    invoiceNumber: formData.get('invoiceNumber') as string,
    buyerName: formData.get('buyerName') as string,
    lines: JSON.parse(formData.get('lines') as string)
  });

  return invoice;
}
```

### Vue.js

```vue
<script setup lang="ts">
import { CompliPayClient } from 'complipay';

// Configure via environment variables
// .env:
// VITE_COMPLIPAY_URL=http://localhost:8000
// VITE_COMPLIPAY_API_KEY=your_api_key

const client = new CompliPayClient({
  baseUrl: import.meta.env.VITE_COMPLIPAY_URL,
  apiKey: import.meta.env.VITE_COMPLIPAY_API_KEY
});

async function submitInvoice() {
  const invoice = await client.invoices.create({...});
  await client.compliance.submit(invoice.data.id);
}
</script>
```

### Express.js / Node.js

```javascript
const express = require('express');
const { CompliPayClient } = require('complipay');

const app = express();

// Configure via environment variables
// COMPLIPAY_URL=http://localhost:8000
// COMPLIPAY_API_KEY=your_api_key
const client = new CompliPayClient({
  baseUrl: process.env.COMPLIPAY_URL || 'http://localhost:8000',
  apiKey: process.env.COMPLIPAY_API_KEY
});

app.post('/invoices', async (req, res) => {
  try {
    const invoice = await client.invoices.create(req.body);
    res.json(invoice);
  } catch (error) {
    res.status(error.statusCode || 500).json({ error: error.message });
  }
});
```

## Webhooks

```typescript
import { WebhooksResource } from 'complipay';
import express from 'express';

const app = express();

app.post('/webhook', express.raw({ type: 'application/json' }), async (req, res) => {
  const signature = req.headers['x-signature'] as string;

  const isValid = await WebhooksResource.verifySignature(
    req.body,
    signature,
    process.env.WEBHOOK_SECRET!
  );

  if (!isValid) {
    return res.status(401).send('Invalid signature');
  }

  const event = JSON.parse(req.body.toString());

  switch (event.type) {
    case 'invoice.cleared':
      console.log('Invoice cleared:', event.data.id);
      break;
    case 'invoice.rejected':
      console.log('Invoice rejected:', event.data.errors);
      break;
  }

  res.send('OK');
});
```

## Error Handling

```typescript
import {
  CompliPayClient,
  CompliPayError,
  AuthenticationError,
  ValidationError,
  ZatcaError
} from 'complipay';

try {
  await client.invoices.create({...});
} catch (error) {
  if (error instanceof AuthenticationError) {
    console.error('Invalid API key');
  } else if (error instanceof ValidationError) {
    console.error('Validation errors:', error.errors);
  } else if (error instanceof ZatcaError) {
    console.error('ZATCA rejected:', error.errors);
  } else if (error instanceof CompliPayError) {
    console.error('API error:', error.message);
  }
}
```

## License

MIT
