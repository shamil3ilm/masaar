# Receiving Webhooks

Masaar tells your system when something happens to an invoice or to a
certificate, by POSTing JSON to the URL you register. Every delivery is
signed with your webhook's secret; verify the signature before acting on it.

## What arrives

```http
POST /your/endpoint
Content-Type: application/json
X-Webhook-ID: 9b2f0c1e-...           same on every retry of this delivery
X-Webhook-Event: invoice.cleared
X-Webhook-Timestamp: 2026-01-01T09:30:00.000000Z
X-Webhook-Signature: sha256=5d41402abc4b2a76b9719d911017c592...
User-Agent: Masaar-Webhook/1.0

{"id":"9b2f0c1e-...","event":"invoice.cleared","timestamp":"2026-01-01T09:30:00.000000Z","data":{...}}
```

## Subscribing

A partner's server registers with its licence key: `POST /api/v1/webhooks`
with `X-API-Key` and `X-API-Secret`, which needs the `webhook.manage` scope and
the licence feature `webhook_notifications`. The body names an https URL that
resolves to a public address, and the events:

```json
{"url": "https://erp.example.com/webhooks/masaar", "events": ["*"]}
```

`"*"` subscribes to everything; a list of event names from the table under
[Events](#events) subscribes to those, and a name the table does not have is
refused. The answer carries the webhook's `secret` in
`data.webhook.secret`, once. `GET /api/v1/webhooks` lists the organization's
webhooks; `GET`, `PUT` (`url`, `events`, `is_active`) and `DELETE` on
`/api/v1/webhooks/{webhook}` read, change and remove one.

The portal and app API, signed in as a person, has the same routes under
`/api/webhooks`, and two more: `POST /api/webhooks/{id}/rotate-secret`, which
returns a new secret once, and `POST /api/webhooks/{id}/test`, which sends
`webhook.test`. With a licence key alone, a new secret is had by registering
the URL again and deleting the old webhook.

## Events

| Event | Sent when | `data` carries |
|---|---|---|
| `invoice.issued` | The pipeline or the portal issues an invoice (issuing a Saudi invoice with `POST /api/v1/compliance/generate/{invoiceId}` sends none) | `invoice_id`, `invoice_number`, `status`, `type`, `total`, `currency` |
| `invoice.submitted` | A Saudi invoice is sent to ZATCA from the queue | the submission fields below, and `submitted_at` |
| `invoice.cleared` | ZATCA clears a standard invoice without warnings | the submission fields, `clearance_status`, `cleared_at`, `invoice_hash` |
| `invoice.reported` | ZATCA accepts the report of a simplified invoice without warnings | the submission fields, `reporting_status` |
| `invoice.warning` | ZATCA accepts an invoice **with warnings**, in place of `invoice.cleared` or `invoice.reported` | the submission fields, `warnings` |
| `invoice.rejected` | ZATCA rejects an invoice | the submission fields, `errors`, `error_count` |
| `invoice.failed` | A filing attempt fails | the submission fields; `will_retry` or `permanent` says whether it is tried again |
| `onboarding.ccsid_obtained` | A ZATCA compliance certificate is obtained, for the organization or one of its branches | `org_id`, `branch_id` (null for the organization's own device), `occurred_at` |
| `onboarding.compliance_passed` | The compliance documents pass | `org_id`, `branch_id`, `occurred_at` |
| `onboarding.pcsid_obtained` | The production certificate is obtained: the organization or branch can issue | `org_id`, `branch_id`, `occurred_at` |
| `certificate.expiring` | The signing certificate nears its expiry | `org_id`, `days_remaining`, `expiry_date`, `severity`, `message` |
| `e_invoice.sent` | A UAE e-invoice's service provider takes it for delivery | the e-invoice fields below |
| `e_invoice.delivered` | The buyer's service provider accepts it (message level response `AB` or `AP`) | the e-invoice fields |
| `e_invoice.rejected` | The provider or the buyer's provider refuses it | the e-invoice fields, with `errors` |
| `e_invoice.failed` | Every attempt to hand it to the provider went unanswered | the e-invoice fields |
| `e_invoice.reported` | The authority confirms its tax data, as the provider relays it | the e-invoice fields, with `authority_reference` |
| `e_invoice.report_failed` | Its tax data did not reach the authority. The platform asks the provider again every hour | the e-invoice fields, with `reporting_errors` |
| `e_invoice.received` | Another supplier's e-invoice arrives for the organization | the e-invoice fields |
| `webhook.test` | A test is sent | `message`, `webhook_id` |

The submission fields are `submission_id`, `invoice_id`, `org_id`,
`invoice_number`, `invoice_type`, `issue_date`, `total_amount`, `currency`,
`icv`, `state`, `previous_state`, `submission_type`, `zatca_uuid` and
`timestamp`.

An integrator that subscribes by name and wants every filed invoice names
`invoice.cleared`, `invoice.reported` and `invoice.warning` together: an
invoice accepted with warnings is announced only as `invoice.warning`.

Drafts are not announced: `invoice.created` and `invoice.updated` are no
longer offered. A draft is not a document anyone acts on, and every invoice
that is - issued, filed, answered - has its event above. A webhook that
already names one of them keeps working and simply receives nothing for it.

### UAE organizations

A UAE organization's invoices are sent as Peppol e-invoices, not filed with
ZATCA. Of the invoice events, only `invoice.issued` arrives for them:
`invoice.submitted`, `invoice.cleared`, `invoice.reported` and
`invoice.rejected` describe a ZATCA filing and are never sent for a UAE
invoice.

A UAE `invoice.issued` differs from a Saudi one in two ways:

- `currency` and `total` are the invoice's own. They may be in a currency
  other than dirhams; there is no riyal default.
- An `e_invoice` object describes the e-invoice as it stood at issue: `id`
  (the e-invoice's id in the documents API), `status` (`ready` at issue),
  `reporting_status` (null until the provider takes it) and `message_id`
  (null until the provider accepts it). There is no ICV or ZATCA UUID.

```json
{"invoice_id":"...","invoice_number":"INV-2026-1","status":"issued","type":"standard","total":"1050.00","currency":"USD",
 "e_invoice":{"id":"...","status":"ready","reporting_status":null,"message_id":null}}
```

After that, each step is an `e_invoice.*` event of its own. The service
provider confirms two things separately (UAE Electronic Invoicing Guidelines
v1.1, p10-11):

- **delivery** to the buyer's provider, as `status`: `sent`, `delivered`,
  `rejected` or `failed`;
- the **report** of the tax data to the authority, as `reporting_status`:
  `pending`, `reported` or `report_failed`.

Neither waits for the other. A `reported` can arrive before `delivered`, and
both are announced.

Every `e_invoice.*` event carries the same fields:

```json
{"e_invoice":{"id":"...","invoice_id":"...","number":"INV-2026-1","direction":"out",
  "status":"delivered","reporting_status":"reported","message_id":"...","mls_code":"AP","errors":[],
  "authority_reference":"...","reporting_errors":[],"sender":"0235:1001234567","receiver":"0235:1007654321"}}
```

`invoice_id` is null for a received e-invoice. Read the full record with
`GET /api/compliance/ae/documents/{id}`.

## Verifying the signature

`X-Webhook-Signature` is `sha256=` followed by the lowercase hex HMAC-SHA256 of
the **raw request body**, keyed with your secret.

Three rules matter more than the language:

1. **Hash the bytes you received.** Parsing the JSON and serialising it again
   changes whitespace and escaping, and the signature will not match.
2. **Compare in constant time**, with the `sha256=` prefix included.
3. **Refuse old deliveries.** The timestamp is inside the signed body and is
   fresh on every attempt, so reject anything older than about five minutes.

### PHP

```php
$body = file_get_contents('php://input');            // Laravel: $request->getContent()
$expected = 'sha256='.hash_hmac('sha256', $body, $secret);

if (! hash_equals($expected, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

### Node.js / TypeScript

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

// rawBody is a Buffer: in Express, use express.raw({ type: 'application/json' }).
function verify(rawBody, header, secret) {
  const expected = Buffer.from('sha256=' + createHmac('sha256', secret).update(rawBody).digest('hex'));
  const given = Buffer.from(header ?? '');
  return given.length === expected.length && timingSafeEqual(given, expected);
}
```

### Python

```python
import hashlib
import hmac

def verify(raw_body: bytes, header: str | None, secret: str) -> bool:
    expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, header or "")
```

### Java

```java
Mac mac = Mac.getInstance("HmacSHA256");
mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
String expected = "sha256=" + HexFormat.of().formatHex(mac.doFinal(rawBody));

boolean valid = header != null && MessageDigest.isEqual(
    expected.getBytes(StandardCharsets.UTF_8), header.getBytes(StandardCharsets.UTF_8));
```

### C#

```csharp
using var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret));
var expected = "sha256=" + Convert.ToHexString(hmac.ComputeHash(rawBody)).ToLowerInvariant();

var valid = CryptographicOperations.FixedTimeEquals(
    Encoding.UTF8.GetBytes(expected), Encoding.UTF8.GetBytes(header ?? ""));
```

### Go

```go
mac := hmac.New(sha256.New, []byte(secret))
mac.Write(rawBody)
expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))

valid := hmac.Equal([]byte(expected), []byte(header))
```

## Answering

- Answer with any 2xx, quickly. Do the work afterwards, on a queue.
- A redirect counts as a failure; register the final URL.
- A delivery that got no answer - unreachable, more than 30 seconds, `408`,
  `429` or a `5xx` - is tried up to five times: once, then again after about
  30 seconds, 2 minutes, 10 minutes and 30 minutes. Any other answer, a `4xx`
  among them, is final and not retried.
- Retries carry the same `X-Webhook-ID`. Record the IDs you have processed and
  skip a repeat, so a retry after a slow answer does not act twice.
- After ten failed deliveries in a row, with nothing delivered for a day, the
  webhook is switched off. Switch it back on with `PUT /api/v1/webhooks/{webhook}`
  and `{"is_active": true}`, which also clears the failure count.

## Client libraries

There are no official client libraries. The API is described by
[`docs/openapi.yaml`](openapi.yaml), generated from the routes and checked in
CI, so any OpenAPI generator produces a complete client for your language.
