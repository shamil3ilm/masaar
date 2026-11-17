<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\QrCodeData;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\QrCodeGenerator;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FatooraDebugInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_invoice_reports_error(): void
    {
        $this->artisan('fatoora:debug-invoice', ['invoice' => 'non-existent-id'])
            ->expectsOutputToContain("Invoice 'non-existent-id' not found")
            ->assertFailed();
    }

    public function test_invoice_diagnostics_outputs_details(): void
    {
        $org = Organization::create([
            'name' => 'Al-Noor Trading Est',
            'name_ar' => 'مؤسسة النور للتجارة',
            'country' => 'SA',
            'vat_number' => '310123456700003',
            'status' => Organization::STATUS_ACTIVE,
        ]);

        $qrGenerator = app(QrCodeGenerator::class);
        $qrData = new QrCodeData(
            sellerName: 'Al-Noor Trading Est',
            vatNumber: '310123456700003',
            timestamp: '2026-11-16T12:00:00Z',
            invoiceTotal: '115.00',
            vatTotal: '15.00',
        );
        $qr = $qrGenerator->generatePhase1($qrData);

        $invoice = Invoice::create([
            'org_id' => $org->id,
            'invoice_number' => 'INV-2026-0001',
            'type' => InvoiceType::Standard->value,
            'document_type' => DocumentType::Invoice->value,
            'status' => InvoiceStatus::Issued->value,
            'issue_date' => '2026-11-16',
            'currency' => 'SAR',
            'subtotal' => 100.00,
            'tax_amount' => 15.00,
            'total' => 115.00,
            'icv' => 1,
            'hash' => 'dummy-hash-xyz-1234567890',
            'qr_code' => $qr,
            'signed_xml' => '<Invoice><cbc:ID>INV-2026-0001</cbc:ID></Invoice>',
            'zatca_response' => ['clearanceStatus' => 'CLEARED'],
        ]);

        InvoiceSubmission::create([
            'invoice_id' => $invoice->id,
            'org_id' => $org->id,
            'state' => 'cleared',
            'clearance_status' => 'CLEARED',
            'submission_type' => 'clearance',
            'retry_count' => 0,
        ]);

        $this->artisan('fatoora:debug-invoice', [
            'invoice' => 'INV-2026-0001',
            '--qr' => true,
            '--xml' => true,
            '--raw' => true,
        ])
            ->expectsOutputToContain('=== Invoice Diagnostics: INV-2026-0001')
            ->expectsOutputToContain('Al-Noor Trading Est')
            ->expectsOutputToContain('INV-2026-0001')
            ->expectsOutputToContain('Submission History (1 attempt(s))')
            ->expectsOutputToContain('TLV QR Code Decoded (Tags 1 to 9)')
            ->expectsOutputToContain('Formatted XML Payload')
            ->expectsOutputToContain('Raw ZATCA Response JSON')
            ->assertSuccessful();
    }
}

