# Glossary

Audience: anyone reading the business documents who meets an unfamiliar term.

## Saudi Arabia

- **ZATCA**: the Zakat, Tax and Customs Authority, which runs VAT and e-invoicing in Saudi Arabia.
- **Fatoora**: ZATCA's e-invoicing programme, and the platform and portal invoices are filed with.
- **Phase 1 (Generation)**: invoices must be produced by a compliant electronic solution, not by hand.
- **Phase 2 (Integration)**: the solution is connected to Fatoora, and every invoice is cleared or reported.
- **Wave**: a group of taxpayers ZATCA moves to Phase 2 together. Each group is notified in advance.
- **Standard tax invoice**: a business-to-business invoice. It must be cleared before the buyer receives it.
- **Simplified tax invoice**: a business-to-consumer invoice, usually a receipt. It must be reported within 24 hours.
- **Clearance**: ZATCA validates and stamps a standard invoice before it is shared with the buyer.
- **Reporting**: you send a simplified invoice to ZATCA after giving it to the buyer, within 24 hours.
- **EGS (E-invoice Generation Solution)**: the device or software unit that issues invoices, such as a till. Each has its own certificate.
- **CSID (Cryptographic Stamp Identifier)**: the certificate ZATCA issues to an EGS. A compliance CSID is used for the onboarding checks; a production CSID signs real invoices.
- **OTP (one-time password)**: a short code from the Fatoora portal that authorizes connecting a device.
- **Cryptographic stamp**: the digital signature on each invoice that proves which device issued it and that it was not changed.
- **QR code**: the code printed on a Saudi invoice. A phone app can read the seller, the VAT number, the totals and, in Phase 2, the signature details.
- **ICV (Invoice Counter Value)**: a number that rises by one with every document a device issues, so gaps are visible.
- **PIH (Previous Invoice Hash)**: a fingerprint of the previous invoice, carried by the next. It chains invoices together so none can be removed unnoticed.
- **Hash chain**: the sequence of invoices linked by PIH.
- **UUID**: a unique identifier given to every invoice.
- **PDF/A-3**: an archival PDF format that can carry the invoice's XML inside it.
- **VAT number (Saudi)**: the 15-digit VAT registration number.

## UAE

- **FTA**: the Federal Tax Authority, which receives the tax data from e-invoices.
- **MoF**: the Ministry of Finance, which sets the e-invoicing rules and lists the accredited providers.
- **ASP (Accredited Service Provider)**: a licensed provider that validates your invoices, sends them to the buyer's provider and reports tax data to the FTA.
- **Five-corner model**: the UAE flow of supplier, supplier's ASP, buyer's ASP, buyer and FTA. Also called DCTCE.
- **EmaraTax**: the FTA's online portal, where you appoint your ASP.
- **Peppol**: the international network and document standard UAE e-invoices travel on.
- **PINT AE**: the UAE's version of the Peppol invoice format.
- **Peppol participant identifier**: your address on the network, issued by your ASP.
- **TRN (Tax Registration Number)**: the 15-digit UAE VAT registration number.
- **TIN (Tax Identification Number)**: the first 10 digits of the TRN, used in the Peppol identifier.
- **Commercial invoice**: a UAE e-invoice for supplies that are not tax-invoiced, such as exempt supplies or a seller not registered for VAT.
- **Self-billing**: the buyer issues the invoice on the supplier's behalf, by agreement.
- **Start date**: the day your business must start issuing through its ASP, set by your revenue band or chosen earlier.

## General

- **E-invoice**: an invoice in a structured electronic format that software can read, not just a picture or PDF.
- **XML**: the structured data format e-invoices are written in.
- **Credit note / debit note**: a document that reduces or increases the amount of an invoice already issued.
- **Reverse charge**: the buyer, not the seller, accounts for the VAT, for example on certain imported services or specified goods.
- **Zero-rated / exempt / out of scope**: supplies taxed at 0%, supplies VAT does not apply to, and supplies outside the VAT system altogether.
- **ERP / POS**: your business-management system / your point-of-sale (till) system.
- **API**: the connection your ERP or POS uses to send invoices to the platform.
- **Webhook**: a message the platform sends to your system when something happens, such as an invoice being cleared.
- **Two-factor sign-in**: signing in with a password plus a code from an authenticator app.
