<?php

declare(strict_types=1);

/**
 * UAE FTA compliance spec tests.
 *
 * Tests against UAE Federal Tax Authority e-invoicing requirements
 * as defined in the PINT AE specification (Peppol International BIS Billing
 * — UAE Annex, version 1.0):
 *   CustomizationID: urn:peppol:pint:billing-1@ae-1
 *   ProfileID:       urn:peppol:bis:billing
 *
 * Rules verified:
 * - TRN must be exactly 15 numeric digits (UAE Tax Registration Number)
 * - Only AED is accepted (no SAR, USD, EUR, etc.)
 * - Document types: 380 (invoice), 381 (credit note), 383 (debit note)
 * - ZATCA type 388 is NOT valid for UAE
 * - Credit notes (381) and debit notes (383) must reference the original invoice
 * - VAT rates: 5% standard or 0% zero-rated (no 15% KSA rate)
 * - Tax-inclusive = tax-exclusive + VAT amount (within 0.01 tolerance)
 * - payable_amount must be > 0
 * - PINT AE CustomizationID and ProfileID in generated XML
 */

use App\Domains\Compliance\FTA\DTOs\FtaInvoiceData;
use App\Domains\Compliance\FTA\Exceptions\FtaException;
use App\Domains\Compliance\FTA\Services\FtaValidator;
use App\Domains\Compliance\FTA\Services\FtaXmlBuilder;

// ---------------------------------------------------------------------------
// TRN validation
// ---------------------------------------------------------------------------

it('accepts a TRN that is exactly 15 numeric digits', function () {
    $validator = app(FtaValidator::class);

    expect(fn () => $validator->validateTrn('100000000000003'))->not->toThrow(FtaException::class);
    expect(fn () => $validator->validateTrn('000000000000000'))->not->toThrow(FtaException::class);
    expect(fn () => $validator->validateTrn('999999999999999'))->not->toThrow(FtaException::class);
});

it('rejects a TRN with 14 digits', function () {
    expect(fn () => app(FtaValidator::class)->validateTrn('10000000000000'))
        ->toThrow(FtaException::class);
});

it('rejects a TRN with 16 digits', function () {
    expect(fn () => app(FtaValidator::class)->validateTrn('1000000000000031'))
        ->toThrow(FtaException::class);
});

it('rejects a TRN containing letters', function () {
    expect(fn () => app(FtaValidator::class)->validateTrn('10000000000000A'))
        ->toThrow(FtaException::class);
});

it('rejects a TRN containing spaces', function () {
    expect(fn () => app(FtaValidator::class)->validateTrn('100 000 000 00000'))
        ->toThrow(FtaException::class);
});

it('rejects a TRN containing hyphens', function () {
    expect(fn () => app(FtaValidator::class)->validateTrn('100-000-000-0003'))
        ->toThrow(FtaException::class);
});

// ---------------------------------------------------------------------------
// Currency enforcement — AED only
// ---------------------------------------------------------------------------

it('accepts AED as the invoice currency', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice()))
        ->not->toThrow(FtaException::class);
});

it('rejects SAR — valid in KSA but not UAE', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['currencyCode' => 'SAR'])))
        ->toThrow(FtaException::class);
});

it('rejects USD', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['currencyCode' => 'USD'])))
        ->toThrow(FtaException::class);
});

it('rejects EUR', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['currencyCode' => 'EUR'])))
        ->toThrow(FtaException::class);
});

// ---------------------------------------------------------------------------
// Document type codes
// ---------------------------------------------------------------------------

it('accepts document type 380 (standard invoice per Peppol/PINT AE)', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['documentType' => '380'])))
        ->not->toThrow(FtaException::class);
});

it('accepts document type 381 (credit note) with a reference', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'documentType' => '381',
        'creditNoteReference' => 'INV-AE-001',
    ])))->not->toThrow(FtaException::class);
});

it('accepts document type 383 (debit note) with a reference', function () {
    // 383 debit note is listed in PINT AE spec; UAE FTA confirmed acceptance
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'documentType' => '383',
        'creditNoteReference' => 'INV-AE-001',
    ])))->not->toThrow(FtaException::class);
});

it('rejects document type 388 — KSA ZATCA standard invoice code, not valid in UAE', function () {
    // ZATCA uses 388 for standard invoices; PINT AE uses 380
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['documentType' => '388'])))
        ->toThrow(FtaException::class);
});

it('rejects an unknown document type', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['documentType' => '999'])))
        ->toThrow(FtaException::class);
});

// ---------------------------------------------------------------------------
// Credit / debit note reference requirement
// ---------------------------------------------------------------------------

it('rejects a credit note (381) without an original invoice reference', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'documentType' => '381',
        'creditNoteReference' => null,
    ])))->toThrow(FtaException::class);
});

it('rejects a debit note (383) without an original invoice reference', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'documentType' => '383',
        'creditNoteReference' => null,
    ])))->toThrow(FtaException::class);
});

it('does not require a reference for a standard invoice (380)', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'documentType' => '380',
        'creditNoteReference' => null,
    ])))->not->toThrow(FtaException::class);
});

// ---------------------------------------------------------------------------
// VAT rate enforcement — UAE: 5% standard or 0% zero-rated
// ---------------------------------------------------------------------------

it('accepts 5% VAT (UAE standard rate)', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['vatRate' => 0.05])))
        ->not->toThrow(FtaException::class);
});

it('accepts 0% VAT (zero-rated supplies)', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'vatRate' => 0.00,
        'vatAmount' => 0.00,
        'taxInclusiveAmount' => 1000.00,
    ])))->not->toThrow(FtaException::class);
});

it('rejects 15% VAT — valid in KSA but not UAE', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['vatRate' => 0.15])))
        ->toThrow(FtaException::class);
});

it('rejects an arbitrary non-standard VAT rate', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['vatRate' => 0.10])))
        ->toThrow(FtaException::class);
});

// ---------------------------------------------------------------------------
// Amount math: taxInclusive = taxExclusive + VAT (tolerance 0.01)
// ---------------------------------------------------------------------------

it('accepts a correctly calculated tax-inclusive amount', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'taxExclusiveAmount' => 1000.00,
        'vatAmount' => 50.00,
        'taxInclusiveAmount' => 1050.00,
    ])))->not->toThrow(FtaException::class);
});

it('accepts a tax-inclusive amount within 0.01 rounding tolerance', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'taxExclusiveAmount' => 1000.00,
        'vatAmount' => 50.00,
        'taxInclusiveAmount' => 1050.005, // within 0.01 tolerance
    ])))->not->toThrow(FtaException::class);
});

it('rejects a tax-inclusive amount that does not match the sum', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice([
        'taxExclusiveAmount' => 1000.00,
        'vatAmount' => 50.00,
        'taxInclusiveAmount' => 1100.00, // off by 50 — clearly wrong
    ])))->toThrow(FtaException::class);
});

it('rejects a zero or negative payable amount', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['payableAmount' => 0.00])))
        ->toThrow(FtaException::class);

    expect(fn () => app(FtaValidator::class)->validate(makeUaeInvoice(['payableAmount' => -50.00])))
        ->toThrow(FtaException::class);
});

// ---------------------------------------------------------------------------
// PINT AE XML output
// ---------------------------------------------------------------------------

it('XML contains PINT AE CustomizationID — not generic BIS Billing 3.0', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeUaeInvoice());

    expect($xml)->toContain('urn:peppol:pint:billing-1@ae-1');
    expect($xml)->not->toContain('urn:cen.eu:en16931:2017');
    expect($xml)->not->toContain('urn:fdc:peppol.eu:2017:poacc:billing:3.0');
});

it('XML contains Peppol ProfileID', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeUaeInvoice());

    expect($xml)->toContain('urn:peppol:bis:billing');
});

it('XML contains the supplier TRN', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeUaeInvoice(['supplierTrn' => '100000000000003']));

    expect($xml)->toContain('100000000000003');
});

it('XML contains the invoice number', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeUaeInvoice(['invoiceNumber' => 'INV-UAE-2024-0042']));

    expect($xml)->toContain('INV-UAE-2024-0042');
});

it('XML contains AED as the document currency', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeUaeInvoice());

    expect($xml)->toContain('AED');
});

// ---------------------------------------------------------------------------
// Helper — shared invoice fixture
// ---------------------------------------------------------------------------

function makeUaeInvoice(array $overrides = []): FtaInvoiceData
{
    $taxExcl = $overrides['taxExclusiveAmount'] ?? 1000.00;
    $vat = $overrides['vatAmount'] ?? 50.00;
    $taxIncl = $overrides['taxInclusiveAmount'] ?? 1050.00;

    return new FtaInvoiceData(
        invoiceNumber: $overrides['invoiceNumber'] ?? 'INV-AE-001',
        invoiceDate: $overrides['invoiceDate'] ?? '2026-04-01',
        dueDate: $overrides['dueDate'] ?? '2026-04-30',
        currencyCode: $overrides['currencyCode'] ?? 'AED',
        supplierName: $overrides['supplierName'] ?? 'Acme UAE LLC',
        supplierTrn: $overrides['supplierTrn'] ?? '100000000000003',
        supplierStreet: $overrides['supplierStreet'] ?? '1 Sheikh Zayed Road',
        supplierCity: $overrides['supplierCity'] ?? 'Dubai',
        supplierCountry: $overrides['supplierCountry'] ?? 'AE',
        customerName: $overrides['customerName'] ?? 'Buyer Corp',
        customerTrn: $overrides['customerTrn'] ?? '200000000000009',
        customerStreet: $overrides['customerStreet'] ?? '5 Business Bay',
        customerCity: $overrides['customerCity'] ?? 'Dubai',
        customerCountry: $overrides['customerCountry'] ?? 'AE',
        lineExtensionAmount: $overrides['lineExtensionAmount'] ?? 1000.00,
        taxExclusiveAmount: $taxExcl,
        taxInclusiveAmount: $taxIncl,
        payableAmount: $overrides['payableAmount'] ?? 1050.00,
        vatAmount: $vat,
        vatRate: $overrides['vatRate'] ?? 0.05,
        lines: $overrides['lines'] ?? [
            ['description' => 'Consulting', 'quantity' => 1.0, 'unitPrice' => 1000.00, 'lineTotal' => 1000.00, 'vatRate' => 0.05],
        ],
        documentType: $overrides['documentType'] ?? '380',
        creditNoteReference: $overrides['creditNoteReference'] ?? null,
    );
}
