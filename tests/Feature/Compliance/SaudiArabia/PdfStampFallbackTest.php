<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * An invoice PDF is never a server error.
 *
 * The developer portal issues every caller the same certificate, whose key
 * nobody outside ZATCA holds; the XML signer lets that through in the
 * sandbox (XadesSigner), and the PDF's PAdES stamp refused it, so every PDF
 * there was an HTTP 500. The PDF now follows the XML: in the sandbox it is
 * given unstamped, as its stamp could not verify anyway; elsewhere a stamp
 * that cannot be made is said in words, not a 500.
 */
class PdfStampFallbackTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_sandbox_shared_certificate_still_downloads(): void
    {
        $this->store('sandbox');
        $invoice = $this->issued('PDF-1');

        $response = $this->download($invoice)->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringNotContainsString('ETSI.CAdES.detached', (string) $response->getContent());
    }

    public function test_unstampable_pdf_is_said_in_words(): void
    {
        $this->store('sandbox');
        $invoice = $this->issued('PDF-2');
        config(['fatoora.environment' => 'production']);
        $this->store('production');

        $this->download($invoice)
            ->assertRedirect(route('portal.invoices.show', $invoice->id))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'could not be stamped'));
    }

    /**
     * A key that is not the certificate's, as the developer portal hands out.
     */
    private function store(string $environment): void
    {
        $key = $this->selfSignedCredentials(serial: 1)['privateKey'];
        $certificate = $this->selfSignedCredentials(serial: 2)['certificate'];

        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $key, 'pcsid' => $certificate, 'secret' => 's', 'environment' => $environment,
        ]);
    }

    private function download(Invoice $invoice): TestResponse
    {
        return $this->asRequest(fn () => $this->actingAs($this->admin)
            ->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.invoices.pdf', $invoice->id)));
    }

    private function issued(string $number): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id, 'invoice_number' => $number, 'type' => 'simplified', 'document_type' => 'invoice',
            'status' => 'draft', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create([
            'description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1.000', 'unit_price' => '100.00',
            'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme);

        return $invoice->fresh(['lines']);
    }
}
