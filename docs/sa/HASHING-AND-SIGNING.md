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

- **Over:** the `xades:SignedProperties` element as the document writes it,
  with two declarations written out on top and nothing removed:
  `xmlns:xades` on the element, and `xmlns:ds` on **each** `ds:` child that
  does not already carry one - including the children that inherit the prefix
  from an ancestor.
- **Written as:** base64-of-hex. The invoice reference in the same
  `ds:SignedInfo` is base64-of-bytes. **The two references in one signature are
  encoded differently.**
- **Established by:** having the SDK sign one of this platform's own documents
  (`-sign -invoice <file> -signedInvoice <out>`) and asking which
  serialisation of the block it produced hashes to the digest it recorded.
  Neither the block alone nor the block with only `xmlns:xades` does; that one
  does. The same reconstruction of the authority's own sample reproduces the
  sample's digest, and compacting that element and recomputing leaves its
  check passing - so the indentation is the document's to choose and the
  declarations are not.
- **Status: satisfied by the SDK on 2026-10-09, and by the authority on
  2026-10-10 - and the gap between those two dates is the lesson.** The
  namespace fix below made the SDK's digest check pass, and the live API went
  on refusing every simplified document with "Invalid signed properties
  hashing" while clearing every standard one.

  **The cause was not the digest at all.** `formatOutput` was set on the DOM
  round trip that inserts the QR code, which runs *after* the document is
  signed: pretty-printing re-indented the signature block from 726 bytes to
  1130, so the digest recorded in `ds:SignedInfo` described a block the
  document no longer carried. Removing it had all six documents accepted.

  Two hypotheses were wrong first, and are recorded because the next person
  will reach for them. `xades:SigningTime` did carry a trailing `Z` where
  ZATCA's own signer writes local time with no designator - that was fixed, it
  is what the authority writes, and it changed nothing about the refusal. Then
  four digest encodings were tried against the authority - the SDK's form and
  the canonical form, each as hex and as bytes - and all four were refused,
  which is what finally ruled out the hashing and sent the search elsewhere.

  What settled it was submitting **ZATCA's own published sample** simplified
  invoice, which the live API accepts, and bisecting from there. That
  established the rule in this section is the authority's own (it reproduces
  the digest recorded in three of ZATCA's samples), that indentation of the
  block is irrelevant *when the digest describes it*, that the live API does
  not verify `ds:SignatureValue`, and that the authority's sample carrying
  this platform's block is accepted - so the block was never the problem.

  The SDK could not have found any of it. It recomputes the digest from the
  bytes it is handed, so a document that contradicts itself still passes; all
  26 conformance tests did while the authority refused the documents. **A
  validator that reads what you wrote cannot catch a disagreement about what
  you wrote it over.** Only submitting can, which is what
  `fatoora:onboard --step=submit` is for.

  What now holds it: `EmittedSignatureTest` and `EmittedDigestsTest` assert
  that the recorded digest still describes the block in the document **as
  finally emitted**, on the onboarding path and the production path
  respectively, and the first names the files that must never pretty-print a
  signed document. `QrCodeInjector` owns the load and the save so no caller
  chooses them.

  The `Z` is gone regardless, and the stamp is on the Kingdom's clock so that
  reading it as local time is right - the same clock as `IssueTime` and the
  QR, for the same reason. The earlier note here argued the `Z` should stay
  because a bare stamp is ambiguous. It is the authority's format, and that
  settles it.

- **The namespace shape, which was the other half.** It was wrong until
  2026-10-09, and the reason was the
  block rather than the rule. `DOMDocument::createElementNS` attaches a
  namespace declaration to each element it creates and libxml keeps them when
  the subtree is assembled, so the block this platform built carried ten
  `xmlns:ds` declarations - on the apex and on every `xades:` element - where
  the authority's own block carries none and inherits both prefixes from
  ancestors. The digest was computed over the right rule and the wrong bytes.

  Established by having the SDK sign one of this platform's own invoices and
  comparing the two blocks directly: theirs, zero declarations; ours, ten.
  Building the block with `createElement` and a literal prefixed name - both
  prefixes are already in scope from `ds:Signature` and
  `xades:QualifyingProperties` - declares nothing and matches. Reverting that
  one change brings the failure back on all four simplified documents, which
  is how the fix was checked.

  Indentation is not part of it: the authority's block is pretty-printed and
  this platform's is compact, and both digest correctly, because the digest is
  taken over the element as that document carries it.

## Signature

- **Algorithm:** ECDSA over secp256k1 with SHA-256. Not P-256.
- **Over:** the canonicalised `ds:SignedInfo`.
- **Written as:** base64 of the raw signature.
- **Note:** ZATCA's own shipped samples fail their validator's `signatureValue`
  check. `Data/Samples/Simplified/Invoice/Simplified_Invoice.xml` passes XSD,
  EN, KSA and PIH and then reports `signatureValue: wrong signature Value`,
  because the files were pretty-printed after they were signed, so the
  `SignedInfo` in the file is not the `SignedInfo` that was signed. A sample is
  an oracle for the digests above and not for this one, and a check that
  rejects the authority's own reference documents cannot say anything about
  ours.
- **So it is checked another way.** `SignatureVerifiesTest` verifies the
  signature with OpenSSL over the canonicalised `ds:SignedInfo` using the
  certificate the document itself carries - which is what a verifier does - on
  the document as finally emitted, after the QR is injected and the whole
  thing re-serialised, because that round trip is what would silently
  invalidate a signature that was right when it was made. It needs no SDK and
  no Java, so it runs on every push. A document whose digest is altered must
  fail it, and that is asserted too.
- **This is the one check `ZatcaConformanceTest` still excludes**, named
  individually so a second cannot join it unnoticed.

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
