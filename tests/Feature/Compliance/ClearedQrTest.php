<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Services\InvoiceVerdict;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The QR a cleared standard invoice carries is ZATCA's.
 *
 * The data dictionary says of KSA-14 on a standard invoice that the QR "will
 * be applied by ZATCA": it is in the stamped copy clearance returns. The
 * invoice kept the QR built before submission, so the printed copy and the
 * PDF showed a code the authority had not issued.
 */
class ClearedQrTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUED_QR = 'AQVTZWxsZXI=';

    private const ZATCA_QR = 'AQxaQVRDQSBzdGFtcGVk';

    public function test_the_cleared_qr_replaces_ours(): void
    {
        $invoice = $this->invoice();

        $this->record($invoice, $this->cleared(
            '<cac:AdditionalDocumentReference><cbc:ID>QR</cbc:ID><cac:Attachment>'
            .'<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.self::ZATCA_QR.'</cbc:EmbeddedDocumentBinaryObject>'
            .'</cac:Attachment></cac:AdditionalDocumentReference>'
        ));

        $this->assertSame(self::ZATCA_QR, $invoice->fresh()->qr_code);
    }

    /**
     * Only the reference whose ID is QR: the PIH reference has an attachment
     * too, and it is not a QR.
     */
    public function test_the_pih_is_not_the_qr(): void
    {
        $invoice = $this->invoice();

        $this->record($invoice, $this->cleared(
            '<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment>'
            .'<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">UElI</cbc:EmbeddedDocumentBinaryObject>'
            .'</cac:Attachment></cac:AdditionalDocumentReference>'
        ));

        $this->assertSame(self::ISSUED_QR, $invoice->fresh()->qr_code);
    }

    /**
     * A cleared copy that does not parse keeps the issued QR rather than
     * failing the verdict.
     */
    public function test_unparseable_copy_keeps_our_qr(): void
    {
        $invoice = $this->invoice();

        $this->record($invoice, base64_encode('<Invoice>not closed'));

        $fresh = $invoice->fresh();
        $this->assertSame(self::ISSUED_QR, $fresh->qr_code);
        $this->assertSame('<Invoice>not closed', $fresh->cleared_xml);
    }

    private function record(Invoice $invoice, string $clearedInvoice): void
    {
        app(InvoiceVerdict::class)->record($invoice, FatooraResponse::fromApiResponse([
            'clearanceStatus' => 'CLEARED',
            'validationResults' => ['status' => 'PASS'],
            'clearedInvoice' => $clearedInvoice,
        ]));
    }

    private function cleared(string $references): string
    {
        return base64_encode(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:ID>ACME-1</cbc:ID>'.$references.'</Invoice>'
        );
    }

    private function invoice(): Invoice
    {
        $acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $acme->id,
            'invoice_number' => 'ACME-1',
            'type' => 'standard',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            'icv' => 1,
            'hash' => base64_encode(hash('sha256', 'acme', true)),
            'qr_code' => self::ISSUED_QR,
        ]));
    }
}
