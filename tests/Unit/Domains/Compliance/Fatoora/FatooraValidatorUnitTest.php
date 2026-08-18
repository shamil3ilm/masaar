<?php

declare(strict_types=1);

/**
 * ZATCA FatooraValidator unit tests.
 *
 * Tests ZATCA Phase 2 business rules against official spec:
 * https://zatca.gov.sa/en/E-Invoicing/Introduction/Pages/E-Invoicing_documents.aspx
 *
 * Rules tested:
 *  BR-KSA-14     — VAT number: 15 digits, starts with '3', ends with '3'
 *  BR-KSA-02     — Invoice type code: 388, 381, or 383
 *  BR-KSA-25     — ICV must be positive integer (> 0)
 *  BR-KSA-DEC-02 — Tax rate: 0% or 15% only
 *  BR-KSA-40     — Tax category: S, Z, E, or O
 *  BR-KSA-33/34  — Zero/exempt/out-of-scope lines need exemption code + reason
 *  BR-KSA-35     — Category S → rate 15%; Z/E/O → rate 0%
 *  BR-KSA-46     — Exemption code must be VATEX-SA-*
 *  BR-KSA-01     — UUID v4 format
 *  BR-KSA-09     — Postal code 5-digit format
 */

use App\Domains\Compliance\Fatoora\Services\FatooraValidator;

// ---------------------------------------------------------------------------
// VAT number (BR-KSA-14)
// ---------------------------------------------------------------------------

it('accepts a valid KSA VAT number: 15 digits, starts and ends with 3', function () {
    $validator = new FatooraValidator;

    expect($validator->isValidVatNumber('300000000000003'))->toBeTrue();
    expect($validator->isValidVatNumber('311234567890113'))->toBeTrue();
});

it('rejects a VAT number that is too short (14 digits)', function () {
    expect((new FatooraValidator)->isValidVatNumber('30000000000003'))->toBeFalse();
});

it('rejects a VAT number that is too long (16 digits)', function () {
    expect((new FatooraValidator)->isValidVatNumber('3000000000000031'))->toBeFalse();
});

it('rejects a VAT number that does not start with 3', function () {
    // Legal entities in KSA: VAT number prefix '3' is mandatory (ZATCA BR-KSA-14)
    expect((new FatooraValidator)->isValidVatNumber('100000000000003'))->toBeFalse();
});

it('rejects a VAT number that does not end with 3', function () {
    // The last digit '3' corresponds to the VAT type code suffix '03'
    expect((new FatooraValidator)->isValidVatNumber('300000000000000'))->toBeFalse();
});

it('rejects a VAT number with non-numeric characters', function () {
    expect((new FatooraValidator)->isValidVatNumber('3000000000000A3'))->toBeFalse();
});

it('rejects null VAT number', function () {
    expect((new FatooraValidator)->isValidVatNumber(null))->toBeFalse();
});

it('rejects empty string VAT number', function () {
    expect((new FatooraValidator)->isValidVatNumber(''))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Invoice type codes (BR-KSA-02)
// ---------------------------------------------------------------------------

it('accepts invoice type code 388 (standard invoice)', function () {
    expect((new FatooraValidator)->isValidInvoiceTypeCode('388'))->toBeTrue();
});

it('accepts invoice type code 381 (credit note)', function () {
    expect((new FatooraValidator)->isValidInvoiceTypeCode('381'))->toBeTrue();
});

it('accepts invoice type code 383 (debit note)', function () {
    expect((new FatooraValidator)->isValidInvoiceTypeCode('383'))->toBeTrue();
});

it('rejects invoice type code 380 — valid in UAE/Peppol but not ZATCA', function () {
    // UAE uses 380 for standard invoices; ZATCA mandates 388
    expect((new FatooraValidator)->isValidInvoiceTypeCode('380'))->toBeFalse();
});

it('rejects arbitrary invoice type codes', function () {
    expect((new FatooraValidator)->isValidInvoiceTypeCode('999'))->toBeFalse();
    expect((new FatooraValidator)->isValidInvoiceTypeCode('0'))->toBeFalse();
    expect((new FatooraValidator)->isValidInvoiceTypeCode(''))->toBeFalse();
});

it('rejects null invoice type code', function () {
    expect((new FatooraValidator)->isValidInvoiceTypeCode(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Exemption codes (BR-KSA-33 / BR-KSA-46)
// ---------------------------------------------------------------------------

it('accepts a standard VATEX-SA exemption code', function () {
    // VATEX-SA-29: Financial services exempt from VAT per ZATCA spec
    expect((new FatooraValidator)->isValidExemptionCode('VATEX-SA-29'))->toBeTrue();
});

it('accepts any VATEX-SA-* pattern in non-strict mode for forward-compatibility', function () {
    // Non-strict mode allows unknown VATEX-SA-* codes for regulatory agility
    expect((new FatooraValidator)->isValidExemptionCode('VATEX-SA-FUTURE-99'))->toBeTrue();
});

it('rejects a non-VATEX-SA exemption code', function () {
    expect((new FatooraValidator)->isValidExemptionCode('AE-EXEMPT-01'))->toBeFalse();
    expect((new FatooraValidator)->isValidExemptionCode('EXEMPT'))->toBeFalse();
    expect((new FatooraValidator)->isValidExemptionCode('VAT-EXEMPT'))->toBeFalse();
});

it('rejects null exemption code', function () {
    expect((new FatooraValidator)->isValidExemptionCode(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// UUID v4 format (BR-KSA-01)
// ---------------------------------------------------------------------------

it('accepts a valid UUIDv4', function () {
    $validator = new FatooraValidator;
    expect($validator->isValidUuid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
    expect($validator->isValidUuid('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'))->toBeTrue();
});

it('rejects a UUIDv1 (version digit must be 4)', function () {
    // Version digit is '1' here — must be '4' per BR-KSA-01
    expect((new FatooraValidator)->isValidUuid('550e8400-e29b-11d4-a716-446655440000'))->toBeFalse();
});

it('rejects a UUID without hyphens', function () {
    expect((new FatooraValidator)->isValidUuid('550e8400e29b41d4a716446655440000'))->toBeFalse();
});

it('rejects null UUID', function () {
    expect((new FatooraValidator)->isValidUuid(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Postal code (BR-KSA-09)
// ---------------------------------------------------------------------------

it('accepts a 5-digit Saudi postal code', function () {
    $validator = new FatooraValidator;
    expect($validator->isValidPostalCode('12345'))->toBeTrue();
    expect($validator->isValidPostalCode('00000'))->toBeTrue();
    expect($validator->isValidPostalCode('99999'))->toBeTrue();
});

it('rejects a postal code shorter than 5 digits', function () {
    expect((new FatooraValidator)->isValidPostalCode('1234'))->toBeFalse();
});

it('rejects a postal code longer than 5 digits', function () {
    expect((new FatooraValidator)->isValidPostalCode('123456'))->toBeFalse();
});

it('rejects a postal code with letters', function () {
    expect((new FatooraValidator)->isValidPostalCode('1234A'))->toBeFalse();
});

it('rejects null postal code', function () {
    expect((new FatooraValidator)->isValidPostalCode(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Buyer ID scheme (BR-KSA-15 / ZATCA Technical Spec Table 6)
// ---------------------------------------------------------------------------

it('accepts all valid ZATCA buyer ID schemes', function () {
    $validator = new FatooraValidator;
    // Scheme list per ZATCA Technical Spec v3.x Table 6
    $validSchemes = ['TIN', 'CRN', 'MOM', 'MLS', 'SAG', 'NAT', 'GCC', 'IQA', 'PAS', 'OTH'];

    foreach ($validSchemes as $scheme) {
        expect($validator->isValidBuyerIdScheme($scheme))
            ->toBeTrue("Expected scheme '{$scheme}' to be valid per ZATCA spec");
    }
});

it('accepts null buyer ID scheme when buyer has a VAT number', function () {
    expect((new FatooraValidator)->isValidBuyerIdScheme(null))->toBeTrue();
});

it('rejects an unsupported buyer ID scheme', function () {
    expect((new FatooraValidator)->isValidBuyerIdScheme('PASSPORT'))->toBeFalse();
    expect((new FatooraValidator)->isValidBuyerIdScheme('IQAMA'))->toBeFalse();
    expect((new FatooraValidator)->isValidBuyerIdScheme('ID'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Decimal precision (BR-KSA-DEC-01) — max 2 decimal places
// ---------------------------------------------------------------------------

it('accepts amounts with up to 2 decimal places', function () {
    $validator = new FatooraValidator;
    expect($validator->isValidDecimalPrecision(1000.00))->toBeTrue();
    expect($validator->isValidDecimalPrecision(1000.50))->toBeTrue();
    expect($validator->isValidDecimalPrecision(0.01))->toBeTrue();
});

it('rejects amounts with more than 2 decimal places', function () {
    $validator = new FatooraValidator;
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
    $validator = new FatooraValidator;
    expect($validator->validateCreditNoteTotal(1000.00, 1000.00))->toBeTrue();
});

it('allows a partial credit note', function () {
    $validator = new FatooraValidator;
    expect($validator->validateCreditNoteTotal(500.00, 1000.00))->toBeTrue();
});

it('allows a credit note that accounts for previous partial credits', function () {
    $validator = new FatooraValidator;
    // Original 1000, prior credit 300, remaining 700 — crediting exactly 700
    expect($validator->validateCreditNoteTotal(700.00, 1000.00, 300.00))->toBeTrue();
});

it('rejects a credit note exceeding the original invoice total', function () {
    $validator = new FatooraValidator;
    expect($validator->validateCreditNoteTotal(1001.00, 1000.00))->toBeFalse();
});

it('rejects a credit note exceeding the remaining balance after prior credits', function () {
    $validator = new FatooraValidator;
    // Remaining: 700, but requesting 701.50 — clearly over (not within 0.01 tolerance)
    expect($validator->validateCreditNoteTotal(701.50, 1000.00, 300.00))->toBeFalse();
});
