# 99 — What Could Not Be Verified

Everything that bounds a finding elsewhere in this audit.

---

## 1. The cryptographic stamp is not verified

Conformance **ran** — ZATCA SDK 238-R3.4.8, all six document types, zero errors
and zero advisories. But the suite asserts on business rules (`BR-*`) only, and
deliberately filters four stages that cannot pass with a self-signed key:
the certificate, the QR that embeds it, the signature over both, and the PIH
chain ([`ZatcaConformanceTest.php:155-178`](../tests/Feature/Compliance/ZatcaConformanceTest.php#L155-L178)).

**What the documents say is verified. Who signed them is not.** That is the
bound on gap items 15–18 and on rung L3.

---

## 1b. `CustomizationID` is outside the validator's reach

The SDK's Schematron and XSLT contain **no `CustomizationID` rule**, so the
green run neither endorses nor rejects the value at `XmlBuilder.php:125`. A
passing conformance run is not evidence about this field.

---

## 2. No ZATCA credentials exist

No CCSID, no PCSID, no stored authority response anywhere in the system. Nothing
has been submitted, not even to the sandbox. Consequently unverifiable:

| Cannot be verified without a live call | Gap items |
|---|---|
| Whether the CSR is acceptable (OIDs, template, curve) | 15, 16 |
| Whether the XAdES matches **ZATCA's profile**, not merely valid XAdES | 17, 18 |
| Whether the six-document compliance suite passes | 23 |
| Whether CCSID/PCSID onboarding works | 22, 24, 25 |
| Whether sandbox/simulation/production are correctly separated | 28 |

---

## 3. `.env` files are unreadable by permission policy

`Read` and `grep` on `.env.example` and `docker/.env.template` are denied by the
session's permission configuration — consistent with the brief's rule against
printing `.env` contents.

Their **values** were compared programmatically without being read or printed:
`APP_NAME` and `DB_DATABASE` agree between the two files; `DB_USERNAME` differs
deliberately (`masaar` in the container, matching
[`docker-compose.yml:93`](../docker-compose.yml#L93) `MYSQL_USER: ${DB_USERNAME:-masaar}`;
`root` locally for Laragon).

What could **not** be checked: every other key in those files.

---

## 4. The default PHP silently no-ops the test suite

`php -v` is **8.2.28**; `composer.json` requires `^8.4`. `php artisan test`
aborts in `vendor/composer/platform_check.php` — **and exits 0**. A failure that
reports success.

All results in this audit come from
`C:\laragonin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe`. CI is configured
correctly (`ci.yml` pins 8.4); only the local default is wrong. Recorded as R-13.

---

## 5. Three tests skip on Windows

`SecretFileTest` asserts `0700`/`0600` file modes, which Windows does not
enforce. **This class of check is unverifiable on this machine** and is only
ever proven by CI (`ubuntu-latest`).

---

## 6. ZATCA specification questions left unanswered

Detailed in [06-risks.md](06-risks.md) R-3. Summarised because they are the
largest source of uncertainty in this audit:

| # | Question | Assumption | Confidence |
|---|---|---|---|
| Q1 | Correct `CustomizationID`? | `urn:sa:zatca:documents:1.0` | Low |
| Q3 | ICV/PIH chain per taxpayer or per **EGS unit**? | Unknown — genuinely | None |
| Q4 | Separate Arabic party name mandated? | Unknown | None |
| Q5 | Does ZATCA tolerate **gaps** in the ICV sequence? | Unknown | None |

Each is marked ASSUMPTION where it appears. **Do not act on them from this
audit.** Q3 is the expensive one: its cost rises with every invoice issued.

Q2 (`ProfileID`) was on this list and is now settled against the SDK samples —
it turned out to be wrong in the code. That is the argument for settling Q1 the
same way rather than trusting the assumption above.

---

## 7. Not inspected

Scoped out, or not load-bearing for a ZATCA readiness assessment:

- **`sdks/`** (11 languages) — README self-describes them as skeletons; not part
  of the compliance surface.
- **`cloudflare-worker/`** — not traced.
- **`masaar-erp-frontend` internals** — confirmed to contain no ZATCA logic
  (`grep -ril zatca` clean), then left alone.
- **`masaar-erp-backend`'s 274,398 lines** beyond `Services/Compliance/`,
  `Orchestrators/Sales/` and the webhook path. Its large CQRS-removal refactor
  was characterised from the diff summary and its passing suite, not read
  file by file.
- **`docs/audit/`** (11 prior documents) — read *after* forming my own findings,
  used only to cross-check. Where I disagree with it, I say so. Its
  `09-WORK-MAP.md` reports 358 tests; the suite is now 715, so it is materially
  out of date in the project's favour.
- **`vendor/`**, `node_modules/`.
- **Any `.env` file, private key, certificate, or CSID.** Existence and location
  reported only; no values read or printed anywhere in this audit.

---

## 8. Files written by this audit

Ten documents in `./_audit/`:

```
_audit/00-map.md            _audit/05-data-model.md
_audit/01-summary.md        _audit/06-risks.md
_audit/02-inventory.md      _audit/07-consolidation.md
_audit/03-pipeline.md       _audit/08-next.md
_audit/04-gap-matrix.md     _audit/99-denied.md
```

No migration was run and no schema was altered at any point.
