# Security Audit — Masaar Platform

**Audit date:** 2026-08-03 · **Method:** read-only static analysis · **Codebase:** `c:\laragon\www\Masaar`
**Related:** [00-EXECUTIVE-SUMMARY](00-EXECUTIVE-SUMMARY.md) · [01-DISCOVERY-AND-ARCHITECTURE](01-DISCOVERY-AND-ARCHITECTURE.md)

> **Caveat on method.** This audit is static. Findings marked *Confirmed* were verified by reading the complete code path from route registration to data access. Findings marked *Probable* are strongly indicated by the code but were not executed against a running instance. No dynamic testing, dependency CVE scanning, or infrastructure review was performed — see [§8 Not covered](#8-what-this-audit-did-not-cover).

---

## Severity definitions

| Severity | Meaning | Action |
|---|---|---|
| 🔴 **Critical** | Exploitable now, no authentication required, or causes cross-tenant data loss/disclosure | Block all releases; fix immediately |
| 🟠 **High** | Exploitable with low effort, or defeats a core security control | Fix before any customer traffic |
| 🟡 **Medium** | Requires preconditions, or weakens defence-in-depth | Fix within the current quarter |
| 🔵 **Low** | Hardening; no direct exploit path identified | Backlog |

**Totals: 4 Critical · 6 High · 8 Medium · 4 Low**

---

## 1. Critical findings

### 🔴 C-1 — The `/admin/*` web console has no authentication

**Status:** Resolved — `/admin` is behind `auth` and `platform.admin`; `SessionAuthTest` covers the redirect for each role.
**Evidence:** [`routes/web.php:18-43`](../../routes/web.php#L18-L43); middleware registry at [`bootstrap/app.php:29-46`](../../bootstrap/app.php#L29-L46)

```php
// routes/web.php — no ->middleware(...) anywhere in this group
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/organizations', [AdminController::class, 'organizations']);
    Route::get('/organizations/{id}', [AdminController::class, 'organizationDetail']);
    Route::get('/logs', [AdminController::class, 'logs']);

    Route::post('/queue/process', function () {
        Artisan::call('zatca:process-offline', ['--limit' => 50]);   // ← unauthenticated command execution
    });
    Route::post('/queue/{id}/retry', function (string $id) {
        DB::table('offline_queue')->where('id', $id)->update([...]); // ← unauthenticated state mutation
    });
});
```

An `EnsureUserIsAdmin` middleware exists and is correctly aliased as `admin`, and it *is* applied to the parallel JSON API at `routes/api.php:270`. It is simply never applied to the Blade console. The `web` middleware group supplies session and CSRF handling — **not authentication**.

**Impact.** Any unauthenticated party who reaches the host obtains: the full organization register (names, VAT numbers, status, certificate expiry), per-organization submission statistics, application logs, and the ability to trigger an Artisan command and rewrite offline-queue rows. This is total platform compromise, and it also constitutes a personal-data and tax-data breach under Saudi PDPL.

**Remediation.**
```php
Route::prefix('admin')->name('admin.')
    ->middleware(['auth', 'admin'])   // session auth for Blade + role check
    ->group(function () { /* … */ });
```
Additionally: move both inline closures into `AdminController` methods so they are testable and auditable, and add an IP allowlist in front of `/admin` at the reverse proxy (Traefik is already in `docker-compose.prod.yml`) as defence-in-depth.

**Regression guard.** Add a feature test asserting `GET /admin/organizations` returns 302/401 for a guest and 403 for a non-admin user.

---

### 🔴 C-2 — Customer portal reads tenant identity from a query parameter, unauthenticated

**Status:** Resolved — `PortalTenant` establishes the tenant from the session, never a query parameter, and `PortalDataScopeTest` pins it.
**Evidence:** [`routes/web.php:53-59`](../../routes/web.php#L53-L59); [`CustomerPortalController`](../../app/Domains/Organization/Http/Controllers/CustomerPortalController.php)

```php
private function getOrganizationId(Request $request): ?string
{
    // In production: return auth()->user()->organization_id;
    // For preview: accept query param
    return $request->query('org_id') ?? $request->session()->get('preview_org_id');
}
```

The route group carries no middleware. The controller then queries `invoices`, `invoice_submissions` and `certificate_lineage` filtered by that attacker-supplied `org_id`.

**Impact.** `GET /portal/?org_id=<uuid>` returns any tenant's invoice volumes, clearance/rejection counts and certificate status without credentials. `/portal/submissions` and `/portal/certificates` extend this. Organization UUIDs are disclosed by C-1, so the two findings chain into complete, unauthenticated, cross-tenant disclosure.

The code and route comments both acknowledge this is preview-only scaffolding — but it is registered unconditionally, with no environment guard.

**Remediation.** Introduce real portal authentication and derive the tenant **only** from the authenticated session:

```php
Route::prefix('portal')->middleware(['auth', 'portal.tenant'])->group(...);

private function getOrganizationId(Request $request): string
{
    return $request->user()->organization_id;  // never from input
}
```
As an immediate stopgap before portal auth exists, wrap the group in `if (! app()->environment('production'))`.

**Rule to adopt:** *tenant identity is derived from the credential, never from request input.* Any code reading a tenant identifier from query, body, or header should fail code review.

---

### 🔴 C-3 — API key **and secret** accepted from URL query parameters

**Status:** Resolved — `ValidateLicense` reads credentials from headers only. A URL carries the key and secret together into proxy logs, APM traces and `Referer`.
**Evidence:** [`app/Domains/Licensing/Http/Middleware/ValidateLicense.php`](../../app/Domains/Licensing/Http/Middleware/ValidateLicense.php) — `extractApiKey()` and `extractApiSecret()`

```php
private function extractApiKey(Request $request): ?string {
    // …headers…
    return $request->query('api_key');       // ← credential in URL
}
private function extractApiSecret(Request $request): ?string {
    // …headers…
    return $request->query('api_secret');    // ← secret in URL
}
```

The inline comment reads *"not recommended but supported."* Supporting it is the vulnerability.

**Impact.** URLs are logged everywhere by default: web-server access logs, reverse-proxy logs, CDN logs, APM traces, browser history, and the `Referer` header sent to any third-party asset. This leaks a *complete* credential pair — key **and** secret — into systems with far weaker access controls than the credential store. For a signing platform, a leaked key permits fraudulent invoice submission under the customer's VAT registration.

**Remediation.** Delete both query-parameter fallbacks. Accept credentials from `X-API-Key`/`X-API-Secret` headers or the `Authorization` header only. If a customer is already using query parameters, log a deprecation warning for one release, then remove — do not keep it indefinitely.

Also revoke and reissue any credential that has ever been sent this way; assume it is compromised.

---

### 🔴 C-4 — `TenantIsolationGuard` is dead code; tenant isolation has no defence-in-depth

**Status:** Resolved — the guard is deleted. Isolation is structural: `BelongsToTenant` applies `TenantScope` to every tenant model, and `RawTenantQueryTest` fails the build on an undeclared `DB::table()` against a tenant table.
**Evidence:** `app/Domains/Organization/Services/TenantIsolationGuard.php` (since deleted) — 300 lines. A repository-wide grep for `TenantIsolationGuard` across `app/` and `routes/` returns **only its own class definition.** Zero call sites. It is not registered in `AppServiceProvider` (which registers only `TenantResolver`).

The class docblock states:

> **CRITICAL: One tenant's invalid data must NEVER affect another tenant.**

Actual isolation is implemented by repeating this line by hand in every controller method:

```php
Invoice::where('organization_id', $this->tenant->getOrganizationId())->findOrFail($id);
```

**Impact.** The platform's most important security invariant is enforced by developer discipline alone, with no structural backstop. Every new query is an opportunity for a silent cross-tenant leak, and nothing — not a global scope, not a model observer, not a test — will catch the omission. The presence of an elaborate unused guard makes this *worse* than having none, because a reviewer skimming the codebase will reasonably conclude isolation is centrally enforced.

Two mitigating facts, verified: the audited controllers (`InvoiceController`, `ComplianceController`) *do* scope correctly; and when the JWT lacks `org_id`, `getOrganizationId()` returns `null`, producing `where('organization_id', null)`, which matches no rows — so the current failure mode is fail-closed. This is why C-4 is a systemic risk rather than an active exploit.

**Remediation — choose the structural option, not the guard.** Rather than wiring up the existing 300-line guard, adopt Laravel's native mechanism, which cannot be forgotten:

```php
// app/Domains/Organization/Concerns/BelongsToTenant.php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            $tenantId = app(TenantResolver::class)->getOrganizationId();
            abort_if($tenantId === null, 403, 'No tenant context');
            $q->where($q->getModel()->getTable().'.organization_id', $tenantId);
        });

        static::creating(function ($model) {
            $model->organization_id ??= app(TenantResolver::class)->getOrganizationId();
        });
    }
}
```

Apply to `Invoice`, `InvoiceSubmission`, `Branch`, `Webhook`, `ApiKey`, `ComplianceProfile`, `FtaSubmission`, `LicenseUsage`. Provide one explicit, logged, audited escape hatch for system jobs (`Model::withoutTenantScope(fn () => …)`). Then **delete `TenantIsolationGuard`.**

**Regression guard.** A test that, for each tenant-scoped model, creates rows under two organizations and asserts that a query under tenant A never returns tenant B's rows — including via relationship traversal and via queue jobs.

---

## 2. High findings

### 🟠 H-1 — Signing keys are encrypted with `APP_KEY` on a local filesystem disk

**Status:** Partly resolved — credentials have a dedicated key (`ZATCA_CREDENTIAL_KEY`) with previous-key rotation and a configurable disk, so `APP_KEY` no longer protects them. Per-tenant keys wrapped by a managed KMS are still open, and are where this finding closes.
**Evidence:** [`app/Domains/Organization/Services/BranchService.php:147-170`](../../app/Domains/Organization/Services/BranchService.php#L147-L170); [`Submitter::getSigningCredentials()`](../../app/Domains/Compliance/Fatoora/Services/Submitter.php)

```php
Storage::disk('local')->put($path, encrypt(json_encode($data)));   // AES-256-CBC under APP_KEY
```

Credit where due: keys **are** encrypted at rest and **are** correctly partitioned per organization and per branch (`zatca/{orgId}/pcsid.json`, plus branch-scoped paths). That is better than most implementations of this kind. Three problems remain.

1. **Single key of compromise.** Every tenant's ZATCA private key is encrypted under one `APP_KEY`. That key is present in the environment of every application container, every queue worker, and any developer machine with a production `.env`. Compromise of `APP_KEY` compromises *every* tenant's non-repudiation key simultaneously. These keys sign tax documents on behalf of the taxpayer — the blast radius is legal, not merely technical.
2. **No rotation path.** There is no re-encryption command. Rotating `APP_KEY` today silently renders every stored credential undecryptable.
3. **Local disk breaks horizontal scaling.** `Storage::disk('local')` resolves to `storage/app/private` inside the container. With more than one app replica, a tenant onboarded on replica A cannot sign on replica B. `docker-compose.prod.yml` defines a single `app` service, so this is latent today and becomes an outage the moment the service is scaled.

**Remediation.** Move to envelope encryption with a managed KMS (AWS KMS, or Vault Transit for on-prem):

- Generate a per-tenant data key; encrypt the PEM with the data key; encrypt the data key with the KMS master key; store both ciphertexts in the database (not on disk).
- This removes the local-disk dependency, gives per-tenant key isolation, provides an auditable decrypt log, and makes rotation a KMS operation.
- Write the `masaar:rotate-credential-encryption` command *before* it is needed.

Interim step if KMS is not yet available: move the ciphertext to S3 (`Storage::disk('s3')`) to unblock scaling, and add a distinct `CREDENTIAL_ENCRYPTION_KEY` separate from `APP_KEY`.

---

### 🟠 H-2 — SSRF via certificate revocation checking

**Status:** Resolved — outbound fetches go through `SafeFetch`/`SafeUrl`: https only, host allowlist, no redirects, capped size, private and link-local addresses refused.
**Evidence:** [`CertificateService::checkCrl()`](../../app/Domains/Compliance/Fatoora/Services/CertificateService.php) and `checkOcsp()`

```php
$crlUrls = $this->extractCrlUrls($extensions);   // URL comes from inside the certificate
// …
$crlData = @file_get_contents($crlUrl, false, $context);   // server-side fetch, no allowlist
```

Both the OCSP responder URL and the CRL distribution points are read from extensions **inside the certificate being validated** and then fetched server-side with no scheme, host, or IP-range restriction.

**Impact.** Any actor who can get a certificate into a validation path — via the onboarding flow, or a `verifyCertificateChain()` call — can direct the server to fetch an arbitrary URL. That reaches cloud metadata endpoints (`169.254.169.254`), internal admin interfaces, and databases on the private network. Response content and timing are partially observable through logs and error paths.

**Remediation.**
- Replace `file_get_contents` with a hardened HTTP client: enforce `https://` only, resolve the hostname first and reject RFC1918/loopback/link-local/CGNAT addresses, disable redirects, cap response size and timeout.
- Better: maintain an **allowlist of ZATCA's known OCSP/CRL endpoints**. Masaar validates certificates from exactly one issuer chain; there is no legitimate reason to fetch a URL nobody has vetted.
- Cache CRL responses (they carry validity windows) to avoid a per-submission outbound fetch.

---

### 🟠 H-3 — CRL revocation check is silently non-functional

**Status:** Resolved — revocation is checked with phpseclib against real CA, CRL and certificate fixtures; `CertificateRevocationTest` proves a revoked serial is refused and a good one is not.
**Evidence:** [`CertificateService::checkCrl()`](../../app/Domains/Compliance/Fatoora/Services/CertificateService.php)

```php
$serialNumber = $details['serialNumber'] ?? null;      // decimal string, up to 20 bytes
$serialHex = strtoupper(dechex((int) $serialNumber));  // ← cast to 64-bit int
if (preg_match('/Serial Number:\s*'.preg_quote($serialHex, '/').'/i', $output)) { /* revoked */ }
```

X.509 serial numbers are up to 20 octets (160 bits). `openssl_x509_parse()` returns `serialNumber` as a **decimal string**. Casting it to a PHP `int` saturates at `PHP_INT_MAX` for any realistic ZATCA serial, so `$serialHex` is a fixed, wrong value that will never match the CRL text.

**Impact.** `checkCrl()` returns `['revoked' => false]` for **every** certificate, including genuinely revoked ones. Because `validateForSubmission()` treats a non-revoked result as a pass, a revoked certificate will be accepted for signing. The failure is silent — no error, no warning — which is the dangerous property. The code presents a revocation control that does not exist.

**Remediation.** Use the hex serial directly (`$details['serialNumberHex']`, which `openssl_x509_parse()` provides), and compare using a parsed CRL rather than a regex over `openssl crl -text` output. Prefer OCSP as the primary path with CRL as fallback. **Add a test with a known-revoked fixture certificate** — the absence of such a test is why this survived.

---

### 🟠 H-4 — Revocation and CSR paths shell out to external binaries

**Status:** Resolved — no path shells out. `NoShellOutTest` fails the build on `exec`, `shell_exec`, `proc_open`, `system` or `passthru` anywhere in `app/`.
**Evidence:** `CertificateService.php` lines ~538, ~632, ~640 (`shell_exec` on `openssl`); `FatooraSdkService.php:256`, `FatooraGenerateCsr.php` (10 `exec()` calls on `openssl` and `java`)

```php
$requestCmd = sprintf('openssl ocsp -cert %s %s -url %s -text 2>&1',
    escapeshellarg($certFile), $issuerArg, escapeshellarg($ocspUrl));
$output = shell_exec($requestCmd);
```

Arguments are escaped and the interpolated `$issuerArg` derives from `tempnam()`, so **direct command injection is not present today.** The problems are structural:

- **Fragile trust boundary.** Security-relevant decisions are made by regex-matching human-readable CLI output (`stripos($output, 'revoked')`). A locale change, an OpenSSL version bump, or a translated message silently changes the security verdict. Note that `checkOcsp` treats *any* output containing the substring "revoked" as revoked and any containing "good" as good — an OCSP error message mentioning either word flips the result.
- **Undeclared runtime dependencies.** `openssl` and `java` binaries must exist in the container. Nothing validates this at boot; failure appears as `shell_exec` returning `null`, which the code maps to "check inconclusive → proceed."
- **Any future refactor that interpolates user data into these commands becomes RCE.** The pattern is a loaded weapon.

**Remediation.** Replace shell invocation with `phpseclib/phpseclib` (already a direct dependency, `^3.0`) for X.509 and ASN.1 work, and a native OCSP request over the hardened HTTP client from H-2. Retain the Java Fatoora SDK shell-out only where ZATCA genuinely requires it (SDK-based validation), and assert the binary's presence in a health check.

---

### 🟠 H-5 — OpenSSL configuration injection during CSR generation

**Status:** Resolved — CSR generation writes no configuration file from user input.
**Evidence:** [`CertificateService::createZatcaOpenSslConfig()`](../../app/Domains/Compliance/Fatoora/Services/CertificateService.php)

```php
$config = <<<EOL
[dn]
O = {$data->organizationName}
OU = {$data->organizationUnit}
CN = {$data->commonName}
registeredAddress = {$data->location}
businessCategory = {$data->industry}
EOL;
file_put_contents($tempFile, $config);
```

Tenant-controlled strings (organization name, unit, location, industry) are interpolated into an OpenSSL configuration file with **no escaping and no newline stripping.** A value containing `\n` can terminate the current directive and inject arbitrary config — including a new `[section]` header or an overriding key.

**Impact.** A tenant could alter the generated CSR's extensions or distinguished name beyond what the application intends, producing a certificate request that misrepresents the taxpayer. Whether this reaches a signed certificate depends on ZATCA-side validation, which is why this is rated Probable rather than Confirmed — but the application should not be relying on the authority to sanitise its input.

**Remediation.** Validate these fields at the boundary (`CsrData` construction): reject anything outside a strict allowlist — no newlines, no `[`/`]`/`=`/`#`, length-capped, and matched against ZATCA's documented field constraints. Better, build the CSR with `phpseclib`'s `X509` API and eliminate the config-file round-trip entirely.

---

### 🟠 H-6 — Unauthenticated Prometheus metrics endpoint

**Status:** Resolved — `/metrics` is behind `MetricsAccess`: an allowlisted source IP or `METRICS_TOKEN`.
**Evidence:** [`routes/api.php:53-54`](../../routes/api.php#L53-L54)

```php
Route::get('/metrics', [MetricsController::class, 'index'])->middleware('throttle:60,1');
```

The controller docblock states *"Access: GET /metrics (protected by IP whitelist or auth)"* — neither is applied. Only a rate limit.

**Impact.** Discloses application version, PHP version, `APP_ENV`, and business telemetry (invoice counts, submission states, ZATCA error rates, queue depth). The version strings assist targeted exploitation; the business metrics leak commercial information (customer volume, failure rates) that is competitively sensitive and, in aggregate, informative about individual tenants.

**Remediation.** Bind `/metrics` to an internal listener not exposed by Traefik, or require a bearer token (`METRICS_TOKEN`), or restrict by source IP at the proxy. Scrapers support all three.

---

## 3. Medium findings

| ID | Finding | Evidence | Remediation | Status |
|---|---|---|---|---|
| 🟡 **M-1** | **XML parsing is not explicitly hardened.** ~15 `DOMDocument::loadXML()` call sites (`XadesSigner`, `InvoiceHasher`, `ComplianceValidator`, `FatooraValidator`, `FatooraComplianceService`) pass no libxml flags. PHP 8 + libxml ≥2.9 disable external entities by default, so classic XXE is *not* currently exploitable — but the protection is implicit and one `LIBXML_NOENT` away from regression. Entity-expansion (billion-laughs) and quadratic-blowup DoS remain possible on attacker-supplied XML. | `grep -rn "loadXML" app/` | Centralise all parsing in one `SafeXmlLoader` helper that passes `LIBXML_NONET \| LIBXML_NOCDATA`, sets `libxml_use_internal_errors(true)`, caps document size, and rejects any DOCTYPE outright. Ban direct `loadXML` via a static-analysis rule. | Resolved — every parse goes through `App\Support\Xml`; no direct `loadXML()` remains in `app/`. |
| 🟡 **M-2** | **Two parallel authorization models.** JWT routes use `ApiKey::hasScope()` with a `['*']` wildcard; licence routes use `License::hasScope()` with a separate `ApiScope` enum and implied-scope table. The same operation is reachable under two different authorization vocabularies. Divergence between them is a latent bypass. | `routes/api.php` §JWT vs §v1 | Unify on the `ApiScope` enum; remove the untyped `['*']` wildcard, which grants scopes that do not yet exist at issue time. See [05 §2](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#2-target-architecture). | Resolved by design — `ApiKey` was removed, leaving one scope vocabulary. The two surfaces answer to different audiences and each route file declares its guard once; see [09](09-WORK-MAP.md). |
| 🟡 **M-3** | **Rate limiting is per-user, not per-tenant or per-endpoint.** `RateLimitApi` keys on `auth()->id()` or IP with a flat 60/min. Expensive endpoints (`/pipeline/submit`, which signs and calls ZATCA) share a budget with `/health`. Unauthenticated public routes fall back to IP, which is trivially rotated. | [`RateLimitApi.php`](../../app/Domains/Platform/Http/Middleware/RateLimitApi.php) | Key on `tenant + route-group`; assign per-endpoint cost weights; apply a stricter, separate limit to unauthenticated routes. | Resolved — keyed on tenant, then user, then IP, with per-band limits from `config/security.php`. |
| 🟡 **M-4** | **`ApiKey::recordUsage()` writes to the database on every authenticated request** (`$this->update(['last_used_at' => now()])`), adding a synchronous write to the hot path and creating row-level contention on a busy key. | `ApiKey.php` (since deleted) | Batch via cache with periodic flush, or downgrade to minute-granularity and write only on change. | Superseded — `ApiKey` is gone. `ValidateLicense` still writes a usage event per request, deliberately: `usage_events` is an append-only billing ledger and the queue is database-backed, so deferring costs the same write and risks losing the event. |
| 🟡 **M-5** | **API key hashing is unsalted SHA-256 with no work factor.** Acceptable given 40 characters of `Str::random()` entropy, but offers no defence if the hash table leaks alongside a weak future key format, and `findByKey()` returns on `is_active` without also filtering `expires_at` in SQL (expiry is checked afterwards in PHP — correct today, fragile if another caller uses `findByKey` directly). | `ApiKey.php` (since deleted) | Add a server-side pepper from the secret store; move `expires_at` into the query; document that key entropy is the security control. | Resolved — `License::hashSecret()` is the only place a secret is hashed, HMAC-SHA256 under `security.api_key_pepper`. |
| 🟡 **M-6** | **Error responses may leak internals.** The catch-all handler returns `$e->getMessage()` when `config('app.debug')` is true, and `.env.example` ships `APP_DEBUG=true`. A production deployment seeded from the example file discloses stack-trace-grade detail via the API. | [`bootstrap/app.php`](../../bootstrap/app.php); `.env.example:4` | Set `APP_DEBUG=false` and `APP_ENV=production` in `.env.example`; add a boot-time assertion that refuses to start when `APP_ENV=production && APP_DEBUG=true`. | Resolved — `.env.example` ships `APP_DEBUG=false` with `APP_ENV=production`, and `AppServiceProvider` refuses to boot that combination with debug on. |
| 🟡 **M-7** | **No CSRF protection strategy is documented for the Blade surfaces**, and the `/admin` POST routes mutate state. Laravel's `web` group supplies `VerifyCsrfToken` by default, so this is covered today — but once C-1's auth is added, confirm the forms carry `@csrf` and that no `/admin` route is added to the CSRF exception list. | `routes/web.php` | Verify during the C-1 fix; add a test posting without a token. | Resolved — `CsrfPostureTest` pins all three: the `web` group carries `ValidateCsrfToken`, no route is on the exception list, and every posting form has `@csrf`. Asserted as posture rather than behaviour because the middleware stands itself down under PHPUnit, so a tokenless post returns 200 there and would prove nothing. |
| 🟡 **M-8** | **Audit logging is incomplete for security events.** `AuditService` covers invoice CRUD. There is no audit record for: authentication success/failure, API key creation/revocation, certificate onboarding, credential decryption, tenant-context switches (`/organizations/{id}/switch`), or admin actions. ZATCA and PDPL both expect an auditable trail for tax-document operations. | [`app/Domains/Audit/`](../../app/Domains/Audit/) | Extend the audit domain to a security-event log with actor, tenant, IP, request ID and outcome. Make it append-only. | Resolved — `AuditService` records sign-in, failed sign-in, unknown account and sign-out with actor, tenant and client address; a test asserts no entry carries the secret. |

---

## 4. Low findings

| ID | Finding | Remediation | Status |
|---|---|---|---|
| 🔵 **L-1** | `TenantIsolationGuard::runWithoutTenant()` disables enforcement via mutable singleton state — a request-scoped side effect that would be unsafe under Octane/Swoole. | Moot once the class is deleted (C-4); if any similar pattern is reintroduced, scope it to a closure, never to instance state. | Moot — `TenantIsolationGuard` is deleted. |
| 🔵 **L-2** | `TenantIsolationGuard::getEntityTenantId()` uses `property_exists()` on Eloquent models, which does not see attributes loaded into `$attributes`. The check silently returns `null` for most models, causing the guard to *pass* entities it should reject. | Moot with C-4's deletion; noted because it demonstrates the class was never exercised. | Moot — `TenantIsolationGuard` is deleted. |
| 🔵 **L-3** | No dependency vulnerability scanning in CI. `composer.lock` was last updated 2026-02-02; six months of advisories are unreviewed. | Add `composer audit` and Dependabot/Renovate to `.github/workflows/`. | Resolved — `composer audit --locked` runs in CI on every push. Re-rated **H-7** when the scan found 40 advisories, 10 of them high, in the locked tree; `composer update` cleared them. |
| 🔵 **L-4** | `LogSanitizer` exists in `Fatoora/Helpers/` but is not consistently applied — several `Log::debug`/`Log::error` calls in the submission path log full context arrays that may contain invoice or credential data. | Route all compliance-domain logging through `ComplianceLogger` and make sanitisation mandatory there. | Resolved — compliance logging goes through `ComplianceLogger`, which sanitises via `LogSanitizer` before writing. |

---

## 5. Controls that are correctly implemented

Noting these matters: they show the security thinking is present, and they should not be disturbed during remediation.

- **CORS defaults to closed.** `config/cors.php` deliberately returns an empty origin list when `CORS_ALLOWED_ORIGINS` is unset, with a comment explaining the choice, and `supports_credentials` is `false`. This is better than the Laravel default and better than most codebases.
- **Signing credentials are encrypted at rest** and partitioned per organization and per branch.
- **Monetary arithmetic uses `bcmath`,** not floats — correct for tax computation and a common source of ZATCA rejection elsewhere.
- **Idempotency is explicitly scoped and documented** (`organization + endpoint + key`, 24h window) with a dedicated `submission_idempotency` table.
- **Invoice mutation is transactional** and gated on `isEditable()`, preventing edits after issuance.
- **API keys are stored hashed,** never in plaintext, and `key_hash` is in `$hidden`.
- **Structured exception rendering** maps domain exceptions to stable error codes and correct HTTP statuses.
- **JWT failure modes are distinguished** (expired / invalid / absent) without leaking token internals.

---

## 6. Remediation sequence

**Sprint 1 — unauthenticated exposure (blocking; ~1 week)**
1. C-1 — add `['auth','admin']` to `/admin`; move closures into the controller.
2. C-2 — gate `/portal` behind auth; derive tenant from session. Stopgap: non-production only.
3. C-3 — delete query-parameter credential extraction; rotate exposed keys.
4. H-6 — restrict `/metrics`.
5. M-6 — fix `.env.example`; add the production/debug boot assertion.
6. Feature tests asserting the auth posture of **every** registered route.

**Sprint 2 — cryptographic correctness (~1 week)**
7. H-3 — fix the CRL serial comparison; add a revoked-certificate fixture test.
8. H-2 — allowlist OCSP/CRL endpoints; harden the fetch client.
9. H-5 — validate `CsrData` fields at the boundary.
10. M-1 — introduce `SafeXmlLoader`; ban direct `loadXML`.

**Sprint 3 — structural isolation (~2 weeks)**
11. C-4 — `BelongsToTenant` global scope across all tenant-scoped models; delete `TenantIsolationGuard`; add the two-tenant leakage test suite.
12. M-8 — security audit events.

**Quarter 2 — key management (~3 weeks)**
13. H-1 — KMS envelope encryption; move ciphertext off local disk; write the rotation command.
14. H-4 — replace shell-outs with `phpseclib`.
15. M-2, M-3, M-4, M-5 — auth unification and rate-limit redesign, alongside the [05](05-TARGET-ARCHITECTURE-AND-ROADMAP.md) API consolidation.

---

## 7. Compliance exposure summary

| Regime | Exposure | Driver |
|---|---|---|
| **Saudi PDPL** | High | C-1/C-2 expose taxpayer identifiers and VAT numbers without authentication; M-8 means a breach could not be reconstructed from logs. |
| **ZATCA Phase 2** | Medium | H-3 permits signing with a revoked certificate. H-1's shared key weakens the non-repudiation property the CSID is meant to provide. |
| **UAE FTA (2027 mandate)** | Low today | FTA engine is pre-production; the same controls must be in place before it is used. |
| **SOC 2 / ISO 27001 readiness** | Not achievable today | Requires C-1…C-4 closed, M-8 audit trail, H-1 key management, and dependency scanning (L-3). |

---

## 8. What this audit did **not** cover

State these plainly to whoever consumes this report; they are real gaps, not omissions.

- **No dynamic testing.** No requests were issued against a running instance. Confirmed findings were verified by reading complete code paths, not by exploitation.
- **No dependency CVE scan.** `composer.lock` and `package-lock.json` were not checked against advisory databases (see L-3).
- **No infrastructure review.** Docker images, Traefik configuration, TLS parameters, database hardening, network segmentation, secret injection and backup/DR procedures were not assessed. `docker-compose.prod.yml` was read only for service topology.
- **No review of `erp-backend` or `erp-frontend` security.** Both were inspected only far enough to characterise the integration seam. `erp-backend` is ~2,100 PHP files and warrants its own audit — particularly its own multi-tenancy enforcement, which was not examined.
- **No cryptographic review of the ZATCA signature construction itself** against the official specification. `XadesSigner` and `InvoiceHasher` were read for security properties, not verified for conformance. That requires ZATCA's SDK conformance suite — see [03 §6](03-QUALITY-PERFORMANCE-MAINTAINABILITY.md#6-testing-strategy).
- **No threat model or abuse-case analysis** for the business logic (e.g. invoice-number squatting, ICV manipulation across tenants, deliberate offline-queue flooding).

A dynamic penetration test should follow Sprint 1, once the unauthenticated surfaces are closed.
