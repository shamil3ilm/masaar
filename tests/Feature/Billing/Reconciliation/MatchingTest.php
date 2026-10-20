<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\MatchRule;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatchItem;
use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Services\Matcher;
use App\Domains\Billing\Reconciliation\Services\Reconciliation;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Services\Credits;
use App\Domains\Billing\Services\Payments;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statement lines matched to what customers owe: a reference and the exact
 * amount confirmed by itself, everything less sure suggested for an admin;
 * fees, short and over payments, several invoices and several lines; money
 * already recorded claimed, never recorded twice; and every match undone
 * as cleanly as it was made.
 */
class MatchingTest extends TestCase
{
    use ReconcilesStatements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReconciliation();
    }

    public function test_reference_and_exact_amount_confirm_themselves(): void
    {
        $invoice = $this->invoice('1000');

        $line = $this->line($this->statement([['2026-10-03', '1150.00', 'PLT-1', 'Transfer', 'Acme Trading']]));

        $this->assertSame(LineStatus::Matched, $line->status);
        $match = ReconciliationMatch::query()->where('status', MatchStatus::Confirmed->value)->sole();
        $this->assertTrue($match->auto_confirmed);
        $this->assertSame(100, $match->confidence);
        $this->assertSame(MatchRule::Reference, $match->rule);
        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);

        $payment = Payment::query()->withoutGlobalScopes()->sole();
        $this->assertSame(PaymentChannel::Bank, $payment->channel);
        $this->assertSame('1150.00', (string) $payment->total);
        $this->assertSame('PLT-1', $payment->reference);
        $this->assertSame($this->account, $payment->bank_account);
        $this->assertSame('2026-10-03', $payment->paid_at->toDateString());
        $this->assertTrue($match->items()->where('role', ReconciliationMatchItem::CREATED)->where('payment_id', $payment->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'finance.reconciliation.auto_confirmed')->where('entity_id', $match->id)->exists());
    }

    public function test_amount_alone_waits_for_an_admin(): void
    {
        $invoice = $this->invoice('1000');

        $line = $this->line($this->statement([['2026-10-04', '1150.00', '', 'INCOMING TRANSFER']]));

        $this->assertSame(LineStatus::Suggested, $line->status);
        $match = ReconciliationMatch::query()->sole();
        $this->assertSame(MatchStatus::Suggested, $match->status);
        $this->assertSame(MatchRule::Amount, $match->rule);
        $this->assertLessThan(Matcher::HIGH, $match->confidence);
        $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());
        $this->assertSame(ReceivableStatus::Open, $invoice->fresh()->status);
    }

    public function test_low_confidence_needs_a_check(): void
    {
        $invoice = $this->invoice('1000');
        $this->statement([['2026-10-04', '1150.00', '', 'INCOMING TRANSFER']]);
        $match = ReconciliationMatch::query()->sole();

        try {
            app(Reconciliation::class)->confirm($match, (string) $this->operations->id);
            $this->fail('A low-confidence match was confirmed without a check.');
        } catch (DomainException) {
        }

        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        $this->assertSame(MatchStatus::Confirmed, $match->fresh()->status);
        $this->assertSame((string) $this->operations->id, (string) $match->fresh()->confirmed_by);
        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);
    }

    /** Short by less than the tolerance: the bank's fee, the invoice paid. */
    public function test_small_shortfall_is_the_banks_fee(): void
    {
        $invoice = $this->invoice('1000');
        $this->statement([['2026-10-03', '1149.50', 'PLT-1']]);
        $match = ReconciliationMatch::query()->sole();

        $this->assertSame(90, $match->confidence);
        $this->assertSame('0.50', (string) $match->fee_amount);

        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        $payment = Payment::query()->withoutGlobalScopes()->sole();
        $this->assertSame('1150.00', (string) $payment->total);
        $this->assertSame('0.50', (string) $payment->fee_amount);
        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);
    }

    public function test_short_payment_leaves_it_part_paid(): void
    {
        $invoice = $this->invoice('1000');
        $this->statement([['2026-10-03', '1000.00', 'PLT-1']]);
        $match = ReconciliationMatch::query()->sole();

        $this->assertSame(MatchRule::Partial, $match->rule);
        $this->assertContains('short', $match->basis);

        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        $this->assertSame(ReceivableStatus::PartlyPaid, $invoice->fresh()->status);
        $this->assertSame('150.00', $invoice->fresh()->balance());
        $this->assertTrue($match->fresh()->isShort());
    }

    public function test_overpayment_goes_to_the_customers_credit(): void
    {
        $invoice = $this->invoice('1000');
        $this->statement([['2026-10-03', '1200.00', 'PLT-1']]);
        $match = ReconciliationMatch::query()->sole();

        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('50.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
        $this->assertTrue($match->fresh()->isOver());
    }

    public function test_one_line_pays_two_named_invoices(): void
    {
        $first = $this->invoice('1000');
        $second = $this->invoice('500');

        $this->statement([['2026-10-03', '1725.00', 'PLT-1 PLT-2']]);
        $match = ReconciliationMatch::query()->sole();
        $this->assertSame(MatchRule::Combined, $match->rule);

        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        $this->assertSame(ReceivableStatus::Paid, $first->fresh()->status);
        $this->assertSame(ReceivableStatus::Paid, $second->fresh()->status);
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
    }

    public function test_unnamed_line_adding_up_to_invoices(): void
    {
        $this->invoice('1000');
        $this->invoice('500');

        $this->statement([['2026-10-03', '1725.00', '', 'ACME TRADING MONTHLY', 'Acme Trading']]);

        $combined = ReconciliationMatch::query()->where('rule', MatchRule::Combined->value)->sole();
        $this->assertContains('amount_sum', $combined->basis);
        $this->assertContains('payer_name', $combined->basis);
        $this->assertSame(2, $combined->items()->where('role', ReconciliationMatchItem::RECEIVABLE)->count());
    }

    public function test_two_lines_paying_one_invoice(): void
    {
        $invoice = $this->invoice('1000');

        $import = $this->statement([['2026-10-03', '600.00', '', 'PART 1'], ['2026-10-05', '550.00', '', 'PART 2']]);

        $match = ReconciliationMatch::query()->where('rule', MatchRule::Combined->value)->sole();
        $this->assertSame(2, $match->items()->where('role', ReconciliationMatchItem::LINE)->count());

        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(2, Payment::query()->withoutGlobalScopes()->count());
        $this->assertSame(LineStatus::Matched, $this->line($import, 1)->status);
        $this->assertSame(LineStatus::Matched, $this->line($import, 2)->status);
    }

    /** A transfer an admin already recorded is claimed, never recorded again. */
    public function test_recorded_transfer_is_claimed_not_duplicated(): void
    {
        $this->invoice('1000');
        $recorded = app(Payments::class)->recordTransfer($this->acme, new ReceivedPayment('1150.00', 'SAR', CarbonImmutable::parse('2026-10-03'), reference: 'TRF-2026-0042'), null);

        $line = $this->line($this->statement([['2026-10-03', '1150.00', 'TRF-2026-0042']]));

        $this->assertSame(LineStatus::Matched, $line->status);
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
        $match = ReconciliationMatch::query()->where('status', MatchStatus::Confirmed->value)->sole();
        $this->assertTrue($match->items()->where('role', ReconciliationMatchItem::PAYMENT)->where('payment_id', $recorded->id)->exists());
        $this->assertFalse($match->items()->where('role', ReconciliationMatchItem::CREATED)->exists());
    }

    public function test_settlement_line_claims_its_card_payment(): void
    {
        config(['billing.gateway' => 'simulator']);
        $invoice = $this->invoice('1000');
        app(Payments::class)->recordSettledCard($this->acme, new ReceivedPayment('1150.00', 'SAR', CarbonImmutable::parse('2026-10-02'), reference: 'PLT-1', fee: '28.75',
            allocations: [(string) $invoice->id => '1150.00']), 'txn_sim_01jbkx0000000000000000000a', null);

        $import = $this->settlement();

        $this->assertSame(LineStatus::Matched, $this->line($import, 1)->status);
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->where('channel', 'gateway')->where('gateway_reference', 'txn_sim_01jbkx0000000000000000000a')->count());
    }

    /** Paid by card, never back from the gateway: recorded from the report. */
    public function test_settlement_records_a_missing_card_payment(): void
    {
        config(['billing.gateway' => 'simulator']);
        $invoice = $this->invoice('1000');

        $import = $this->settlement();

        $this->assertSame(LineStatus::Matched, $this->line($import, 1)->status);
        $payment = Payment::query()->withoutGlobalScopes()->sole();
        $this->assertSame(PaymentChannel::Gateway, $payment->channel);
        $this->assertSame('txn_sim_01jbkx0000000000000000000a', $payment->gateway_reference);
        $this->assertSame('28.75', (string) $payment->fee_amount);
        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);
    }

    public function test_rejected_suggestion_is_not_made_again(): void
    {
        $this->invoice('1000');
        $line = $this->line($this->statement([['2026-10-04', '1150.00', '', 'INCOMING TRANSFER']]));
        $match = ReconciliationMatch::query()->sole();

        app(Reconciliation::class)->reject($match, (string) $this->operations->id);

        $this->assertSame(LineStatus::Unmatched, $line->fresh()->status);
        $this->assertFalse(app(Matcher::class)->suggest($line->fresh()));
        $this->assertSame(1, ReconciliationMatch::query()->count());
    }

    public function test_undoing_a_match_reverses_its_payment(): void
    {
        $invoice = $this->invoice('1000');
        $line = $this->line($this->statement([['2026-10-03', '1200.00', 'PLT-1']]));
        $match = ReconciliationMatch::query()->sole();
        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        app(Reconciliation::class)->unmatch($match, 'Matched to the wrong customer', (string) $this->operations->id);

        $payment = Payment::query()->withoutGlobalScopes()->sole();
        $this->assertNotNull($payment->reversed_at);
        $this->assertSame('Matched to the wrong customer', $payment->reversal_reason);
        $this->assertSame(ReceivableStatus::Open, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->paid_on);
        $this->assertSame('0.00', (string) $invoice->fresh()->amount_paid);
        $this->assertSame(0.0, (float) PaymentAllocation::query()->withoutGlobalScopes()->sum('amount'));
        $this->assertNotNull(BillingCredit::query()->withoutGlobalScopes()->sole()->reversed_at);
        $this->assertSame('0.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
        $this->assertSame(MatchStatus::Reversed, $match->fresh()->status);
        $this->assertSame(LineStatus::Unmatched, $line->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.reconciliation.reversed')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'finance.payment.reversed')->exists());

        // Matched again, properly: a new payment, the reversed one stays.
        app(Reconciliation::class)->manual([(string) $line->id], (string) $this->acme->id, [(string) $invoice->id => '1150.00'], [], (string) $this->operations->id);
        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(2, Payment::query()->withoutGlobalScopes()->count());
    }

    public function test_undoing_frees_a_claimed_payment(): void
    {
        $this->invoice('1000');
        $recorded = app(Payments::class)->recordTransfer($this->acme, new ReceivedPayment('1150.00', 'SAR', CarbonImmutable::parse('2026-10-03'), reference: 'TRF-2026-0042'), null);
        $this->statement([['2026-10-03', '1150.00', 'TRF-2026-0042']]);
        $match = ReconciliationMatch::query()->sole();

        app(Reconciliation::class)->unmatch($match, 'Wrong line', (string) $this->operations->id);

        $this->assertNull($recorded->fresh()->reversed_at);
    }

    public function test_a_used_credit_blocks_the_undo(): void
    {
        $this->invoice('1000');
        $this->statement([['2026-10-03', '1200.00', 'PLT-1']]);
        $match = ReconciliationMatch::query()->sole();
        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);
        // The next invoice takes the 50.00 credit.
        $this->invoice('100');

        $this->expectException(DomainException::class);

        app(Reconciliation::class)->unmatch($match, 'Wrong', (string) $this->operations->id);
    }

    public function test_setting_a_line_aside_and_back(): void
    {
        $this->invoice('1000');
        $line = $this->line($this->statement([['2026-10-04', '1150.00', '', 'INCOMING TRANSFER']]));

        app(Reconciliation::class)->ignore($line, 'Transfer between our own accounts', (string) $this->operations->id);

        $this->assertSame(LineStatus::Ignored, $line->fresh()->status);
        $this->assertSame((string) $this->operations->id, (string) $line->fresh()->ignored_by);
        $this->assertSame(MatchStatus::Superseded, ReconciliationMatch::query()->sole()->status);

        app(Reconciliation::class)->restore($line->fresh(), (string) $this->operations->id);

        $this->assertSame(LineStatus::Unmatched, $line->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.reconciliation.line_ignored')->exists());
    }

    public function test_split_sets_the_amounts_by_hand(): void
    {
        $first = $this->invoice('1000');
        $second = $this->invoice('500');
        $this->statement([['2026-10-03', '1000.00', 'PLT-1 PLT-2']]);
        $match = ReconciliationMatch::query()->sole();

        $split = app(Reconciliation::class)->split($match, [(string) $first->id => '400.00', (string) $second->id => '575.00'], (string) $this->operations->id);

        $this->assertSame(MatchStatus::Superseded, $match->fresh()->status);
        $this->assertSame(MatchRule::Manual, $split->rule);
        $this->assertSame('750.00', $first->fresh()->balance());
        $this->assertSame(ReceivableStatus::Paid, $second->fresh()->status);
        $this->assertSame('25.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
    }

    public function test_a_line_is_matched_once(): void
    {
        $invoice = $this->invoice('1000');
        $line = $this->line($this->statement([['2026-10-03', '1150.00', 'PLT-1']]));

        $this->expectException(DomainException::class);

        app(Reconciliation::class)->manual([(string) $line->id], (string) $this->acme->id, [(string) $invoice->id => '1150.00'], [], (string) $this->operations->id);
    }

    private function settlement(): StatementImport
    {
        return app(StatementImports::class)->import(StatementKind::Settlement, 'settlement.csv',
            (string) file_get_contents(base_path('tests/Fixtures/statements/settlement.csv')), null, null, (string) $this->operations->id);
    }
}
