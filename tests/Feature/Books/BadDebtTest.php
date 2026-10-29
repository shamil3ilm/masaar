<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Services\CustomerPayments;
use App\Domains\Books\Services\Receivables;
use App\Domains\Books\Services\SalesBadDebts;
use App\Domains\Books\Services\VatPeriods;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\VatReturn as UaeReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * Bad-debt relief on a sale never paid for, worked by hand: KSA VAT
 * Implementing Regulations Art 40(7)-(9); UAE Decree-Law Art 64(1).
 */
class BadDebtTest extends TestCase
{
    use BooksFixtures;
    use PintInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private CarbonImmutable $sold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
        $this->sold = CarbonImmutable::now()->subMonths(13)->startOfMonth();
    }

    /**
     * 10,000 + 1,500 sold thirteen months ago, declared, never paid: the
     * whole 1,500 comes off box 1 this month. 2,300 paid later gives back a
     * fifth: 300.
     */
    public function test_saudi_relief_and_later_payment(): void
    {
        $invoice = $this->sale($this->acme, [[10000, 15]], ['issue_date' => $this->sold->addDays(2)->toDateString()]);
        $this->file($this->acme, $this->sold, SaudiReturn::class);

        $this->claim($this->acme, $invoice, ['certificate' => 'CPA-2026-14', 'unrelated' => true]);

        $this->assertSame([0.0, -10000.0, -1500.0], $this->box($this->saudiDraft(), '1'));
        $row = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today())->firstWhere('number', 'INV-1');
        $this->assertSame([11500.0, 0.0, Receivables::WRITTEN_OFF], [$row['written_off'], $row['balance'], $row['status']]);

        app(CustomerPayments::class)->record($invoice, '2300', CarbonImmutable::today(), PaymentMethod::BankTransfer);

        $this->assertSame([0.0, -8000.0, -1200.0], $this->box($this->saudiDraft(), '1'));
        $this->assertSame(9200.0, app(SalesBadDebts::class)->active($invoice));
    }

    public function test_saudi_conditions_are_enforced(): void
    {
        $recent = $this->sale($this->acme, [[1000, 15]], ['issue_date' => now()->subMonths(6)->toDateString()]);
        $this->file($this->acme, CarbonImmutable::now()->subMonths(6)->startOfMonth(), SaudiReturn::class);
        $this->assertRefused($this->acme, $recent, ['certificate' => 'C-1', 'unrelated' => true], 'needs 12 months');

        $undeclared = $this->sale($this->acme, [[1000, 15]], ['issue_date' => $this->sold->toDateString()]);
        $this->assertRefused($this->acme, $undeclared, ['certificate' => 'C-1', 'unrelated' => true], 'declared on a return you filed');

        $this->file($this->acme, $this->sold, SaudiReturn::class);
        $this->assertRefused($this->acme, $undeclared, ['unrelated' => true], 'certificate');
        $this->assertRefused($this->acme, $undeclared, ['certificate' => 'C-1'], 'not a related person');

        $large = $this->sale($this->acme, [[100000, 15]], ['issue_date' => $this->sold->addDay()->toDateString()]);
        $this->assertRefused($this->acme, $large, ['certificate' => 'C-1', 'unrelated' => true], 'formal recovery failed');
    }

    /**
     * Two sales of 11,500 thirteen months ago; this month a 2,300 credit
     * note on both takes 1,150 off each (300 VAT in Adjustments). Relief on
     * INV-1 is on the 10,350 left - 1,350 VAT, never the 150 again: box 1
     * gives -11,000 / -1,650.
     */
    public function test_relief_follows_note_on_several(): void
    {
        $one = $this->sale($this->acme, [[10000, 15]], ['issue_date' => $this->sold->addDays(2)->toDateString()]);
        $this->sale($this->acme, [[10000, 15]], ['issue_date' => $this->sold->addDays(3)->toDateString()]);
        $this->file($this->acme, $this->sold, SaudiReturn::class);
        $this->sale($this->acme, [[2000, 15]], ['document_type' => 'credit_note', 'billing_ref' => 'INV-1, INV-2']);

        $this->claim($this->acme, $one, ['certificate' => 'CPA-2026-14', 'unrelated' => true]);

        $this->assertSame(10350.0, app(SalesBadDebts::class)->active($one));
        $this->assertSame([0.0, -11000.0, -1650.0], $this->box($this->saudiDraft(), '1'));
    }

    /** 34,500 dollars at 3.75 is SAR 129,375: above SAR 100,000, so evidence is needed. */
    public function test_evidence_limit_counts_in_riyals(): void
    {
        $invoice = $this->sale($this->acme, [[30000, 15]], ['issue_date' => $this->sold->addDays(2)->toDateString(), 'currency' => 'USD', 'exchange_rate' => '3.75']);
        $this->file($this->acme, $this->sold, SaudiReturn::class);

        $this->assertRefused($this->acme, $invoice, ['certificate' => 'C-1', 'unrelated' => true], 'formal recovery failed');
    }

    /** Relief on a dollar sale with no rate to riyals is named, not counted at zero. */
    public function test_relief_without_rate_is_named(): void
    {
        $invoice = $this->sale($this->acme, [[1000, 15]], ['issue_date' => $this->sold->addDays(2)->toDateString(), 'currency' => 'USD']);
        $this->file($this->acme, $this->sold, SaudiReturn::class);
        $this->claim($this->acme, $invoice, ['certificate' => 'C-1', 'unrelated' => true, 'evidence' => 'Judgment 7']);

        $draft = $this->saudiDraft();

        $this->assertSame([0.0, 0.0, 0.0], $this->box($draft, '1'));
        $this->assertStringContainsString('INV-1', implode(' ', $draft->problems));
    }

    /**
     * UAE: 2,000 + 100 sold by a Dubai company eight months ago, written
     * off and the customer told: 100 off box 1b.
     */
    public function test_uae_relief_needs_notice(): void
    {
        config(['peppol.countries.AE.enabled' => true]);
        $gulf = $this->uaeOrganization();
        $sold = CarbonImmutable::now()->subMonths(8)->startOfMonth();
        $invoice = $this->uaeInvoice($gulf, ['invoice_number' => 'AE-7', 'issue_date' => $sold->addDay()->toDateString(), 'due_date' => $sold->addMonth()->toDateString(),
            'subtotal' => '2000.00', 'tax_amount' => '100.00', 'total' => '2100.00'],
            [$this->uaeLine(['net_amount' => 2000, 'unit_price' => 1000, 'tax_amount' => 100, 'line_total' => 2100])]);
        $this->file($gulf, $sold, UaeReturn::class);

        $this->assertRefused($gulf, $invoice, [], 'customer was told');
        $this->claim($gulf, $invoice, ['notified_on' => CarbonImmutable::today()]);

        $draft = app(UaeReturn::class)->draft((string) $gulf->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()->startOfDay());
        $box = $draft->box('1b');
        $this->assertSame([0.0, -2000.0, -100.0], [$box->amount, $box->adjustment, $box->vat]);
    }

    /** @param array<string, mixed> $claim */
    private function claim(Organization $organization, Invoice $invoice, array $claim): void
    {
        app(SalesBadDebts::class)->claim($organization, $invoice, ['written_off_on' => CarbonImmutable::today(), ...$claim]);
    }

    /** @param array<string, mixed> $claim */
    private function assertRefused(Organization $organization, Invoice $invoice, array $claim, string $because): void
    {
        try {
            $this->claim($organization, $invoice, $claim);
            $this->fail('Relief was granted without: '.$because);
        } catch (DomainException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }

    /** @param class-string $return */
    private function file(Organization $organization, CarbonImmutable $month, string $return): void
    {
        $draft = app($return)->draft((string) $organization->id, $month, $month->endOfMonth()->startOfDay());
        app(VatPeriods::class)->file((string) $organization->id, $month, $month->endOfMonth()->startOfDay(), $month->endOfMonth()->addDays(20), $draft, null);
    }

    private function saudiDraft(): VatReturnDraft
    {
        return app(SaudiReturn::class)->draft((string) $this->acme->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()->startOfDay());
    }

    /** @return array{0: float|null, 1: float|null, 2: float|null} */
    private function box(VatReturnDraft $draft, string $key): array
    {
        $box = $draft->box($key);

        return [$box->amount, $box->adjustment, $box->vat];
    }
}
