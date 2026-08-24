# 03 — The Invoice Pipeline

**A pipeline exists, it is complete end to end, and it crosses one repo
boundary.** This is the entire compliance surface.

---

## The two entry points

| Path | Route file | Caller | Controller |
|---|---|---|---|
| **A — ERP one-shot** | [`routes/api/partner.php`](../routes/api/partner.php) (`license` guard, `/v1`) | masaar-erp-backend, or any ERP | [`PipelineController`](../app/Domains/Pipeline/Http/Controllers/PipelineController.php) |
| **B — direct authoring** | [`routes/api/tenant.php`](../routes/api/tenant.php) (`jwt.auth` guard) | a signed-in human | [`InvoiceController::store`](../app/Domains/Invoice/Http/Controllers/InvoiceController.php#L51) |

Path B creates and persists an invoice **only** — it does not sign or submit.
Path A is the compliance pipeline proper. Everything below traces Path A.

---

## Full trace: "user saves an invoice" → "persisted and delivered"

```
┌─ masaar-erp-backend ────────────────────────────────────────────────────────────────┐
│                                                                              │
│  user posts a sales invoice                                                  │
│        ↓                                                                     │
│  PostInvoiceOrchestrator::handle()                    Orchestrators/Sales/    │
│    ├─ …GL posting, stock, etc. (inside a transaction)                        │
│    └─ handleZatcaSubmission()                                   :194         │
│         ├─ if (! $invoice->requiresCompliance()) return;        :196         │
│         ├─ ZatcaInvoiceTransformer::transform($invoice)   → JSON payload     │
│         ├─ CompliPayClient::submitInvoice($invoice)             :201         │
│         │     └── HTTP POST  {ZATCA_INTEGRATION_URL}/v1/…   ── config/       │
│         │          (Laravel Http::retry(3, 1000s), 30s timeout) zatca-       │
│         │                                                       integration  │
│         ├─ on success → writes back onto the ERP invoice:       :204-209     │
│         │     compliance_status · compliance_uuid · compliance_hash          │
│         │     compliance_qr_code · compliance_response · compliance_submitted_at│
│         ├─ on ZATCA rejection → compliance_notes = 'ZATCA rejected: …' :220  │
│         └─ on ConnectionException →                             :235-245     │
│               compliance_status = COMPLIANCE_PENDING                         │
│               RetryComplianceSubmission::dispatch()->delay(5 min)            │
└──────────────────────────────────────────────────────────────────────────────┘
                                    │  HTTPS  ·  licence-key auth
                                    ▼
┌─ Masaar ─────────────────────────────────────────────────────────────────────┐
│                                                                              │
│  routes/api/partner.php   →  middleware: license, throttle (per-tenant)      │
│        ↓                                                                     │
│  PipelineSubmitRequest              validation at the boundary               │
│        ↓                                                                     │
│  PipelineController                                                          │
│        ↓                                                                     │
│  PipelineService::submitInvoice($data, $orgId, $branchId, $idempotencyKey)   │
│    │                                              Pipeline/Services/         │
│    │                                                                         │
│    ├─(1) resolveBranch()                                        :85          │
│    │     Branch must belong to this org — else FatooraException.             │
│    │     ("an unchecked identifier would let one taxpayer issue              │
│    │       documents under another's credentials")                           │
│    │                                                                         │
│    ├─(2) InvoiceDrafter::draft()                  Pipeline/Services/         │
│    │     ┌── DB::transaction ─────────────────────────────────────┐  :46     │
│    │     │  Invoice::create(...)                                  │          │
│    │     │    └─ boot::creating → generateNextIcv($orgId)  Invoice:151       │
│    │     │         ┌ DB::transaction (savepoint) ──────────┐              │
│    │     │         │  SELECT … FROM organizations          │              │
│    │     │         │    WHERE id=? FOR UPDATE   ← the lock │      :215-218  │
│    │     │         │  SELECT MAX(icv) WHERE org_id=?       │      :226-228  │
│    │     │         │  return max+1                         │              │
│    │     │         └───────────────────────────────────────┘              │
│    │     │  $invoice->lines()->create(...)  ← bcmath money                 │
│    │     └── commit  (lock held until here — this is what makes it safe) ──┘ │
│    │                                                                         │
│    ├─(3) Submitter::generate($invoice, $org)     Fatoora/Services/  :57      │
│    │     ├─ KillSwitch::assertNotEnabled(SWITCH_ISSUANCE)       :64          │
│    │     ├─ KillSwitch::assertNotEnabled(SWITCH_SIGNING)        :65          │
│    │     ├─ CredentialStore::get(org, branch, PCSID)  ← decrypts key         │
│    │     └─ DocumentBuilder::generateComplianceData()                        │
│    │          ├─ XmlBuilder::build(InvoiceXmlData)          UBL 2.1 via DOM  │
│    │          │    addSignatureExtension → UBLExtensions scaffold  :49       │
│    │          │    addInvoiceIdentification (UUID, ICV, BT-3 @name) :119     │
│    │          │    …supplier, customer, delivery, paymentMeans,              │
│    │          │      allowanceCharge, taxTotal, lines                        │
│    │          ├─ InvoiceHasher      → SHA-256 invoice hash                   │
│    │          ├─ EcdsaSigner        → secp256k1 signature                    │
│    │          ├─ XadesSigner::sign  → XAdES into UBLExtensions (1021 L)      │
│    │          ├─ TlvEncoder + QrCodeGenerator::generatePhase2 → 9-tag QR     │
│    │          └─ ChainState / ChainEntry ← PIH advanced                      │
│    │     └─ $invoice->update([hash, qr_code, signed_xml,                     │
│    │                          status => Issued])                 :80-86      │
│    │        ⚠ THE IRREVERSIBLE STEP. Everything after is best-effort.        │
│    │                                                                         │
│    ├─(4) PipelineNotifier::issued($invoice)                                  │
│    │                                                                         │
│    └─(5) OfflineFallback::submit($invoice, [idempotency_key])      :42       │
│          ├─ Connectivity / CircuitBreaker check → if down: queueForOffline   │
│          ├─ SubmissionTracker::submit($invoice, $key, $async)      :58       │
│          │    ├─ SubmissionIdempotency  ← dedupe on idempotency_key (unique) │
│          │    ├─ DuplicateDetector                                           │
│          │    ├─ InvoiceSubmission row created, state = queued/submitted     │
│          │    ├─ SubmissionStateLog     ← every transition, with actor + IP  │
│          │    ├─ sync  → Submitter::submit()                                 │
│          │    └─ async → ProcessFatooraSubmission::dispatch()  (419 L job,   │
│          │                tries + backoff [10,60,300]s)                      │
│          │                                                                   │
│          │    Submitter::submit()                                 :118       │
│          │      ├─ validateCertificate()  ← expiry + revocation (OCSP/CRL)   │
│          │      ├─ DocumentBuilder::generateComplianceData(… PIH …)  :159    │
│          │      └─ ⑂ if ($invoice->requiresClearance())           :171       │
│          │           B2B → FatooraClient::clearInvoice()                     │
│          │                  POST /invoices/clearance/single      Client:84   │
│          │           B2C → validateReportingDeadline()  ← 24h    :254-298    │
│          │                 FatooraClient::reportInvoice()                    │
│          │                  POST /invoices/reporting/single      Client:92   │
│          │      ├─ updateInvoiceStatus($invoice, $response)       :189       │
│          │      └─ AuditService::logZatcaSubmission()             :192       │
│          │                                                                   │
│          └─ on failure/ConnectionException → OfflineQueue::queue()  :136     │
│               offline_queue table; drained by                                │
│               Schedule::command('fatoora:process-offline --limit=50')        │
│                                                                              │
│  Events raised → InvoiceSubmitted / Cleared / Reported / Warning /           │
│                  Rejected / Failed          Fatoora/Events/                  │
│        ↓                                                                     │
│  DispatchInvoiceWebhook (listener)                    Fatoora/Listeners/     │
│        ↓  HMAC-signed POST                                                   │
└──────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                     masaar-erp-backend  ZatcaWebhookController
                       ← VerifyZatcaWebhook middleware
```

---

## Everything named

### Repo boundary: masaar-erp-backend
| Kind | Name |
|---|---|
| Orchestrator | `App\Orchestrators\Sales\PostInvoiceOrchestrator` |
| Service | `App\Services\Compliance\CompliPayClient` (552 L) |
| Transformer | `App\Services\Compliance\ZatcaInvoiceTransformer` |
| Adapter | `App\Services\Compliance\ZatcaClientV1` |
| Job | `App\Jobs\RetryComplianceSubmission` (5-min delay) |
| Controller | `Api\V1\Compliance\ZatcaWebhookController` |
| Middleware | `VerifyZatcaWebhook` |
| Config | `config/zatca-integration.php` |

### Masaar — HTTP layer
`PipelineController` · `PipelineSubmitRequest` · `InvoiceController` ·
`CreateInvoiceRequest` · `ComplianceController` · `OnboardingController` ·
`BranchOnboardingController`

### Masaar — services (all `App\Domains\Compliance\Fatoora\Services`)
`DocumentBuilder` · `XmlBuilder` · `XadesSigner` · `EcdsaSigner` ·
`InvoiceHasher` · `TlvEncoder` · `QrCodeGenerator` · `Submitter` ·
`SubmissionTracker` · `SubmissionGuard` · `OfflineFallback` · `OfflineQueue` ·
`DuplicateDetector` · `CircuitBreaker` · `Connectivity` · `KillSwitch` ·
`CertificateService` · `CredentialStore` · `CsidOnboarding` · `ClearanceState` ·
`InvoiceValidator` · `TimestampValidator` · `VatPeriodTracker`
Plus `App\Domains\Pipeline\Services`: `PipelineService` · `InvoiceDrafter` ·
`PipelineNotifier` · `PipelineResult`

### Masaar — client
`FatooraClient` — **the only** class that makes outbound HTTP to ZATCA.
Endpoints: `/invoices/clearance/single`, `/invoices/reporting/single`,
`/invoices/compliance`, `/compliance`, `/compliance/invoices`,
`/production/csids` (POST + PATCH for renewal).

### Masaar — jobs & queues
| | |
|---|---|
| Job | `ProcessFatooraSubmission` (419 L), `$tries`, `backoff() = config('fatoora.queue.backoff', [10,60,300])` |
| Queue connection | `config('fatoora.queue.*')`; `ZATCA_QUEUE_CONNECTION` honoured (covered by `QueueRoutingTest`) |
| Durable queue | `offline_queue` table + `fatoora:process-offline` scheduled command |

### Masaar — events & listeners
`InvoiceSubmitted` · `InvoiceCleared` · `InvoiceReported` · `InvoiceWarning` ·
`InvoiceRejected` · `InvoiceFailed` (all extend `BaseInvoiceEvent`)
→ `DispatchInvoiceWebhook`

### Masaar — models / tables
`Invoice` + `InvoiceLine` · `InvoiceSubmission` · `SubmissionIdempotency` ·
`SubmissionStateLog` · `ChainState` (`hash_chain_state`) ·
`ChainEntry` (`hash_chain_history`) · `OfflineItem` (`offline_queue`)

### Masaar — scheduled work ([`routes/console.php`](../routes/console.php))
```
fatoora:process-offline --limit=50      drain the offline queue
fatoora:check-certificate --notify      expiry alerts at 30/14/7/3/1 days
fatoora:verify-hash-chain               chain integrity sweep
compliance:cleanup-offline-queue
compliance:index-health --alert
compliance:partition-maintenance --create-future --months-ahead=2
license:{cleanup-rate-limits,check-expiration,report-usage}
```

---

## Three observations from the trace

**1. The irreversible step is step 3, not step 5.**
`Submitter::generate()` sets `status => Issued` and writes the signed XML
([`Submitter.php:80-86`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L80-L86)).
From that moment `Invoice::IMMUTABLE_FIELDS` locks 17 columns and deletion
throws. Submission (step 5) is a separate, retryable concern. This separation
is deliberate and documented, and it is the right shape for B2C. For B2B it is
the wrong shape — see [06-risks.md](06-risks.md) R-4.

**2. `generateComplianceData` is called twice per invoice.**
Once in `generate()` ([`:71`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L71))
and again in `submit()` ([`:159`](../app/Domains/Compliance/Fatoora/Services/Submitter.php#L159)).
The XML is rebuilt, re-hashed and re-signed rather than read back from
`invoices.signed_xml`. ECDSA is non-deterministic (random `k`), so **the
signature submitted to ZATCA is not byte-identical to the one persisted at
issuance** — even though both are valid over the same document. Whichever is
archived, one of them was never seen by ZATCA. Flagged as R-5.

**3. The chain is advanced during document generation, not after acceptance.**
PIH/ICV move forward at signing time. If submission then fails permanently, the
chain has already consumed that ICV. That is arguably correct under ZATCA
(the counter is per issued document, not per accepted one) and the offline
queue depends on it — but it means a rejected invoice leaves a permanent link
in the chain. `fatoora:verify-hash-chain` exists to detect breaks; nothing
reconciles *rejected* links.
