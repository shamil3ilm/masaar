# Masaar JavaScript — use the TypeScript SDK

**There is no separate JavaScript SDK.** Use [`sdks/typescript`](../typescript/),
which compiles to JavaScript and works from both Node.js and the browser.

```ts
import { MasaarClient } from '@masaar/sdk';

const client = new MasaarClient({
  baseUrl: 'https://api.masaar.sa',
  apiKey: process.env.MASAAR_API_KEY,
  apiSecret: process.env.MASAAR_API_SECRET,
});
```

> **Not yet on npm.** No Masaar SDK is published to a package registry. Vendor
> the TypeScript source directly until one is released — `npm install masaar`
> does **not** install this client.

---

### Why this file used to say otherwise

Until 2026-08-18 this README documented a full JavaScript client — an
`invoices` resource, a `compliance` resource, a `verifyWebhookSignature`
helper and a UMD bundle on unpkg. None of it existed; the directory has never
contained JavaScript source. Anyone following it wrote code against an API that
was never built.

It was replaced rather than deleted so that existing links land on a correction
instead of a 404. The API surface it described is a reasonable sketch of what a
generated SDK should eventually expose — see [`docs/audit/`](../../docs/audit/)
for the plan to generate SDKs from the OpenAPI specification.
