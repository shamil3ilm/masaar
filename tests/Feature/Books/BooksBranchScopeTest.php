<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Auth\Models\User;
use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Services\SalesBadDebts;
use App\Domains\Books\Services\VatPeriods;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\WorkingBranches;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Books and branches.
 *
 * A VAT return is the taxable person's, one per period for the whole
 * organization (KSA VAT Implementing Regulations Art 59; UAE VAT
 * Executive Regulation, Cabinet Decision 52 of 2017, Art 64), so it is drafted from every branch for sales
 * and purchases alike, whatever the branch switcher says, and only someone
 * who sees every branch opens it. Receivables, an invoice's payments and a
 * statement follow the branches the person may see, like every other list.
 *
 * Two branches: North sells 1,000 + 150 VAT, South 2,000 + 300; one
 * purchase of 500 + 75 for the organization.
 */
class BooksBranchScopeTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    private Organization $acme;

    private Branch $north;

    private Branch $south;

    private Customer $customer;

    private Invoice $northSale;

    private Invoice $southSale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
        $this->north = $this->branch('North');
        $this->south = $this->branch('South');
        $this->customer = Customer::withoutTenantScope(fn () => Customer::query()->create(['org_id' => $this->acme->id, 'name' => 'Gulf Supplies', 'vat_number' => '310122393500003']));
        $this->northSale = $this->sale($this->acme, [[1000, 15]], ['invoice_number' => 'NORTH-1', 'branch_id' => $this->north->id, 'customer_id' => $this->customer->id]);
        $this->southSale = $this->sale($this->acme, [[2000, 15]], ['invoice_number' => 'SOUTH-1', 'branch_id' => $this->south->id, 'customer_id' => $this->customer->id]);
        $this->purchase($this->acme, $this->supplier($this->acme), [[PurchaseTreatment::Standard, '500']]);
    }

    public function test_return_ignores_the_branch_switcher(): void
    {
        $accountant = $this->member($this->acme, 'accountant');
        app(WorkingBranches::class)->choose((string) $accountant->id, (string) $this->acme->id, (string) $this->north->id);

        $csv = $this->asRequest(fn () => $this->actingAs($accountant)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.books.vat-return.csv'))->assertOk()->streamedContent());

        $this->assertStringContainsString('1,"Standard rated sales",3000.00,0.00,450.00,2', $csv);
        $this->assertStringContainsString('7,"Standard rated domestic purchases",500.00,0.00,75.00,1', $csv);

        $this->asRequest(fn () => $this->actingAs($accountant)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.books.vat-return'))->assertOk()->assertSee(__('This return covers every branch of the organization.')));
    }

    public function test_branch_limited_member_cannot_open_return(): void
    {
        $member = $this->limitedTo($this->north);

        $reason = __('The VAT return covers every branch of the organization. Ask an owner, an administrator or the accountant to open it.');

        foreach (['portal.books.vat-return', 'portal.books.vat-return.csv', 'portal.books.vat-return.pdf'] as $route) {
            $this->asRequest(fn () => $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])->get(route($route)))
                ->assertRedirect(route('portal.books.index'))
                ->assertSessionHas('error', $reason);
        }

        $this->asRequest(fn () => $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])->get(route('portal.books.index')))
            ->assertOk()->assertDontSee(route('portal.books.vat-return'));
    }

    public function test_receivables_follow_branch_access(): void
    {
        $member = $this->limitedTo($this->north);

        $csv = $this->asRequest(fn () => $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.books.receivables.csv'))->assertOk()->streamedContent());

        $this->assertStringContainsString('NORTH-1', $csv);
        $this->assertStringNotContainsString('SOUTH-1', $csv);

        $this->asRequest(fn () => $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.books.invoices.show', $this->southSale->id)))->assertNotFound();
        $this->asRequest(fn () => $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.books.invoices.show', $this->northSale->id)))->assertOk();
    }

    public function test_statement_follows_branch_access(): void
    {
        $member = $this->limitedTo($this->north);

        $this->asRequest(fn () => $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.books.statements.show', $this->customer->id)))
            ->assertOk()
            ->assertSee('NORTH-1')->assertDontSee('SOUTH-1')
            ->assertSee(__('This statement covers only the branches you see, not the customer\'s whole account.'));

        $this->asRequest(fn () => $this->as($this->acme, 'admin')->get(route('portal.books.statements.show', $this->customer->id)))
            ->assertOk()->assertSee('SOUTH-1')
            ->assertDontSee(__('This statement covers only the branches you see, not the customer\'s whole account.'));
    }

    /**
     * Bad-debt relief weighs what the customer owes the organization (KSA
     * VAT IR Art 40(7): above SAR 100,000, evidence that recovery failed),
     * not what the branch switcher shows: North's 1,150 alone is under the
     * limit, South's 115,000 puts the customer over it.
     */
    public function test_relief_weighs_every_branch(): void
    {
        $sold = CarbonImmutable::now()->subMonths(13)->startOfMonth();
        $north = $this->sale($this->acme, [[1000, 15]], ['invoice_number' => 'NORTH-OLD', 'branch_id' => $this->north->id, 'customer_id' => $this->customer->id, 'issue_date' => $sold->addDay()->toDateString()]);
        $this->sale($this->acme, [[100000, 15]], ['invoice_number' => 'SOUTH-OLD', 'branch_id' => $this->south->id, 'customer_id' => $this->customer->id, 'issue_date' => $sold->addDay()->toDateString()]);
        $draft = app(SaudiReturn::class)->draft((string) $this->acme->id, $sold, $sold->endOfMonth()->startOfDay());
        app(VatPeriods::class)->file((string) $this->acme->id, $sold, $sold->endOfMonth()->startOfDay(), $sold->endOfMonth()->addDays(20), $draft, null);

        $owner = $this->member($this->acme, 'owner');
        app(WorkingBranches::class)->choose((string) $owner->id, (string) $this->acme->id, (string) $this->north->id);

        $this->asRequest(fn () => $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id, '_token' => 'relief'])
            ->post(route('portal.books.invoices.bad-debt', $north->id), ['_token' => 'relief', 'written_off_on' => CarbonImmutable::today()->toDateString(), 'certificate' => 'CPA-1', 'unrelated' => '1']))
            ->assertSessionHas('error', fn (?string $error): bool => str_contains((string) $error, 'formal recovery failed'));

        $this->assertSame(0.0, app(SalesBadDebts::class)->active($north));
    }

    private function branch(string $name): Branch
    {
        return Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id, 'name' => $name, 'street' => 'Olaya Street', 'building_number' => '1234',
            'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345', 'device_serial' => strtolower($name).'-1',
        ]));
    }

    private function limitedTo(Branch $branch): User
    {
        $member = $this->member($this->acme, 'member');
        app(BranchAccess::class)->assign((string) $this->acme->id, (string) $branch->id, (string) $member->id, 'member');

        return $member;
    }
}
