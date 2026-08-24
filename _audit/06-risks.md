# 06 — Risk Register

Assessed against `main`.

| ID | Risk | Severity | Evidence | Fix | Effort |
|---|---|---|---|---|---|
| **R-1** | The conformance suite is built but never runs | 🔴 CRITICAL | `ZatcaSdk.php:34-47` skips unless `ZATCA_SDK_PATH` is set; twelve `ZatcaConformanceTest` cases stand down. No generated document has met ZATCA’s schema, EN16931 rules or Schematron. | Set `ZATCA_SDK_PATH`, run the suite, fix what it reports. | 2–4 h |
| **R-2** | One encryption secret covers every tenant; no KMS | 🟠 HIGH | `CredentialStore::cipher()` `:60-76`. The docblock `:29-31` names this as the open half of a prior finding. | Per-tenant data key wrapped by a KMS. `cipher()` is the only place that changes — the class was built for this. | 8–16 h |
| **R-3** | **Four unresolved ZATCA spec questions.** Nothing in the codebase can settle them. | 🔴 CRITICAL | See table below | Read the spec / ask ZATCA. Do **not** guess. | 4–8 h research |
| **R-4** | B2B clearance is treated as non-blocking | 🟠 HIGH | `PipelineService.php:30-32` "once an invoice is issued it stays issued"; `:143-189` catches all; issuance at `Submitter.php:80-86` precedes submission. | Split the rule by document type — see "Failure paths" below. | 6–10 h |
| **R-5** | The submitted signature ≠ the archived signature | 🟠 HIGH | `generateComplianceData()` called twice per invoice: `Submitter.php:71` (issuance) and `:159` (submission). ECDSA `k` is random, so the two signatures differ over the same document. | Generate once, persist, re-read at submission. Or accept it and archive only `cleared_xml`. | 3–5 h |
| **R-6** | Two PIH sources of truth, one weakly constrained | 🟡 MEDIUM | Accessor `Invoice.php:364-372` derives from `invoices`; `hash_chain_state`/`_history` (`0160`) store it. `hash_chain_history (org_id, icv)` is a **non-unique index** (`0160:37`) while `invoices` has a unique constraint on the same pair. | Unique constraint on `hash_chain_history`; a reconciliation assertion in `fatoora:verify-hash-chain` that A == B. | 2–4 h |
| **R-7** | Silent fallback to the **wrong EC curve** | 🟠 HIGH | `FatooraGenerateCsr.php:307-309` and `:490-491`: if `secp256k1` is unavailable, retries with `prime256v1` behind a `warn()`. ZATCA requires secp256k1. | Make it fatal. A wrong-curve key onboards and then fails cryptographically, far from the cause. | 30 min |
| **R-8** | No reconciliation: issued vs cleared vs reported | 🟡 MEDIUM | Nothing found; `VatPeriodTracker` (319 L) is the natural host and does not do it. Data is all present. | Scheduled command: issued invoices with no terminal submission state, older than N hours → report/alert. | 4–6 h |
| **R-9** | No **push** alert when submissions fail platform-wide | 🟡 MEDIUM | `MetricsController.php:221-222` exposes `queue_failed_jobs_total` (pull-only; no Prometheus configured in repo). Tenant webhooks fire; nothing pages you. Contrast `CheckCertificateExpiry` `:171-188`, which pushes on a threshold ladder. | Mirror the certificate-alert pattern for submission failure rate. | 3–5 h |
| **R-10** | No retention policy | 🟡 MEDIUM | No `retention` config; not stated in `docs/`. ZATCA/GAZT require multi-year retention (commonly cited as 6 years for VAT — **unverified**, see R-3). | Confirm the period, then document and enforce it. Nothing currently deletes invoices, so this is a policy gap, not active data loss. | 2–3 h + research |
| **R-11** | 135 commits unpushed; `origin/main` 7 months stale | 🟠 HIGH | `git rev-list --count origin/main..HEAD` = **135**; `origin/main` HEAD `262514d` = **2026-02-03**. | `git push -u origin chore/security-remediation-and-cleanup`. | 5 min |
| **R-12** | masaar-erp-backend: 149 uncommitted changes incl. 41 staged deletions, dormant 3 months | 🟠 HIGH | `git status --short` in `masaar-erp-backend`; last commit `89b055f` 2026-05-24; 0 commits/90d; no CI. | Commit to a branch or stash with a message. The context is already gone; the work is one `checkout` from following it. | 1–2 h |
| **R-13** | Default `php` on this machine silently no-ops the test suite | 🟡 MEDIUM | `php -v` = 8.2.28; `composer.json` requires `^8.4`; `php artisan test` dies in `platform_check.php` **with exit code 0**. | Use the 8.4.12 binary, or make it the Laragon default. CI is already correct (`ci.yml` pins 8.4). | 15 min |
| **R-14** | `invoice_number` not unique per tenant | 🟡 MEDIUM | `0080:62` — non-unique index only. Enforced in app code by `DuplicateDetector`. | Unique `(org_id, invoice_number)` — see [05-data-model.md](05-data-model.md). | 1 h |
| **R-15** | Buyer-VAT lookup is a full table scan | 🟢 LOW | `invoices.buyer_vat_number` unindexed (`0080:28`). Gap item 35 asks for retrieval by VAT number. | Add index. | 30 min |
| **R-16** | `invoices.icv` nullable; `status`/`type` are varchar not enum | 🟢 LOW | `0080:19-20,57` vs `invoice_submissions.state` enum (`0140:50`). | Tighten **after** the sandbox run, when the shape is settled. | 2 h |
| **R-17** | `*.bak` not gitignored | 🟢 LOW | `.claude/settings.json.bak` untracked and unignored. `.gitignore` covers `.env.*` because a `.env.bak` nearly leaked a live `APP_KEY` — the same class of file elsewhere is uncovered. | Add `*.bak`. | 2 min |
| **R-18** | No `DebitNoteTest` | 🟢 LOW | `CreditNoteTest.php` exists; no debit-note equivalent. Both are in the six-type suite. | Mirror the credit-note test. | 1–2 h |

---

## R-3 in detail — the four questions only the spec can answer

These are flagged rather than guessed at. **Do not act on the assumptions
below without checking them against the specification.**

| # | Question | Current code | Why it matters |
|---|---|---|---|
| **Q1** | Is `CustomizationID` correct? | `urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod` — [`XmlBuilder.php:125`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L125) | This is a **generic OASIS** string. ASSUMPTION: ZATCA expects `urn:sa:zatca:documents:1.0`. A wrong value is likely rejected at the schematron gate. **Nothing asserts it.** |
| **Q2** | ~~`ProfileID` per document type~~ **SETTLED** | `reporting:1.0` for every type — [`XmlBuilder.php:127-137`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php#L127-L137) | Settled against ZATCA’s SDK samples: BT-23 is `reporting:1.0` on all nineteen, and the validator rejects anything else as `BR-KSA-EN16931-01`. Clearance is chosen by the endpoint, not by a field in the document. Pinned by `XmlProfileTest:66` and a negative assertion at `:75`. |
| **Q3** | Is the ICV/PIH chain per **taxpayer** or per **EGS unit**? | Per taxpayer — `hash_chain_state` PK is `org_id` ([`0160:14`](../database/migrations/0160_hash_chain.php#L14)), `generateNextIcv($organizationId)` ([`Invoice.php:212`](../app/Domains/Invoice/Models/Invoice.php#L212)) | Branches are modelled as EGS units *everywhere else* — separate certificates, separate onboarding, branch-scoped credential paths. If ZATCA requires a chain per unit, this is **the most expensive defect in the codebase**, and it gets worse with every invoice issued. |
| **Q4** | Is a separate Arabic party name mandated? | No bilingual columns; one `name` / `buyer_name` | Cheap now (a migration), expensive after data exists. |

**Q3 is the one to settle first.** Q1, Q2 and Q4 are hours of work to correct.
Q3 is a re-keying of the chain, and its cost grows monotonically.

### Why Q1 still matters

`XmlProfileTest` pins both constants, so neither can drift unnoticed — but the
test says plainly what that is worth: *"a tripwire, not a certificate: whether
these are the values ZATCA requires is an open question."*

Q2 turned out to be wrong. `CustomizationID` sits in the same file, in the same
unverified condition, and the check that settled one settles the other.

`FatooraValidate` reads the generated document rather than printing a fixed
checklist, so it can report a missing element instead of only confirming
presence (`XmlProfileTest::test_checklist_can_report_absence`).

---

## Step 6 — Chain integrity under concurrency

**Verdict: the ICV chain is safe under concurrent load on MySQL/PostgreSQL.**
This is one of the better-engineered parts of the system.

### Why it holds

`Invoice::generateNextIcv()` ([`Invoice.php:212-230`](../app/Domains/Invoice/Models/Invoice.php#L212-L230))
takes `lockForUpdate()` on the **`organizations` row**, not on invoice rows.
The docblock ([`:198-209`](../app/Domains/Invoice/Models/Invoice.php#L198-L209))
explains why the obvious alternative is wrong, and it is right:

> Locking `SELECT MAX(icv) ... FOR UPDATE` looks equivalent but has a hole: for
> an organization's first invoice there are no invoice rows to lock, so two
> concurrent requests both read no rows and both allocate 1.

Three layers of defence:

1. **The lock** serialises allocation on a row that always exists.
2. **The transaction boundary** keeps it held until after the INSERT. Both real
   creation paths comply — `InvoiceDrafter::draft()`
   ([`:46`](../app/Domains/Pipeline/Services/InvoiceDrafter.php#L46), whose
   docblock states the requirement explicitly) and `InvoiceController::store()`
   ([`:53`](../app/Domains/Invoice/Http/Controllers/InvoiceController.php#L53)).
   Laravel nests the inner transaction as a savepoint, so the lock survives to
   the outer commit.
3. **`unique(org_id, icv)`** ([`0080:66`](../database/migrations/0080_invoices.php#L66))
   fails the insert rather than corrupting the chain. `IcvAllocationTest::test_duplicate_icv_rejected`
   asserts exactly this.

PIH inherits the safety: `getPreviousInvoiceHashAttribute()` orders by **ICV,
not `created_at`** — deliberately, per its docblock ([`:352-354`](../app/Domains/Invoice/Models/Invoice.php#L352)),
because wall-clock ordering is not deterministic under concurrent inserts.

### Four caveats

1. **A third creation path would break it.** The guarantee depends on callers
   wrapping `Invoice::create()` in a transaction — a convention, not a
   constraint. The unique index degrades this from corruption to a failed
   insert, but a new code path that forgets will throw under load.
   *Mitigation: an architecture test asserting every `Invoice::create()` is
   inside a transaction. ~2 h.*

2. **`lockForUpdate()` is a no-op on SQLite** — which is what the test suite
   runs on. **The concurrency property is therefore not covered by any passing
   test**; only the unique-index backstop is. The reasoning is sound and the
   production driver will honour it, but per this audit's own rules the lock
   itself is PRESENT-UNVERIFIED.

3. **Gapless is not guaranteed** (gap-matrix item 8). A transaction that rolls
   back after allocation burns that ICV permanently — `MAX(icv)+1` never reuses
   it. ZATCA cares about monotonic sequence; whether it tolerates gaps is
   effectively **Q5** and I could not resolve it from the codebase.

4. **`hash_chain_history` accepts duplicate `(org_id, icv)`** — R-6. The audit
   trail of the chain is less constrained than the chain.

---

## Step 7 — Failure paths

| Scenario | What happens | Verdict |
|---|---|---|
| **ZATCA unreachable** | `Connectivity` + `CircuitBreaker` probe first; `OfflineFallback::submit()` `:55` routes to `queueForOffline()` before attempting. On `ConnectionException` mid-flight, `:79-84` catches and queues. `offline_queue` drained by `fatoora:process-offline --limit=50` (scheduled). Invoice stays **Issued** with a valid hash and QR. | ✅ **Handled well.** Correct for B2C. See R-4 for B2B. |
| **400 / rejection** | `updateInvoiceStatus()` `:379-404` sets `status = Rejected`, stores `clearance_status`, `reporting_status`, `validation_status`, **`warnings` and `errors` separately**. `InvoiceRejected` event → tenant webhook. `ErrorCode.php` maps 485 lines of codes. | ✅ **Handled well.** |
| **WARNING response** | Modelled as a **first-class distinct outcome**: `warning` in the `state` enum (`0140:50`), `conditionally_accepted` in `clearance_state` (`:57`), a separate `zatca_warnings` JSON column (`:60`), and its own `InvoiceWarning` event. | ✅ **Better than most.** A warning is not silently coerced to success. |
| **Mid-request timeout** | `SubmissionIdempotency` (unique `idempotency_key`, `0140:37`) with `status` and `attempt_count` makes retry safe; `DuplicateDetector` (396 L) is a second guard; `ProcessFatooraSubmission` retries on `backoff [10,60,300]`, then `failed()` `:385-418` marks terminal state, clears `next_retry_at`, logs at error, fires `InvoiceFailed(permanent: true)`. | ✅ **Handled.** Unknown-outcome timeouts are the classic double-submission risk and idempotency addresses it. |

### Is anything silently swallowed?

**No.** I looked specifically for this. Every catch block in `PipelineService`
(`:118-133`, `:165-189`) logs with context and returns the error to the caller.
`Submitter` audit-logs every submission (`:192-196`). The state machine records
transitions with actor and IP. `LogSanitizer` (253 L) keeps secrets out without
dropping the events.

Two narrower notes:
- `validateCertificate()` returns early when `config('fatoora.features.certificate_revocation_check')`
  is false ([`Submitter.php:216-218`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L216)) —
  a deliberate switch, but it disables revocation checking silently at runtime.
- `validateReportingDeadline()` has the same shape via `fatoora.reporting.enforce_deadline`
  ([`:267`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L267)).

Both are legitimate operational escape hatches. Neither is logged when it fires.

### Does the system distinguish blocking B2B from non-blocking B2C?

**Partly — at the endpoint, not at the outcome.** This is R-4.

✅ It **does** route correctly: `requiresClearance()` selects
`POST /invoices/clearance/single` vs `POST /invoices/reporting/single`
([`Submitter.php:171-186`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L171-L186)),
and it **does** enforce the B2C 24-hour window ([`:254-298`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L254)).

❌ It **does not** distinguish a *failed clearance* from a *failed report*. The
invoice is marked `Issued` at `Submitter.php:80-86` — before submission is
attempted — and `PipelineService` returns an issued invoice regardless. For B2C
that is correct and required (the offline queue depends on it). For B2B,
clearance is pre-issuance: an uncleared standard invoice is not legally
issuable, and the ERP receives one that looks issued.

**Suggested shape:**

```
B2C (simplified)   issue → report → queue on failure      ← current behaviour, correct
B2B (standard)     draft → clear → issue only on CLEARED
                          └─ on failure: stay pending_clearance,
                             return a non-issued result, do NOT
                             hand the ERP a QR to print
```

This is the single largest behavioural change in this audit and it interacts
with the offline queue, `PipelineResult` and the ERP contract. Do not start it
before Q1–Q3 are answered — the sandbox run may change what "cleared" means in
practice.
