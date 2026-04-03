<?php

declare(strict_types=1);

use App\Domains\Compliance\FTA\DTOs\FtaInvoiceData;
use App\Domains\Compliance\FTA\Services\FtaXmlBuilder;

function makeFtaData(array $overrides = []): FtaInvoiceData
{
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
        taxExclusiveAmount: $overrides['taxExclusiveAmount'] ?? 1000.00,
        taxInclusiveAmount: $overrides['taxInclusiveAmount'] ?? 1050.00,
        payableAmount: $overrides['payableAmount'] ?? 1050.00,
        vatAmount: $overrides['vatAmount'] ?? 50.00,
        vatRate: $overrides['vatRate'] ?? 0.05,
        lines: $overrides['lines'] ?? [
            ['description' => 'Consulting', 'quantity' => 1.0, 'unitPrice' => 1000.00, 'lineTotal' => 1000.00, 'vatRate' => 0.05],
        ],
        documentType: $overrides['documentType'] ?? '380',
        creditNoteReference: $overrides['creditNoteReference'] ?? null,
    );
}

it('emits the correct PINT AE CustomizationID', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeFtaData());
    expect($xml)->toContain('urn:peppol:pint:billing-1@ae-1');
});

it('emits the correct Peppol ProfileID', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeFtaData());
    expect($xml)->toContain('urn:peppol:bis:billing');
});

it('does NOT emit BIS Billing 3.0 customization ID', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeFtaData());
    expect($xml)->not->toContain('urn:cen.eu:en16931:2017')
        ->and($xml)->not->toContain('urn:fdc:peppol.eu:2017:poacc:billing:3.0');
});

it('includes the invoice number in the XML', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeFtaData(['invoiceNumber' => 'INV-UAE-9999']));
    expect($xml)->toContain('INV-UAE-9999');
});

it('includes VAT amount in the XML', function () {
    $xml = app(FtaXmlBuilder::class)->build(makeFtaData());
    expect($xml)->toContain('50'); // VAT amount 50.00
});
