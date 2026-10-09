# Saudi Arabia - every hash, encoding and key in a ZATCA document

ZATCA's rules for these are not what the surrounding standards would suggest,
and they are not consistent with each other: two digests in the same signature
are encoded differently, and one of them is taken over a reconstruction of an
element rather than over the element. A value in the wrong form is not reported
as a wrong form. It is reported as a wrong certificate, a wrong signature or an
invalid PIH, which is why each entry below says how it was established rather
than only what it is.

Everything here was checked against ZATCA's own artefacts - the Java SDK's
`Data/` directory and the signed samples it ships - and not against prose.
`ZatcaConformanceTest` runs the SDK over documents this platform generates;
point `ZATCA_SDK_PATH` at an unpacked SDK to run it.

## The two encodings

Nearly every digest here is SHA-256, and the question is always what it is
taken over and how the result is written. Two forms appear:

| Name used below | PHP | Decodes to |
|---|---|---|
| **base64-of-bytes** | `base64_encode(hash('sha256', $x, true))` | 32 bytes |
| **base64-of-hex** | `base64_encode(hash('sha256', $x))` | 64 hex characters |

A value that decodes to 64 bytes rather than 32 is in the second form. That is
the quickest way to tell which one a sample is using.

## Invoice hash (the QR's tag 6)

- **Over:** the invoice XML with `UBLExtensions`, `cac:Signature` and the QR's
  `AdditionalDocumentReference` removed, then canonicalised.
- **Written as:** base64-of-bytes.
- **Where:** `ds:Reference URI=""`, and the hash the generate endpoint returns.
- **Established by:** the reference digest in ZATCA's signed samples decodes to
  32 bytes.

## Previous invoice hash (PIH)

- **Over:** the previous document's invoice hash.
- **Genesis, for the first document in a chain:** base64-of-hex of the string
  `"0"`, which is the literal
  `NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==`.
  It is **not** thirty-two zero bytes, and not base64-of-bytes of anything.
- **Where:** `FatooraConfig::GENESIS_PIH`, emitted by `XmlBuilder` and recorded
  by `ChainRecorder`, which have to agree.
- **Established by:** it is the value the SDK ships in `Data/PIH/pih.txt` and
  checks KSA-13 against. This platform sent base64 of thirty-two zero bytes
  until 2026-10-09, so every first document in a chain was refused with
  "PIH is inValid".

## Signing certificate digest (xades:CertDigest)

- **Over:** the certificate's **base64 text**, the contents of
  `ds:X509Certificate` - and not the DER bytes that text encodes.
- **Written as:** base64-of-hex.
- **Established by:** the digest recorded in
  `Data/Samples/Simplified/Invoice/Simplified_Invoice.xml` equals the
  base64-of-hex digest of that sample's own certificate text. The digest of its
  DER matches in neither encoding.

## SignedProperties reference digest

- **Over:** a **reconstruction** of the `xades:SignedProperties` element - not
  the element as the document writes it, and not a canonical form of it. ZATCA
  rebuilds the block from a template and digests that. The template carries:
  - `Id="xadesSignedProperties"`, fixed rather than generated per signature;
  - `xmlns:xades` on the element and `xmlns:ds` on each `ds:` child, and
    nowhere else;
  - indentation of 36, 40, 44, 48 and 52 spaces by depth, and 32 before the
    closing tag;
  - LF line endings.
- **Written as:** base64-of-hex. The invoice reference in the same
  `ds:SignedInfo` is base64-of-bytes. **The two references in one signature are
  encoded differently.**
- **Established by:** built from the four values in ZATCA's own signed sample,
  that template hashes byte for byte to the digest the sample records, where
  neither inclusive nor exclusive canonicalisation of the same element does.
- **Status: not satisfied for documents generated here.** The template is
  proven against the sample, and this platform's recorded digest is that
  template over its own values, and the SDK still answers
  `xadesSignedPropertiesDigestValue: wrong`. Two checks stay excluded in
  `ZatcaConformanceTest::businessRules()` because of it - that one, and the
  `signatureValue` taken over it. The authority's Security Features
  Implementation Standards is where to settle it.

## Signature

- **Algorithm:** ECDSA over secp256k1 with SHA-256. Not P-256.
- **Over:** the canonicalised `ds:SignedInfo`.
- **Written as:** base64 of the raw signature.
- **Note:** ZATCA's own shipped samples fail their validator's `signatureValue`
  check, because the files were pretty-printed after they were signed. A sample
  is an oracle for the digests above, and not for this one.

## X509IssuerName

- **Form:** the issuer's whole distinguished name, most specific component
  first, every component present including the repeated ones. ZATCA's
  pre-production CA is `CN=PRZEINVOICESCA4-CA, DC=extgazt, DC=gov, DC=local` -
  a CN and three DCs, with no O and no C.
- **Established by:** that string appears verbatim in ZATCA's signed sample,
  and `openssl_x509_parse` returns its components in the reverse order. A
  formatter naming only CN, O and C - the three fields a self-signed
  certificate happens to have - emits the CN alone for a real CSID.

## QR code (simplified documents)

- **Form:** TLV, then base64. Tags 1-9: seller name, VAT number, timestamp,
  total with VAT, VAT total, invoice hash, signature, public key, and for a
  simplified document the certificate's own signature.
- **Note:** tags 8 and 9 carry key material, so a QR is only as sound as the
  certificate behind it. With a self-signed certificate the SDK cannot check it
  and reports nothing about it either way.

## Keys and certificates

- **Key:** EC secp256k1. Generating one on Windows needs an explicit
  `openssl.cnf` path, or every key operation fails with a BIO error that reads
  like a malformed request rather than a missing config.
- **CSR template:** what tells ZATCA which environment a request is for. A
  request carrying another environment's template is refused.

  | Template | Environment | SDK flag |
  |---|---|---|
  | `TSTZATCA-Code-Signing` | compliance / sandbox | `-nonprod` |
  | `PREZATCA-Code-Signing` | simulation | `-sim` |
  | `ZATCA-Code-Signing` | production | none; the SDK's default |

- **CSR organization unit:** the 10-digit TIN of the group member whose device
  is being onboarded. The SDK refuses a department name for all three
  templates, so the `fatoora:generate-csr` default of `IT Department` cannot
  produce a CSR through it.
- **Formats the SDK hands back:** the CSR is base64 of a PEM, the private key
  is base64 of a DER, and `Data/Certificates/cert.pem` is base64 of a DER with
  no PEM armour. Each needs wrapping or decoding before OpenSSL reads it, and a
  missing wrap surfaces as `error:1E08010C:DECODER routines::unsupported` or as
  "X.509 Certificate cannot be retrieved".
- **Credential storage:** `CredentialStore` encrypts with
  `fatoora.signing.key`, falling back to `APP_KEY`, on the disk named by
  `fatoora.signing.disk`. One secret covers every tenant.

## Testing without a taxpayer

The SDK ships a certificate issued by ZATCA's own pre-production CA
(`CN=PRZEINVOICESCA4-CA`) with its matching secp256k1 key, for the sample
taxpayer `399999999900003`. Signing conformance documents with those, rather
than with a key generated here, is what lets the SDK check the certificate and
the QR that embeds it at all: `SigningCredentials::authorityCredentials()`
loads them when `ZATCA_SDK_PATH` is set, and the suite falls back to a
self-signed pair when it is not.

What it cannot establish: that ZATCA's portal will issue a CSID for a CSR, and
that its API will clear or report a document. Those need a real taxpayer.
