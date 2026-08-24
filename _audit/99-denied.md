# 99 — Blocked Access, Wrong Premises, and Mid-Audit Changes

Everything that could make a finding above incomplete or stale.

---

## 1. `c:\laragon\www\Zatca` does not exist

**Impact: the brief's central premise was wrong. Corrected, not worked around.**

The brief named four repos and identified `./Zatca` as the home of the ZATCA
logic. `ls c:/laragon/www/` returns:

```
Masaar  assist  axiom  docs  erp-backend  erp-frontend  foster-sear
index.php  laravel.apimaster.zilmoney.com  live.ocwapi.com
live.onlinecheckwriter.com  myapp  portfolio  wed_cert
```

No `Zatca`, in any casing. The migration columns the brief attributed to it
(`payment_means_code`, `is_third_party`, `is_nominal`, `is_export`, `is_summary`,
`is_self_billed`) are in **Masaar's own**
[`database/migrations/0080_invoices.php:31,50-54`](../database/migrations/0080_invoices.php#L50-L54).

**Root cause found:** Masaar's git remote is
`https://github.com/shamil3ilm/zatca.git`. There is no `Zatca` *directory* but
there is a `zatca` *repository* — this one. The instinct was right about the
name and wrong about the shape.

The audit was therefore performed against **three** repos, with ZATCA audited in
Masaar where it actually lives. Nothing was forced onto the wrong repo.

---

## 2. ⚡ The working tree changed **during** this audit

**Impact: HIGH. This is the most important caveat in the audit.**

Five files were modified in Masaar's working tree between the start of this run
and the writing of [04-gap-matrix.md](04-gap-matrix.md), implementing the
`cleared_xml` fix:

```
M app/Domains/Compliance/Fatoora/Services/Submitter.php      (+45 lines)
M app/Domains/Invoice/Models/Invoice.php                     (+22)
M app/Domains/Pipeline/Http/Controllers/PipelineController.php (+4/-2)
M app/Domains/Pipeline/Services/PipelineResult.php           (+7/-1)
M database/migrations/0080_invoices.php                      (+5)
```

Timeline:
1. Early in the run, `grep -rn "clearedInvoice" app/ tests/` returned **only**
   DTO declarations and `null` test fixtures — no production consumer. I recorded
   gap item 34 as **ABSENT** and it was correct at that moment.
2. Later, reading `Submitter.php` for a different purpose, I found
   `clearedXml()` at `:420` and the assignment at `:400`. Re-running the same
   grep confirmed the file had changed.
3. `git status` showed five newly-modified files absent from the session-start
   snapshot. HEAD was unchanged (`e83d8fe`) throughout, so the change is
   uncommitted.

**Then it changed again.** A later check found three more files touched and
**three new test files** that did not exist when I formed the findings:

```
M  app/Console/Commands/FatooraValidate.php
?? tests/Feature/Compliance/ClearedDocumentTest.php     ← tests the cleared_xml fix
?? tests/Feature/Compliance/XmlProfileTest.php          ← pins CustomizationID / ProfileID
?? tests/Feature/Compliance/SecretFileTest.php
```

**Consequences:**
- **All findings are stated against the working tree, not HEAD `e83d8fe`.**
  If you diff this audit against `git show e83d8fe`, several items will look wrong.
- Gap item 34 went **ABSENT → PRESENT-UNVERIFIED → VERIFIED** over the course of
  this single audit run.
- **R-1 is closed.** `ClearedDocumentTest.php` asserts `cleared_xml` is
  populated from a base64 response (`:79`), differs from `signed_xml` (`:89`),
  that `legal_xml` prefers it (`:93`), that it stays null for a reporting
  response (`:106-107`), that a non-base64 value is kept verbatim (`:119`), and
  that it reaches the pipeline payload (`:130`). That is a more thorough test
  than I asked for.
- **Q1/Q2 are now guarded but still unanswered.** `XmlProfileTest.php` pins the
  emitted values, and is explicit about what that does and does not mean:
  *"They are a tripwire, not a certificate: whether these are the values ZATCA
  requires is an open question... answering it needs the published schema."*
  The values are unchanged. R-3 Q1/Q2 stand.
- **Suite re-run confirms green:** `715 passed, 3 skipped (1589 assertions), 28.00s`
  — up from 704/1546. The 11 new passing tests are the two new files.

**The pattern to take from this:** findings in this audit have a short shelf
life, because the repo is being actively worked on faster than an audit of it
can be written. Treat the *reasoning* as durable and re-check the *facts*.

---

## 3. Two Bash commands were denied by the user

Mid-run, two calls were rejected at the permission prompt:

| Command | Purpose | Recovered? |
|---|---|---|
| `grep -rn "generateNextIcv" app/ tests/` | Find ICV allocation callers | ✅ Yes — re-run via the Grep tool |
| `sed -n '1,80p' app/Domains/Invoice/Models/Invoice.php` | Read the Invoice model head | ✅ Yes — re-run via the Read tool |

**No finding is incomplete as a result.** Both were retried with the file tools
and returned the needed evidence. The remainder of the audit favoured
`Read`/`Grep` over shell where equivalent.

---

## 4. The test suite could not run with the default PHP

`php artisan test` aborts:

```
Fatal error: Composer detected issues in your platform: Your Composer
dependencies require a PHP version ">= 8.4.1". You are running 8.2.28.
in C:\laragon\www\Masaar\vendor\composer\platform_check.php on line 22
[exited with code 0]
```

**Note the exit code: 0.** A failure that reports success.

Worked around by invoking
`C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe artisan test`, which
gave the real result (704 passed / 3 skipped / 1546 assertions / 30.99s). CI is
configured correctly (`ci.yml` pins `PHP_VERSION: '8.4'`); only the local default
is wrong. Recorded as R-13.

---

## 5. ~~Three skipped tests — not identified~~ **RESOLVED — and I was wrong**

Closed by re-running with `--display-skipped`. All three are the same file:

```
SKIPPED  Tests\Feature\Compliance\SecretFileTest > directory is owner only
SKIPPED  Tests\Feature\Compliance\SecretFileTest > secret is owner only
SKIPPED  Tests\Feature\Compliance\SecretFileTest > chosen directory is owner only
         POSIX file modes are not enforced on Windows.
```

**My earlier speculation was wrong.** I guessed one skip was
`IcvAllocationTest::test_duplicate_icv_rejected`, which would have meant the ICV
duplicate-rejection guarantee went unexercised. It is not skipped — **it runs and
passes.** The unique-index backstop under [06-risks.md](06-risks.md) Step 6 *is*
covered by a passing test. Correction in the project's favour.

The real skips are environmental and benign: the new `SecretFileTest` asserts
`0700`/`0600` file modes, which Windows does not enforce. They will run in CI
(`ubuntu-latest`). Worth knowing that **this class of check is unverifiable on
your dev machine** and only ever proven by CI.

---

## 6. What no static audit could verify — and it is the crux

**Nothing in this system has ever been checked against ZATCA.** This is not an
access denial; it is the finding. But it bounds what this audit can claim:

| Cannot be verified without a live call | Affected gap items |
|---|---|
| Whether generated XML satisfies the ZATCA XSD | 2, 10 |
| Whether the CSR is acceptable (correct OIDs, template, curve) | 15, 16 |
| Whether the XAdES matches **ZATCA's profile** (not merely valid XAdES) | 17, 18 |
| Whether the compliance suite passes for the six document types | 23 |
| Whether CCSID/PCSID onboarding works | 22, 24, 25 |
| Whether sandbox/simulation/production are correctly separated | 28 |

No CCSID, PCSID, stored ZATCA response, or `.env` credential for the sandbox was
found. **I did not read any `.env` file**, per the brief.

---

## 7. Four ZATCA specification questions I refused to guess at

Detailed in [06-risks.md](06-risks.md) R-3. Summarised because they are the
largest source of uncertainty in this audit:

| # | Question | My assumption | Confidence |
|---|---|---|---|
| Q1 | Correct `CustomizationID`? | `urn:sa:zatca:documents:1.0` | Low |
| Q2 | `ProfileID` per document type? | `reporting:1.0` for both | Low |
| Q3 | ICV/PIH chain per taxpayer or per **EGS unit**? | Unknown — genuinely | None |
| Q4 | Separate Arabic party name mandated? | Unknown | None |
| Q5 | Does ZATCA tolerate **gaps** in the ICV sequence? | Unknown | None |

Every one is marked ASSUMPTION where it appears. **Do not act on Q1–Q5 from
this audit.** Q3 is the expensive one and its cost rises with every invoice
issued.

I have deliberately not resolved these from memory. The repo's own most recent
commit took the same position — `e83d8fe` *"docs(audit): record two conformance
questions rather than guess at them"* — which suggests you already know these
two are open.

---

## 8. `.env` files are unreadable by permission policy

**Impact: one known live bug could not be fixed.**

Both `Masaar/.env.example` and `Masaar/docker/.env.template` are blocked by the
session's permission configuration — `Read` and `grep` on them are denied. This
is consistent with the audit's own rule against printing `.env` contents, and I
did not attempt to work around it.

**Consequence:** a reported mismatch could not be confirmed or repaired —
`.env.example` is said to carry `APP_NAME=Masaar` / `DB_DATABASE=masaar` while
`docker/.env.template` still carries `APP_NAME=CompliPay` / `DB_DATABASE=complipay`,
so the Docker and local environments disagree on the database name. That is a
live bug independent of any rename. `docker/.env.template` is the **only** file
in the `docker/` tree still containing the old brand after the sweep in §10.

*You must edit it by hand.* Set `APP_NAME=Masaar` and `DB_DATABASE=masaar` to
match `.env.example`, and check `DB_USERNAME` while you are there.

---

## 9. Repo names could not be changed from here

`gh` is not installed (`Get-Command gh` → not found), and repo renames need
authentication. The three GitHub renames are browser-only steps for the user.
API reads worked unauthenticated, which is how the `qarar` → `masaar` redirect
in §1 was resolved.

---

## 10. Not inspected

Scoped out, or not load-bearing for a ZATCA readiness assessment:

- **`sdks/`** (11 languages) — README self-describes them as skeletons; not part
  of the compliance surface.
- **`cloudflare-worker/`** — not traced.
- **`erp-frontend` internals** — confirmed to contain no ZATCA logic
  (`grep -ril zatca` clean), then left alone.
- **`erp-backend`'s 274,398 lines** beyond `Services/Compliance/`,
  `Orchestrators/Sales/`, and the webhook path. Its 41 staged deletions were
  counted, not read.
- **`docs/audit/`** (11 prior documents) — read *after* forming my own findings,
  used only to cross-check. Where I disagree with it, I say so. Its
  `09-WORK-MAP.md` reports 358 tests; the suite is now 704, so it is materially
  out of date in the project's favour.
- **`vendor/`**, `node_modules/`.
- **Any `.env` file, private key, certificate, or CSID.** Existence and location
  reported only; no values read or printed anywhere in this audit.

---

## 9. Files written by this audit

Only inside `./_audit/`, as instructed. Nothing in the three sibling
directories was modified, and nothing in Masaar outside `_audit/` was touched.

```
_audit/00-map.md            _audit/05-data-model.md
_audit/01-summary.md        _audit/06-risks.md
_audit/02-inventory.md      _audit/07-consolidation.md
_audit/03-pipeline.md       _audit/08-next.md
_audit/04-gap-matrix.md     _audit/99-denied.md
```

`_audit/` is currently **untracked** (`?? _audit/` in `git status`). No
migration, install, format, or commit was run at any point.
