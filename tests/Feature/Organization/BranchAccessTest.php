<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A cashier sees and issues at their own branches, and nowhere else - held
 * in the query, so another branch's invoice is not there to find by URL or
 * through the API, whatever the screens show.
 *
 * Someone assigned to no branch, and every administrator, works across the
 * organization as before.
 */
class BranchAccessTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private const TOKEN = 'branch-access-token';

    private Organization $acme;

    private Branch $riyadh;

    private Branch $jeddah;

    private User $owner;

    private User $cashier;

    private Invoice $atRiyadh;

    private Invoice $atJeddah;

    private Invoice $atHeadOffice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
        $this->riyadh = $this->branch('Riyadh', ready: true);
        $this->jeddah = $this->branch('Jeddah');

        $this->owner = $this->memberOf('admin');
        $this->cashier = $this->memberOf('member');
        app(BranchAccess::class)->assign($this->acme->id, $this->riyadh->id, $this->cashier->id, 'member');

        $this->atRiyadh = $this->makeInvoice($this->acme, 'RUH-1', ['branch_id' => $this->riyadh->id]);
        $this->atJeddah = $this->makeInvoice($this->acme, 'JED-1', ['branch_id' => $this->jeddah->id]);
        $this->atHeadOffice = $this->makeInvoice($this->acme, 'HQ-1');
    }

    public function test_a_cashier_lists_their_branch_only(): void
    {
        $this->page($this->cashier, '/portal/invoices')
            ->assertOk()
            ->assertSee('RUH-1')
            ->assertDontSee('JED-1')
            ->assertDontSee('HQ-1');
    }

    public function test_other_branches_are_hidden_by_url(): void
    {
        $this->page($this->cashier, '/portal/invoices/'.$this->atJeddah->id)->assertNotFound();
        $this->page($this->cashier, '/portal/invoices/'.$this->atHeadOffice->id)->assertNotFound();
        $this->page($this->cashier, '/portal/invoices/'.$this->atRiyadh->id)->assertOk();
    }

    public function test_other_branches_are_hidden_by_api(): void
    {
        $this->api($this->cashier)->getJson('/api/invoices/'.$this->atJeddah->id)->assertNotFound();
        $this->api($this->cashier)->getJson('/api/invoices/'.$this->atJeddah->id.'/pdf')->assertNotFound();
        $this->api($this->cashier)->getJson('/api/invoices/'.$this->atRiyadh->id)->assertOk();

        $numbers = collect($this->api($this->cashier)->getJson('/api/invoices')->assertOk()->json('data'))->pluck('invoice_number')->all();

        $this->assertSame(['RUH-1'], $numbers);
    }

    public function test_the_owner_sees_every_branch(): void
    {
        $this->page($this->owner, '/portal/invoices')
            ->assertOk()
            ->assertSee('RUH-1')
            ->assertSee('JED-1')
            ->assertSee('HQ-1');
    }

    public function test_an_unassigned_member_sees_everything(): void
    {
        $this->page($this->memberOf('member'), '/portal/invoices')
            ->assertOk()
            ->assertSee('JED-1')
            ->assertSee('HQ-1');
    }

    /**
     * By organization role: an owner and an admin see every branch, an
     * accountant every branch read only; a member or viewer assigned to a
     * branch sees it alone.
     */
    public function test_roles_decide_every_branch(): void
    {
        $access = app(BranchAccess::class);
        $owner = $this->memberOf('owner');
        $accountant = $this->memberOf('accountant');
        // Assigned or not, an accountant sees every branch.
        $access->assign($this->acme->id, $this->riyadh->id, $accountant->id, 'member');
        $viewer = $this->memberOf('viewer');
        $access->assign($this->acme->id, $this->riyadh->id, $viewer->id, 'viewer');

        $this->assertNull($access->allowed($owner->id, $this->acme->id));
        $this->assertNull($access->allowed($this->owner->id, $this->acme->id));
        $this->assertNull($access->allowed($accountant->id, $this->acme->id));
        $this->assertSame([$this->riyadh->id], $access->allowed($viewer->id, $this->acme->id));

        $this->assertTrue($access->mayWriteAt($owner->id, $this->acme->id, $this->jeddah->id));
        $this->assertFalse($access->mayWriteAt($accountant->id, $this->acme->id, $this->riyadh->id));
        $this->assertFalse($access->mayWriteAt($accountant->id, $this->acme->id, null));

        $this->page($accountant, '/portal/invoices')->assertOk()->assertSee('JED-1')->assertSee('HQ-1');
    }

    /** The switcher's choice is the person's own customization, with no level above it. */
    public function test_the_choice_is_a_customization(): void
    {
        $this->postAs($this->owner, '/portal/branch', ['branch' => $this->jeddah->id])->assertRedirect();

        $this->assertSame($this->jeddah->id, app(Customizations::class)->own('branches.working', Level::User, Scope::member($this->acme->id, $this->owner->id)));
        $this->assertSame([Level::User], app(Customizations::class)->definition('branches.working')->levels);
    }

    public function test_the_switcher_narrows_and_is_kept(): void
    {
        $this->postAs($this->owner, '/portal/branch', ['branch' => $this->jeddah->id])->assertRedirect();

        $this->page($this->owner, '/portal/invoices')
            ->assertOk()
            ->assertSee('JED-1')
            ->assertDontSee('RUH-1')
            ->assertDontSee('HQ-1');

        // An invoice of another branch still opens from a link.
        $this->page($this->owner, '/portal/invoices/'.$this->atRiyadh->id)->assertOk();

        $this->postAs($this->owner, '/portal/branch', ['branch' => ''])->assertRedirect();
        $this->page($this->owner, '/portal/invoices')->assertSee('RUH-1')->assertSee('HQ-1');
    }

    public function test_switching_outside_ones_branches_is_refused(): void
    {
        $this->postAs($this->cashier, '/portal/branch', ['branch' => $this->jeddah->id])->assertSessionHas('error');

        $this->page($this->cashier, '/portal/invoices')->assertDontSee('JED-1');
    }

    public function test_new_invoices_take_the_working_branch(): void
    {
        $this->postAs($this->owner, '/portal/branch', ['branch' => $this->riyadh->id]);

        $this->postAs($this->owner, '/portal/invoices', $this->form('RUH-NEW'))->assertRedirect();

        $this->assertSame($this->riyadh->id, $this->stored('RUH-NEW')->branch_id);
    }

    public function test_a_cashiers_invoice_names_their_branch(): void
    {
        $this->postAs($this->cashier, '/portal/invoices', $this->form('CASH-1'))->assertRedirect();

        $this->assertSame($this->riyadh->id, $this->stored('CASH-1')->branch_id);
    }

    public function test_a_branch_not_ready_refuses_drafts(): void
    {
        $this->postAs($this->owner, '/portal/branch', ['branch' => $this->jeddah->id]);

        $this->postAs($this->owner, '/portal/invoices', $this->form('JED-NEW'))->assertSessionHas('error');

        $this->assertNull(Invoice::withoutTenantScope(fn () => Invoice::query()->where('invoice_number', 'JED-NEW')->first()));
    }

    public function test_a_branch_viewer_cannot_issue(): void
    {
        $auditor = $this->memberOf('member');
        app(BranchAccess::class)->assign($this->acme->id, $this->riyadh->id, $auditor->id, 'viewer');

        $this->page($auditor, '/portal/invoices/'.$this->atRiyadh->id)->assertOk();
        $this->postAs($auditor, '/portal/invoices/'.$this->atRiyadh->id.'/issue')->assertForbidden();
        $this->postAs($auditor, '/portal/invoices', $this->form('NOPE-1'))->assertSessionHas('error');
    }

    public function test_the_api_refuses_another_branch(): void
    {
        $this->api($this->cashier)->postJson('/api/invoices', $this->apiPayload(['branch_id' => $this->jeddah->id]))->assertForbidden();
        $this->api($this->cashier)->postJson('/api/invoices', $this->apiPayload(['invoice_number' => 'API-1']))->assertCreated();

        $this->assertSame($this->riyadh->id, $this->stored('API-1')->branch_id);
    }

    public function test_the_dashboard_counts_their_branch(): void
    {
        $this->page($this->cashier, '/portal')
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats): bool => $stats['invoices_month'] === 1);

        $this->page($this->owner, '/portal')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['invoices_month'] === 3);
    }

    private function page(User $user, string $uri): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)->get($uri));
    }

    /**
     * Posted inside a request, with the token the form would carry.
     *
     * @param  array<string, mixed>  $data
     */
    private function postAs(User $user, string $uri, array $data = []): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['_token' => self::TOKEN])
            ->post($uri, ['_token' => self::TOKEN, ...$data]));
    }

    private function api(User $user): self
    {
        return $this->withToken(JWTAuth::claims(['org_id' => $this->acme->id, 'role' => $user->roleIn($this->acme->id)])->fromUser($user));
    }

    private function stored(string $number): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->where('invoice_number', $number)->firstOrFail());
    }

    /**
     * @return array<string, mixed>
     */
    private function form(string $number): array
    {
        return [
            'invoice_number' => $number, 'type' => 'simplified', 'document_type' => 'invoice',
            'issue_date' => now()->toDateString(), 'currency' => 'SAR', 'buyer_name' => 'Walk-in customer', 'buyer_name_ar' => 'عميل نقدي',
            'lines' => [['description' => 'Widget', 'description_ar' => 'قطعة', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '15', 'unit_code' => 'PCE']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function apiPayload(array $overrides): array
    {
        return [
            'invoice_number' => 'API-'.uniqid(), 'type' => 'simplified', 'issue_date' => now()->toDateString(), 'buyer_name' => 'Buyer', 'buyer_name_ar' => 'المشتري',
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ...$overrides,
        ];
    }

    private function memberOf(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function branch(string $name, bool $ready = false): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id, 'name' => $name, 'device_serial' => '1-300000000000003|2-'.$name.'|3-'.strtoupper(Brand::slug()),
            'street' => 'King Road', 'building_number' => '1234', 'district' => 'Centre', 'city' => $name, 'postal_code' => '12345',
            'onboarding_status' => $ready ? Branch::STATUS_ACTIVE : Branch::STATUS_PENDING,
        ]));
    }
}
