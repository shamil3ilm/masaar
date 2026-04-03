<?php

declare(strict_types=1);

use App\Domains\Compliance\FTA\DTOs\FtaInvoiceData;
use App\Domains\Compliance\FTA\Exceptions\FtaException;
use App\Domains\Compliance\FTA\Services\FtaValidator;

function makeFtaValidatorData(array $overrides = []): FtaInvoiceData
{
    return new FtaInvoiceData(
        invoiceNumber: $overrides['invoiceNumber'] ?? 'INV-AE-001',
        invoiceDate: $overrides['invoiceDate'] ?? '2026-04-01',
        dueDate: $overrides['dueDate'] ?? '2026-04-30',
        currencyCode: $overrides['currencyCode'] ?? 'AED',
        supplierName: 'Acme UAE LLC',
        supplierTrn: $overrides['supplierTrn'] ?? '100000000000003',
        supplierStreet: '1 Sheikh Zayed Road',
        supplierCity: 'Dubai',
        supplierCountry: 'AE',
        customerName: 'Buyer Corp',
        customerTrn: $overrides['customerTrn'] ?? '200000000000009',
        customerStreet: '5 Business Bay',
        customerCity: 'Dubai',
        customerCountry: 'AE',
        lineExtensionAmount: 1000.00,
        taxExclusiveAmount: 1000.00,
        taxInclusiveAmount: $overrides['taxInclusiveAmount'] ?? 1050.00,
        payableAmount: 1050.00,
        vatAmount: $overrides['vatAmount'] ?? 50.00,
        vatRate: $overrides['vatRate'] ?? 0.05,
        lines: [
            ['description' => 'Consulting', 'quantity' => 1.0, 'unitPrice' => 1000.00, 'lineTotal' => 1000.00, 'vatRate' => 0.05],
        ],
        documentType: $overrides['documentType'] ?? '380',
        creditNoteReference: $overrides['creditNoteReference'] ?? null,
    );
}

it('passes validation for a valid invoice', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData()))
        ->not->toThrow(\Throwable::class);
});

it('throws for non-AED currency', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData(['currencyCode' => 'SAR'])))
        ->toThrow(FtaException::class);
});

it('throws for TRN too short', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData(['supplierTrn' => '12345'])))
        ->toThrow(FtaException::class);
});

it('throws for non-numeric TRN', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData(['supplierTrn' => 'ABC000000000003'])))
        ->toThrow(FtaException::class);
});

it('throws for invalid document type', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData(['documentType' => '999'])))
        ->toThrow(FtaException::class);
});

it('throws for invalid VAT rate', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData(['vatRate' => 0.15])))
        ->toThrow(FtaException::class);
});

it('throws for credit note missing reference', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData([
        'documentType'        => '381',
        'creditNoteReference' => null,
    ])))->toThrow(FtaException::class);
});

it('accepts zero VAT rate', function () {
    expect(fn () => app(FtaValidator::class)->validate(makeFtaValidatorData([
        'vatRate'            => 0.00,
        'vatAmount'          => 0.00,
        'taxInclusiveAmount' => 1000.00,
    ])))->not->toThrow(\Throwable::class);
});
