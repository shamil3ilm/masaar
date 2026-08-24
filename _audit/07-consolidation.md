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

| Repo | Product | Substantive feature work | State |
|---|---|---|---|
| **Masaar** | Compliance API | ongoing | Alive · 117 commits/90d · CI · clean, pushed |
| **masaar-erp-backend** | ERP | May 2026 | Dormant · CI · 2121 tests · 274k LOC · clean, pushed |
| **masaar-erp-frontend** | ERP UI | May 2026 | Dormant · CI · dependent on the above · clean, pushed |

Three repos, **two products**, **one developer**, **no client**.

All three are clean and fully pushed, so nothing is at risk of loss. The
dormancy flag is about attention, not fragility.

### Verdict

## Keep Masaar. Park the ERP. Compliance lives in Masaar only.

**1. Masaar — KEEP. This is the product.**
It is the only thing being worked on, the only thing with CI, the only thing
with a coherent test suite, and the only one that is independently sellable.
15,106 lines of ZATCA logic and 704 passing tests is a real asset. Everything in
this audit's next-steps applies here and nowhere else.

**2. masaar-erp-backend — PARK. Do not delete.**

I am not telling you to delete 274,398 lines and 2121 passing tests. But be
honest about what it is: **a full ERP is not a side quest.** SAP and Odoo are
ERPs; Masaar is a compliance API. One solo developer cannot ship both, and the
ERP had zero commits in the ninety days before this audit while Masaar had 117.
You have already chosen; you just had not said so.

It is now in a safe state to leave: committed, pushed, and under CI, so the
suite runs without anyone remembering to run it. It remains a working reference
implementation of an ERP integrating with Masaar, which is worth something as a
demo and as proof the partner API works end to end.

**3. masaar-erp-frontend — PARK with masaar-erp-backend.** It has no independent meaning; it
is the UI for a dormant backend. Same fate, same reasoning.

### Compliance ownership

**The ERP files invoices through the platform and holds no compliance logic of
its own.** It carried a UAE FTA UBL builder and service, and a Qatar GTA
service — 11 files and ~1,100 lines including 478 lines of tests. All of it was
unreachable: no controller, route, job, orchestrator or provider referenced any
of it, and neither service imported an HTTP client, so neither could have
transmitted anything. They generated XML into a local table. Deleted.

Deleting them was not the whole fix. `Organization::requiresCompliance()`
listed `['SA', 'AE', 'IN']` while the submission path carries no jurisdiction —
`PostInvoiceOrchestrator` calls `MasaarClient::submitInvoice()`, which posts to
`/pipeline/submit` with no country and a circuit breaker keyed `'zatca'`.
**An Emirati or Indian organization's invoice was filed with ZATCA as a Saudi
document.** The list is Saudi only now, with a test naming every country that
must stay off it until the platform can file for that jurisdiction.

**What is correctly kept in the ERP:**

| File | Why |
|---|---|
| `Services/Compliance/MasaarClient.php` | the one HTTP client to the platform |
| `Services/Compliance/ZatcaInvoiceTransformer.php` | ERP model → platform payload |
| `Services/Compliance/ZatcaClientV1.php` | adapter onto `ExternalApiClient` |
| `Services/Compliance/CircuitBreaker.php` | **not a duplicate.** Bound at `AppServiceProvider:27`, resolved by `MasaarClient:522`. It guards the ERP→platform call; Masaar's guards the platform→ZATCA call. Different failure domains, both needed. |

### What the platform still needs before it can serve all jurisdictions

Removing the ERP's copies makes Masaar the only path. It is not yet able to be
that path beyond Saudi Arabia:

1. **`ComplianceRouter` has no production consumer.** Bound in
   `ComplianceServiceProvider`, used only by its own test.
   `Pipeline/Services/PipelineService.php:7-9` imports `Fatoora\...` directly
   and catches `FatooraException`. The jurisdiction dispatcher is never on the
   request path.
2. **The FTA controller is on the wrong surface.** `routes/api/tenant.php:72-76`
   behind `jwt.auth` — not the partner/licence API an ERP calls.
3. **UAE transport assumes the wrong model.** `FTA/Services/FtaService.php:123-127`
   does a bearer-token REST `POST {base}/invoices`. The UAE mandate is a
   five-corner DCTCE model over Peppol, reaching the FTA through an Accredited
   Service Provider. There is no endpoint to POST to. This is an accreditation
   question before it is a coding one, and it decides whether the UAE path is
   viable in its current shape at all.

Items 1 and 2 are ordinary work. Item 3 should be settled before more is built
on that path.

**Qatar should not be built.** The GTA approved a draft law in May 2026; it
still requires the Shura Council, the Amir's assent and Gazette publication,
and **no technical specification has been published.** There is nothing to
build against.

### Naming

Resolved. Folder names, repository names and product identity agree:

| Directory | GitHub repo | What it is |
|---|---|---|
| `Masaar` | `masaar` | compliance platform |
| `masaar-erp-backend` | `masaar-erp-backend` | ERP |
| `masaar-erp-frontend` | `masaar-erp-frontend` | ERP UI |

Verified by `git ls-remote` against each clone's `main`. Old names redirect,
with one exception worth knowing: `github.com/shamil3ilm/masaar` addressed the
ERP before and addresses the platform now, because that name was deliberately
re-claimed. An external link using it resolves somewhere new.

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

### What is done, and what is next

Done: repositories and folders renamed and verified; all three clean, on `main`
and pushed; CI added to both ERP repositories; the ERP's dead UAE and Qatar
compliance removed and its jurisdiction list corrected; the platform's dead
`fta.peppol` config deleted.

Next, in order:

1. **A compliance CSID** — the single item blocking ladder L3 and L4.
   See [08-next.md](08-next.md).
2. **Settle the UAE transport question** — ASP accreditation, or integrating
   with an accredited provider. Decides whether the current FTA path is viable.
3. **Wire `ComplianceRouter` into `PipelineService`** and expose the FTA path on
   the partner API, so jurisdiction dispatch is real rather than bound-and-unused.
4. **Correct `README.md:23-35`**, which still describes `erp/` as a future git
   submodule inside a monorepo parent that does not exist.

Item 1 comes first because it converts the largest unknown in this audit into a
list. Item 2 should precede item 3: there is no point routing traffic to a
transport that cannot deliver.
