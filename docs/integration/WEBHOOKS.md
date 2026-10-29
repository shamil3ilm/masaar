# Webhooks

Audience: developers whose system should hear when an invoice is filed,
rejected or delivered, instead of polling. Errors and limits are in
[API.md](API.md).

## Managing endpoints

| Route (`/api/v1`, scope `webhook.manage`, feature `webhook_notifications`) | Does |
|---|---|
| `POST /api/v1/webhooks` `{"url", "events"}` | Register. `url` is https and resolves to a public address; `events` is a list from the table below or `["*"]`. The answer carries `data.webhook.secret`, **once** |
| `GET /api/v1/webhooks`, `GET /api/v1/webhooks/{webhook}` | List, read (`failure_count`, `last_triggered_at`) |
| `PUT /api/v1/webhooks/{webhook}` | Change `url`, `events`, `is_active` |
| `DELETE /api/v1/webhooks/{webhook}` | Remove |
| `GET /api/v1/webhooks/events` | The events there are |
| `POST /api/v1/webhooks/{webhook}/test` | Send a `webhook.test` delivery now; `200` if your endpoint answered 2xx, else `422` |
| `POST /api/v1/webhooks/{webhook}/rotate-secret` | A new secret, answered once (`data.secret`). For 24 hours (`data.previous_valid_until`) deliveries carry a signature with each secret; `{"revoke_previous": true}` ends the old one at once, for a secret that leaked |
| `GET /api/v1/webhooks/{webhook}/logs` | Every delivery attempt, paginated (`per_page`, default 20) |

The bearer-token API has the same routes under `/api/webhooks` (registering,
changing, removing and rotating are an administrator's). Portal ›
Integrations › Webhooks does all of it without code, and shows each attempt
with personal data taken out. The secret is stored encrypted and never shown
again; lost, rotate it.

## Rotating the secret

Rotate, put the new secret in your receiver, and deploy it at your pace:
for 24 hours after a rotation every delivery carries two signatures in
`X-Webhook-Signature`, one with the new secret and one with the old, so a
receiver holding either verifies it (the samples below accept any match).
After that only the new secret signs. Rotating again within the window
keeps only the secret just replaced. If the old secret leaked, rotate with
`{"revoke_previous": true}` (in the portal: *end the old secret now*): only
the new one signs from that moment.

## What arrives

```http
POST /your/endpoint
Content-Type: application/json
X-Webhook-ID: 9b2f0c1e-...            the same on every retry of this delivery
X-Webhook-Event: invoice.cleared
X-Webhook-Timestamp: 2026-01-01T09:30:00.000000Z
X-Webhook-Signature: sha256=5d41402abc...
User-Agent: <product>-Webhook/1.0

{"id":"9b2f0c1e-...","event":"invoice.cleared","timestamp":"2026-01-01T09:30:00.000000Z","data":{...}}
```

## Events

| Event | Country | Sent when | `data` carries |
|---|---|---|---|
| `invoice.issued` | both | An invoice is issued by the pipeline or the portal (not by `/compliance/generate`) | `invoice_id`, `invoice_number`, `status`, `type`, `total`, `currency`; UAE: `e_invoice` (`id`, `status`, `reporting_status`, `message_id`) |
| `invoice.submitted` | SA | Sent to ZATCA from the queue | submission fields, `submitted_at` |
| `invoice.cleared` | SA | A standard invoice cleared without warnings | submission fields, `clearance_status`, `cleared_at`, `invoice_hash` |
| `invoice.reported` | SA | A simplified invoice reported without warnings | submission fields, `reporting_status` |
| `invoice.warning` | SA | Accepted **with warnings**, instead of cleared or reported | submission fields, `warnings` (`code`, `category`, `message`) |
| `invoice.rejected` | SA | Rejected | submission fields, `errors` (each rule), `error_count` |
| `invoice.failed` | SA | A filing attempt failed without a verdict | submission fields, `will_retry` or `permanent` |
| `onboarding.ccsid_obtained`, `onboarding.compliance_passed`, `onboarding.pcsid_obtained` | SA | A device's compliance certificate, compliance checks, production certificate | `org_id`, `branch_id`, `unit_id`, `occurred_at` |
| `certificate.expiring` | SA | A device's certificate nears expiry, one event per device | `org_id`, `device_id`, `days_remaining`, `expiry_date`, `severity`, `message` |
| `e_invoice.sent`, `e_invoice.delivered`, `e_invoice.rejected`, `e_invoice.failed` | UAE | Delivery to the buyer's provider: taken, accepted (`AB`/`AP`), refused, unanswered | e-invoice fields (`errors` on a rejection) |
| `e_invoice.reported`, `e_invoice.report_failed` | UAE | The tax data's report to the authority: confirmed, failed (asked again hourly) | e-invoice fields, `authority_reference` / `reporting_errors` |
| `e_invoice.received` | UAE | Another supplier's e-invoice arrives | e-invoice fields |
| `schedule.run_created`, `schedule.failed` | both | A schedule made its invoice for a period / could not (retried after 15, 30, 60, 120 minutes) | schedule fields, `invoice_id`, `invoice_number`, `invoice_status`, `total`, `currency` / `error`, `attempts`, `will_retry` |
| `webhook.test` | both | A test | `message`, `webhook_id` |

Submission fields: `submission_id`, `invoice_id`, `org_id`, `invoice_number`,
`invoice_type`, `issue_date`, `total_amount`, `currency`, `icv`, `state`,
`previous_state`, `submission_type`, `zatca_uuid`, `timestamp`. E-invoice
fields (under `e_invoice`): `id`, `invoice_id` (null when received),
`number`, `direction`, `status`, `reporting_status`, `message_id`,
`mls_code`, `errors`, `authority_reference`, `reporting_errors`, `sender`,
`receiver`. Schedule fields: `schedule_id`, `schedule_name`, `org_id`,
`run_id`, `period`, `period_start`, `period_end`.

To hear of every filed Saudi invoice by name, subscribe to `invoice.cleared`,
`invoice.reported` **and** `invoice.warning`. A UAE invoice gets
`invoice.issued` and then the `e_invoice.*` events; delivery and report
arrive separately and in either order. Drafts are never announced.

## Verifying the signature

`X-Webhook-Signature` is `sha256=` and the lowercase hex HMAC-SHA256 of the
**raw request body**, keyed with the webhook's secret. During a secret
rotation it carries two, separated by a comma (`sha256=<new>,sha256=<old>`):
accept the delivery when any one matches.

1. Hash the bytes received; re-serialised JSON will not match.
2. Compare each signature in the header with yours in constant time, prefix
   included.
3. Refuse a delivery whose `timestamp` (inside the signed body, fresh on every
   attempt) is more than five minutes from your clock, so a delivery captured
   and sent again later is refused. `X-Webhook-Timestamp` repeats it, but only
   the body's is signed.

```php
$verify = function (string $rawBody, string $header, string $secret, int $tolerance = 300): bool {
    $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    $signed = false;
    foreach (explode(',', $header) as $given) {
        $signed = hash_equals($expected, trim($given)) || $signed;
    }
    $sent = $signed ? strtotime((string) (json_decode($rawBody, true)['timestamp'] ?? '')) : false;

    return $sent !== false && abs(time() - $sent) <= $tolerance;
};

$body = $request->getContent();                       // plain PHP: file_get_contents('php://input')
$valid = $verify($body, (string) $request->header('X-Webhook-Signature'), $secret);
```

```js
import { createHmac, timingSafeEqual } from 'node:crypto';
// rawBody is a Buffer: in Express, express.raw({ type: 'application/json' })
function verify(rawBody, header, secret, toleranceSeconds = 300) {
  const expected = Buffer.from('sha256=' + createHmac('sha256', secret).update(rawBody).digest('hex'));
  const signed = (header ?? '').split(',').some((given) => {
    const candidate = Buffer.from(given.trim());
    return candidate.length === expected.length && timingSafeEqual(candidate, expected);
  });
  if (!signed) return false;
  const sent = Date.parse(JSON.parse(rawBody.toString('utf8')).timestamp);
  return Math.abs(Date.now() - sent) <= toleranceSeconds * 1000;
}
```

```python
import hashlib, hmac, json
from datetime import datetime, timezone

def verify(raw_body: bytes, header: str | None, secret: str, tolerance: int = 300) -> bool:
    expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
    if not any(hmac.compare_digest(expected, given.strip()) for given in (header or "").split(",")):
        return False
    sent = datetime.fromisoformat(json.loads(raw_body)["timestamp"].replace("Z", "+00:00"))
    return abs((datetime.now(timezone.utc) - sent).total_seconds()) <= tolerance
```

Java, C# and Go are the same three steps: `HmacSHA256` with
`MessageDigest.isEqual`, `HMACSHA256` with
`CryptographicOperations.FixedTimeEquals`, `hmac.New(sha256.New, …)` with
`hmac.Equal`. The public `/developers` page has these samples in curl too.

## Answering and retries

- Answer any 2xx within 30 seconds; do the work afterwards, on a queue. A
  redirect is a failure: register the final URL.
- No answer, a timeout, `408`, `429` or `5xx`: tried again after about 30
  seconds, 2, 10 and 30 minutes (five attempts). Any other answer, a `4xx`
  included, is final.
- Every attempt carries the same `X-Webhook-ID`: record the ones processed
  and skip repeats.
- After ten failed deliveries in a row with nothing delivered for a day, the
  endpoint is switched off. `PUT /api/v1/webhooks/{webhook}` with
  `{"is_active": true}` switches it back on and clears the count.
