<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Branch writes that span more than one row or store.
 *
 * A branch's credentials are what let it sign, its default flag is what an
 * invoice naming no branch falls back to, and an organization has one default.
 * Each of those must survive a write that fails half-way or a request that
 * runs alongside another.
 */
class BranchServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds']);

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);
    }

    /**
     * Credentials go only after the branch row does, so a delete that fails
     * leaves a branch that can still sign.
     */
    public function test_failed_delete_keeps_branch_credentials(): void
    {
        $branch = $this->branch('Jeddah');
        $credentials = app(CredentialStore::class);
        $credentials->put($this->organization->id, $branch->id, CredentialStore::PCSID, ['privateKey' => 'k', 'pcsid' => 'c']);

        Branch::deleting(fn () => throw new \RuntimeException('branch store down'));

        $this->assertThrows(fn () => app(BranchService::class)->delete($branch), \RuntimeException::class);

        $this->assertNotNull($credentials->get($this->organization->id, $branch->id, CredentialStore::PCSID));
    }

    public function test_failed_default_switch_keeps_the_default(): void
    {
        $current = $this->branch('Riyadh', default: true);
        $next = $this->branch('Jeddah');

        Branch::updating(fn (Branch $branch) => $branch->isDirty('is_default') && $branch->is_default
            ? throw new \RuntimeException('branch store down')
            : null);

        $this->assertThrows(fn () => $next->setAsDefault(), \RuntimeException::class);

        $this->assertTrue($current->fresh()->is_default);
    }

    /**
     * A second first-call creates its Main Branch after this one has looked
     * for a branch and found none. One branch results, not two.
     */
    public function test_concurrent_first_calls_create_one_branch(): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired): void {
            if ($fired || ! str_contains($query->sql, '"branches"') || ! str_contains($query->sql, '"is_active"')) {
                return;
            }

            $fired = true;
            $this->branch('Main Branch', default: true);
        });

        app(BranchService::class)->getOrCreateDefault($this->organization);

        $this->assertSame(1, Branch::withoutTenantScope(fn () => Branch::count()));
    }

    private function branch(string $name, bool $default = false): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->organization->id,
            'name' => $name,
            'device_serial' => 'EGS-'.Str::uuid(),
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
            'is_default' => $default,
        ]));
    }
}
