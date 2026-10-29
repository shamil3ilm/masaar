<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Models\CustomerReceipt;
use App\Domains\Books\Services\CustomerPayments;
use App\Domains\Books\Services\CustomerStatements;
use App\Domains\Books\Services\Receivables;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money that is the customer's: a credit balance paid back, and money
 * received on account - set against invoices later, or paid back - each
 * worked by hand through what is owed and the statement.
 */
class CustomerRefundTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    private Organization $acme;

    private Customer $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
        $this->gulf = Customer::acrossTenants()->create(['org_id' => $this->acme->id, 'name' => 'Gulf Supplies', 'vat_number' => '310122393500003']);
    }

    /**
     * 115.00 paid, then credited in full: 115.00 is the customer's. It is
     * paid back - the statement shows the refund - and nothing more can be.
     */
    public function test_credit_balance_is_refunded(): void
    {
        $invoice = $this->sale($this->acme, [[100, 15]], ['customer_id' => $this->gulf->id, 'issue_date' => now()->subDays(3)->toDateString()]);
        $payments = app(CustomerPayments::class);
        $payments->record($invoice, '115', CarbonImmutable::today()->subDays(2), PaymentMethod::BankTransfer);
        $this->sale($this->acme, [[100, 15]], ['document_type' => 'credit_note', 'billing_ref' => 'INV-1', 'customer_id' => $this->gulf->id, 'issue_date' => now()->subDay()->toDateString()]);
        $this->assertSame(-115.0, app(Receivables::class)->outstanding($invoice));

        $payments->refund($invoice, '115', CarbonImmutable::today(), PaymentMethod::BankTransfer, 'RF-1');

        $this->assertSame(0.0, app(Receivables::class)->outstanding($invoice));
        $statement = $this->statement();
        $this->assertSame([0.0, 'Refund (Bank transfer)'], [$statement['closing'], end($statement['lines'])['kind']]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no credit');
        $payments->refund($invoice, '1', CarbonImmutable::today(), PaymentMethod::Cash);
    }

    /** A credit note naming no invoice found is a credit of its own, refunded on the note. */
    public function test_loose_credit_note_is_refunded(): void
    {
        $note = $this->sale($this->acme, [[200, 15]], ['document_type' => 'credit_note', 'billing_ref' => 'OLD-9', 'customer_id' => $this->gulf->id]);

        app(CustomerPayments::class)->refund($note, '200', CarbonImmutable::today(), PaymentMethod::Cash);

        $row = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today())->firstWhere('number', 'INV-1');
        $this->assertSame([-200.0, -30.0], [$row['paid'], $row['balance']]);
    }

    /**
     * 1,000.00 received on account; an invoice of 1,150.00 follows and 600.00
     * of the money pays it: 550.00 owed on it and 400.00 still on account.
     * The statement counts the money once, when it came: 150.00 owed.
     */
    public function test_receipt_on_account_pays_invoices(): void
    {
        $payments = app(CustomerPayments::class);
        $receipt = $payments->receive((string) $this->acme->id, $this->gulf, '1000', 'SAR', CarbonImmutable::today()->subDays(5), PaymentMethod::BankTransfer, 'TRF-77');
        $rows = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today());
        $this->assertSame([-1000.0, Receivables::CREDIT], [$rows->sole()['balance'], $rows->sole()['status']]);

        $invoice = $this->sale($this->acme, [[1000, 15]], ['customer_id' => $this->gulf->id, 'issue_date' => now()->subDays(2)->toDateString()]);
        $payments->allocate($receipt, $invoice, '600', CarbonImmutable::today());

        $rows = app(Receivables::class)->asAt((string) $this->acme->id, CarbonImmutable::today());
        $this->assertSame([550.0, -400.0], [$rows->firstWhere('number', 'INV-1')['balance'], $rows->first(fn (array $row): bool => $row['invoice'] === null)['balance']]);
        $this->assertSame(400.0, $payments->unallocated($receipt));
        $this->assertSame(150.0, $this->statement()['closing']);
        $this->assertSame(['current' => -400.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0, 'total' => -400.0], app(Receivables::class)->ageing($rows->filter(fn (array $row): bool => $row['invoice'] === null))['SAR']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('400.00 SAR is left');
        $payments->allocate($receipt, $invoice, '450', CarbonImmutable::today());
    }

    /**
     * What is left on account is paid back; a receipt with money set against
     * an invoice is not reversed until that payment is.
     */
    public function test_money_on_account_is_refunded(): void
    {
        $payments = app(CustomerPayments::class);
        $receipt = $payments->receive((string) $this->acme->id, $this->gulf, '1000', 'SAR', CarbonImmutable::today()->subDays(5), PaymentMethod::BankTransfer);
        $invoice = $this->sale($this->acme, [[1000, 15]], ['customer_id' => $this->gulf->id, 'issue_date' => now()->subDays(2)->toDateString()]);
        $paid = $payments->allocate($receipt, $invoice, '600', CarbonImmutable::today());

        $payments->refundReceipt($receipt, '400', CarbonImmutable::today(), PaymentMethod::BankTransfer, 'RF-2');

        $this->assertSame(0.0, $payments->unallocated($receipt));
        $this->assertSame(550.0, $this->statement()['closing']);

        try {
            $payments->reverseReceipt($receipt, 'Wrong customer');
            $this->fail('A receipt with money set against an invoice was reversed.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('first', $e->getMessage());
        }

        $payments->reverse($paid, 'Wrong invoice');
        $this->assertSame(600.0, $payments->unallocated($receipt));
        $payments->reverseReceipt(CustomerReceipt::query()->where('kind', CustomerReceipt::REFUND)->sole(), 'Not paid back after all');
        $payments->reverseReceipt($receipt, 'Wrong customer');

        $this->assertSame(0.0, $payments->unallocated($receipt));
        $this->assertSame(1150.0, $this->statement()['closing']);
    }

    /** The statement page records money on account, pays an invoice from it, and pays back the rest. */
    public function test_portal_records_money_on_account(): void
    {
        $invoice = $this->sale($this->acme, [[1000, 15]], ['customer_id' => $this->gulf->id]);
        $accountant = $this->as($this->acme, 'accountant');

        $accountant->post(route('portal.books.receipts.store', $this->gulf->id), ['amount' => '1000', 'currency' => 'SAR', 'received_on' => now()->toDateString(), 'method' => 'bank_transfer'])
            ->assertSessionHas('success');
        $receipt = CustomerReceipt::query()->sole();
        $accountant->post(route('portal.books.receipts.allocate', $receipt->id), ['invoice_id' => $invoice->id, 'amount' => '600', 'paid_on' => now()->toDateString()])->assertSessionHas('success');
        $accountant->post(route('portal.books.receipts.refund', $receipt->id), ['amount' => '400', 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertSessionHas('success');

        $this->asRequest(fn () => $accountant->get(route('portal.books.statements.show', $this->gulf->id))->assertOk()->assertSee('data-on-account', false)->assertSee('Refund'));
        $this->assertSame(0.0, app(CustomerPayments::class)->unallocated($receipt));
        $this->assertSame(550.0, app(Receivables::class)->outstanding($invoice));
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        return app(CustomerStatements::class)->for((string) $this->acme->id, $this->gulf, CarbonImmutable::today()->subDays(30), CarbonImmutable::today());
    }
}
