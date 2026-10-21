<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * The invoice history: sorted, narrowed and exported as the link says.
 *
 * Every request runs inside asRequest(), so the tenant scope is the one a
 * browser gets; the CSV is held to the same rows as the page, which is the
 * claim that matters - an export of more than the list shows would be a
 * report nobody asked for, and an export of another tenant's rows a leak.
 */
class InvoiceHistoryTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 09:00:00');

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->member = User::factory()->create(['name' => 'Sara Cashier']);
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_amount_range_narrows_the_list(): void
    {
        $this->makeInvoice($this->acme, 'SMALL-1', ['total' => '50.00']);
        $this->makeInvoice($this->acme, 'MID-1', ['total' => '250.00']);
        $this->makeInvoice($this->acme, 'BIG-1', ['total' => '9000.00']);

        $this->page('/portal/invoices?amount_min=100&amount_max=500')
            ->assertOk()
            ->assertSee('MID-1')
            ->assertDontSee('SMALL-1')
            ->assertDontSee('BIG-1');
    }

    public function test_date_preset_reads_the_month(): void
    {
        $this->makeInvoice($this->acme, 'OCT-1', ['issue_date' => '2026-10-02']);
        $this->makeInvoice($this->acme, 'SEP-1', ['issue_date' => '2026-09-28']);

        $this->page('/portal/invoices?period=this_month')
            ->assertOk()
            ->assertSee('OCT-1')
            ->assertDontSee('SEP-1');

        $this->page('/portal/invoices?period=last_month')
            ->assertOk()
            ->assertSee('SEP-1')
            ->assertDontSee('OCT-1');
    }

    public function test_custom_dates_include_the_last_day(): void
    {
        $this->makeInvoice($this->acme, 'IN-1', ['issue_date' => '2026-10-10']);
        $this->makeInvoice($this->acme, 'OUT-1', ['issue_date' => '2026-10-11']);

        $this->page('/portal/invoices?from=2026-10-01&to=2026-10-10')
            ->assertOk()
            ->assertSee('IN-1')
            ->assertDontSee('OUT-1');
    }

    public function test_buyer_filter_matches_vat_number(): void
    {
        $this->makeInvoice($this->acme, 'GULF-1', ['buyer_name' => 'Gulf Supplies', 'buyer_vat_number' => '310122393500003']);
        $this->makeInvoice($this->acme, 'NOOR-1', ['buyer_name' => 'Noor Trading']);

        $this->page('/portal/invoices?buyer=3101223935')
            ->assertOk()
            ->assertSee('GULF-1')
            ->assertDontSee('NOOR-1');
    }

    public function test_the_arabic_name_is_searched(): void
    {
        $this->makeInvoice($this->acme, 'GULF-2', ['buyer_name' => 'Gulf Supplies', 'buyer_name_ar' => 'إمدادات الخليج']);
        $this->makeInvoice($this->acme, 'NOOR-2', ['buyer_name' => 'Noor Trading']);

        $this->page('/portal/invoices?q='.urlencode('إمدادات'))
            ->assertOk()
            ->assertSee('GULF-2')
            ->assertDontSee('NOOR-2');
    }

    public function test_a_wildcard_is_matched_literally(): void
    {
        $this->makeInvoice($this->acme, 'A%B-1', ['buyer_name' => 'Percent Co']);
        $this->makeInvoice($this->acme, 'AXB-1', ['buyer_name' => 'Plain Co']);

        $this->page('/portal/invoices?q='.urlencode('A%B'))
            ->assertOk()
            ->assertSee('Percent Co')
            ->assertDontSee('Plain Co');
    }

    public function test_branch_and_document_narrow_the_list(): void
    {
        $branch = $this->branch('Jeddah');
        $this->makeInvoice($this->acme, 'JED-1', ['branch_id' => $branch->id]);
        $this->makeInvoice($this->acme, 'JED-CN', ['branch_id' => $branch->id, 'document_type' => 'credit_note', 'billing_ref' => 'JED-1']);
        $this->makeInvoice($this->acme, 'HQ-1');

        $this->page('/portal/invoices?branch='.$branch->id.'&document=credit_note')
            ->assertOk()
            ->assertSee('JED-CN')
            ->assertDontSee('JED-1')
            ->assertDontSee('HQ-1');
    }

    /**
     * A branch may have several tills, and the till filter tells them apart.
     */
    public function test_till_narrows_within_a_branch(): void
    {
        $branch = $this->branch('Jeddah');
        $lane = app(Tills::class)->add($branch, 'Express lane');
        $this->makeInvoice($this->acme, 'JED-T1', ['branch_id' => $branch->id, 'device_id' => $branch->id]);
        $this->makeInvoice($this->acme, 'JED-T2', ['branch_id' => $branch->id, 'device_id' => $lane->id]);

        $this->page('/portal/invoices?till='.$lane->id)
            ->assertOk()
            ->assertSee('Express lane · Jeddah')
            ->assertSee('JED-T2')
            ->assertDontSee('JED-T1');
    }

    public function test_another_tenants_branch_narrows_nothing(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $theirs = Branch::withoutTenantScope(fn () => Branch::create($this->address($rival, 'Theirs')));
        $this->makeInvoice($this->acme, 'ACME-1');

        $this->page('/portal/invoices?branch='.$theirs->id)
            ->assertOk()
            ->assertSee('ACME-1')
            ->assertDontSee('Theirs');
    }

    public function test_issued_by_finds_one_persons_invoices(): void
    {
        $other = User::factory()->create(['name' => 'Omar Manager']);
        $other->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->makeInvoice($this->acme, 'SARA-1', ['status' => 'issued', 'issued_by' => $this->member->id]);
        $this->makeInvoice($this->acme, 'OMAR-1', ['status' => 'issued', 'issued_by' => $other->id]);

        $this->page('/portal/invoices?issued_by='.$this->member->id)
            ->assertOk()
            ->assertSee('SARA-1')
            ->assertDontSee('OMAR-1');
    }

    public function test_amount_sorts_largest_first(): void
    {
        $this->makeInvoice($this->acme, 'MID-1', ['total' => '200.00']);
        $this->makeInvoice($this->acme, 'BIG-1', ['total' => '900.00']);
        $this->makeInvoice($this->acme, 'SMALL-1', ['total' => '10.00']);

        $this->page('/portal/invoices?sort=amount')
            ->assertOk()
            ->assertSeeInOrder(['BIG-1', 'MID-1', 'SMALL-1']);

        $this->page('/portal/invoices?sort=amount&dir=asc')
            ->assertOk()
            ->assertSeeInOrder(['SMALL-1', 'MID-1', 'BIG-1']);
    }

    public function test_buyer_sorts_from_the_start(): void
    {
        $this->makeInvoice($this->acme, 'Z-1', ['buyer_name' => 'Zahra Foods']);
        $this->makeInvoice($this->acme, 'A-1', ['buyer_name' => 'Amal Stores']);

        $this->page('/portal/invoices?sort=buyer')
            ->assertOk()
            ->assertSeeInOrder(['Amal Stores', 'Zahra Foods']);
    }

    public function test_chips_say_and_remove_filters(): void
    {
        $this->makeInvoice($this->acme, 'ACME-1', ['status' => 'issued', 'total' => '300.00']);

        $response = $this->page('/portal/invoices?status=issued&amount_min=100')->assertOk();

        $response->assertSee('Filtered by')
            ->assertSee('At least 100')
            ->assertSee('href="'.e(url('/portal/invoices').'?amount_min=100').'"', false)
            ->assertSee('href="'.e(url('/portal/invoices').'?status=issued').'"', false)
            ->assertSee('Clear all');
    }

    public function test_page_size_is_offered_and_kept(): void
    {
        foreach (range(1, 12) as $n) {
            $this->makeInvoice($this->acme, sprintf('INV-%02d', $n));
        }

        $response = $this->page('/portal/invoices?per_page=10&status=draft')->assertOk();

        $response->assertSee('per_page=10', false)
            ->assertSee('Showing 1 to 10 of 12');
    }

    public function test_unknown_values_fall_back_to_defaults(): void
    {
        $this->makeInvoice($this->acme, 'ACME-1');

        $this->page('/portal/invoices?per_page=0&sort=nonsense&dir=sideways&amount_min=1e9&from=2026-02-31')
            ->assertOk()
            ->assertSee('ACME-1')
            ->assertDontSee('Filtered by');
    }

    public function test_notes_list_holds_only_notes(): void
    {
        $this->makeInvoice($this->acme, 'INV-1');
        $this->makeInvoice($this->acme, 'CN-1', ['document_type' => 'credit_note', 'billing_ref' => 'INV-1']);
        $this->makeInvoice($this->acme, 'DN-1', ['document_type' => 'debit_note', 'billing_ref' => 'INV-1']);

        $this->page('/portal/notes')
            ->assertOk()
            ->assertSee('CN-1')
            ->assertSee('DN-1')
            ->assertDontSee('INV-1');
    }

    public function test_csv_holds_exactly_the_filtered_rows(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $this->makeInvoice($this->acme, 'KEEP-1', ['total' => '300.00', 'buyer_name' => '=HYPERLINK("x")']);
        $this->makeInvoice($this->acme, 'DROP-1', ['total' => '10.00']);
        $this->makeInvoice($rival, 'RIVAL-1', ['total' => '300.00']);

        $csv = $this->asRequest(fn (): string => $this->actingAs($this->member)
            ->get('/portal/invoices/export.csv?amount_min=100')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent());

        $this->assertStringContainsString('KEEP-1', $csv);
        $this->assertStringNotContainsString('DROP-1', $csv);
        $this->assertStringNotContainsString('RIVAL-1', $csv);
        $this->assertStringContainsString('\'=HYPERLINK', $csv);
        $this->assertCount(2, array_filter(explode("\n", trim($csv))));
    }

    public function test_the_export_link_carries_the_filters(): void
    {
        $this->makeInvoice($this->acme, 'ACME-1');

        $this->page('/portal/invoices?status=draft&sort=amount')
            ->assertOk()
            ->assertSee(e(url('/portal/invoices/export.csv').'?status=draft&sort=amount'), false);
    }

    /**
     * The page as the member's browser gets it.
     */
    private function page(string $uri): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($this->member)->get($uri));
    }

    private function branch(string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create($this->address($this->acme, $name)));
    }

    /**
     * @return array<string, string>
     */
    private function address(Organization $organization, string $name): array
    {
        return [
            'org_id' => $organization->id, 'name' => $name, 'device_serial' => '1-'.$name.'|2-001|3-'.strtoupper(Brand::slug()),
            'street' => 'King Road', 'building_number' => '1234', 'district' => 'Centre', 'city' => 'Riyadh', 'postal_code' => '12345',
        ];
    }
}
