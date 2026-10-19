<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationGroup;
use App\Domains\Organization\Services\BranchAccess;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * The reports of one branch, all of them with a line per branch, and a
 * group's companies side by side - each figure its country's report of the
 * documents the person may see.
 */
class BranchReportTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private const TOKEN = 'branch-report-token';

    private Organization $acme;

    private Branch $riyadh;

    private Branch $jeddah;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 09:00:00');

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
        $this->riyadh = $this->branch($this->acme, 'Riyadh');
        $this->jeddah = $this->branch($this->acme, 'Jeddah');
        $this->owner = $this->memberOf($this->acme, 'admin');

        $this->issued($this->acme, 'RUH-1', '100.00', $this->riyadh);
        $this->issued($this->acme, 'RUH-2', '50.00', $this->riyadh);
        $this->issued($this->acme, 'JED-1', '30.00', $this->jeddah);
        $this->issued($this->acme, 'HQ-1', '10.00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_combined_reports_break_down_by_branch(): void
    {
        $this->page($this->owner, '/portal/reports')
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['documents'] === 4 && $summary['total_base'] === 190.0)
            ->assertViewHas('branchBreakdown', fn (array $rows): bool => array_column($rows, 'documents', 'name') === ['Riyadh' => 2, 'Jeddah' => 1, 'Head office' => 1]
                && array_column($rows, 'base', 'name') === ['Riyadh' => 150.0, 'Jeddah' => 30.0, 'Head office' => 10.0])
            ->assertSee('By branch');
    }

    public function test_one_branch_reports_alone(): void
    {
        $this->postAs($this->owner, '/portal/branch', ['branch' => $this->jeddah->id]);

        $this->page($this->owner, '/portal/reports')
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['documents'] === 1 && $summary['total_base'] === 30.0)
            ->assertViewHas('branchBreakdown', []);
    }

    public function test_a_cashiers_report_is_their_branch(): void
    {
        $cashier = $this->memberOf($this->acme, 'member');
        app(BranchAccess::class)->assign($this->acme->id, $this->riyadh->id, $cashier->id, 'member');

        $this->page($cashier, '/portal/reports')
            ->assertOk()
            ->assertViewHas('summary', fn (array $summary): bool => $summary['documents'] === 2)
            ->assertViewHas('branchBreakdown', []);

        $csv = $this->asRequest(fn (): string => $this->actingAs($cashier)->get('/portal/reports/invoices.csv')->streamedContent());

        $this->assertStringContainsString('RUH-1', $csv);
        $this->assertStringNotContainsString('JED-1', $csv);
        $this->assertStringNotContainsString('HQ-1', $csv);
    }

    public function test_the_day_close_reads_the_switcher(): void
    {
        $this->postAs($this->owner, '/portal/branch', ['branch' => $this->riyadh->id]);

        $this->page($this->owner, '/portal/reports/close?day=2026-10-15')
            ->assertOk()
            ->assertViewHas('close', fn (array $close): bool => array_sum(array_column($close['branches'], 'documents')) === 2);
    }

    public function test_a_group_shows_its_companies(): void
    {
        $group = OrganizationGroup::query()->create(['name' => 'Acme Group']);
        $this->acme->forceFill(['group_id' => $group->id])->save();
        $logistics = Organization::create(['name' => 'Acme Logistics', 'country' => 'SA', 'vat_number' => '311111111111113', 'group_id' => $group->id, 'status' => 'active']);
        $hidden = Organization::create(['name' => 'Acme Hidden', 'country' => 'SA', 'group_id' => $group->id, 'status' => 'active']);
        $logistics->users()->attach($this->owner->id, ['role' => 'member', 'status' => 'active']);
        $this->issued($logistics, 'LOG-1', '70.00');
        $this->issued($hidden, 'HID-1', '999.00');

        $this->page($this->owner, '/portal/reports/group')
            ->assertOk()
            ->assertViewHas('rows', fn (array $rows): bool => array_column($rows, 'base', 'name') === ['Acme' => 190.0, 'Acme Logistics' => 70.0])
            ->assertViewHas('sums', ['SAR' => ['documents' => 5, 'base' => 260.0, 'vat' => 39.0]])
            ->assertDontSee('Acme Hidden');

        $this->page($this->owner, '/portal')->assertSee('Open the group');
    }

    public function test_outside_a_group_there_is_none(): void
    {
        $this->page($this->owner, '/portal/reports/group')->assertNotFound();
    }

    /**
     * An issued, sealed invoice of one standard-rated line, as the VAT
     * summary counts it.
     */
    private function issued(Organization $organization, string $number, string $net, ?Branch $branch = null): Invoice
    {
        $vat = number_format((float) $net * 0.15, 2, '.', '');
        $total = number_format((float) $net * 1.15, 2, '.', '');

        $invoice = $this->makeInvoice($organization, $number, [
            'branch_id' => $branch?->id,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'subtotal' => $net,
            'tax_amount' => $vat,
            'total' => $total,
            'issue_date' => '2026-10-15',
            'issued_at' => '2026-10-15 08:00:00',
        ]);

        $invoice->lines()->create([
            'description' => 'Item', 'quantity' => '1', 'unit_price' => $net, 'tax_rate' => '15', 'tax_category' => 'S',
            'tax_amount' => $vat, 'line_total' => $total,
        ]);

        // Lines go on a draft; the seal and the verdict come after.
        Invoice::withoutTenantScope(fn () => $invoice->update(['status' => 'accepted', 'hash' => base64_encode(hash('sha256', $number, true))]));

        return $invoice;
    }

    /**
     * As the user, working in Acme: someone in two companies chooses one.
     */
    private function page(User $user, string $uri): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id])->get($uri));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postAs(User $user, string $uri, array $data): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($user)
            ->withSession(['_token' => self::TOKEN])
            ->post($uri, ['_token' => self::TOKEN, ...$data]));
    }

    private function memberOf(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function branch(Organization $organization, string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $organization->id, 'name' => $name, 'device_serial' => '1-'.$organization->id.'|2-'.$name.'|3-'.strtoupper(Brand::slug()),
            'street' => 'King Road', 'building_number' => '1234', 'district' => 'Centre', 'city' => $name, 'postal_code' => '12345',
        ]));
    }
}
