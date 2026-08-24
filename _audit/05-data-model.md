# 05 — Data Model

Sixteen migrations, ordinal-numbered `0010`–`0190`, all in
[`database/migrations/`](../database/migrations/). Squashed/consolidated rather
than incremental — there is one migration per table group, not a history of
`add_x_to_y` files. A generated `docs/schema.sql` accompanies them.

---

## Required-field audit

Every field the brief asked about, against the schema as it stands.

### ✅ Present and correct

| Field | Where | Notes |
|---|---|---|
| **Seller VAT number** | `organizations.vat_number` varchar(15) nullable — [`0050:17`](../database/migrations/0050_organizations.php#L17) | Length 15 is right for a Saudi VAT number. Covered by `tests/Feature/Organization/VatNumberTest.php`. |
| **Seller TIN / CR** | `organizations.cr_number` varchar(20) nullable — [`0050:25`](../database/migrations/0050_organizations.php#L25) | Commercial Registration. |
| **Seller address** | `organizations.{street,building_number,additional_street,district,city,postal_code}` — [`0050:19-24`](../database/migrations/0050_organizations.php#L19-L24) | `postal_code` char(5) — correct for KSA. All six ZATCA address parts present. |
| **Buyer identifiers** | `invoices.{buyer_name,buyer_vat_number,buyer_address}` — [`0080:26-28`](../database/migrations/0080_invoices.php#L26-L28) | `buyer_address` is `text`, cast to `array` (`Invoice.php:86`) — a JSON blob, not columns. |
| **Invoice UUID** | `invoices.id` uuid PRIMARY KEY — [`0080:14`](../database/migrations/0080_invoices.php#L14) | `HasUuids`; doubles as the ZATCA submission UUID (`Submitter.php:176`). |
| **ICV** | `invoices.icv` unsignedBigInteger nullable — [`0080:57`](../database/migrations/0080_invoices.php#L57) | **`unique(['org_id','icv'])`** at [`0080:66`](../database/migrations/0080_invoices.php#L66). |
| **PIH** | *derived* — `Invoice::getPreviousInvoiceHashAttribute()` [`Invoice.php:364-372`](../app/Domains/Invoice/Models/Invoice.php#L364-L372) | Deliberately no column; see "Two chains" below. |
| **Invoice hash** | `invoices.hash` varchar(255) nullable — [`0080:39`](../database/migrations/0080_invoices.php#L39) | |
| **QR payload** | `invoices.qr_code` text nullable — [`0080:40`](../database/migrations/0080_invoices.php#L40) | Base64 TLV; `text` is ample for 9 tags. |
| **Signed XML** | `invoices.signed_xml` longText nullable — [`0080:41`](../database/migrations/0080_invoices.php#L41) | |
| **Submission status** | `invoice_submissions.state` enum(10) — [`0140:50`](../database/migrations/0140_submissions.php#L50) | `draft·queued·pending_submission·submitted·cleared·reported·warning·rejected·failed·cancelled` |
| **ZATCA response payload** | `invoices.zatca_response` json — [`0080:58`](../database/migrations/0080_invoices.php#L58); `submission_idempotency.response_body`/`response_headers` — [`0140:23-24`](../database/migrations/0140_submissions.php#L23-L24) | Three layers of capture. |
| **Clearance/reporting timestamps** | `invoice_submissions.{cleared_at,queued_at,signed_at,submitted_at,completed_at}` — [`0140:58,70-73`](../database/migrations/0140_submissions.php#L70-L73) | Genuinely granular. |
| **Warning / error arrays** | `invoice_submissions.zatca_warnings` json + `zatca_errors` json — [`0140:60-61`](../database/migrations/0140_submissions.php#L60-L61) | **Separate columns** — the distinction ZATCA requires is modelled, not collapsed. |
| **Invoice-type flags** | `invoices.{is_third_party,is_nominal,is_export,is_summary,is_self_billed}` boolean default false, each `->comment()`-ed with its BT-3 bit — [`0080:50-54`](../database/migrations/0080_invoices.php#L50-L54) | The columns from your brief. Mapping verified end-to-end by `InvoiceTypeCodeTest`. |
| **Foreign currency** | `invoices.currency` char(3) default `SAR` + `exchange_rate` decimal(16,6) — [`0080:21-25`](../database/migrations/0080_invoices.php#L21-L25) | Docblock cites BR-KSA-CU-01 and explains the 6 dp. `ForeignCurrencyTest`, `ExchangeRateTest`. |
| **Tax categories** | `invoice_lines.{tax_category char(1) default 'S', exempt_code, exempt_reason}` — [`0080:87-89`](../database/migrations/0080_invoices.php#L87-L89) | |
| **EGS unit** | `invoices.branch_id` uuid nullable FK → `branches` — [`0080:17,60,71`](../database/migrations/0080_invoices.php#L17) | |
| **ERP correlation** | `invoices.erp_reference_id` varchar(255) indexed — [`0080:55,61`](../database/migrations/0080_invoices.php#L55) | |
| **Determinism metadata** | `invoices.{rule_version,schema_version,determined_at,signature_algorithm,hash_algorithm,cert_id}` — [`0080:43-48`](../database/migrations/0080_invoices.php#L43-L48) | Records *which rules* produced the document. Better than most implementations. |

### ⚠️ Arabic fields — PARTIAL

There are **no bilingual columns**. `organizations.name` and `invoices.buyer_name`
are single varchar(255). `TextNormalizer` (297 L) handles Arabic text properly
and `SellerNameBytesTest` guards byte length, but if ZATCA mandates a *separate*
Arabic party name alongside the Latin one, the schema cannot express it.

ASSUMPTION: a single field carrying Arabic satisfies the requirement. **Unverified
— see [06-risks.md](06-risks.md) R-3.** If wrong, this is a migration, not a
redesign.

---

## Two chains, one truth — the schema's sharpest edge

The PIH exists **twice**, computed two different ways:

| | Source | Mechanism |
|---|---|---|
| **A** | `invoices` table | `getPreviousInvoiceHashAttribute()` — live query: `where org_id … where icv < $this->icv … whereNotNull('hash') … orderByDesc('icv')->value('hash')` ([`Invoice.php:364-372`](../app/Domains/Invoice/Models/Invoice.php#L364-L372)) |
| **B** | `hash_chain_state` + `hash_chain_history` | Stored rows: `last_hash`, `last_icv`, `last_invoice_id`, `certificate_id`, `cert_transition` ([`0160`](../database/migrations/0160_hash_chain.php)) |

**Every consumer reads A.** `Submitter.php:66,99,165`,
`ProcessFatooraSubmission.php:141`, `OfflineFallback.php:104` — all use the
accessor. B is written and read by `OfflineQueue.php:384,417`,
`VerifyHashChain.php:124`, and the platform dashboard.

The accessor is well-reasoned — its docblock explains ordering by ICV rather
than `created_at` "because wall-clock is not deterministic under concurrent
inserts", and why the tenant scope is lifted. That is right.

But **nothing asserts A and B agree**, and they can diverge: `hash_chain_history`
has only a *non-unique* index on `(org_id, icv)` ([`0160:37`](../database/migrations/0160_hash_chain.php#L37))
while `invoices` has a *unique* constraint on the same pair. The table designed
to be the tamper-evident record of the chain has weaker integrity guarantees
than the table it is meant to attest.

---

## Concurrency & constraint review

| Constraint | Status |
|---|---|
| `invoices` unique `(org_id, icv)` | ✅ Present — the backstop that makes ICV allocation safe |
| `submission_idempotency` unique `idempotency_key` | ✅ Present ([`0140:37`](../database/migrations/0140_submissions.php#L37)) |
| `hash_chain_state` PK `org_id` | ✅ One row per tenant — "a second row would mean two competing heads" (`ChainState.php:14-16`) |
| `hash_chain_history` unique `(org_id, icv)` | ❌ **Missing** — index only |
| `invoices` unique `(org_id, invoice_number)` | ❌ **Missing** — index only ([`0080:62`](../database/migrations/0080_invoices.php#L62)) |
| FKs with correct cascade | ✅ `org_id` cascades; `branch_id`/`profile_id` null on delete |

---

## Nullability review

Correctly nullable (populated later in the lifecycle): `hash`, `qr_code`,
`signed_xml`, `icv`, `zatca_response`, `cert_id`, `exchange_rate`,
`buyer_vat_number` (a B2C buyer has none).

**Questionable:**

- **`invoices.icv` is nullable** ([`0080:57`](../database/migrations/0080_invoices.php#L57)).
  It is auto-assigned in `boot::creating` *only if* `org_id` is set
  ([`Invoice.php:151-155`](../app/Domains/Invoice/Models/Invoice.php#L151-L155)).
  Nullable is defensible for a draft, but an **Issued** invoice with a null ICV
  is a compliance failure the schema permits.
- **`invoices.status` and `type` are plain varchar(255)**
  ([`0080:19-20`](../database/migrations/0080_invoices.php#L19-L20)) while
  `invoice_submissions.state` is a proper `enum`. Enforcement lives only in the
  PHP cast. Inconsistent, and the weaker choice is on the more important table.
- **`hash_chain_state.certificate_id` is NOT NULL** ([`0160:18`](../database/migrations/0160_hash_chain.php#L18))
  but `invoices.cert_id` is nullable. A pre-onboarding invoice cannot get a chain
  state row.

---

## Indexing review

Well indexed: `invoices` carries 9 indexes, `invoice_submissions` 13.
`compliance:index-health --alert` runs daily to catch drift.

**Gaps:**

| Missing index | Why it matters |
|---|---|
| `invoices.buyer_vat_number` | Gap-matrix item 35 asks for retrieval **by VAT number**. Seller-VAT works via `org_id`; **buyer-VAT is a full table scan.** |
| `invoices.(org_id, document_type)` | `document_type` unindexed entirely; "all credit notes this quarter" scans. |
| `invoices.(org_id, status, issue_date)` | The natural reconciliation query (item 40) has no covering index. |
| `hash_chain_history` unique `(org_id, icv)` | Integrity, not performance — see above. |

`invoices` is also missing an index on `supply_date`, which is the VAT-period
determinant in some cases (`VatPeriodTracker` exists but queries `issue_date`).

---

## Required migrations

Dependency-ordered. None is large; together roughly **6–9 hours** including tests.

### Priority 1 — correctness

**`0200_cleared_xml.php`** — unblocks gap item 34 / risk R-1.
```php
Schema::table('invoices', function (Blueprint $t) {
    // ZATCA returns the cleared document for B2B; that is the legal invoice,
    // not the one we signed. signed_xml stays as the pre-clearance artefact.
    $t->longText('cleared_xml')->nullable()->after('signed_xml');
    $t->timestamp('cleared_xml_received_at')->nullable()->after('cleared_xml');
});
```
Then wire `FatooraResponse::$clearedInvoice` → this column in
`Submitter::updateInvoiceStatus()`, and make invoice delivery/retrieval prefer
`cleared_xml` over `signed_xml` when present.

**`0210_chain_history_unique.php`** — closes the A/B divergence window.
```php
Schema::table('hash_chain_history', function (Blueprint $t) {
    $t->dropIndex('hash_chain_history_organization_id_icv_index');
    $t->unique(['org_id', 'icv'], 'hash_chain_history_org_icv_unique');
});
```
⚠️ Will fail on existing duplicates — run a detection query first.

**`0220_invoice_number_unique.php`**
```php
Schema::table('invoices', function (Blueprint $t) {
    $t->dropIndex('invoices_invoice_number_index');
    $t->unique(['org_id', 'invoice_number'], 'invoices_org_number_unique');
});
```
An invoice number must be unique per taxpayer. Today only a non-unique index
guards it, and `DuplicateDetector` enforces it in application code only.

### Priority 2 — retrieval

**`0230_retrieval_indexes.php`**
```php
Schema::table('invoices', function (Blueprint $t) {
    $t->index('buyer_vat_number', 'invoices_buyer_vat_idx');
    $t->index(['org_id', 'document_type'], 'invoices_org_doctype_idx');
    $t->index(['org_id', 'status', 'issue_date'], 'invoices_org_status_date_idx');
});
```

### Priority 3 — depends on unresolved questions

**`0240_arabic_party_names.php`** — *only if* R-3 confirms separate Arabic
fields are mandated:
```php
$t->string('name_ar', 255)->nullable();       // organizations
$t->string('buyer_name_ar', 255)->nullable(); // invoices
```

**`0250_branch_scoped_chain.php`** — *only if* R-3 confirms the ICV/PIH chain is
**per EGS unit** rather than per taxpayer. This one is **not small**: it
re-keys `hash_chain_state` from `org_id` to `(org_id, branch_id)`, changes the
`invoices` unique constraint to `(org_id, branch_id, icv)`, and rewrites
`generateNextIcv()` and `getPreviousInvoiceHashAttribute()`. **Resolve the spec
question before writing any of it** — and before onboarding a second branch,
because migrating a live chain is far worse than getting it right first.

### Not recommended yet

Tightening `invoices.icv` to NOT NULL, or converting `status`/`type` to enums,
would be correct but both are breaking changes to a schema that has no
production data. Do them **after** the sandbox run, when you know the shape is
final — not before.
