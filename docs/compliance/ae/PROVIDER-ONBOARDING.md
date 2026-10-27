# UAE e-invoicing: connecting an accredited service provider

**Audience:** whoever evaluates or connects a UAE accredited service
provider (ASP): the owner, an engineer adapting the gateway, an operator
switching a deployment over.

The readiness checklist, the questions to ask, the switch-over steps and
the open gaps. The model is in [README.md](README.md). Sources:
**Guidelines** (UAE Electronic Invoicing Guidelines v1.1), **Considerations**
(Considerations for selecting an Accredited Service Provider v1.0),
**Programme** (UAE eInvoicing Programme presentation), cited by page.

## The contract

Everything provider-specific sits behind `AspGateway`
(`app/Domains/Compliance/Networks/Peppol/Contracts/`): `submit` (returns a
`SubmitReceipt`), `status`, `participantExists`, `findByReference`,
`retryReport`, `verifyWebhook`, `parseStatusWebhook`, `parseInboundWebhook`.
`HttpAspGateway` speaks a generic REST shape; for a real provider adapt
`HttpAspGateway`, `WebhookSignature` and `WebhookPayload` in
`.../Peppol/Gateways/` and nothing else. The simulator implements the same
contract. `AspGatewayResolver` picks the provider per organization; only the
platform's partner (`peppol.providers.platform`) is configured.

## Readiness checklist

**Endpoints**
- [ ] Submit UBL 2.1 PINT AE (billing and self-billing, invoice and credit
      note); returns a message id synchronously.
- [ ] Status by message id. The platform polls every ten minutes for sent
      documents without a callback for 30 minutes, up to 14 days.
- [ ] Lookup by our own reference (the document id), for a send whose
      worker died (gap 5).
- [ ] Participant lookup before issuing (Guidelines p11 rows 5–6).
- [ ] Separate sandbox and production URLs and credentials.

**Authentication and idempotency**
- [ ] Machine credential; the generic gateway uses OAuth2 client
      credentials (`/oauth/token`), cached until a minute before expiry.
- [ ] Honours `Idempotency-Key: <document id>`; a retry returns the first
      message id. `409` is read as "already have it".

**Callbacks**
- [ ] Every status change and inbound document is called back, signed:
      HMAC-SHA256 over `"{timestamp}.{raw body}"` in `X-Signature`
      (optionally `sha256=`), timestamp in `X-Signature-Timestamp`, refused
      past 300 seconds. A different scheme changes only `WebhookSignature`.
- [ ] Retries a callback answered `404`, `5xx` or timed out (both routes are
      idempotent).
- [ ] Registers `POST {APP_URL}/api/peppol/webhooks/status` and
      `.../inbound`. Ideally two secrets valid at once, for rotation.

**Statuses** — map each provider status in `WebhookPayload::statusFrom` to
the delivery and reporting tracks in
[FILING-STATES.md](../../architecture/FILING-STATES.md#4-the-uae-network-document)
(`sent`, `delivered` for `AB`/`AP`, `rejected` incl. `RE`, `reported`,
`report_failed`; Guidelines p10–11, p41).
- [ ] Errors carry rule id, severity and location (gap 7).
- [ ] A failed report is its own status and can be re-reported.
- [ ] What is sent for the reserved endpoints (`0235:9900000097`, `...98`,
      `...99`), which the platform treats as `not_exchanged`.
- [ ] Documents with status `not_transmitted` (issued before the
      organization's start date) are never handed over.

**Inbound**
- [ ] Each document for our participant identifiers: message id, XML as it
      travelled, sender and receiver (`0235:` + TIN, with or without the
      `iso6523-actorid-upis::` prefix), and Corner 5's confirmation
      (Guidelines p11 step 11).

**Sandbox**
- [ ] Covers the six tests of Guidelines p40–41 (send, delivery, exchange
      confirmation, receive, report, report confirmation), with test
      identifiers on both sides and triggerable refusals and unknown
      receivers. Ask whether Corner 5 is real or simulated (Programme p15–16).

## Questions to ask

1. Are the buyer's provider's and the authority's confirmations separate
   events, each with a time, the authority's reference and a failure
   reason? (Guidelines p10–11, p41, p48)
2. Which statuses do the reserved endpoints get, and which is final?
   (p23, p26, p28)
3. Do errors carry the PINT AE rule id, severity and location? Is there a
   validate-only call? (p10 step 2)
4. Can we look up a buyer's participant identifier before issuing?
   (p11 row 6; Programme p7)
5. Do you keep our `cbc:UUID` (generated per document) or replace it, and
   if you convert the document, do you return what travelled? (p11 row 7)
6. Do you honour an idempotency key, and can we look up by our reference?
   (p46)
7. How are outages announced to us and the authority; are pending invoices
   sent on resumption? (p45–46)
8. Self-billing and credit notes for both specifications? (p23)
9. Do you forward the authority's confirmation for received invoices?
   (p11 step 11)
10. Does the sandbox cover the six tests, including reporting? (p40–41)
11. One platform account for many taxpayers, each with its own credentials
    and callbacks? (one ASP per taxpayer, p14; tax group members may differ,
    p38)
12. How is the participant identifier issued, and how do we learn of
    reverification or offboarding through EmaraTax? (p6, p41, p46)
13. Log retention, hosting location, and keeping invoices for us?
    (Guidelines 5.4; Considerations p6–7)
14. Uptime, support response, certifications? (Considerations p7)
15. Own product or a third party's; who supports it? (Considerations p6)

For a **partner** provider also ask: can the platform resell the service
or must each taxpayer contract directly; can the platform complete
EmaraTax onboarding on the taxpayer's behalf (p38–39); the pricing model
(Considerations p7); how a taxpayer moves to or from you (p41); and
whether one connection serves many taxpayers by participant identifier.

## Switching from the simulator

1. Get sandbox URL, credentials, webhook secret and test identifiers.
2. Adapt the three gateway classes; pin real bodies in
   `tests/Feature/Compliance/Networks/Peppol/AspHttpGatewayTest.php`.
3. Set `PEPPOL_GATEWAY=` (empty) and the `PEPPOL_ASP_*` variables; register
   both callback URLs.
4. Enter one organization's issued participant identifier
   (`PUT /api/compliance/ae/profile` or the Company page), record its
   appointment and start date.
5. Run the simulator's scenarios against the sandbox: each kind to
   `delivered` then `reported`; a refusal; an unknown receiver; a buyer
   refusal; a lost answer and its retry; an inbound invoice. Check the
   console's Peppol page and run `peppol:poll-status` once with callbacks
   blocked.
6. Production: real URL and credentials. Production refuses to boot with
   any credential missing or with `PEPPOL_GATEWAY=simulator`.

## Gaps

The owner's decision: one platform partner provider now, with seams for one
per organization later (`AspGatewayResolver`, `provider_appointments`).

| # | Gap | State |
|---|---|---|
| 1 | Delivery and reporting tracked apart | Closed: two tracks; `peppol:retry-reports` hourly; `e_invoice.report_failed` |
| 2 | Report-only documents | Closed: `not_exchanged`, `peppol.countries.AE.report_only_endpoints` |
| 3 | A provider per taxpayer | Open: resolution is per organization, but a second provider needs its `peppol.providers` entry, an adapter and callback routes naming the connection |
| 4 | Record of transmission | Closed: append-only `peppol_document_events` with source, status, code, message id, errors, body hash, time (Guidelines 5.4, p47) |
| 5 | Stuck in `sending` | Closed: `findByReference`; `peppol:recover-sending` every ten minutes (p46) |
| 6 | Confirmations for received invoices | Closed: recorded and announced as `e_invoice.reported` |
| 7 | Structured errors | Closed: `AspError` `{code, severity, field, message}`, read from objects or code-led strings |
| 8 | Outages | Closed for what the platform sees: `PEPPOL_RETRY_WINDOW_HOURS` (72); three no-verdicts in a row is an outage (`ProviderOutage::THRESHOLD`); waiting documents go on recovery (p45–46) |
| 9 | Participant lookup | Closed: `participantExists`; unregistered recipients refused; cached a day (registered) or ten minutes (not); unknown stops nothing |
| 10 | UUID | Closed: per-document `document_uuid`; provider's id kept as `provider_reference` |

Nice to have: business-level responses (not in the UAE documents), mutual
TLS or an IP allowlist on callbacks, and the authority's tax-data reference
where returned (Programme p15; Guidelines p48).
<!-- Networks/Peppol/Gateways/{HttpAspGateway,WebhookSignature,AspGatewayResolver}.php; Sending/{ParticipantDirectory,ProviderOutage}.php; Console/PollStatus.php -->
