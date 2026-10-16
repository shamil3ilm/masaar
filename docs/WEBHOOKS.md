# Receiving Webhooks

Masaar tells your system when something happens to an invoice, to onboarding
or to a certificate, by POSTing JSON to the URL you register. Every delivery is
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

Events: `invoice.created`, `invoice.updated`, `invoice.issued`,
`invoice.submitted`, `invoice.cleared`, `invoice.reported`, `invoice.rejected`,
`onboarding.ccsid_obtained`, `onboarding.compliance_passed`,
`onboarding.pcsid_obtained`, `certificate.expiring`, and `webhook.test` when you
send a test from the API.

The secret is returned once, when the webhook is created, and again when you
rotate it (`POST /api/webhooks/{id}/rotate-secret`).

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
- A delivery is tried up to five times: once, then again after about 30
  seconds, 2 minutes, 10 minutes and 30 minutes.
- Retries carry the same `X-Webhook-ID`. Record the IDs you have processed and
  skip a repeat, so a retry after a slow answer does not act twice.

## Client libraries

There are no official client libraries. The API is described by
[`docs/openapi.yaml`](openapi.yaml), generated from the routes and checked in
CI, so any OpenAPI generator produces a complete client for your language.
