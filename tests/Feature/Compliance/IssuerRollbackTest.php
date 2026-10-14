<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Models\ChainEntry;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\InvoiceIssuer;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * An issuance that rolled back is not reported as an issuance.
 *
 * Submitter::generate sets icv, hash, qr_code, signed_xml and status on the
 * model it is given, inside its transaction. If anything after that throws -
 * the chain write, a deadlock, a lock-wait timeout - DB::transaction rolls the
 * database back and the PHP object keeps every one of those values.
 *
 * InvoiceIssuer::generate refreshed on success and in neither of its catch
 * blocks, while InvoiceIssuer::file refreshes on all three of its branches.
 * So after a failed issuance PipelineService handed that same stale object to
 * PipelineResult::build, which reads status, hash, qr_code and signed_xml
 * straight off it and returned them to the ERP alongside the error.
 *
 * The ERP therefore recorded an issued invoice carrying a compliance hash for
 * a document the database still holds as a draft with hash and icv null. An
 * ERP that prints that QR prints a hash that exists nowhere, the two systems
 * cannot be reconciled afterwards, and the invoice number is spent on a
 * document this platform never issued.
 *
 * The assertions are deliberately on the caller's own instance rather than on
 * a fresh read. A fresh read was always correct; what the caller held was not,
 * and the caller is what the ERP's answer is built from.
 */
class IssuerRollbackTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $credentials = $this->selfSignedCredentials();

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        app(CredentialStore::class)->put(
            $this->organization->id,
            null,
            CredentialStore::PCSID,
            ['privateKey' => $credentials['privateKey'], 'pcsid' => $credentials['certificate'], 'secret' => 'device-secret'],
        );
    }

    public function test_a_failed_issuance_is_not_reported(): void
    {
        $this->failTheChainWrite();

        $invoice = $this->draft('ACME-1');

        $outcome = app(InvoiceIssuer::class)->issue($invoice, $this->organization, file: false);

        $this->assertFalse($outcome->issued, 'a failed issuance reported itself as issued');

        $this->assertSame(InvoiceStatus::Draft, $invoice->status, 'the caller was left holding status issued');
        $this->assertNull($invoice->hash, 'the caller was left holding a hash the database never kept');
        $this->assertNull($invoice->icv, 'the caller was left holding a counter the database never kept');
        $this->assertNull($invoice->qr_code);
    }

    /**
     * And the database agrees, which it always did.
     */
    public function test_the_stored_invoice_is_untouched(): void
    {
        $this->failTheChainWrite();

        $invoice = $this->draft('ACME-2');

        app(InvoiceIssuer::class)->issue($invoice, $this->organization, file: false);

        $stored = Invoice::withoutTenantScope(fn () => Invoice::find($invoice->id));

        $this->assertSame(InvoiceStatus::Draft, $stored->status);
        $this->assertNull($stored->hash);
        $this->assertDatabaseCount('hash_chain_history', 0);
    }

    /**
     * Fail the chain entry, the last write inside generate's transaction, so
     * the failure lands after the invoice has been mutated in memory.
     *
     * Through a model event rather than a mock: ChainRecorder is final, and
     * this exercises the real write path instead of replacing it.
     */
    private function failTheChainWrite(): void
    {
        ChainEntry::creating(function (): void {
            throw new RuntimeException('the chain write failed');
        });
    }

    private function draft(string $number): Invoice
    {
        $invoice = Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => $number,
            'type' => 'standard',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_vat_number' => '300000000000003',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]);

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }
}
