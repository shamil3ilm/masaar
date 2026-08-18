<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Pipeline\Services\PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The branch an invoice is issued from decides which certificate signs it.
 *
 * ZATCA treats each branch as a separate EGS unit with its own CSID, so
 * signing a branch's invoice with the organization's default credentials
 * attributes the document to the wrong device.
 *
 * The pipeline accepted a branch_id and discarded it: the column existed with
 * an index and a foreign key, Submitter already read $invoice->branch, but the
 * model had no branch relation, branch_id was not fillable, and BranchService
 * was an optional constructor argument the container therefore never supplied.
 */
class BranchRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA', 'vat_number' => '300000000000011']);
    }

    public function test_branch_is_stored_on_the_invoice(): void
    {
        $branch = $this->makeBranch($this->acme, 'Riyadh');

        $result = $this->submit(['branch' => $branch->id]);

        $this->assertDatabaseHas('invoices', [
            'id' => $result['invoice_id'],
            'branch_id' => $branch->id,
        ]);
    }

    public function test_invoice_exposes_its_branch(): void
    {
        $branch = $this->makeBranch($this->acme, 'Riyadh');

        $invoice = Invoice::withoutTenantScope(
            fn () => Invoice::find($this->submit(['branch' => $branch->id])['invoice_id'])
        );

        $this->assertSame('Riyadh', $invoice->branch->name);
    }

    public function test_no_branch_leaves_it_null(): void
    {
        $result = $this->submit();

        $this->assertDatabaseHas('invoices', [
            'id' => $result['invoice_id'],
            'branch_id' => null,
        ]);
    }

    /**
     * The security half. An unchecked branch identifier would let one taxpayer
     * issue documents signed by another's certificate.
     */
    public function test_another_tenants_branch_is_refused(): void
    {
        $theirs = $this->makeBranch($this->rival, 'Rival Jeddah');

        $this->expectException(FatooraException::class);

        $this->submit(['branch' => $theirs->id]);
    }

    public function test_unknown_branch_is_refused(): void
    {
        $this->expectException(FatooraException::class);

        $this->submit(['branch' => '00000000-0000-0000-0000-000000000000']);
    }

    /**
     * A refused branch must not leave a half-created invoice behind.
     */
    public function test_refused_branch_creates_no_invoice(): void
    {
        $theirs = $this->makeBranch($this->rival, 'Rival Jeddah');

        try {
            $this->submit(['branch' => $theirs->id]);
        } catch (FatooraException) {
            // expected
        }

        $this->assertDatabaseCount('invoices', 0);
    }

    private function makeBranch(Organization $organization, string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $organization->id,
            'name' => $name,
            // ZATCA requires each EGS unit to carry a unique device serial.
            'device_serial' => '1-Masaar|2-1.0|3-'.uniqid(),
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]));
    }

    /**
     * Runs the pipeline without submitting, so nothing reaches ZATCA.
     */
    private function submit(array $options = []): array
    {
        return app(PipelineService::class)->submitInvoice(
            data: [
                'invoice_number' => 'INV-'.uniqid(),
                'type' => 'standard',
                'document_type' => 'invoice',
                'issue_date' => now()->toDateString(),
                'buyer_name' => 'Buyer',
                'auto_submit' => false,
                'lines' => [['description' => 'Widget', 'quantity' => 1, 'unit_price' => '100.00']],
            ],
            organizationId: $this->acme->id,
            branchId: $options['branch'] ?? null,
        );
    }
}
