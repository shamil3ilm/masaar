<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * One counter and one chain per device.
 *
 * ZATCA keeps the invoice counter (ICV) and the hash chain (PIH) per EGS
 * unit: each device numbers its own documents from one and names its own
 * previous document. The counter and the chain were kept per organization,
 * which was the same thing while every document was head office's. A branch
 * onboarded as its own device signs and files as itself, and its documents
 * were numbered in head office's sequence and chained onto head office's
 * last invoice - so the branch's chain, as ZATCA sees it, had gaps and named
 * predecessors it never issued.
 *
 * The device is whichever certificate signed: the branch when it has one,
 * head office otherwise.
 */
class DeviceChainTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private Organization $acme;

    /** @var array<string, array{privateKey: string, certificate: string}> */
    private array $keys = [];

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

        $this->onboard(null, 'head');
    }

    public function test_a_branch_counts_from_one(): void
    {
        $this->issue('HQ-1');
        $this->issue('HQ-2');

        $jeddah = $this->branch();
        $this->onboard($jeddah->id, 'jeddah');

        $first = $this->issue('JED-1', $jeddah->id);

        $this->assertSame(1, $first->icv);
        $this->assertSame($jeddah->id, $first->device_id);
        $this->assertSame(FatooraConfig::GENESIS_PIH, $this->pih($first), 'a device\'s first document names the genesis hash');
    }

    public function test_a_branch_chains_on_itself(): void
    {
        $jeddah = $this->branch();
        $this->onboard($jeddah->id, 'jeddah');

        $first = $this->issue('JED-1', $jeddah->id);
        $this->issue('HQ-1');
        $second = $this->issue('JED-2', $jeddah->id);

        $this->assertSame(2, $second->icv);
        $this->assertSame($first->hash, $this->pih($second));
    }

    public function test_head_office_is_not_interrupted(): void
    {
        $jeddah = $this->branch();
        $this->onboard($jeddah->id, 'jeddah');

        $this->issue('HQ-1');
        $second = $this->issue('HQ-2');
        $this->issue('JED-1', $jeddah->id);
        $third = $this->issue('HQ-3');

        $this->assertSame(3, $third->icv);
        $this->assertSame($second->hash, $this->pih($third));
    }

    /**
     * A branch with no certificate of its own signs as head office, so it is
     * head office's device and continues head office's count.
     */
    public function test_a_bare_branch_is_head_office(): void
    {
        $this->issue('HQ-1');

        $invoice = $this->issue('LOBBY-1', $this->branch()->id);

        $this->assertSame($this->acme->id, $invoice->device_id);
        $this->assertSame(2, $invoice->icv);
    }

    /**
     * Filed by the device that signed it, even if the branch has been
     * onboarded since: the document carries head office's signature, so the
     * branch's credentials would file it as a device that never signed it.
     */
    public function test_the_signing_device_files_it(): void
    {
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);

        $branch = $this->branch();
        $invoice = $this->issue('LOBBY-1', $branch->id);

        $this->onboard($branch->id, 'late');

        app(SubmissionTracker::class)->submit($invoice);

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')
            === ['Basic '.base64_encode($this->keys['head']['certificate'].':head-secret')]);
    }

    public function test_the_chain_verifies_across_devices(): void
    {
        $jeddah = $this->branch();
        $this->onboard($jeddah->id, 'jeddah');

        $this->issue('HQ-1');
        $this->issue('JED-1', $jeddah->id);
        $this->issue('HQ-2');
        $this->issue('JED-2', $jeddah->id);

        $this->artisan('fatoora:verify-hash-chain')->assertExitCode(0);
    }

    private function onboard(?string $branchId, string $device): void
    {
        $this->keys[$device] = $this->selfSignedCredentials();

        app(CredentialStore::class)->put($this->acme->id, $branchId, CredentialStore::PCSID, [
            'privateKey' => $this->keys[$device]['privateKey'],
            'pcsid' => $this->keys[$device]['certificate'],
            'secret' => $device.'-secret',
        ]);
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

        return Invoice::withoutTenantScope(fn () => $invoice->fresh());
    }

    /**
     * The PIH the signed document itself carries, which is what ZATCA reads.
     */
    private function pih(Invoice $invoice): string
    {
        $xml = new \DOMDocument;
        $xml->loadXML((string) $invoice->signed_xml);

        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return (string) $xpath->evaluate(
            'string(//cac:AdditionalDocumentReference[cbc:ID="PIH"]/cac:Attachment/cbc:EmbeddedDocumentBinaryObject)'
        );
    }
}
