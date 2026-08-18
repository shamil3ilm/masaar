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

This directory holds no JavaScript source and is kept only so links to it land
on this pointer rather than a 404. The plan is to generate SDKs from the
OpenAPI specification instead of maintaining one per language by hand — see
[`docs/audit/`](../../docs/audit/).
