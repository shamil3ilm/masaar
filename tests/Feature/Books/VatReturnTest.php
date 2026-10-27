<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\VatReturn as UaeReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Organization\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * The VAT return drafted from sales and the purchases in Books, worked by
 * hand: each box, and the documents behind it.
 */
class VatReturnTest extends TestCase
{
    use BooksFixtures;
    use PintInvoices;
    use RefreshDatabase;

    /**
     * Sales: 10,000 standard (VAT 1,500), 2,000 exported. Purchases: 4,000
     * standard (VAT 600), an import of 5,000 with 750 paid at customs,
     * 100,000 of services from abroad 70% deductible, 1,000 exempt.
     *
     * Box 9: 100,000, adjustment -30,000, deductible 10,500; the 15,000
     * assessed is due as well. Box 13 = 1,500 + 15,000 - (600 + 750 +
     * 10,500) = 4,650: the reverse charge costs its 30%, 4,500. With a
     * correction of 100 and 200 carried forward, box 16 is 4,550.
     */
    public function test_saudi_boxes_add_up(): void
    {
        $acme = $this->saudi();
        $this->sale($acme, [[10000, 15]]);
        $this->sale($acme, [[2000, 0, 'Z', 'VATEX-SA-32']]);
        $local = $this->supplier($acme);
        $abroad = $this->supplier($acme, ['name' => 'London Law LLP', 'country_code' => 'GB', 'vat_number' => null]);
        $this->purchase($acme, $local, [[PurchaseTreatment::Standard, '4000']]);
        $this->purchase($acme, $local, [[PurchaseTreatment::Import, '5000', '750']]);
        $this->purchase($acme, $abroad, [[PurchaseTreatment::ReverseCharge, '100000']], ['share' => '70']);
        $this->purchase($acme, $local, [[PurchaseTreatment::Exempt, '1000']]);
        $this->purchase($acme, $local, [[PurchaseTreatment::OutOfScope, '250', null, true]]);

        $draft = $this->saudiDraft((string) $acme->id, ['corrections' => 100.0, 'carried_forward' => 200.0]);

        $this->assertSame([10000.0, 0.0, 1500.0], $this->figures($draft, '1'));
        $this->assertSame([2000.0, 0.0, 0.0], $this->figures($draft, '4'));
        $this->assertSame([12000.0, 0.0, 1500.0], $this->figures($draft, '6'));
        $this->assertSame([4000.0, 0.0, 600.0], $this->figures($draft, '7'));
        $this->assertSame([5000.0, 0.0, 750.0], $this->figures($draft, '8'));
        $this->assertSame([100000.0, -30000.0, 10500.0], $this->figures($draft, '9'));
        $this->assertSame([1000.0, 0.0, 0.0], $this->figures($draft, '11'));
        $this->assertSame([110000.0, -30000.0, 11850.0], $this->figures($draft, '12'));
        $this->assertSame(15000.0, $draft->box('9-due')->vat);
        $this->assertSame(4650.0, $draft->box('13')->vat);
        $this->assertSame(4550.0, $draft->box('16')->vat);
        $this->assertSame(250.0, $draft->box('oos-purchases')->amount);
        $this->assertSame(250.0, $draft->box('capital')->amount);
        $this->assertCount(1, $draft->box('9')->entries);
        $this->assertSame('London Law LLP', $draft->box('9')->entries[0]->party);
    }

    /**
     * A supplier's credit note of 500 (VAT 75) against last month's invoice
     * is an adjustment to box 7; one against this month's nets the amount.
     */
    public function test_earlier_period_credit_is_adjustment(): void
    {
        $acme = $this->saudi();
        $supplier = $this->supplier($acme);
        $earlier = $this->purchase($acme, $supplier, [[PurchaseTreatment::Standard, '2000']], ['date' => now()->subMonthNoOverflow()->startOfMonth()->toDateString()]);
        $current = $this->purchase($acme, $supplier, [[PurchaseTreatment::Standard, '1000']]);
        $this->purchase($acme, $supplier, [[PurchaseTreatment::Standard, '500']], ['type' => 'credit_note', 'original' => $earlier->id]);
        $this->purchase($acme, $supplier, [[PurchaseTreatment::Standard, '100']], ['type' => 'credit_note', 'original' => $current->id]);

        $this->assertSame([900.0, -500.0, 60.0], $this->figures($this->saudiDraft((string) $acme->id), '7'));
    }

    /**
     * Dubai company: 1,000 (VAT 50) from head office in box 1b, 2,000 (VAT
     * 100) from its Abu Dhabi branch in 1a, 300 zero rated. Purchases: 400
     * (VAT 20) and 1,000 of services from abroad, 50 assessed and
     * recovered. Due 200, recoverable 70, payable 130.
     */
    public function test_uae_sales_split_by_emirate(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $branch = Branch::withoutTenantScope(fn () => Branch::create(['org_id' => $gulf->id, 'name' => 'Abu Dhabi', 'emirate' => 'AUH', 'device_serial' => 'AUH-1', 'street' => 'Corniche Road', 'building_number' => '1', 'district' => 'Al Markaziyah', 'postal_code' => '00000', 'city' => 'Mussafah', 'country_code' => 'AE']));
        $period = ['issue_date' => now()->toDateString(), 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00'];
        $this->uaeInvoice($gulf, ['invoice_number' => 'AE-1', ...$period]);
        $this->uaeInvoice($gulf, ['invoice_number' => 'AE-2', 'branch_id' => $branch->id, ...$period, 'subtotal' => '2000.00', 'tax_amount' => '100.00', 'total' => '2100.00'],
            [$this->uaeLine(['net_amount' => 2000, 'unit_price' => 1000, 'tax_amount' => 100, 'line_total' => 2100])]);
        $this->uaeInvoice($gulf, ['invoice_number' => 'AE-3', ...$period, 'subtotal' => '300.00', 'tax_amount' => '0.00', 'total' => '300.00'],
            [$this->uaeLine(['tax_category' => 'Z', 'tax_rate' => 0, 'tax_amount' => 0, 'net_amount' => 300, 'unit_price' => 150, 'line_total' => 300])]);
        $this->purchase($gulf, $this->supplier($gulf, ['name' => 'Dubai Stationers', 'country_code' => 'AE']), [[PurchaseTreatment::Standard, '400']], ['currency' => 'AED']);
        $this->purchase($gulf, $this->supplier($gulf, ['name' => 'Cloud Hosting Ltd', 'country_code' => 'IE', 'vat_number' => null]), [[PurchaseTreatment::ReverseCharge, '1000']], ['currency' => 'AED']);

        $draft = app(UaeReturn::class)->draft((string) $gulf->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

        $this->assertSame('AED', $draft->currency);
        $this->assertSame([2000.0, null, 100.0], $this->figures($draft, '1a'));
        $this->assertSame([1000.0, null, 50.0], $this->figures($draft, '1b'));
        $this->assertSame([0.0, null, 0.0], $this->figures($draft, '1c'));
        $this->assertSame([1000.0, null, 50.0], $this->figures($draft, '3'));
        $this->assertSame([300.0, null, null], $this->figures($draft, '4'));
        $this->assertSame([400.0, 0.0, 20.0], $this->figures($draft, '9'));
        // Box 10 carries the reverse-charge expenses' amount with their VAT,
        // so box 11 totals both columns over the same expenses (H-68).
        $this->assertSame([1000.0, null, 50.0], $this->figures($draft, '10'));
        $this->assertSame([1400.0, 0.0, 70.0], $this->figures($draft, '11'));
        $this->assertSame(200.0, $draft->box('12')->vat);
        $this->assertSame(70.0, $draft->box('13')->vat);
        $this->assertSame(130.0, $draft->box('14')->vat);
        $this->assertSame('AE-2', $draft->box('1a')->entries[0]->number);
    }

    /**
     * Two imports of goods by a Dubai registrant, worked by hand:
     *
     * - 10,000 cleared under its own customs code, VAT accounted for in the
     *   return (Cabinet Decision No. 52 of 2017 Art 48(1), (4)(b)): box 6
     *   10,000 / 500 due, and box 10 10,000 / 500 recoverable.
     * - 4,000 on which 200 was paid at customs before release (Art 50(1)):
     *   nothing more is due, so it is not in box 6; the 200 is input tax
     *   (Federal Decree-Law No. 8 of 2017 Art 54), box 9 4,000 / 200.
     *
     * Due 500, recoverable 700, payable -200.
     */
    public function test_uae_customs_paid_import_is_input(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $abroad = $this->supplier($gulf, ['name' => 'Shenzhen Parts', 'country_code' => 'CN', 'vat_number' => null]);
        $this->purchase($gulf, $abroad, [[PurchaseTreatment::ImportInReturn, '10000']], ['currency' => 'AED']);
        $this->purchase($gulf, $abroad, [[PurchaseTreatment::Import, '4000', '200']], ['currency' => 'AED']);

        $draft = app(UaeReturn::class)->draft((string) $gulf->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

        $this->assertSame([10000.0, null, 500.0], $this->figures($draft, '6'));
        $this->assertSame([4000.0, 0.0, 200.0], $this->figures($draft, '9'));
        $this->assertSame([10000.0, null, 500.0], $this->figures($draft, '10'));
        $this->assertSame(500.0, $draft->box('12')->vat);
        $this->assertSame(700.0, $draft->box('13')->vat);
        $this->assertSame(-200.0, $draft->box('14')->vat);
    }

    /**
     * Saudi Arabia: an import whose VAT is accounted for through the reverse
     * charge (deferred) is box 9 of ZATCA's return, and due as well.
     */
    public function test_saudi_deferred_import_is_box_9(): void
    {
        $acme = $this->saudi();
        $abroad = $this->supplier($acme, ['name' => 'Shenzhen Parts', 'country_code' => 'CN', 'vat_number' => null]);
        $this->purchase($acme, $abroad, [[PurchaseTreatment::ImportInReturn, '2000']]);

        $draft = $this->saudiDraft((string) $acme->id);

        $this->assertSame([2000.0, 0.0, 300.0], $this->figures($draft, '9'));
        $this->assertSame(300.0, $draft->box('9-due')->vat);
        $this->assertSame(0.0, $draft->box('13')->vat);
    }

    /**
     * Last month's invoice of 1,000 (VAT 150), received this month, is this
     * month's input VAT (KSA VAT IR Art 49(7)-(8)): it holds the invoice now.
     */
    public function test_received_date_decides_the_period(): void
    {
        $acme = $this->saudi();
        $supplier = $this->supplier($acme);
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->purchase($acme, $supplier, [[PurchaseTreatment::Standard, '1000']], ['date' => $lastMonth, 'received' => now()->toDateString()]);
        $this->purchase($acme, $supplier, [[PurchaseTreatment::Standard, '400']], ['date' => $lastMonth]);

        $this->assertSame([1000.0, 0.0, 150.0], $this->figures($this->saudiDraft((string) $acme->id), '7'));
    }

    /**
     * A branch with no emirate, in a company with none, and a buyer with
     * none: box 1x, with the way to fix it.
     */
    public function test_unknown_emirate_is_box_1x(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization(['emirate' => null]);
        $branch = Branch::withoutTenantScope(fn () => Branch::create(['org_id' => $gulf->id, 'name' => 'Mussafah', 'device_serial' => 'X-1', 'street' => 'Road 1', 'building_number' => '1', 'district' => '', 'postal_code' => '', 'city' => 'Mussafah', 'country_code' => 'AE']));
        $this->uaeInvoice($gulf, ['invoice_number' => 'AE-9', 'branch_id' => $branch->id, 'issue_date' => now()->toDateString(), 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00'], null, ['buyer_subdivision' => '']);

        $draft = app(UaeReturn::class)->draft((string) $gulf->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());

        $this->assertSame([1000.0, null, 50.0], $this->figures($draft, '1x'));
        $this->assertSame([route('portal.branches.index')], array_values($draft->links));
    }

    /** Existing UAE branches take the one emirate their address names. */
    public function test_backfill_reads_unambiguous_addresses(): void
    {
        $gulf = $this->uaeOrganization();
        $make = fn (string $serial, string $district, string $city) => Branch::withoutTenantScope(fn () => Branch::create(['org_id' => $gulf->id, 'name' => $serial, 'device_serial' => $serial,
            'street' => 'Road', 'building_number' => '1', 'district' => $district, 'postal_code' => '', 'city' => $city, 'country_code' => 'AE']));
        $dubai = $make('B-1', 'Al Barsha', 'Dubai');
        $clash = $make('B-2', 'Sharjah', 'Dubai');
        $none = $make('B-3', 'Industrial Area', 'Mussafah');

        (require database_path('migrations/1805_branch_emirate.php'))->backfill();

        $this->assertSame(['DXB', null, null], [$dubai->fresh()->emirate, $clash->fresh()->emirate, $none->fresh()->emirate]);
    }

    /**
     * @param  array<string, float>  $manual
     */
    private function saudiDraft(string $organizationId, array $manual = []): VatReturnDraft
    {
        return app(SaudiReturn::class)->draft($organizationId, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth(), $manual);
    }

    /** @return array{0: float|null, 1: float|null, 2: float|null} */
    private function figures(VatReturnDraft $draft, string $key): array
    {
        $box = $draft->box($key) ?? $this->fail('No box '.$key);

        return [$box->amount, $box->adjustment, $box->vat];
    }
}
