<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An administrator adds and edits branches - address, printer, who works
 * there - and sees where each branch's device stands, as its country says.
 */
class BranchScreensTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'branch-screens-token';

    private Organization $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
        $this->owner = $this->memberOf($this->acme, 'admin');
    }

    public function test_an_administrator_adds_a_branch(): void
    {
        $this->send($this->owner, '/portal/branches', $this->address('Olaya'))->assertRedirect();

        $branch = $this->branchNamed('Olaya');

        $this->assertSame(1, $branch->till_number);
        $this->assertSame('SA', $branch->country_code);
        $this->assertTrue($branch->is_default);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Branch.created', 'entity_id' => $branch->id]);
    }

    public function test_the_country_checks_the_address(): void
    {
        $this->send($this->owner, '/portal/branches', [...$this->address('Olaya'), 'building_number' => '12', 'postal_code' => 'ABCDE'])
            ->assertSessionHasErrors(['building_number', 'postal_code']);

        $this->assertDatabaseCount('branches', 0);
    }

    public function test_a_member_cannot_manage_branches(): void
    {
        $member = $this->memberOf($this->acme, 'member');

        $this->page($member, '/portal/branches')->assertForbidden();
        $this->send($member, '/portal/branches', $this->address('Olaya'))->assertForbidden();
    }

    public function test_the_printer_and_address_are_saved(): void
    {
        $this->send($this->owner, '/portal/branches', $this->address('Olaya'));
        $branch = $this->branchNamed('Olaya');

        $this->send($this->owner, '/portal/branches/'.$branch->id, [...$this->address('Olaya Mall'), 'paper' => '80mm', 'dpi' => '203', 'dots' => '576'])
            ->assertRedirect('/portal/branches/'.$branch->id);

        $branch->refresh();
        $this->assertSame('Olaya Mall', $branch->name);
        $printing = app(PrintPreferences::class);
        $this->assertSame(PaperSize::Roll80, $printing->branchPaper($this->acme->id, $branch->id));
        $this->assertSame(['dpi' => 203, 'dots' => 576], $printing->receiptPrinter($this->acme->id, $branch->id));
    }

    public function test_the_owner_is_listed_as_owner(): void
    {
        $this->send($this->owner, '/portal/branches', $this->address('Olaya'));
        $branch = $this->branchNamed('Olaya');
        $founder = $this->memberOf($this->acme, 'owner');
        $books = $this->memberOf($this->acme, 'accountant');

        $page = $this->page($this->owner, '/portal/branches/'.$branch->id)->assertOk();

        $page->assertSee('Owner')->assertSee('Accountant');
        $page->assertDontSee('name="people['.$founder->id.']"', false);
        $page->assertSee('name="people['.$books->id.']"', false);
    }

    public function test_people_are_assigned_with_roles(): void
    {
        $this->send($this->owner, '/portal/branches', $this->address('Olaya'));
        $branch = $this->branchNamed('Olaya');
        $cashier = $this->memberOf($this->acme, 'member');
        $auditor = $this->memberOf($this->acme, 'member');

        $this->send($this->owner, '/portal/branches/'.$branch->id.'/people', ['people' => [$cashier->id => 'member', $auditor->id => 'viewer']])
            ->assertRedirect();

        $this->assertDatabaseHas('branch_user', ['branch_id' => $branch->id, 'user_id' => $cashier->id, 'role' => 'member', 'created_by' => $this->owner->id]);
        $this->assertDatabaseHas('branch_user', ['branch_id' => $branch->id, 'user_id' => $auditor->id, 'role' => 'viewer']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.member.branch_assigned', 'entity_id' => $branch->id]);

        $this->send($this->owner, '/portal/branches/'.$branch->id.'/people', ['people' => [$cashier->id => '']]);

        $this->assertSoftDeleted('branch_user', ['branch_id' => $branch->id, 'user_id' => $cashier->id, 'deleted_by' => $this->owner->id]);

        // Put back: the same row returns, and the unique pair holds.
        $this->send($this->owner, '/portal/branches/'.$branch->id.'/people', ['people' => [$cashier->id => 'viewer']]);

        $this->assertNotSoftDeleted('branch_user', ['branch_id' => $branch->id, 'user_id' => $cashier->id, 'role' => 'viewer', 'deleted_by' => null]);
    }

    public function test_another_organizations_branch_is_not_found(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $theirs = Branch::withoutTenantScope(fn () => Branch::create(['org_id' => $rival->id, ...$this->address('Theirs'), 'device_serial' => 'rival-1']));

        $this->page($this->owner, '/portal/branches/'.$theirs->id)->assertNotFound();
        $this->send($this->owner, '/portal/branches/'.$theirs->id, $this->address('Mine now'))->assertNotFound();
    }

    public function test_a_saudi_branch_shows_its_device(): void
    {
        $this->send($this->owner, '/portal/branches', $this->address('Olaya'));
        $branch = $this->branchNamed('Olaya');

        $this->page($this->owner, '/portal/branches/'.$branch->id)
            ->assertOk()
            // Its tills, the first one made with it, each with where it stands.
            ->assertSee('Add a till')
            ->assertSee('Till 1')
            ->assertSee('Not connected to ZATCA');

        $this->page($this->owner, '/portal/branches')->assertOk()->assertSee('Olaya')->assertSee('Not yet');
    }

    public function test_a_uae_branch_has_no_device(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = Organization::create(['name' => 'Gulf Trading LLC', 'country' => 'AE', 'status' => 'active']);
        $admin = $this->memberOf($gulf, 'admin');

        $this->send($admin, '/portal/branches', ['name' => 'Marina', 'street' => 'Marina Walk', 'city' => 'Dubai'])
            ->assertSessionHasNoErrors();

        $branch = $this->branchNamed('Marina');

        $this->assertSame('AE', $branch->country_code);
        $this->page($admin, '/portal/branches/'.$branch->id)->assertOk()->assertDontSee('Add a till');
    }

    private function page(User $user, string $uri): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)->get($uri));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(User $user, string $uri, array $data): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['_token' => self::TOKEN])
            ->post($uri, ['_token' => self::TOKEN, ...$data]));
    }

    /**
     * @return array<string, string>
     */
    private function address(string $name): array
    {
        return ['name' => $name, 'street' => 'Olaya Street', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345'];
    }

    private function branchNamed(string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::query()->where('name', $name)->firstOrFail());
    }

    private function memberOf(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
