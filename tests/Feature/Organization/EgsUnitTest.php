<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tills (EGS units): every device there was becomes one under the id it had
 * (1120), so its chain, its credentials and its numbering carry on; and
 * every organization and branch made since comes with its own.
 */
class EgsUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_devices_become_units_unchanged(): void
    {
        $migration = require database_path('migrations/1220_egs_units.php');
        $migration->down();

        $organizationId = (string) Str::uuid();
        DB::table('organizations')->insert([
            'id' => $organizationId,
            'name' => 'Acme Retail',
            'country' => 'SA',
            'compliance_profile' => json_encode(['zatca_onboarded' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $riyadh = $this->legacyBranch($organizationId, 'Riyadh', 1, 'active');
        $removed = $this->legacyBranch($organizationId, 'Closed', 2, 'pending', deleted: true);

        $headOffice = $this->legacyInvoice($organizationId, null, $organizationId, 7);
        $branch = $this->legacyInvoice($organizationId, $riyadh, $riyadh, 3);

        $migration->up();

        $units = EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->where('org_id', $organizationId)->get()->keyBy('id'));

        $this->assertEqualsCanonicalizing([$organizationId, $riyadh, $removed], $units->keys()->all());
        $this->assertNull($units[$organizationId]->branch_id);
        $this->assertNull($units[$organizationId]->till_number);
        $this->assertSame('active', $units[$organizationId]->onboarding_status);
        $this->assertSame($riyadh, $units[$riyadh]->branch_id);
        $this->assertSame(1, $units[$riyadh]->till_number);
        $this->assertSame('EGS-Riyadh', $units[$riyadh]->egs_serial);
        $this->assertSame('active', $units[$riyadh]->onboarding_status);
        $this->assertSame(EgsUnit::ACTIVE, $units[$riyadh]->status);
        $this->assertSame(EgsUnit::RETIRED, $units[$removed]->status);

        // The documents name their units, and keep their counters.
        $this->assertSame($organizationId, Invoice::withoutTenantScope(fn () => $headOffice->fresh()->unit->id));
        $this->assertSame($riyadh, Invoice::withoutTenantScope(fn () => $branch->fresh()->unit->id));
    }

    public function test_device_must_be_a_unit(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->expectException(QueryException::class);

        $this->legacyInvoice((string) $organization->id, null, (string) Str::uuid(), 1);
    }

    public function test_organization_comes_with_head_office(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $unit = EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->find($organization->id));

        $this->assertNotNull($unit);
        $this->assertTrue($unit->isHeadOffice());
        $this->assertSame(__('Head office'), $unit->label());
    }

    public function test_branch_comes_with_its_first_till(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $branch = $this->branch($organization, 'Riyadh');

        $unit = EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->find($branch->id));

        $this->assertSame($branch->id, $unit->branch_id);
        $this->assertSame($branch->till_number, $unit->till_number);
        $this->assertSame($branch->device_serial, $unit->egs_serial);
        $this->assertFalse($unit->isHeadOffice());
    }

    public function test_tills_and_branches_share_numbers(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $riyadh = $this->branch($organization, 'Riyadh');

        $second = EgsUnit::withoutTenantScope(fn () => EgsUnit::query()->create([
            'org_id' => $organization->id,
            'branch_id' => $riyadh->id,
        ]));
        $jeddah = $this->branch($organization, 'Jeddah');

        $this->assertSame([1, 2, 3], [$riyadh->till_number, $second->till_number, $jeddah->till_number]);
        $this->assertSame(__('Till :number', ['number' => 2]), $second->label());
    }

    private function branch(Organization $organization, string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $organization->id,
            'name' => $name,
            'device_serial' => 'EGS-'.$name,
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => $name,
            'postal_code' => '12345',
        ]));
    }

    private function legacyBranch(string $organizationId, string $name, int $till, string $status, bool $deleted = false): string
    {
        $id = (string) Str::uuid();

        DB::table('branches')->insert([
            'id' => $id,
            'org_id' => $organizationId,
            'name' => $name,
            'device_serial' => 'EGS-'.$name,
            'industry' => 'General',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => $name,
            'postal_code' => '12345',
            'country_code' => 'SA',
            'onboarding_status' => $status,
            'invoice_count' => 0,
            'is_active' => true,
            'is_default' => false,
            'till_number' => $till,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);

        return $id;
    }

    private function legacyInvoice(string $organizationId, ?string $branchId, string $deviceId, int $icv): Invoice
    {
        return Invoice::withoutTenantScope(function () use ($organizationId, $branchId, $deviceId, $icv): Invoice {
            $invoice = Invoice::create([
                'org_id' => $organizationId,
                'branch_id' => $branchId,
                'type' => 'simplified',
                'document_type' => 'invoice',
                'status' => 'draft',
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'subtotal' => '100.00',
                'tax_amount' => '15.00',
                'total' => '115.00',
            ]);
            $invoice->forceFill(['device_id' => $deviceId, 'icv' => $icv])->save();

            return $invoice;
        });
    }
}
