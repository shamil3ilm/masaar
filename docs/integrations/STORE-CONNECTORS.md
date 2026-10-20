# Store connectors: research

A store connector would let a merchant connect an online store in a few
clicks: every paid order becomes an invoice, every refund a credit note,
without a developer. Today a store connects the way an ERP does - an API key
from Portal › Integrations and its own code calling `POST /api/v1/pipeline/submit`
- which most small merchants cannot do.

This is research only. Nothing here is built, and the platforms are named
here and nowhere else: code, configuration and the interface would name a
connector by what it does, never by vendor.

**Owner decision:** which platforms to connect first, whether to list
connectors in each platform's app store under the company's partner account,
and the choices in [Decisions](#decisions).

## Which platforms matter

Ranked by how many of the platform's likely customers sell on them. Market
shares are not published reliably; the order below is a judgement from public
merchant counts, app-store listings and how often each appears in local
e-invoicing vendors' integration lists.

### Saudi Arabia

| Platform | Why it matters |
|---|---|
| Salla | The most widely used local store builder, Arabic first, aimed at small and medium merchants - the customers least able to integrate an API themselves. Public partner programme and app store, OAuth apps, order webhooks. |
| Zid | The other large local store builder, with a similar merchant base and a public partner app market, OAuth and webhooks. |
| Shopify | The leading international platform; used by larger and cross-border Saudi brands. Mature app platform, OAuth, signed webhooks, an approval process for apps that read customer data. |
| WooCommerce | Self-hosted on WordPress, common with agencies and established retailers. No central app store: a plugin the merchant installs, calling the platform's API with REST keys or a webhook to it. |
| Marketplaces (Amazon, noon) | Large, but the marketplace generates and controls the seller's documents and exposes orders through seller APIs with their own terms. Later, if at all. |

Point-of-sale systems with online ordering (Foodics, Rewaa and others) already
ship their own e-invoicing; they are not store connectors and are out of scope.

### United Arab Emirates

| Platform | Why it matters |
|---|---|
| Shopify | The most common platform for UAE online stores. |
| WooCommerce | Common with agencies, as in Saudi Arabia. |
| Salla, Zid | Both expanding into the UAE; one connector serves both countries. |
| Magento / Adobe Commerce | Larger retailers; an extension rather than an app store listing. |

The UAE's e-invoicing, as announced, starts with business and government
buyers and adds consumer sales later. Most store orders are consumer sales,
so in the UAE a connector matters mostly for B2B stores until then. In Saudi
Arabia every sale needs an e-invoice already (a simplified invoice reported
within 24 hours for a consumer, a standard invoice cleared for a business), so
Saudi stores are where connectors pay off first.

## What a connector needs

### 1. An app on the store platform

- A partner account with each platform and an **OAuth 2.0 app** (authorization
  code flow): the merchant clicks Install in the store's admin, approves the
  scopes, and the connector receives a store access token (and a refresh token
  where the platform rotates them).
- **Scopes**: read orders, read products, read customers (only the fields an
  invoice needs), and write order notes or metadata if the invoice link is to
  appear on the order. Nothing else.
- App store review: listing requirements, privacy policy, support contact, a
  test store; Shopify additionally reviews access to protected customer data.
- **Install and uninstall events**: on install, link the store to the
  organization (the merchant signs in to the portal in the OAuth flow); on
  uninstall, revoke the connector's API key (Portal › Integrations) and the
  stored store token.
- Tokens are credentials: encrypted at rest, never logged, scoped to one
  store and one organization.

### 2. Order webhooks

- Subscribe to the order events each platform sends: created, paid, updated,
  fulfilled, cancelled, refunded. **Verify every webhook's signature** with the
  app's secret (each platform signs with an HMAC header) before acting.
- **Idempotency**: the store's order id is the invoice's `erp_reference_id` and
  the `Idempotency-Key`, so a webhook delivered twice, or replayed after an
  outage, never makes a second invoice. Platforms retry for hours to days.
- **When to issue** (decision): on payment is the usual tax point for a prepaid
  online order; cash on delivery is paid on delivery, so those wait for the
  delivered or paid event.
- **Backfill and reconciliation**: on install, offer the last days' orders; a
  daily job compares the store's paid orders with issued invoices and reports
  the gaps, because a missed webhook is otherwise a missed tax invoice.
- Queue the work: a webhook is acknowledged at once, the invoice made by a job,
  so the platform's short timeouts never cost an order.

### 3. Mapping an order to an invoice

| Store order | Invoice |
|---|---|
| Consumer, no VAT number | Simplified invoice |
| Buyer gave a VAT number (a checkout field or a customer tag) | Standard invoice, with the buyer's name, VAT number and address |
| Line items, quantity, unit price | Lines; the platform's prices include VAT, so `prices_include_vat` |
| Product names | `description`, and in Saudi Arabia the Arabic name (`description_ar`) from the platform's Arabic translation |
| SKU | `product_code`, matching a saved product where one has the same code |
| Shipping | A document-level charge, reason code DL (UNCL 7161, delivery) |
| Discount codes | Line or document discounts, as the platform splits them |
| Payment method | Payment means (UNCL 4461): card 48, bank transfer 30, cash on delivery 10 |
| Store, warehouse or pick-up point | A branch and till where the merchant has several (EGS units in Saudi Arabia) |
| Currency | The order's; the tax amounts in the country's currency as the rules require |

### 4. Refunds and returns

- An issued invoice is never changed or cancelled. A **full or partial refund
  is a credit note** that references the original invoice (`billing_ref`) and
  states the reason (ZATCA requires one; the portal offers the listed
  reasons).
- Map refund line items to credit-note lines; a refunded shipping fee to a
  credit on the charge; a goodwill refund with no items to a document-level
  amount.
- An order cancelled **before** its invoice was issued needs nothing; one
  cancelled **after** needs a full credit note.
- Exchanges are a credit note for what came back and a new invoice for what
  went out.

### 5. Telling the store

- Write the invoice number and its public link (the QR code's link, see
  Portal › QR codes) back to the order as a note or metadata, so the merchant
  and the customer can open the invoice from the order.
- Webhooks from us (`invoice.cleared`, `invoice.reported`, `invoice.rejected`)
  update the order's note; a rejection is shown to the merchant in the
  connector's settings page with the reason.

## How it fits what exists

- The connector acts with an **organization API key** (sandbox while the
  merchant tries it) with `invoice.submit` and `compliance.status`, so the
  licence's features, allowances and request log apply unchanged.
- It calls the existing pipeline (`/api/v1/pipeline/submit` and `/batch`) and
  receives the existing webhooks; the platform API needs no connector-specific
  endpoints.
- What is new: the OAuth app per platform, a small connector service holding
  store tokens and translating orders, and a Connectors page in Portal ›
  Integrations to install, see the last orders synced, and disconnect.

## Decisions

For the owner:

1. **Which platforms, in which order.** Suggested: Salla and Zid first (Saudi
   merchants, every sale needs an invoice now), then Shopify, then a
   WooCommerce plugin; the UAE as its consumer phase approaches.
2. **App-store listings.** Listing requires a partner account in the company's
   name with each platform, their review, and published support and privacy
   terms.
3. **When an order is invoiced**: on payment, or on fulfilment for cash on
   delivery.
4. **How a business buyer is recognised** at checkout (a VAT-number field, a
   customer tag), which decides standard against simplified.
5. **Pricing**: whether connectors are part of the plan or an add-on, and
   whether synced orders count against the invoice allowance like any other.
6. **Where connectors run**: inside the platform, or as a separate service
   talking to the API with each merchant's key.
