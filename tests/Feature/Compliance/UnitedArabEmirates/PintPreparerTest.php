<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\ComplianceProfile;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hand-off to the transport: a valid document stored 'ready', or
 * nothing stored at all.
 */
class PintPreparerTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_ready_document_is_stored(): void
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization());

        $document = app(PeppolPreparer::class)->prepare($invoice);
        $stored = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->findOrFail($document->id));

        $this->assertSame([
            'org_id' => $invoice->org_id,
            'invoice_id' => $invoice->id,
            'direction' => 'out',
            'country' => 'AE',
            'customization_id' => PintAe::BILLING,
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'invoice',
            'status' => 'ready',
            'sender_endpoint' => '0235:1001234567',
            'receiver_endpoint' => '0235:1007654321',
        ], $stored->only(['org_id', 'invoice_id', 'direction', 'country', 'customization_id', 'profile_id', 'document_type', 'status', 'sender_endpoint', 'receiver_endpoint']));
        $this->assertStringContainsString('<cbc:ID>'.$invoice->invoice_number.'</cbc:ID>', $stored->xml);
    }

    public function test_invalid_invoice_stores_nothing(): void
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization(['trn' => null]));

        try {
            app(PeppolPreparer::class)->prepare($invoice);
            $this->fail('An invoice missing mandatory fields was prepared.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Seller tax identifier is missing.', $e->getMessage());
        }

        $this->assertSame(0, PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->count()));
    }

    public function test_second_prepare_returns_same_document(): void
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization());
        $preparer = app(PeppolPreparer::class);

        $this->assertSame($preparer->prepare($invoice)->id, $preparer->prepare($invoice)->id);
        $this->assertSame(1, PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->count()));
    }

    public function test_draft_is_not_prepared(): void
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization(), [], issued: false);

        $this->expectException(DomainException::class);

        app(PeppolPreparer::class)->prepare(Invoice::withoutTenantScope(fn () => $invoice->fresh()));
    }

    /** The buyer issues a self-billed invoice, so it is the buyer that sends. */
    public function test_self_billed_sent_by_buyer(): void
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization(), ['is_self_billed' => true], null, [
            'buyer_legal_registration' => ['type' => 'TL', 'id' => 'CN-7654321', 'authority' => 'ADDED'],
        ]);

        $document = app(PeppolPreparer::class)->prepare($invoice);

        $this->assertSame(PintAe::SELF_BILLING, $document->customization_id);
        $this->assertSame('0235:1001234567', $document->sender_endpoint);
        $this->assertSame('0235:1007654321', $document->receiver_endpoint);
    }

    public function test_organization_needs_a_uae_profile(): void
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization());
        ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::query()->update(['status' => ComplianceProfile::STATUS_SUSPENDED]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no active UAE compliance profile');

        app(PeppolPreparer::class)->prepare($invoice);
    }
}
