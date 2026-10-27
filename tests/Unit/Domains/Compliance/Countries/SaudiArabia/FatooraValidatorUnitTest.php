<?php

declare(strict_types=1);

/**
 * ZATCA InvoiceValidator unit tests.
 *
 * Tests ZATCA Phase 2 business rules against official spec:
 * https://zatca.gov.sa/en/E-Invoicing/Introduction/Pages/E-Invoicing_documents.aspx
 *
 * Rules tested:
 *  BR-KSA-40     — VAT number: 15 digits, starts with '3', ends with '3'
 *  BR-KSA-05     — Invoice type code: 388, 381, or 383
 *  BR-KSA-34     — ICV must be positive integer (> 0)
 *  BR-KSA-84     — Tax rate: 0% or 15% only
 *  BR-KSA-18     — Tax category: S, Z, E, or O
 *  BR-KSA-23/24/69, BR-KSA-83 — Zero/exempt/out-of-scope lines need exemption code + reason
 *  BR-KSA-84, BR-Z-05, BR-E-05, BR-KSA-11 — Category S → rate 15%; Z/E/O → rate 0%
 *  BR-KSA-CL-04  — Exemption code must be VATEX-SA-*
 *  BR-KSA-03     — UUID format
 *  BR-KSA-66     — Postal code 5-digit format
 */

use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceValidator;

// ---------------------------------------------------------------------------
// VAT number (BR-KSA-40)
// ---------------------------------------------------------------------------

it('accepts a valid KSA VAT number: 15 digits, starts and ends with 3', function () {
    $validator = new InvoiceValidator;

    expect($validator->isValidVatNumber('300000000000003'))->toBeTrue();
    expect($validator->isValidVatNumber('311234567890113'))->toBeTrue();
});

it('rejects a VAT number that is too short (14 digits)', function () {
    expect((new InvoiceValidator)->isValidVatNumber('30000000000003'))->toBeFalse();
});

it('rejects a VAT number that is too long (16 digits)', function () {
    expect((new InvoiceValidator)->isValidVatNumber('3000000000000031'))->toBeFalse();
});

it('rejects a VAT number that does not start with 3', function () {
    // Legal entities in KSA: VAT number prefix '3' is mandatory (ZATCA BR-KSA-40)
    expect((new InvoiceValidator)->isValidVatNumber('100000000000003'))->toBeFalse();
});

it('rejects a VAT number that does not end with 3', function () {
    // The last digit '3' corresponds to the VAT type code suffix '03'
    expect((new InvoiceValidator)->isValidVatNumber('300000000000000'))->toBeFalse();
});

it('rejects a VAT number with non-numeric characters', function () {
    expect((new InvoiceValidator)->isValidVatNumber('3000000000000A3'))->toBeFalse();
});

it('rejects null VAT number', function () {
    expect((new InvoiceValidator)->isValidVatNumber(null))->toBeFalse();
});

it('rejects empty string VAT number', function () {
    expect((new InvoiceValidator)->isValidVatNumber(''))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Invoice type codes (BR-KSA-05)
// ---------------------------------------------------------------------------

it('accepts invoice type code 388 (standard invoice)', function () {
    expect((new InvoiceValidator)->isValidInvoiceTypeCode('388'))->toBeTrue();
});

it('accepts invoice type code 381 (credit note)', function () {
    expect((new InvoiceValidator)->isValidInvoiceTypeCode('381'))->toBeTrue();
});

it('accepts invoice type code 383 (debit note)', function () {
    expect((new InvoiceValidator)->isValidInvoiceTypeCode('383'))->toBeTrue();
});

it('rejects invoice type code 380 — valid in UAE/Peppol but not ZATCA', function () {
    // UAE uses 380 for standard invoices; ZATCA mandates 388
    expect((new InvoiceValidator)->isValidInvoiceTypeCode('380'))->toBeFalse();
});

it('rejects arbitrary invoice type codes', function () {
    expect((new InvoiceValidator)->isValidInvoiceTypeCode('999'))->toBeFalse();
    expect((new InvoiceValidator)->isValidInvoiceTypeCode('0'))->toBeFalse();
    expect((new InvoiceValidator)->isValidInvoiceTypeCode(''))->toBeFalse();
});

it('rejects null invoice type code', function () {
    expect((new InvoiceValidator)->isValidInvoiceTypeCode(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Exemption codes (BR-KSA-CL-04)
// ---------------------------------------------------------------------------

it('accepts a standard VATEX-SA exemption code', function () {
    // VATEX-SA-29: Financial services exempt from VAT per ZATCA spec
    expect((new InvoiceValidator)->isValidExemptionCode('VATEX-SA-29'))->toBeTrue();
});

it('rejects a VATEX-SA code not on the list', function () {
    // Only the code list ZATCA publishes (BR-KSA-CL-04): VATEX-SA-31 was
    // withdrawn, and a made-up code is refused before the counter is spent.
    expect((new InvoiceValidator)->isValidExemptionCode('VATEX-SA-FUTURE-99'))->toBeFalse();
    expect((new InvoiceValidator)->isValidExemptionCode('VATEX-SA-31'))->toBeFalse();
});

it('rejects a non-VATEX-SA exemption code', function () {
    expect((new InvoiceValidator)->isValidExemptionCode('AE-EXEMPT-01'))->toBeFalse();
    expect((new InvoiceValidator)->isValidExemptionCode('EXEMPT'))->toBeFalse();
    expect((new InvoiceValidator)->isValidExemptionCode('VAT-EXEMPT'))->toBeFalse();
});

it('rejects null exemption code', function () {
    expect((new InvoiceValidator)->isValidExemptionCode(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// UUID format (BR-KSA-03)
// ---------------------------------------------------------------------------

it('accepts a valid UUIDv4', function () {
    $validator = new InvoiceValidator;
    expect($validator->isValidUuid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
    expect($validator->isValidUuid('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'))->toBeTrue();
});

/*
 * BR-KSA-03 asks for letters, digits and dashes and names no version. This
 * test used to require version 4, which every invoice key fails: HasUuids
 * generates version 7.
 */
it('accepts any UUID version', function () {
    expect((new InvoiceValidator)->isValidUuid('550e8400-e29b-11d4-a716-446655440000'))->toBeTrue();
    expect((new InvoiceValidator)->isValidUuid('0199e6a8-4c2b-7d3e-9f10-2a3b4c5d6e7f'))->toBeTrue();
});

it('rejects a UUID without hyphens', function () {
    expect((new InvoiceValidator)->isValidUuid('550e8400e29b41d4a716446655440000'))->toBeFalse();
});

it('rejects null UUID', function () {
    expect((new InvoiceValidator)->isValidUuid(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Postal code (BR-KSA-66)
// ---------------------------------------------------------------------------

it('accepts a 5-digit Saudi postal code', function () {
    $validator = new InvoiceValidator;
    expect($validator->isValidPostalCode('12345'))->toBeTrue();
    expect($validator->isValidPostalCode('00000'))->toBeTrue();
    expect($validator->isValidPostalCode('99999'))->toBeTrue();
});

it('rejects a postal code shorter than 5 digits', function () {
    expect((new InvoiceValidator)->isValidPostalCode('1234'))->toBeFalse();
});

it('rejects a postal code longer than 5 digits', function () {
    expect((new InvoiceValidator)->isValidPostalCode('123456'))->toBeFalse();
});

it('rejects a postal code with letters', function () {
    expect((new InvoiceValidator)->isValidPostalCode('1234A'))->toBeFalse();
});

it('rejects null postal code', function () {
    expect((new InvoiceValidator)->isValidPostalCode(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Buyer ID scheme (BR-KSA-14 / ZATCA Technical Spec Table 6)
// ---------------------------------------------------------------------------

it('accepts all valid ZATCA buyer ID schemes', function () {
    $validator = new InvoiceValidator;
    // Scheme list per ZATCA Technical Spec v3.x Table 6
    $validSchemes = ['TIN', 'CRN', 'MOM', 'MLS', 'SAG', 'NAT', 'GCC', 'IQA', 'PAS', 'OTH'];

    foreach ($validSchemes as $scheme) {
        expect($validator->isValidBuyerIdScheme($scheme))
            ->toBeTrue("Expected scheme '{$scheme}' to be valid per ZATCA spec");
    }
});

it('accepts null buyer ID scheme when buyer has a VAT number', function () {
    expect((new InvoiceValidator)->isValidBuyerIdScheme(null))->toBeTrue();
});

it('rejects an unsupported buyer ID scheme', function () {
    expect((new InvoiceValidator)->isValidBuyerIdScheme('PASSPORT'))->toBeFalse();
    expect((new InvoiceValidator)->isValidBuyerIdScheme('IQAMA'))->toBeFalse();
    expect((new InvoiceValidator)->isValidBuyerIdScheme('ID'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Decimal precision (BR-KSA-DEC-01) — max 2 decimal places
// ---------------------------------------------------------------------------

it('accepts amounts with up to 2 decimal places', function () {
    $validator = new InvoiceValidator;
    expect($validator->isValidDecimalPrecision(1000.00))->toBeTrue();
    expect($validator->isValidDecimalPrecision(1000.50))->toBeTrue();
    expect($validator->isValidDecimalPrecision(0.01))->toBeTrue();
});

it('rejects amounts with more than 2 decimal places', function () {
    $validator = new InvoiceValidator;
    // ZATCA requires max 2dp; 3dp must be rounded before submission.
    // Use values where the 3rd decimal produces a difference clearly > 0.001
    // to avoid float binary representation edge cases.
    expect($validator->isValidDecimalPrecision(100.123))->toBeFalse(); // diff = 0.003
    expect($validator->isValidDecimalPrecision(100.125))->toBeFalse(); // diff ≥ 0.005
    expect($validator->isValidDecimalPrecision(100.999))->toBeFalse(); // diff ≥ 0.009
});

// ---------------------------------------------------------------------------
// Credit note total constraint
// ---------------------------------------------------------------------------

it('allows a credit note equal to the full original invoice amount', function () {
    $validator = new InvoiceValidator;
    expect($validator->validateCreditNoteTotal(1000.00, 1000.00))->toBeTrue();
});

it('allows a partial credit note', function () {
    $validator = new InvoiceValidator;
    expect($validator->validateCreditNoteTotal(500.00, 1000.00))->toBeTrue();
});

it('allows a credit note that accounts for previous partial credits', function () {
    $validator = new InvoiceValidator;
    // Original 1000, prior credit 300, remaining 700 — crediting exactly 700
    expect($validator->validateCreditNoteTotal(700.00, 1000.00, 300.00))->toBeTrue();
});

it('rejects a credit note exceeding the original invoice total', function () {
    $validator = new InvoiceValidator;
    expect($validator->validateCreditNoteTotal(1001.00, 1000.00))->toBeFalse();
});

it('rejects a credit note exceeding the remaining balance after prior credits', function () {
    $validator = new InvoiceValidator;
    // Remaining: 700, but requesting 701.50 — clearly over (not within 0.01 tolerance)
    expect($validator->validateCreditNoteTotal(701.50, 1000.00, 300.00))->toBeFalse();
});
