# Masaar TypeScript/JavaScript SDK

ZATCA-compliant e-invoicing API client for TypeScript and JavaScript.

Works with Node.js 14+, React, Vue, Angular, Next.js, or any JavaScript environment.

> **Important**: By using this SDK, you agree to the Masaar [Terms of Use](../../TERMS.md) and [License](../../LICENSE). Commercial use requires [registration](../../README.md#registration).

## Installation

```bash
npm install masaar
# or
yarn add masaar
# or
pnpm add masaar
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
import { MasaarClient, InvoiceLine } from 'masaar';

// For local development
const client = new MasaarClient({
  baseUrl: 'http://localhost:8000',  // Your server URL
  apiKey: 'your_api_key'
});

// For production, use your deployed server URL:
// const client = new MasaarClient({
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
const { MasaarClient } = require('masaar');

// For local development
const client = new MasaarClient({
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
import { MasaarClient } from 'masaar';

// Configure via environment variables
// .env.local:
// NEXT_PUBLIC_MASAAR_URL=http://localhost:8000
// MASAAR_API_KEY=your_api_key

const client = new MasaarClient({
  baseUrl: process.env.NEXT_PUBLIC_MASAAR_URL!,
  apiKey: process.env.MASAAR_API_KEY!
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
import { MasaarClient } from 'masaar';

// Configure via environment variables
// .env:
// VITE_MASAAR_URL=http://localhost:8000
// VITE_MASAAR_API_KEY=your_api_key

const client = new MasaarClient({
  baseUrl: import.meta.env.VITE_MASAAR_URL,
  apiKey: import.meta.env.VITE_MASAAR_API_KEY
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
const { MasaarClient } = require('masaar');

const app = express();

// Configure via environment variables
// MASAAR_URL=http://localhost:8000
// MASAAR_API_KEY=your_api_key
const client = new MasaarClient({
  baseUrl: process.env.MASAAR_URL || 'http://localhost:8000',
  apiKey: process.env.MASAAR_API_KEY
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
import { WebhooksResource } from 'masaar';
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
  MasaarClient,
  MasaarError,
  AuthenticationError,
  ValidationError,
  ZatcaError
} from 'masaar';

try {
  await client.invoices.create({...});
} catch (error) {
  if (error instanceof AuthenticationError) {
    console.error('Invalid API key');
  } else if (error instanceof ValidationError) {
    console.error('Validation errors:', error.errors);
  } else if (error instanceof ZatcaError) {
    console.error('ZATCA rejected:', error.errors);
  } else if (error instanceof MasaarError) {
    console.error('API error:', error.message);
  }
}
```

## Legal

By using this SDK, you agree to:

- [Terms of Use](../../TERMS.md) - Acceptable use policy
- [License](../../LICENSE) - Controlled Open Source License (COSL)
- [Security Policy](../../SECURITY.md) - Security requirements

**Commercial use requires registration.** See [Registration](../../README.md#registration).

## License

Controlled Open Source License (COSL) - See [LICENSE](../../LICENSE)
