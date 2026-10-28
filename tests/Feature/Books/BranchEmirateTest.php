<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Organization\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * A UAE company's branch names its emirate, which the VAT return reports
 * its sales in; a Saudi branch is asked for none.
 */
class BranchEmirateTest extends TestCase
{
    use BooksFixtures;
    use PintInvoices;
    use RefreshDatabase;

    public function test_uae_branch_needs_its_emirate(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $address = ['name' => 'Corniche', 'street' => 'Corniche Road', 'city' => 'Abu Dhabi'];

        $this->as($gulf, 'admin')->post(route('portal.branches.store'), $address)->assertSessionHasErrors('emirate');
        $this->as($gulf, 'admin')->post(route('portal.branches.store'), [...$address, 'emirate' => 'XYZ'])->assertSessionHasErrors('emirate');
        $this->as($gulf, 'admin')->post(route('portal.branches.store'), [...$address, 'emirate' => 'AUH'])->assertSessionHasNoErrors();

        $this->assertSame('AUH', Branch::acrossTenants()->where('org_id', $gulf->id)->sole()->emirate);
        $this->asRequest(fn () => $this->as($gulf, 'admin')->get(route('portal.branches.create'))->assertOk()->assertSee('Ras Al Khaimah'));
    }

    public function test_saudi_branch_asks_no_emirate(): void
    {
        $acme = $this->saudi();

        $this->asRequest(fn () => $this->as($acme, 'admin')->get(route('portal.branches.create'))->assertOk()->assertDontSee('name="emirate"', false));
    }
}
