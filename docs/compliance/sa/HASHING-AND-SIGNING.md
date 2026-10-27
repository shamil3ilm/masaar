# Saudi Arabia — every hash, encoding and key in a ZATCA document

Audience: engineers touching the signer, the chain or the credential store,
and reviewers who need to know how each value was established.

ZATCA's forms are not what the surrounding standards suggest, and a value in
the wrong form is reported as a wrong certificate, signature or PIH rather
than a wrong form. Each entry was established against ZATCA's own artefacts —
the SDK's `Data/` directory and its signed samples — and, where noted, the
live sandbox ([STATUS.md](STATUS.md)). Code: `app/Domains/Compliance/Countries/SaudiArabia/Signing/`.

## The two encodings

| Name | PHP | Decodes to |
|---|---|---|
| **base64-of-bytes** | `base64_encode(hash('sha256', $x, true))` | 32 bytes |
| **base64-of-hex** | `base64_encode(hash('sha256', $x))` | 64 hex characters |

## Digests

| Value | Over | Written as | Established by |
|---|---|---|---|
| Invoice hash (QR tag 6, `ds:Reference URI=""`) | The XML without `UBLExtensions`, `cac:Signature` and the QR's `AdditionalDocumentReference`, canonicalised | base64-of-bytes | ZATCA's sample digests decode to 32 bytes |
| PIH (KSA-13) | The previous document's invoice hash | as stored | BR-KSA-26 |
| Genesis PIH | The string `"0"` | base64-of-hex: `NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==` | The SDK's `Data/PIH/pih.txt`; 32 zero bytes is refused "PIH is inValid" |
| `xades:CertDigest` | The certificate's **base64 text** (`ds:X509Certificate`), not its DER | base64-of-hex | Reproduces the digest in the SDK's `Simplified_Invoice.xml` |
| SignedProperties reference | `xades:SignedProperties` as the document writes it, with `xmlns:xades` on the element and `xmlns:ds` on each `ds:` child | base64-of-hex | The SDK signing one of the platform's documents; reproduces three of ZATCA's samples |

The genesis value is `FatooraConfig::GENESIS_PIH`, emitted by
`Document/UblHeaderBuilder` and recorded by `Issuing/ChainRecorder`, which must
agree. The two references in one `ds:SignedInfo` are **encoded differently**.

## Never re-indent a signed document

The QR is inserted by a DOM round trip **after** signing
(`QrCodeInjector`, which owns the load and save). If that round trip
pretty-prints, the digest in `ds:SignedInfo` no longer describes the block the
document carries, and the live API refuses every simplified document with
"Invalid signed properties hashing" while standard ones still clear (ZATCA
verifies the seller's signature only on what it does not stamp itself). The
SDK cannot see this — it recomputes the digest from the bytes it is given —
so only submitting can. `EmittedSignatureTest` (onboarding path) and
`EmittedDigestsTest` (production path) assert the recorded digest still
describes the emitted block. Indentation of the block itself is irrelevant
*when the digest describes it*.

The block is built with `createElement` and literal prefixed names, not
`createElementNS`, which would add a namespace declaration to every element
and change the bytes (`SignedPropertiesBuilder`).

`ds:Signature` carries `Id="signature"` and `QualifyingProperties` the
`Target` `signature` without `#`, as ZATCA's accepted documents do;
`xades:SigningTime` is on the Kingdom's clock with no `Z`, as ZATCA's signer
writes it.

## Signature

- ECDSA over **secp256k1** (not P-256) with SHA-256, over the canonicalised
  `ds:SignedInfo`, written as base64 (`EcdsaSigner`, `XadesSigner`).
- The SDK's `signatureValue` check fails on ZATCA's own samples (pretty-printed
  after signing), so it is the one check `ZatcaConformanceTest` excludes, by
  name. `SignatureVerifiesTest` verifies the signature with OpenSSL against the
  document's own certificate, on the document as finally emitted.
- Outside the sandbox `XadesSigner` refuses a private key that does not match
  the certificate.

## X509IssuerName

The issuer's whole DN, most specific first, every component — for ZATCA's
pre-production CA `CN=PRZEINVOICESCA4-CA, DC=extgazt, DC=gov, DC=local`. Taken
verbatim from ZATCA's signed sample; `openssl_x509_parse` returns the
components in reverse.

## QR code

TLV then base64, tags 1–9: seller name (the legal name as registered — see
[RULES.md](RULES.md#seller)), VAT number, timestamp, total with VAT, VAT total,
invoice hash, signature, public key, and on a simplified document the
certificate's signature (`QrCodeGenerator`, `TlvEncoder`). On a cleared
invoice the QR in ZATCA's stamped copy replaces the one built before
submission (`Filing/InvoiceVerdict`).

## Keys, CSRs and certificates

- **Key:** EC secp256k1. On Windows key generation needs an explicit
  `openssl.cnf`, or fails with a misleading BIO error.
- **CSR template** names the environment; another environment's is refused.
  It follows the organization's environment (`FatooraEnvironment::of()`,
  `Onboarding/CsrBuilder::templateFor()`):

  | Template | Environment | SDK flag |
  |---|---|---|
  | `TSTZATCA-Code-Signing` | sandbox | `-nonprod` |
  | `PREZATCA-Code-Signing` | simulation | `-sim` |
  | `ZATCA-Code-Signing` | production | none |

- **CSR organization unit:** the 10-digit TIN of the group member; the SDK
  refuses a department name, so `fatoora:generate-csr`'s default
  `--unit=IT Department` does not pass it.
- **SDK output formats:** CSR is base64 of a PEM, the key base64 of a DER,
  `cert.pem` base64 of a DER without armour; each needs wrapping before
  OpenSSL reads it.
- **Validity:** an expired certificate cannot stamp
  (`IssuingStep::assertNotExpired()`); revocation is checked before each stamp
  (`RevocationCheck`), except where no revocation list is configured or the
  check is switched off ([RULES.md](RULES.md#certificates)).

## Credential storage

`CredentialStore` keeps each device's CCSID and PCSID encrypted on
`fatoora.signing.disk`, under `fatoora.signing.key` (`ZATCA_CREDENTIAL_KEY`;
falls back to `APP_KEY` when empty). Previous keys
(`fatoora.signing.previous_keys`) decrypt only, so
`platform:rotate-credential-key` can re-encrypt every file. One secret covers
every tenant. Every credential records the environment that issued it and is
never handed out in another; a switch archives rather than deletes
(`CredentialStore::archive()`). Every production certificate a device has held
is kept as a version (`device_certificates`, `Models/CertificateVersion`), and a
document is filed with the version that signed it
(`CredentialStore::forInvoice()`).

## Testing without a taxpayer

The SDK ships a certificate from ZATCA's pre-production CA with its key, for
the sample taxpayer `399999999900003`. The test trait
`tests/Fixtures/SigningCredentials.php` (`authorityCredentials()`) signs
conformance documents with it when `ZATCA_SDK_PATH` is set, which is what lets
the SDK check the certificate and QR; otherwise a self-signed pair is used.
