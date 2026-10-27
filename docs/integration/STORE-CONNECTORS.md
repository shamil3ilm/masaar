# Store connectors (not offered)

Audience: merchants and developers asking whether an online store can send
its orders without code, and whoever picks this design up later.

**Decision: there are no store connectors for now.** A store connects the
way an ERP does: an API key from Portal › Integrations, and its own code (or a
developer's) calling `POST /api/v1/pipeline/submit` ([QUICKSTART.md](QUICKSTART.md)).
What follows is the design kept for later; nothing in it is built.

## The design, for later

A connector would let a merchant install an app from the store platform's
admin and have every paid order become an invoice and every refund a credit
note. It would act with an organization API key (sandbox while trying), call
the existing pipeline and receive the existing webhooks; the API needs no
connector-specific endpoint. What would be new: an OAuth app per store
platform, a small service holding store tokens (encrypted, never logged) and
translating orders, and a Connectors page in Portal › Integrations. Code and
configuration would name a connector by what it does, never by vendor.

**Orders.** Subscribe to the platform's signed order events (created, paid,
fulfilled, cancelled, refunded) and verify each signature. The order id is
the invoice's `erp_reference_id` and `Idempotency-Key`, so a redelivered
event never makes a second invoice. Acknowledge at once and invoice from a
queue; reconcile daily against the store's paid orders, since a missed event
would be a missed tax invoice.

**Mapping.**

| Order | Invoice |
|---|---|
| A consumer | Simplified invoice (Saudi Arabia); outside UAE e-invoicing |
| A buyer who gave a VAT number | Standard invoice with the buyer's name, VAT number and address |
| Items, quantities, prices | Lines, `prices_include_vat` true |
| Names and their Arabic translation | `description`, `description_ar` |
| SKU | `product_code` |
| Shipping | A document charge, reason code `DL` |
| Discount codes | Line or document discounts |
| Payment method | Payment means: card `48`, transfer `30`, cash on delivery `10` |
| Store or pick-up point | Branch and till |

**Refunds.** An issued invoice is never changed: a full or partial refund is
a credit note with `billing_ref` and a reason; an exchange is a credit note
and a new invoice; an order cancelled before it was invoiced needs nothing.

**Open questions for the owner** when this is revisited: which store
platforms first, listing in their app stores under the company's partner
account, whether an order is invoiced on payment or on delivery for cash on
delivery, how a business buyer is recognised at checkout, pricing, and
whether connectors run inside the platform or as a separate service.
