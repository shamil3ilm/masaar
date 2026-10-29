# Errors

Audience: developers of ERPs, POS systems and partner platforms handling the
API's refusals. Every code the API can answer with is on this page, with what
to do about it; the rest of the API is in [API.md](API.md).

## The envelope

```json
{"success": true, "message": "...", "data": {}}
{"success": false, "error": {"code": "...", "message": "...", "category": "...", "retryable": false, "details": {}}}
```

`retryable` is true for 408, 429, 502, 503 and 504; a retryable compliance
error also carries `retry_after` and `max_retries`, and `Retry-After` is set
whenever a retryable error has a wait. Every answer carries `X-Request-Id`
(yours, if you sent a valid one); quote it when asking for help.

In short: when `retryable` is true, send the same request again under the
same `Idempotency-Key` after `Retry-After`; when it is false, sending the same
request again gets the same answer, so fix the cause first.

## Platform codes

They follow the status: `BAD_REQUEST` 400, `UNAUTHORIZED` 401, `FORBIDDEN`
403, `NOT_FOUND` 404, `METHOD_NOT_ALLOWED` 405, `REQUEST_TIMEOUT` 408,
`CONFLICT` 409, `PAYLOAD_TOO_LARGE` 413, `VALIDATION_ERROR` 422 (with
`details` per field), `RATE_LIMITED` 429, `SERVER_ERROR` 500,
`UPSTREAM_ERROR` 502, `SERVICE_UNAVAILABLE` 503, `UPSTREAM_TIMEOUT` 504. Fix
the request for a 4xx (a 422 names each field in `details`); retry a 408, 429
or 502-504 later; report a 500 with its `X-Request-Id`.

## Named codes

| HTTP | `error.code` | Meaning | What to do |
|---|---|---|---|
| 401 | `LICENSE_INVALID_KEY` | No key, an unknown key or a wrong secret, answered alike | Check both headers; too many tries are rate limited |
| 403 | `LICENSE_EXPIRED`, `LICENSE_SUSPENDED`, `LICENSE_REVOKED`, `LICENSE_INACTIVE`, `LICENSE_PENDING_ACTIVATION` | The licence cannot be used | Ask the platform operator to renew or activate it |
| 403 | `API_KEY_EXPIRED`, `API_KEY_PENDING_APPROVAL`, `API_KEY_ADDRESS_NOT_ALLOWED` | An organization key is past its expiry, awaits approval, or was used from an address not on its list | Issue a new key, wait for approval, or add the address in Portal › Integrations |
| 403 | `AUTH_SCOPE_DENIED` | `details.required_scope` is missing | Give the key that scope |
| 403 | `LICENSE_FEATURE_UNAVAILABLE` | `details.feature` is missing | Move to a plan with that feature |
| 403 | `AUTH_ENV_MISMATCH` | A sandbox key on a route that issues or files, for an organization filing live | Use a production key |
| 403 | `LICENSE_INVALID` | A self-hosted deployment's own licence is not valid | The deployment's operator renews it |
| 403 | `FORBIDDEN` | `org_id` in the body is not the key's organization | Send the key's own `org_id` |
| 409 | `RECORD_IN_USE` | A customer or product an invoice used | Archive it instead (`details.archive`) |
| 409 | `IDEMPOTENCY_KEY_IN_PROGRESS` | A request under the same `Idempotency-Key` is still being answered | Send it again shortly; the first one's answer comes back |
| 422 | `IDEMPOTENCY_KEY_REUSED` | The `Idempotency-Key` was used with another body | Use a new key for a new request |
| 422 | `JURISDICTION_UNAVAILABLE` | The organization's country is switched off on this deployment | Ask the operator; nothing new can be issued meanwhile |
| 422 | `JURISDICTION_ENDPOINT_MISMATCH` | A UAE organization on `/api/v1/compliance/*` | Use the routes in `details.use` |
| 422 | `CERTIFICATE_ERROR` | A certificate or credential refused during onboarding | Start onboarding again with a new OTP |
| 429 | `LICENSE_RATE_LIMITED` | A licence call limit, or too many wrong credentials | Wait `Retry-After` |
| 429 | `LICENSE_QUOTA_EXCEEDED` | The month's invoice allowance is used up; `details` has `limit_type`, `limit`, `current` | Wait for next month or raise the plan |
| 500 | `LICENSE_SYSTEM_ERROR`, `SIGNING_ERROR` | Ours; retrying the same request will not help | Report it with the `X-Request-Id` |

## Compliance codes

The category comes from the prefix.

| Code | HTTP | Retry | Meaning | What to do |
|---|---|---|---|---|
| `AUTH_006` / `AUTH_011` / `AUTH_012` | 403 | no | Organization suspended / not yet approved to file / no active plan | The business resolves it with the operator |
| `AUTH_010` | 403 | no | Credential environment does not match the target | Onboard the device in the organization's environment |
| `AUTH_013` | 403 | no | Email address not verified | Verify it from the email sent |
| `AUTH_014` | 403 | no | A feature (the pipeline, batching) switched off for the organization by a platform administrator, or the account frozen for an overdue payment (`details.reason` `account_frozen`; notes correcting issued invoices still go through) | Ask the operator, or settle the overdue payment |
| `VAL_001` / `VAL_002` | 400 | no | A field missing / unreadable (also: a `branch_id` or till not the organization's) | Fix the field and send again |
| `VAL_025` | 422 | no | Already issued; issuing again would fork the hash chain | Read the invoice's status instead |
| `VAL_026` | 409 | no | The organization already has an invoice with this number - often a request sent again without its `Idempotency-Key` after a timeout | Read that invoice; send retries under the first call's key |
| `VAL_099` | 422 | no | The invoice did not pass validation | Fix what `details` names |
| `ZATCA_003` / `ZATCA_005` | 422 | no | The authority rejected the hash / QR | Report it with the `X-Request-Id` |
| `ZATCA_008` / `ZATCA_009` | 409 | no | Already cleared / reported | Nothing: it is filed |
| `ZATCA_016`, `ZATCA_019` | 503 | yes | The authority is unavailable / in maintenance | Wait; the platform also retries |
| `ZATCA_017` | 504 | yes | The authority timed out | Wait; the platform reconciles the answer |
| `ZATCA_018` | 429 | yes | The authority's rate limit | Wait `Retry-After` |
| `ZATCA_020` | 502 | no | The authority refused the device's certificate and secret: the device is paused ([API.md](API.md#filing-states-what-you-see)) | Renew or onboard the device again; what waited is then filed |
| `ZATCA_021` | 502 | no | The authority did not process the document (404, 409, 413, an unreadable 400); nothing was judged | Report it with the `X-Request-Id` |
| `CERT_001` / `CERT_002` / `CERT_004` / `CERT_005` | 404 / 403 / 403 / 422 | no | Certificate not found / expired / revoked / unreadable | Onboard the device again |
| `CERT_006` | 503 | yes | Revocation status could not be checked | Wait and send again |
| `NET_001`, `NET_003`, `NET_006` | 502 | yes | The authority could not be reached | Wait; the platform queues and retries |
| `NET_002` | 504 | yes | Connection timed out | Wait and send again under the same key |
| `RATE_001`, `RATE_002`, `RATE_003`, `RATE_004` | 429 | yes | Platform rate, quota, concurrency or daily limit | Wait `Retry-After`; send fewer at once |
| `SYS_001` / `SYS_007` | 500 / 503 | no / yes | Internal error / maintenance | Report a 500; wait out a 503 |
| `SYS_008` | 503 | yes | Busy: the device was issuing another invoice for longer than the request could wait, or the database undid the request against another one. Nothing was issued: the counter and chain are as they were. A draft the pipeline made is kept, and the retry issues it | Send the same request again under the same `Idempotency-Key` after `Retry-After`: as long as an issuance waits for a busy device, 5 seconds unless the deployment changes it. A till without credentials of its own shares head office's device, so a busy store sees this more; give each till its own |
| `IDEM_003` / `IDEM_004` | 400 / 409 | no / yes | Key reused with another request / still being processed | Use a new key / wait for the first request's answer |

Licence errors from the credential check and the invoice allowance carry
`category` `licensing`; scope and feature refusals carry `license`.
