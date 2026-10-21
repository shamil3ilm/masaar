<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Books\Services\PurchasePayments;
use App\Domains\Books\Services\Purchases;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\VatReturn as UaeReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;

/**
 * UAE input tax that waits on payment (Federal Decree-Law No. 8 of 2017
 * Art 55(1), Art 64(2); Cabinet Decision No. 52 of 2017 Art 54), worked by
 * hand.
 */
class InputTaxOnPaymentTest extends TestCase
{
    use BooksFixtures;
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        $this->gulf = $this->uaeOrganization();
    }

    /**
     * 1,000 + VAT 50 = 1,050, not to be paid within six months: nothing
     * recovered on receipt; 420 paid this month recovers 50 x 420 / 1,050 =
     * 20.00 (Art 54(1)); the other 630 next month recovers 30.00.
     */
    public function test_held_purchase_recovers_as_paid(): void
    {
        $purchase = $this->purchase($this->gulf, $this->supplier($this->gulf), [[PurchaseTreatment::Standard, '1000']], ['currency' => 'AED', 'paid_later' => true]);
        $payments = app(PurchasePayments::class);
        $payments->record($purchase, '420', CarbonImmutable::today());

        $this->assertSame([1000.0, 0.0, 20.0], $this->figures($this->draft(CarbonImmutable::now()), '9'));
        $this->assertSame(20.0, $this->draft(CarbonImmutable::now())->box('13')->vat);

        $payments->record($purchase, '630', CarbonImmutable::today()->addMonthNoOverflow()->startOfMonth());
        $this->assertSame([0.0, 0.0, 30.0], $this->figures($this->draft(CarbonImmutable::now()->addMonthNoOverflow()), '9'));
    }

    /**
     * 2,000 + VAT 100, recovered on the intent to pay eight months ago, half
     * paid; the supplier writes the rest off this month: 100 x 1,050 /
     * 2,100 = 50.00 comes back, with -1,000 of the amount (Art 64(2)).
     */
    public function test_write_off_takes_back_unpaid_tax(): void
    {
        $earlier = now()->subMonths(8)->toDateString();
        $purchase = $this->purchase($this->gulf, $this->supplier($this->gulf), [[PurchaseTreatment::Standard, '2000']], ['currency' => 'AED', 'date' => $earlier, 'due' => $earlier]);
        app(PurchasePayments::class)->record($purchase, '1050', CarbonImmutable::parse($earlier)->addMonth());
        app(Purchases::class)->writeOff($this->gulf, $purchase, CarbonImmutable::today(), null);

        $this->assertSame([0.0, -1000.0, -50.0], $this->figures($this->draft(CarbonImmutable::now()), '9'));
    }

    /** Unpaid six months after its agreed date: pointed out, nothing reversed. */
    public function test_overdue_intent_is_pointed_out(): void
    {
        $earlier = now()->subMonths(8)->toDateString();
        $this->purchase($this->gulf, $this->supplier($this->gulf), [[PurchaseTreatment::Standard, '300']], ['currency' => 'AED', 'date' => $earlier, 'due' => $earlier, 'number' => 'LATE-1']);

        $draft = $this->draft(CarbonImmutable::now());

        $this->assertStringContainsString('LATE-1', implode(' ', $draft->problems));
        $this->assertSame([0.0, 0.0, 0.0], $this->figures($draft, '9'));
    }

    /** Through the portal: marked paid later, paid in part, written off. */
    public function test_portal_records_payment_and_write_off(): void
    {
        $supplier = $this->supplier($this->gulf);
        $this->asRequest(fn () => $this->as($this->gulf, 'accountant')->get(route('portal.books.purchases.create'))->assertOk()
            ->assertSee('Will not be paid within six months of the agreed payment date'));

        $this->as($this->gulf, 'accountant')->post(route('portal.books.purchases.store'), [
            'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'DS-1', 'invoice_date' => now()->toDateString(),
            'document_type' => 'invoice', 'currency' => 'AED', 'deductible_share' => '100', 'paid_later' => '1',
            'lines' => [['description' => 'Desks', 'treatment' => 'standard', 'net' => '1000']],
        ])->assertSessionHasNoErrors();

        $purchase = PurchaseInvoice::withoutTenantScope(fn () => PurchaseInvoice::query()->sole());
        $this->assertTrue($purchase->paid_later);

        $this->as($this->gulf, 'accountant')->post(route('portal.books.purchases.pay', $purchase->id), ['amount' => '525', 'paid_on' => now()->toDateString()])
            ->assertRedirect(route('portal.books.purchases.show', $purchase->id));
        $this->as($this->gulf, 'accountant')->post(route('portal.books.purchases.write-off', $purchase->id), ['written_off_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->asRequest(fn () => $this->as($this->gulf, 'viewer')->get(route('portal.books.purchases.show', $purchase->id))->assertOk()->assertSee('525.00'));
        $this->assertSame([1000.0, 0.0, 25.0], $this->figures($this->draft(CarbonImmutable::now()), '9'));
    }

    public function test_saudi_purchase_cannot_wait_on_payment(): void
    {
        $acme = $this->saudi();

        $this->expectException(ValidationException::class);
        $this->purchase($acme, $this->supplier($acme), [[PurchaseTreatment::Standard, '100']], ['paid_later' => true]);
    }

    public function test_overpaying_a_supplier_is_refused(): void
    {
        $purchase = $this->purchase($this->gulf, $this->supplier($this->gulf), [[PurchaseTreatment::Standard, '100']], ['currency' => 'AED']);

        $this->expectException(DomainException::class);
        app(PurchasePayments::class)->record($purchase, '106', CarbonImmutable::today());
    }

    private function draft(CarbonImmutable $month): VatReturnDraft
    {
        return app(UaeReturn::class)->draft((string) $this->gulf->id, $month->startOfMonth(), $month->endOfMonth()->startOfDay());
    }

    /** @return array{0: float|null, 1: float|null, 2: float|null} */
    private function figures(VatReturnDraft $draft, string $key): array
    {
        $box = $draft->box($key) ?? $this->fail('No box '.$key);

        return [$box->amount, $box->adjustment, $box->vat];
    }
}
