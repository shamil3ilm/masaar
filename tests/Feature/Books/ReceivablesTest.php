<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Books\Services\CustomerPayments;
use App\Domains\Books\Services\CustomerStatements;
use App\Domains\Books\Services\Receivables;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What customers owe, as at a date: payments, credit notes, ageing and the
 * customer's statement, each worked by hand.
 */
class ReceivablesTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
    }

    /**
     * INV-1: 1,150.00, due 30 days ago, 400.00 paid 20 days ago - 750.00
     * owed, 30 days late. INV-2: 2,300.00 from 100 days ago with no due
     * date - due on issue, over 90. INV-3: 115.00 credited in full.
     */
    public function test_balances_and_ageing_as_at_today(): void
    {
        $one = $this->sale($this->acme, [[1000, 15]], ['issue_date' => now()->subDays(60)->toDateString(), 'due_date' => now()->subDays(30)->toDateString()]);
        $this->sale($this->acme, [[2000, 15]], ['issue_date' => now()->subDays(100)->toDateString()]);
        $three = $this->sale($this->acme, [[100, 15]]);
        $this->sale($this->acme, [[100, 15]], ['document_type' => 'credit_note', 'billing_ref' => $three->invoice_number]);
        $this->pay($one, '400', now()->subDays(20));

        $rows = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today())->keyBy('number');

        $this->assertSame([1150.0, 400.0, 750.0, 30, '1_30', Receivables::PART_PAID], $this->pick($rows['INV-1']));
        $this->assertSame([2300.0, 0.0, 2300.0, 100, 'over_90', Receivables::UNPAID], $this->pick($rows['INV-2']));
        $this->assertSame(Receivables::CREDITED, $rows['INV-3']['status']);
        $this->assertSame(
            ['current' => 0.0, '1_30' => 750.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 2300.0, 'total' => 3050.0],
            app(Receivables::class)->ageing($rows->values())['SAR'],
        );
    }

    /**
     * As at 25 days ago INV-1 was owed in full and 5 days late: the payment
     * 20 days ago had not been made. INV-3 did not exist.
     */
    public function test_past_date_rebuilds_the_balance(): void
    {
        $one = $this->sale($this->acme, [[1000, 15]], ['issue_date' => now()->subDays(60)->toDateString(), 'due_date' => now()->subDays(30)->toDateString()]);
        $this->sale($this->acme, [[100, 15]]);
        $this->pay($one, '400', now()->subDays(20));

        $rows = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today()->subDays(25))->keyBy('number');

        $this->assertSame([1150.0, 0.0, 1150.0, 5, '1_30', Receivables::UNPAID], $this->pick($rows['INV-1']));
        $this->assertFalse($rows->has('INV-2'));
    }

    /** 2,300.00 with a 575.00 credit note and nothing paid: part credited, 1,725.00 owed. */
    public function test_credit_without_payment_is_part_credited(): void
    {
        $invoice = $this->sale($this->acme, [[2000, 15]]);
        $this->sale($this->acme, [[500, 15]], ['document_type' => 'credit_note', 'billing_ref' => $invoice->invoice_number]);

        $row = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today())->firstWhere('number', 'INV-1');

        $this->assertSame([575.0, 0.0, 1725.0, Receivables::PART_CREDITED], [$row['credited'], $row['paid'], $row['balance'], $row['status']]);
        $this->assertSame('Part credited', Receivables::statusLabel($row['status']));
    }

    /**
     * A walk-in sale paid in cash (10) or by card (48) at the till is paid on
     * issue; one on credit (30) is owed. A refund at the till leaves nothing.
     */
    public function test_till_sales_are_paid_on_issue(): void
    {
        $cash = $this->sale($this->acme, [[100, 15]], ['type' => 'simplified', 'payment_means_code' => '10']);
        $this->sale($this->acme, [[200, 15]], ['type' => 'simplified', 'payment_means_code' => '48']);
        $this->sale($this->acme, [[300, 15]], ['type' => 'simplified', 'payment_means_code' => '30']);
        $this->sale($this->acme, [[100, 15]], ['type' => 'simplified', 'payment_means_code' => '10', 'document_type' => 'credit_note', 'billing_ref' => $cash->invoice_number]);

        $rows = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today())->keyBy('number');

        $this->assertSame([0.0, Receivables::PAID], [$rows['INV-2']['balance'], $rows['INV-2']['status']]);
        $this->assertSame(0.0, $rows['INV-1']['balance']);
        $this->assertSame([345.0, Receivables::UNPAID], [$rows['INV-3']['balance'], $rows['INV-3']['status']]);
        $this->assertSame(['SAR' => ['current' => 345.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => 345.0]],
            app(Receivables::class)->ageing($rows->values()));
    }

    public function test_overpayment_is_refused(): void
    {
        $invoice = $this->sale($this->acme, [[100, 15]]);
        $this->pay($invoice, '100', now());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('owes 15.00 SAR');

        $this->pay($invoice, '20', now());
    }

    /** Reversed, the 100.00 is owed again - and only once reversed. */
    public function test_reversal_restores_the_balance(): void
    {
        $invoice = $this->sale($this->acme, [[100, 15]]);
        $payment = $this->pay($invoice, '100', now());
        $payments = app(CustomerPayments::class);

        $payments->reverse($payment, 'Cheque bounced');

        $this->assertSame(115.0, app(Receivables::class)->outstanding($invoice));

        $this->expectException(DomainException::class);
        $payments->reverse($payment, 'Again');
    }

    /**
     * Opening 1,150.00 (an invoice 40 days ago); then 1,000.00 paid and a
     * 115.00 credit note: closing 35.00, what Receivables says is owed.
     */
    public function test_statement_runs_to_the_balance(): void
    {
        $customer = Customer::acrossTenants()->create(['org_id' => $this->acme->id, 'name' => 'Gulf Supplies', 'vat_number' => '310122393500003']);
        $invoice = $this->sale($this->acme, [[1000, 15]], ['issue_date' => now()->subDays(40)->toDateString(), 'buyer_vat_number' => '310122393500003']);
        $this->pay($invoice, '1000', now()->subDays(10));
        $this->sale($this->acme, [[100, 15]], ['document_type' => 'credit_note', 'billing_ref' => $invoice->invoice_number, 'issue_date' => now()->subDays(5)->toDateString(), 'buyer_vat_number' => '310122393500003']);

        $statement = app(CustomerStatements::class)->for((string) $this->acme->id, $customer, CarbonImmutable::today()->subDays(30), CarbonImmutable::today());

        $this->assertSame([1150.0, 35.0, 0.0, 1115.0], [$statement['opening'], $statement['closing'], $statement['debits'], $statement['credits']]);
        $this->assertSame([150.0, 35.0], array_column($statement['lines'], 'balance'));
        $this->assertSame(35.0, app(Receivables::class)->outstanding($invoice));
    }

    /**
     * A 345.00 credit note on INV-1 (1,150.00) and INV-2 (2,300.00) falls on
     * each by its share of their 3,450.00: 115.00 and 230.00. No loose row.
     */
    public function test_note_on_several_invoices_spreads(): void
    {
        $one = $this->sale($this->acme, [[1000, 15]]);
        $two = $this->sale($this->acme, [[2000, 15]]);
        $this->sale($this->acme, [[300, 15]], ['document_type' => 'credit_note', 'billing_ref' => 'INV-1, INV-2']);

        $rows = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today())->keyBy('number');

        $this->assertSame([115.0, 1035.0], [$rows['INV-1']['credited'], $rows['INV-1']['balance']]);
        $this->assertSame([230.0, 2070.0], [$rows['INV-2']['credited'], $rows['INV-2']['balance']]);
        $this->assertFalse($rows->has('INV-3'));
        $this->assertSame([1035.0, 2070.0], [app(Receivables::class)->outstanding($one), app(Receivables::class)->outstanding($two)]);
    }

    /** What INV-1 owes after its share of the note caps the payment: 1,035.00, not 1,150.00. */
    public function test_payment_cap_follows_spread_note(): void
    {
        $one = $this->sale($this->acme, [[1000, 15]]);
        $this->sale($this->acme, [[2000, 15]]);
        $this->sale($this->acme, [[300, 15]], ['document_type' => 'credit_note', 'billing_ref' => 'INV-1;INV-2']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('owes 1,035.00 SAR');

        $this->pay($one, '1150', now());
    }

    /**
     * One invoice's balance is read on its own: the same few queries with
     * 3 other invoices or 30, and a till sale is never loaded for the list
     * of what is still owed.
     */
    public function test_one_balance_reads_bounded_queries(): void
    {
        $invoice = $this->sale($this->acme, [[1000, 15]]);
        $counted = fn (): int => $this->queries(fn () => app(Receivables::class)->outstanding($invoice));

        foreach (range(1, 3) as $ignored) {
            $this->sale($this->acme, [[100, 15]]);
        }
        $few = $counted();

        foreach (range(1, 27) as $ignored) {
            $this->sale($this->acme, [[100, 15]]);
            $this->sale($this->acme, [[100, 15]], ['type' => 'simplified', 'payment_means_code' => '10']);
        }

        $this->assertSame($few, $counted());
        $this->assertLessThanOrEqual(5, $few);

        $open = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today(), open: true);
        $this->assertSame(31, $open->count());
    }

    private function queries(\Closure $callback): int
    {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $callback();
        $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $count;
    }

    private function pay(Invoice $invoice, string $amount, \DateTimeInterface $on): InvoicePayment
    {
        return app(CustomerPayments::class)->record($invoice, $amount, CarbonImmutable::instance($on)->startOfDay(), PaymentMethod::BankTransfer, 'TRF-1');
    }

    /** @return list<mixed> */
    private function pick(array $row): array
    {
        return [$row['total'], $row['paid'], $row['balance'], $row['days_overdue'], $row['bucket'], $row['status']];
    }
}
