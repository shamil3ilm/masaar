<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The device that issues a document signs it.
 *
 * A branch is its own EGS unit with its own certificate, and it files with
 * that certificate's credentials. Issuance signed with the organization's
 * certificate whatever branch the invoice came from - the branch was never
 * passed - so a branch document went to ZATCA signed by one device and
 * filed by another.
 *
 * And with no credentials at all, issuance signed nothing and carried on. For
 * an organization not yet onboarded that is Phase 1, and correct. For one
 * that is onboarded it produced a document that could never be filed, without
 * a word.
 */
class BranchSigningTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    /** @var array{privateKey: string, certificate: string} */
    private array $head;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);

        $this->head = $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->acme->id, null, CredentialStore::PCSID, [
            'privateKey' => $this->head['privateKey'],
            'pcsid' => $this->head['certificate'],
            'secret' => 'head-office-secret',
        ]);
    }

    public function test_a_branch_signs_with_its_certificate(): void
    {
        $jeddah = $this->selfSignedCredentials();
        $branch = $this->branch();

        app(CredentialStore::class)->put($this->acme->id, $branch->id, CredentialStore::PCSID, [
            'privateKey' => $jeddah['privateKey'],
            'pcsid' => $jeddah['certificate'],
            'secret' => 'jeddah-secret',
        ]);

        $invoice = $this->issue('ACME-1', $branch->id);

        $this->assertSame($this->body($jeddah['certificate']), $this->signer($invoice));
    }

    /**
     * And a branch with no certificate of its own falls back to the
     * organization's, as filing does.
     */
    public function test_a_bare_branch_uses_head_office(): void
    {
        $invoice = $this->issue('ACME-2', $this->branch()->id);

        $this->assertSame($this->body($this->head['certificate']), $this->signer($invoice));
    }

    public function test_onboarded_without_credentials_issues_nothing(): void
    {
        app(CredentialStore::class)->forget($this->acme->id);

        try {
            $this->issue('ACME-3');
            $this->fail('An onboarded organization with no credentials issued an unsigned invoice.');
        } catch (FatooraException) {
            // Refused, which is the point.
        }

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::where('invoice_number', 'ACME-3')->first());

        $this->assertNull($invoice->hash, 'the refused invoice was given a hash anyway');
    }

    /**
     * Phase 1: not onboarded, so there is nothing to sign with and nothing
     * wrong with that.
     */
    public function test_phase_one_still_issues_unsigned(): void
    {
        $this->acme->update(['compliance_profile' => ['zatca_onboarded' => false]]);
        app(CredentialStore::class)->forget($this->acme->id);

        $invoice = $this->issue('ACME-4');

        $this->assertNotNull($invoice->hash);
        $this->assertNull($this->signer($invoice));
    }

    private function branch(): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => 'Jeddah Branch',
            'device_serial' => 'EGS-JED-'.uniqid(),
            'industry' => 'Retail',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));
    }

    private function issue(string $number, ?string $branchId = null): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'branch_id' => $branchId,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'supply_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Widget',
            'quantity' => '1.000',
            'unit_price' => '100.00',
            'tax_rate' => '15.00',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        app(Submitter::class)->generate($invoice->fresh(['lines']), $this->acme->fresh());

        return $invoice->fresh();
    }

    /**
     * The certificate embedded in the signature, or null when unsigned.
     */
    private function signer(Invoice $invoice): ?string
    {
        preg_match('/<ds:X509Certificate>([^<]+)<\/ds:X509Certificate>/', (string) $invoice->signed_xml, $match);

        return isset($match[1]) ? preg_replace('/\s+/', '', $match[1]) : null;
    }

    private function body(string $pem): string
    {
        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }
}
