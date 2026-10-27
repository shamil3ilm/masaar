<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\VatReturn as UaeReturn;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * Specified goods bought from a registered UAE supplier, the buyer
 * accounting for the VAT (Federal Decree-Law No. 8 of 2017 Art 48(3), (8)):
 * output tax in box 3, recovered in box 10.
 */
class DomesticReverseChargeTest extends TestCase
{
    use BooksFixtures;
    use PintInvoices;
    use RefreshDatabase;

    /** 10,000 AED of specified goods: 500 due in box 3, 500 recovered in box 10; net nil. */
    public function test_uae_buyer_declares_both_sides(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $souk = $this->supplier($gulf, ['name' => 'Gold Souk Trading', 'country_code' => 'AE', 'vat_number' => '100555555500003']);

        $purchase = $this->purchase($gulf, $souk, [[PurchaseTreatment::DomesticReverseCharge, '10000', null, false, 'DL8.48.8.1']], ['currency' => 'AED']);

        $this->assertSame(['0.00', '500.00', 'DL8.48.8.1'], [$purchase->vat_tax, $purchase->deductible_vat_tax, $purchase->lines[0]->goods_type]);
        $draft = app(UaeReturn::class)->draft((string) $gulf->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()->startOfDay());
        $this->assertSame([10000.0, 500.0], [$draft->box('3')->amount, $draft->box('3')->vat]);
        $this->assertSame([10000.0, 500.0], [$draft->box('10')->amount, $draft->box('10')->vat]);
        $this->assertSame(0.0, $draft->box('14')->vat);
    }

    public function test_conditions_refuse_the_rest(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $local = $this->supplier($gulf, ['name' => 'Gold Souk Trading', 'country_code' => 'AE', 'vat_number' => '100555555500003']);
        $abroad = $this->supplier($gulf, ['name' => 'London Bullion', 'country_code' => 'GB', 'vat_number' => 'GB123']);
        $acme = $this->saudi();

        foreach ([
            [$gulf, $local, null, 'choose which specified goods'],
            [$gulf, $abroad, 'DL8.48.8.1', 'supplier registered for VAT in AE'],
            [$acme, $this->supplier($acme), 'DL8.48.8.1', 'only for a business registered for VAT'],
        ] as [$organization, $supplier, $goods, $because]) {
            try {
                $this->purchase($organization, $supplier, [[PurchaseTreatment::DomesticReverseCharge, '100', null, false, $goods]], ['currency' => $organization->country === 'AE' ? 'AED' : 'SAR']);
                $this->fail('Accepted: '.$because);
            } catch (ValidationException $e) {
                $this->assertStringContainsString($because, $e->errors()['lines.0.goods_type'][0]);
            }
        }
    }
}
