# 07 — Consolidation Verdict & Extractability

---

## Part A — Extractability (Step 8)

**This is the best news in the audit.** The ZATCA logic is already almost
free of the application, and the seam is in exactly the right place.

### The core is framework-free

Every class that does the compliance-critical work imports **nothing from
Laravel and nothing from Eloquent**:

| Class | LOC | Imports |
|---|---|---|
| [`XmlBuilder`](../app/Domains/Compliance/Fatoora/Services/XmlBuilder.php) | 978 | `FatooraConfig`, `AddressData`, `InvoiceXmlData`, `DOMDocument`, `DOMElement` |
| [`XadesSigner`](../app/Domains/Compliance/Fatoora/Services/XadesSigner.php) | 1021 | `SigningException`, `FatooraTime`, `Support\Xml`, `DOM*` |
| [`EcdsaSigner`](../app/Domains/Compliance/Fatoora/Services/EcdsaSigner.php) | 249 | `SigningException` |
| [`InvoiceHasher`](../app/Domains/Compliance/Fatoora/Services/InvoiceHasher.php) | 142 | `Support\Xml`, `DOM*` |
| [`TlvEncoder`](../app/Domains/Compliance/Fatoora/Services/TlvEncoder.php) | 94 | `InvalidArgumentException` |
| [`QrCodeGenerator`](../app/Domains/Compliance/Fatoora/Services/QrCodeGenerator.php) | 128 | `QrCodeData`, `InvalidArgumentException` |

Supporting helpers are equally clean: [`App\Support\Xml`](../app/Support/Xml.php)
imports only `DOMDocument`; [`FatooraTime`](../app/Domains/Compliance/Fatoora/Helpers/FatooraTime.php)
only `DateTimeImmutable`/`DateTimeZone` — not Carbon. `grep -rl "Illuminate\Support\Facades"`
across `Fatoora/Services/` returns **nothing**: not one facade in the whole
service layer.

That is roughly **2,600 lines of pure, portable compliance logic** already
taking DTOs in and returning strings out.

### The seam is one file

[`DocumentBuilder`](../app/Domains/Compliance/Fatoora/Services/DocumentBuilder.php)
(259 L) is the **only** class in the generation chain that imports
`App\Domains\Invoice\Models\Invoice` and `App\Domains\Organization\Models\Organization`
(lines 13-14). It maps Eloquent models → `InvoiceXmlData` / `AddressData` /
`QrCodeData` and calls the pure layer.

That is precisely the DTO boundary you described wanting. It already exists;
it just has a model-shaped adapter bolted to its front.

### What would need to change

| Item | Work |
|---|---|
| `DocumentBuilder` | Split: `InvoiceXmlData::fromArray()` in the package + a thin `EloquentDocumentAdapter` staying in the app |
| `FatooraConfig` | One `config()` call, at [`:273`](../app/Domains/Compliance/Fatoora/Config/FatooraConfig.php#L273). Inject an array/config object instead. |
| `FatooraClient` | Uses Laravel's `Http` facade → swap for PSR-18 + Guzzle |
| `CredentialStore` | Uses `Storage` + `Crypt` → define a `CredentialStore` **interface**; app supplies the Laravel implementation |
| Logging | `Log::` appears in `CsidOnboarding`, `Submitter`, `OfflineFallback` → PSR-3 `LoggerInterface` |
| Exceptions | Already self-contained (`FatooraException` 239 L, `ErrorCode` 485 L) |
| Tests | Existing tests use `RefreshDatabase` for the *model* paths; the pure-layer tests (`UblTotalsTest`, `XadesPropertiesTest`, `InvoiceTypeCodeTest`) go through `DocumentBuilder` and would need re-pointing at DTOs |

### Effort estimate

| Phase | Hours |
|---|---|
| Extract pure core + DTOs into `masaar/zatca-core`, no behaviour change | 12–16 |
| Define + implement the four interfaces (client, credentials, logger, config) | 10–14 |
| Re-point tests at DTO inputs; add fixture-based tests | 12–16 |
| App-side adapters + wiring; verify the 704-test suite still green | 8–10 |
| Packaging, autoload, docs | 4–6 |
| **Total** | **46–62 h** ≈ 1.5 focused weeks |

**Verdict: genuinely feasible, and unusually cheap for a 15,000-line domain.**
Most of the cost is tests, not code — the architecture is already right.

### But do not do it yet

Extraction now would freeze the current design into a package boundary **before
Q1–Q3 in [06-risks.md](06-risks.md) are answered**. If Q3 resolves to a
per-EGS-unit chain, the DTO shape changes. **Run the sandbox first, fix what it
exposes, then extract.** The seam will still be there.

---

## Part B — Consolidation verdict (Step 9)

### The situation, stated plainly

| Repo | Product | Last commit | 90d commits | State |
|---|---|---|---|---|
| **Masaar** (`origin: zatca`) | Compliance API | 2026-08-23 | **107** | Alive, 135 commits unpushed |
| **masaar-erp-backend** (`origin: qarar`) | ERP | 2026-05-24 | **0** | Dormant · 149 uncommitted · 41 staged deletions · no CI · 274k LOC |
| **masaar-erp-frontend** (`origin: masaar-frontend`) | ERP UI | 2026-05-24 | **0** | Dormant · dependent on the above |

Three repos, **two products**, **one developer**, **no client**.

### Verdict

## Keep Masaar. Park the ERP. Delete nothing yet.

**1. Masaar — KEEP. This is the product.**
It is the only thing being worked on, the only thing with CI, the only thing
with a coherent test suite, and the only one that is independently sellable.
15,106 lines of ZATCA logic and 704 passing tests is a real asset. Everything in
this audit's next-steps applies here and nowhere else.

**2. masaar-erp-backend — PARK, deliberately and safely. Do not delete.**

I am not telling you to delete 274,398 lines and 189 test files. But be honest
about what it is: **a full ERP is not a side quest.** SAP and Odoo are ERPs;
Masaar is a compliance API. One solo developer cannot ship both, and the ERP has
had zero commits in three months while Masaar had 107 — you have already chosen,
you just have not said so.

The urgent part is **not** the decision. It is that masaar-erp-backend holds **149
uncommitted changes including 41 staged deletions** with no commit and three
months of lost context. That is one `git checkout` from gone. Commit it to a
branch today, whatever state it is in.

Then leave it. It is your reference implementation of a real ERP integrating
with Masaar, which is worth something as a demo and as proof the partner API
works end to end.

**3. masaar-erp-frontend — PARK with masaar-erp-backend.** It has no independent meaning; it
is the UI for a dormant backend. Same fate, same reasoning.

### Which overlaps to remove

Two things genuinely duplicate, and both should collapse **toward Masaar**:

| Overlap | Where | Verdict |
|---|---|---|
| **Circuit breaker** | `masaar-erp-backend/app/Services/Compliance/CircuitBreaker.php` vs `Masaar/.../Fatoora/Services/CircuitBreaker.php` (334 L) | Masaar's is the real one — it guards the actual ZATCA calls. masaar-erp-backend's guards an HTTP call to Masaar, which is a different and much simpler problem; Laravel's `Http::retry()` covers it. **Delete masaar-erp-backend's.** |
| **Qatar GTA** | `masaar-erp-backend/app/Services/Compliance/QatarGtaEInvoiceService.php` vs `Masaar/docs/qa/` (planned) | Two answers to one question, in the wrong repo. Qatar compliance belongs behind `ComplianceRouter` alongside `FatooraEngine` and `FtaEngine`. **Delete masaar-erp-backend's** when Qatar is actually built; until then it is dead weight in a dormant repo. |

**What is not an overlap:** masaar-erp-backend's `CompliPayClient` /
`ZatcaInvoiceTransformer` / `ZatcaClientV1` are a thin, correct client (757 lines
total, no cryptography). Keep them. That boundary is drawn in the right place
and is the proof that Masaar's partner API is usable.

### Fix the naming

This actively caused the wrong premise in this audit's brief:

| Directory | GitHub repo | Should be |
|---|---|---|
| `Masaar` | `zatca` | `masaar` |
| `masaar-erp-backend` | `qarar` | `qarar-backend` (or `masaar-erp`) |
| `masaar-erp-frontend` | `masaar-frontend` | `qarar-frontend` — it is **not** Masaar's frontend |

Two `gh repo rename` calls and a local `git remote set-url`. Ten minutes, and it
stops the confusion recurring.

### The smallest structure that ships a ZATCA-compliant product

**One repo: Masaar. Nothing else is required.**

Masaar already contains everything the compliance product needs — invoice
authoring (`InvoiceController`), the partner API for ERPs
(`routes/api/partner.php`), an admin console and customer portal
(`routes/web.php`), licensing, webhooks, and eleven client SDKs. It does not
need the ERP to be sellable. That is the whole point of having built it as an
API.

If you later want the ERP as a monorepo sibling, the README already describes
that shape (`README.md:23-35`) — but note it describes a submodule that **does
not exist**, so either build it or correct the README. Right now the README
promises a structure the repos do not have.

### Concretely, this week

1. `cd masaar-erp-backend && git checkout -b wip/2026-05-refactor && git add -A && git commit` — **stop the bleeding** (R-12)
2. `cd Masaar && git push -u origin chore/security-remediation-and-cleanup` — **135 commits off this machine** (R-11)
3. Rename the three repos
4. Delete `masaar-erp-backend/app/Services/Compliance/CircuitBreaker.php`
5. Correct `README.md:23-35` to describe the repos as they are

Then everything else in [08-next.md](08-next.md), which concerns only Masaar.
