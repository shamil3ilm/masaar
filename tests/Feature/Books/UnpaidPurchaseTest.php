<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Books\Services\PurchaseLedger;
use App\Domains\Books\Services\PurchasePayments;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * KSA VAT Implementing Regulations Art 40(10) and (11), worked by hand: a
 * purchase not paid in full twelve months after its supply gives back the
 * input VAT on what is unpaid at that date, in the period that date falls
 * in; a later payment reclaims it, in the payment's period.
 *
 * 10,000 + VAT 1,500 = 11,500, supplied on the 10th of this month a year
 * ago; 4,600 paid then. On the 10th of this month 6,900 is unpaid: 1,500 x
 * 6,900 / 11,500 = 900.00 comes off box 7, with -6,000 of the amount. Next
 * month 2,300 is paid: 1,500 x 2,300 / 11,500 = 300.00 is reclaimed, with
 * 2,000 of the amount.
 */
class UnpaidPurchaseTest extends TestCase
{
    use BooksFixtures;
    use PintInvoices;
    use RefreshDatabase;

    public function test_unpaid_year_gives_back_input(): void
    {
        $acme = $this->saudi();
        $this->yearOld($acme);

        $this->assertSame([0.0, -6000.0, -900.0], $this->figures($this->draft((string) $acme->id, CarbonImmutable::now()), '7'));
        // The month before, nothing: twelve months have not passed.
        $this->assertSame([0.0, 0.0, 0.0], $this->figures($this->draft((string) $acme->id, CarbonImmutable::now()->subMonthNoOverflow()), '7'));
        $this->assertSame(-900.0, $this->draft((string) $acme->id, CarbonImmutable::now())->box('12')->vat);
    }

    public function test_later_payment_reclaims_input(): void
    {
        $acme = $this->saudi();
        $purchase = $this->yearOld($acme);
        $next = CarbonImmutable::now()->addMonthNoOverflow()->startOfMonth();
        app(PurchasePayments::class)->record($purchase, '2300', $next);

        $this->assertSame([0.0, 2000.0, 300.0], $this->figures($this->draft((string) $acme->id, $next), '7'));
        // This month's reversal is unchanged by next month's payment.
        $this->assertSame([0.0, -6000.0, -900.0], $this->figures($this->draft((string) $acme->id, CarbonImmutable::now()), '7'));
    }

    public function test_paid_in_time_changes_nothing(): void
    {
        $acme = $this->saudi();
        $purchase = $this->yearOld($acme);
        app(PurchasePayments::class)->record($purchase, '6900', $this->deadline()->subDays(2));

        $this->assertSame([0.0, 0.0, 0.0], $this->figures($this->draft((string) $acme->id, CarbonImmutable::now()), '7'));
        $this->assertSame([], app(PurchaseLedger::class)->unpaidOver((string) $acme->id, CarbonImmutable::now()->endOfMonth(), 12));
    }

    public function test_unpaid_list_names_the_purchase(): void
    {
        $acme = $this->saudi();
        $this->yearOld($acme);
        $draft = $this->draft((string) $acme->id, CarbonImmutable::now());

        $this->assertStringContainsString('LATE-12', implode(' ', $draft->problems));

        $this->asRequest(fn () => $this->as($acme, 'accountant')->get(route('portal.books.purchases.unpaid', ['as_at' => CarbonImmutable::now()->endOfMonth()->toDateString()]))->assertOk()
            ->assertSee('LATE-12')->assertSee('6,900.00')->assertSee('900.00')->assertSee($this->deadline()->toDateString()));
    }

    public function test_uae_has_no_unpaid_list(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();

        $this->asRequest(fn () => $this->as($gulf, 'accountant')->get(route('portal.books.purchases.unpaid'))->assertNotFound());
    }

    private function deadline(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth()->addDays(9);
    }

    private function yearOld(Organization $organization): PurchaseInvoice
    {
        $supplied = $this->deadline()->subYearNoOverflow();
        $purchase = $this->purchase($organization, $this->supplier($organization), [[PurchaseTreatment::Standard, '10000']], ['date' => $supplied->toDateString(), 'number' => 'LATE-12']);
        app(PurchasePayments::class)->record($purchase, '4600', $supplied);

        return $purchase;
    }

    private function draft(string $organizationId, CarbonImmutable $month): VatReturnDraft
    {
        return app(SaudiReturn::class)->draft($organizationId, $month->startOfMonth(), $month->endOfMonth()->startOfDay());
    }

    /** @return array{0: float|null, 1: float|null, 2: float|null} */
    private function figures(VatReturnDraft $draft, string $key): array
    {
        $box = $draft->box($key) ?? $this->fail('No box '.$key);

        return [$box->amount, $box->adjustment, $box->vat];
    }
}
