# Quickstart: first call to first filed invoice

Audience: a developer connecting an ERP, POS or store to the platform for the
first time. Ten minutes, against the sandbox.

Every request and answer on this page is sent through the application by
`tests/Feature/Docs/QuickstartExamplesTest.php`, so what you read here is what
the API does.

## 0. What you need

| You need | Where it is |
|---|---|
| A sandbox API key and secret | Portal › Integrations › API keys › New key. Environment **sandbox**, scopes `invoice.submit`, `compliance.status` and `invoice.read`. The secret is shown once. A sandbox key starts `cp_test_` |
| Your organization id (`org_id`) | The top of the same page: **Organization ID**, a UUID |
| An organization connected to ZATCA's sandbox | Portal › ZATCA. The sandbox certificate is issued for ZATCA's test taxpayer only, so the organization's VAT number is `399999999900003` |
| The base URL | Your deployment, for example `https://sandbox.example.com` |

```bash
export base_url=https://sandbox.example.com
export api_key=cp_test_...
export api_secret=...
```

## 1. Submit an invoice

One call drafts the invoice, issues it (number, hash, signature, QR) and files
it with the authority. Save this simplified (B2C) invoice as `invoice.json`,
with your `org_id` and today's date (`YYYY-MM-DD`) in place of the placeholders:

<!-- example: POST /api/v1/pipeline/submit sa -->
```json
{
  "org_id": "<your organization id>",
  "type": "simplified",
  "document_type": "invoice",
  "issue_date": "<today>",
  "erp_reference_id": "SO-0001",
  "payment_means_code": "10",
  "lines": [
    { "description": "Coffee beans 1 kg", "description_ar": "حبوب قهوة 1 كجم", "quantity": 2, "unit_price": "60.00", "tax_rate": 15 }
  ]
}
```

```bash
curl -X POST "$base_url/api/v1/pipeline/submit" \
  -H "X-API-Key: $api_key" -H "X-API-Secret: $api_secret" \
  -H "Idempotency-Key: quickstart-0001" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  --data @invoice.json
```

`201`. The invoice is issued and queued for ZATCA; a simplified invoice is
reported within the 24 hours ZATCA allows, usually seconds later:

<!-- quickstart: submit 201 -->
```json
{
  "success": true,
  "message": "Invoice submitted through the pipeline",
  "data": {
    "invoice_id": "<uuid>",
    "invoice_number": "<from your sequence>",
    "erp_reference_id": "SO-0001",
    "status": "issued",
    "compliance_status": "pending",
    "hash": "<base64>",
    "qr_code": "<base64>",
    "totals": { "subtotal": "120.00", "discount_amount": "0.00", "tax_amount": "18.00", "total": "138.00" },
    "errors": []
  }
}
```

Keep `invoice_id`; the next step reads it as `$invoice_id`. Print the QR on
the receipt now; it does not change.

## 2. Follow it until it is filed

```bash
curl "$base_url/api/v1/pipeline/status/$invoice_id" \
  -H "X-API-Key: $api_key" -H "X-API-Secret: $api_secret" -H "Accept: application/json"
```

<!-- quickstart: status 200 -->
```json
{
  "success": true,
  "message": "Invoice status retrieved",
  "data": {
    "invoice_id": "<uuid>",
    "status": "accepted",
    "type": "simplified",
    "totals": { "subtotal": "120.00", "discount_amount": "0.00", "tax_amount": "18.00", "total": "138.00" }
  }
}
```

`accepted` means ZATCA reported it: the invoice is filed. In production,
subscribe to the `invoice.reported`, `invoice.warning` and `invoice.rejected`
webhooks instead of polling ([WEBHOOKS.md](WEBHOOKS.md)).

## 3. Retry safely

Run the same `curl` from step 1 again, as your ERP would after a timeout. The
same `Idempotency-Key` with the same body answers with the same invoice; no
second invoice is made:

<!-- quickstart: replay 201 -->
```json
{
  "success": true,
  "data": { "invoice_id": "<the same uuid>", "status": "accepted", "compliance_status": "reported", "errors": [] }
}
```

The same key with a different body is refused, and nothing is made. Use a new
key for a new invoice:

<!-- quickstart: reused 422 -->
```json
{
  "success": false,
  "error": { "code": "IDEMPOTENCY_KEY_REUSED", "category": "idempotency", "retryable": false }
}
```

## 4. When the credentials are wrong

A missing key, an unknown key and a wrong secret all answer the same, so the
answer never says which keys exist:

<!-- quickstart: wrong-secret 401 -->
```json
{
  "success": false,
  "error": { "code": "LICENSE_INVALID_KEY", "message": "Invalid API key or secret", "category": "licensing", "retryable": false }
}
```

Ten wrong secrets for one key from one address in five minutes (or thirty
across keys) lock that address out with `429 LICENSE_RATE_LIMITED` and a
`Retry-After` header.

## 5. Keep your copy in step

An ERP that keeps its own copy of the invoices asks for what changed since
it last looked, and walks the answer by cursor: send `cursor=` empty for
the first page, then each page's `meta.next_cursor`, until it is `null`.
The order is oldest change first, so every invoice changed since `since`
comes once, even while new ones are being made.

```bash
since="$last_sync"   # the updated_at your last sync ended at; empty the first time, for everything
cursor=""
while :; do
  page=$(curl -s -G "$base_url/api/v1/invoices" \
    --data-urlencode "updated_since=$since" --data-urlencode "cursor=$cursor" -d per_page=100 \
    -H "X-API-Key: $api_key" -H "X-API-Secret: $api_secret" -H "Accept: application/json")
  echo "$page" | jq -c '.data[]'    # save each invoice in your copy, by its id
  cursor=$(echo "$page" | jq -r '.meta.next_cursor // empty')
  [ -z "$cursor" ] && break
done
```

Each page:

<!-- quickstart: sync 200 -->
```json
{
  "success": true,
  "data": [
    { "id": "<the same uuid>", "invoice_number": "<from your sequence>", "erp_reference_id": "SO-0001", "status": "accepted", "updated_at": "<when it last changed>" }
  ],
  "meta": { "per_page": 100, "next_cursor": null }
}
```

Keep the `updated_at` of the last invoice you saved as `$last_sync` for the
next run. The bound is inclusive, so the next run may bring that invoice
again: save by `id` and it is simply updated in place.

## Next

- [API.md](API.md): authentication, scopes, every error code, limits,
  idempotency, filing states, pagination and versioning.
- [SAUDI.md](SAUDI.md) and [UAE.md](UAE.md): the invoice fields each country
  takes, standard (B2B) invoices, notes and the country's own endpoints.
- [WEBHOOKS.md](WEBHOOKS.md): events and signature verification.
- Going live: a production key (`cp_live_`) is refused nothing a sandbox key
  was, but a sandbox key is refused (`403 AUTH_ENV_MISMATCH`) once the
  organization files with ZATCA's production environment.
