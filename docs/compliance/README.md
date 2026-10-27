# Compliance

Audience: anyone who needs to know what the platform complies with, per
country, and where each country's rules are written down.

The platform applies each authority's content rules to the documents it is
given, produces the document format the authority requires, and files it by
the required route. It does not decide what is taxable, at what rate, or
whether a taxpayer must register.

## Per country

| | Saudi Arabia | United Arab Emirates |
|---|---|---|
| Authority | Zakat, Tax and Customs Authority (ZATCA) | Ministry of Finance and Federal Tax Authority |
| Regime | E-invoicing (Fatoora), integration phase | Electronic invoicing through accredited service providers |
| Format | UBL 2.1 per the ZATCA XML Implementation Standard, signed (XAdES, ECDSA), with a QR code | Peppol PINT AE (UBL 2.1), checked against the PINT AE schematron |
| Route | Standard (B2B) cleared before issue; simplified (B2C) reported within 24 hours | Sent to the organization's accredited service provider, which delivers it to the buyer's provider and reports it to the authority |
| Sequence and integrity | ICV counter and PIH hash chain per device (EGS unit) | The provider's; each document keeps an append-only status history |
| Key legal texts | VAT Law and Implementing Regulations; E-Invoicing Regulation; XML Implementation Standard (BR-KSA-*); E-Invoicing Detailed Guidelines; Guideline for Tax Invoicing and Records | Federal Decree-Law No. 8 of 2017 on VAT and Cabinet Decision No. 52 of 2017; Ministerial Decisions No. 243 and 244 of 2025; Electronic Invoicing Guidelines v1.1; PINT AE rules (IBR-*, ALIGNED-IBRP-*) |
| Before the obligation applies | — | No e-invoicing start date set, or before it: an ordinary tax invoice, kept and never transmitted |
| Retention | Six years, longer for capital assets; fifteen for real estate | Five years after the period; longer for real estate, disputes and audits |
| Overview | [sa/README.md](sa/README.md) | [ae/README.md](ae/README.md) |
| Rules as implemented | [sa/RULES.md](sa/RULES.md) | [ae/RULES.md](ae/RULES.md) |
| Also | [sa/HASHING-AND-SIGNING.md](sa/HASHING-AND-SIGNING.md), [sa/ICV-PIH.md](sa/ICV-PIH.md), [sa/STATUS.md](sa/STATUS.md) | [ae/PROVIDER-ONBOARDING.md](ae/PROVIDER-ONBOARDING.md) |

No other country has a module. Adding one is described in
[../architecture/ADDING-A-JURISDICTION.md](../architecture/ADDING-A-JURISDICTION.md).

## Across countries

- [POLICIES.md](POLICIES.md) — policy decisions: invoice identity, timestamps,
  certificate versions, ZATCA environments, retention, what is out of scope.
- [AUDITOR-PACK.md](AUDITOR-PACK.md) — for an external auditor: what the
  system guarantees and where the evidence is.
- [../architecture/FILING-STATES.md](../architecture/FILING-STATES.md) — the
  invoice, submission and network document states.
- [../architecture/BOOKS-AND-FINANCE.md](../architecture/BOOKS-AND-FINANCE.md)
  — the VAT return draft and its box mapping per country.

## Roles

- **The platform** is the taxpayer's e-invoicing solution: it validates,
  builds, signs (Saudi Arabia), files and keeps documents.
- **The taxpayer** (the organization) remains responsible for its tax
  position, its registration details and the data it sends.
- **The accredited service provider** (UAE) is appointed by the taxpayer and
  carries the document over the Peppol network and reports it to the
  authority. The platform connects to it; it does not replace it.
