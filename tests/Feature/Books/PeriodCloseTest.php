<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\VatPeriod;
use App\Domains\Books\Services\PurchasePayments;
use App\Domains\Books\Services\Purchases;
use App\Domains\Books\Services\VatPeriods;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\VatReturn as UaeReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * A filed VAT period is closed: its figures are kept as filed, what is
 * dated in it later counts in the next open period or is refused, and only
 * an owner reopens it.
 */
class PeriodCloseTest extends TestCase
{
    use BooksFixtures;
    use PintInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private CarbonImmutable $last;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
        $this->last = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
    }

    /** Last month: a 1,000 sale (VAT 150), filed - and kept as filed. */
    public function test_filing_snapshots_the_return(): void
    {
        $this->sale($this->acme, [[1000, 15]], ['issue_date' => $this->last->addDays(3)->toDateString()]);

        $period = $this->file($this->acme, $this->last);

        $this->assertSame(VatPeriod::FILED, $period->status);
        $this->assertSame(150.0, (float) $period->figures['boxes']['1']['vat']);
        $this->assertSame('SAR', $period->figures['currency']);
    }

    /**
     * A 2,000 purchase (VAT 300) dated last month, recorded after last
     * month was filed, counts from the first of this month (IR Art 49(8)).
     */
    public function test_late_purchase_goes_forward(): void
    {
        $this->file($this->acme, $this->last);
        $purchase = $this->purchase($this->acme, $this->supplier($this->acme), [[PurchaseTreatment::Standard, '2000']], ['date' => $this->last->addDays(10)->toDateString()]);

        $this->assertSame(CarbonImmutable::now()->startOfMonth()->toDateString(), $purchase->received_on->toDateString());
        $this->assertSame([2000.0, 0.0, 300.0], $this->box($this->saudiDraft(CarbonImmutable::now()), '7'));
        $this->assertSame([0.0, 0.0, 0.0], $this->box($this->saudiDraft($this->last), '7'));
    }

    public function test_void_in_filed_period_refused(): void
    {
        $purchase = $this->purchase($this->acme, $this->supplier($this->acme), [[PurchaseTreatment::Standard, '100']], ['date' => $this->last->addDay()->toDateString()]);
        $this->file($this->acme, $this->last);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('which was filed on');

        app(Purchases::class)->void($purchase, 'Typed twice', null);
    }

    /** A sale dated in the filed month after filing: 150 of VAT has moved. */
    public function test_late_sale_shows_as_drift(): void
    {
        $period = $this->file($this->acme, $this->last);
        $this->sale($this->acme, [[1000, 15]], ['issue_date' => $this->last->addDays(5)->toDateString()]);

        $drift = app(VatPeriods::class)->drift($period, $this->saudiDraft($this->last));

        $this->assertContains(['box' => '1', 'label' => 'Standard rated sales', 'vat' => 150.0, 'amount' => 1000.0], $drift);
    }

    /**
     * N2-2: the late sale is carried to the open period's return. Under SAR
     * 5,000 of net VAT it is corrected in that return (KSA IR Art 63);
     * above, ZATCA is told separately. The return page says which.
     */
    public function test_late_sale_reaches_next_return(): void
    {
        $this->file($this->acme, $this->last);
        $this->sale($this->acme, [[1000, 15]], ['issue_date' => $this->last->addDays(5)->toDateString()]);
        $now = CarbonImmutable::now()->startOfMonth();

        $corrections = app(VatPeriods::class)->corrections((string) $this->acme->id, app(SaudiReturn::class), $now);

        $this->assertCount(1, $corrections);
        $this->assertSame([$this->last->toDateString(), 150.0, true], [$corrections[0]['from'], $corrections[0]['vat'], $corrections[0]['within']]);

        $this->sale($this->acme, [[40000, 15]], ['issue_date' => $this->last->addDays(6)->toDateString()]);
        $this->assertFalse(app(VatPeriods::class)->corrections((string) $this->acme->id, app(SaudiReturn::class), $now)[0]['within']);

        $this->as($this->acme, 'owner')->get(route('portal.books.vat-return', ['from' => $now->toDateString(), 'to' => $now->endOfMonth()->toDateString()]))
            ->assertOk()
            ->assertSee(__('voluntary disclosure'));
    }

    /**
     * UAE: 1,000 + 50 held until paid, received last month; last month
     * filed; a payment then dated last month is recovered this month.
     */
    public function test_late_payment_recovers_next_period(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $purchase = $this->purchase($gulf, $this->supplier($gulf), [[PurchaseTreatment::Standard, '1000']], ['currency' => 'AED', 'paid_later' => true, 'date' => $this->last->addDay()->toDateString()]);
        $this->file($gulf, $this->last, UaeReturn::class);
        app(PurchasePayments::class)->record($purchase, '1050', $this->last->addDays(20));

        $draft = fn (CarbonImmutable $month): VatReturnDraft => app(UaeReturn::class)->draft((string) $gulf->id, $month->startOfMonth(), $month->endOfMonth()->startOfDay());

        $this->assertSame(0.0, $draft($this->last)->box('9')->vat);
        $this->assertSame(50.0, $draft(CarbonImmutable::now())->box('9')->vat);
    }

    /** An accountant files; a member may not; only an owner reopens, with a reason on the log. */
    public function test_owner_alone_reopens_with_reason(): void
    {
        $query = ['from' => $this->last->toDateString(), 'to' => $this->last->endOfMonth()->toDateString(), 'filed_on' => now()->toDateString()];

        $this->as($this->acme, 'member')->post(route('portal.books.vat-return.file'), $query)->assertForbidden();
        $this->as($this->acme, 'accountant')->post(route('portal.books.vat-return.file'), $query)->assertSessionHas('success', 'VAT period marked filed.');

        $period = VatPeriod::acrossTenants()->sole();
        $this->as($this->acme, 'admin')->post(route('portal.books.vat-periods.reopen', $period->id), ['reason' => 'Late invoice'])->assertForbidden();
        $this->as($this->acme, 'owner')->post(route('portal.books.vat-periods.reopen', $period->id), ['reason' => 'Late invoice'])->assertSessionHas('success');

        $this->assertSame([VatPeriod::REOPENED, 'Late invoice'], [$period->fresh()->status, $period->fresh()->reopen_reason]);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'books.vat_period.reopened')->exists());
        $this->asRequest(fn () => $this->as($this->acme, 'viewer')->get(route('portal.books.vat-return'))->assertOk()->assertSee('Reopened: Late invoice'));
    }

    /**
     * A period ends on a month's last day and runs one month or three; once
     * one is filed, the next starts the day after it - no month left out.
     */
    public function test_filing_checks_period_shape_and_gaps(): void
    {
        $refused = function (CarbonImmutable $from, CarbonImmutable $to, string $because): void {
            try {
                app(VatPeriods::class)->file((string) $this->acme->id, $from, $to, CarbonImmutable::now(), $this->saudiDraft($from), null);
                $this->fail('Filed '.$from->toDateString().' to '.$to->toDateString());
            } catch (DomainException $e) {
                $this->assertStringContainsString($because, $e->getMessage());
            }
        };
        $three = $this->last->subMonthsNoOverflow(2);

        $refused($three, $three->addDays(9), 'calendar month or a quarter');
        $refused($three, $three->addMonthsNoOverflow(1)->endOfMonth()->startOfDay(), 'calendar month or a quarter');

        $this->file($this->acme, $three);
        $refused($this->last, $this->last->endOfMonth()->startOfDay(), 'is not filed yet');
        $this->file($this->acme, $three->addMonthNoOverflow());
        $this->assertSame(VatPeriod::FILED, $this->file($this->acme, $this->last)->status);
    }

    /** @param class-string $return */
    private function file(Organization $organization, CarbonImmutable $month, string $return = SaudiReturn::class): VatPeriod
    {
        $draft = app($return)->draft((string) $organization->id, $month, $month->endOfMonth()->startOfDay());

        return app(VatPeriods::class)->file((string) $organization->id, $month, $month->endOfMonth()->startOfDay(), CarbonImmutable::now(), $draft, null);
    }

    private function saudiDraft(CarbonImmutable $month): VatReturnDraft
    {
        return app(SaudiReturn::class)->draft((string) $this->acme->id, $month->startOfMonth(), $month->endOfMonth()->startOfDay());
    }

    /** @return array{0: float|null, 1: float|null, 2: float|null} */
    private function box(VatReturnDraft $draft, string $key): array
    {
        $box = $draft->box($key);

        return [$box->amount, $box->adjustment, $box->vat];
    }
}
