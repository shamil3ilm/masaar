<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\BillingServiceProvider;
use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\DTOs\CardCharge;
use App\Domains\Billing\DTOs\CardCheckout;
use App\Domains\Billing\DTOs\CardRefund;
use App\Domains\Billing\DTOs\CheckoutSession;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Gateways\SimulatedPaymentGateway;
use App\Domains\Billing\Gateways\UnavailablePaymentGateway;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Models\RefundIntent;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Services\Credits;
use App\Domains\Billing\Services\ExchangeRates;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Refunds;
use App\Domains\Billing\Services\Statements;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\Actions\FinanceActions;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Models\ApprovalRequest;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fixtures\AuthorityAnswer;
use Tests\TestCase;

/**
 * Money from customers and back to them: transfers set against invoices,
 * overpayment kept as credit and short payment left part paid, fees and
 * exchange differences recorded, cards through the gateway's simulator,
 * refunds through credit notes, and write-offs under approval.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));

        $platform = Organization::create([
            'name' => Brand::name('en').' Platform', 'name_ar' => 'منصة '.Brand::name('ar'), 'country' => 'SA',
            'vat_number' => '300000000000003', 'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        config(['billing.organization_id' => $platform->id]);

        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '310122393500003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);

        $this->mock(InvoiceIssuer::class, fn ($mock) => $mock->shouldReceive('issue')->andReturn(IssueOutcome::issuedOnly()));

        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $this->operations->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
    }

    /** No allocation named: the invoice due first is settled first. */
    public function test_a_transfer_settles_oldest_first(): void
    {
        $first = $this->invoice('1000');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $second = $this->invoice('1000');

        $payment = $this->transfer('1500', fee: '15');

        $this->assertSame(ReceivableStatus::Paid, $first->fresh()->status);
        $this->assertSame('350.00', $second->fresh()->amount_paid);
        $this->assertSame(ReceivableStatus::PartlyPaid, $second->fresh()->status);
        $this->assertSame(PaymentChannel::Bank, $payment->channel);
        $this->assertSame('15.00', (string) $payment->fee_amount);
        $this->assertSame('TRF-77', $payment->reference);
        $this->assertSame('0.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
        $this->assertTrue(AuditLog::query()->where('action', 'finance.payment.recorded')->where('entity_id', $payment->id)->exists());
    }

    /** More than owed: the rest is the customer's credit, taken by the next invoice. */
    public function test_an_overpayment_becomes_credit(): void
    {
        $this->invoice('1000');

        $payment = $this->transfer('1200');

        $credit = BillingCredit::query()->sole();
        $this->assertSame(BillingCredit::OVERPAYMENT, $credit->source);
        $this->assertSame((string) $payment->id, (string) $credit->source_id);
        $this->assertSame('50.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));

        $next = $this->invoice('100');
        $this->assertSame('50.00', (string) $next->amount_paid);
        $this->assertSame('0.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
    }

    /** Named allocations across invoices; short of one leaves it part paid. */
    public function test_named_allocations_and_short_payment(): void
    {
        $first = $this->invoice('1000');
        $second = $this->invoice('500');

        $this->transfer('800', allocations: [(string) $first->id => '200', (string) $second->id => '575']);

        $this->assertSame(ReceivableStatus::PartlyPaid, $first->fresh()->status);
        $this->assertSame('950.00', $first->fresh()->balance());
        $this->assertSame(ReceivableStatus::Paid, $second->fresh()->status);
        $this->assertSame('25.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
    }

    public function test_allocations_beyond_the_payment_are_refused(): void
    {
        $first = $this->invoice('1000');

        try {
            $this->transfer('100', allocations: [(string) $first->id => '200']);
            $this->fail('More was allocated than was paid.');
        } catch (DomainException) {
        }

        $this->assertSame(0, Payment::query()->count());
    }

    /**
     * A dirham invoice at 1.0200 paid when the dirham is 1.0210: the
     * difference in riyals is kept on the allocation (1150 x 0.001 = 1.15).
     */
    public function test_exchange_differences_are_recorded(): void
    {
        $settings = app(FinanceSettings::class);
        $settings->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $settings->set(FinanceSettings::CURRENCY, 'AED', null, (string) $this->acme->id);
        $receivable = $this->invoice('1000', rate: '1.02');

        $this->transfer('1150', currency: 'AED', rate: '1.021');

        $this->assertSame('1.15', (string) $receivable->allocations()->sole()->fx_difference);
    }

    /**
     * A dirham invoice at 1.02 paid by card, and one by transfer with no
     * rate typed: each takes the rate table's 1.03 for its day, so 1150 AED
     * realises 1150 x 0.01 = 11.50 SAR each.
     */
    public function test_card_and_transfer_take_table_rate(): void
    {
        config(['billing.gateway' => BillingServiceProvider::SIMULATOR]);
        $settings = app(FinanceSettings::class);
        $settings->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $settings->set(FinanceSettings::CURRENCY, 'AED', null, (string) $this->acme->id);
        app(ExchangeRates::class)->record('AED', CarbonImmutable::parse('2026-10-01'), '1.03', null);
        $card = $this->invoice('1000', rate: '1.02');
        $transfer = $this->invoice('1000', rate: '1.02');

        $session = app(Payments::class)->startCard($card, 'https://example.test/back');
        app(SimulatedPaymentGateway::class)->decide($session->id, true);
        $paid = app(Payments::class)->finishCard($session->id, (string) $this->acme->id);
        $this->transfer('1150', currency: 'AED', allocations: [(string) $transfer->id => '1150']);

        $this->assertSame('1.030000', (string) $paid->exchange_rate);
        $this->assertSame('11.50', (string) $card->allocations()->sole()->fx_difference);
        $this->assertSame('11.50', (string) $transfer->allocations()->sole()->fx_difference);
    }

    /**
     * A dirham transfer with no rate typed and none in the table is
     * refused, as a supplier payment is; so is starting a dirham card
     * payment, before the customer is sent to the gateway.
     */
    public function test_a_foreign_payment_needs_a_rate(): void
    {
        config(['billing.gateway' => BillingServiceProvider::SIMULATOR]);
        $settings = app(FinanceSettings::class);
        $settings->setCurrencies(['SAR', 'AED'], 'SAR', null);
        $settings->set(FinanceSettings::CURRENCY, 'AED', null, (string) $this->acme->id);
        $receivable = $this->invoice('1000', rate: '1.02');

        foreach ([fn () => $this->transfer('1150', currency: 'AED'), fn () => app(Payments::class)->startCard($receivable, 'https://example.test/back')] as $attempt) {
            try {
                $attempt();
                $this->fail('A dirham payment went ahead with no rate.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('The rate table has no AED rate on or before 2026-10-01', $e->getMessage());
            }
        }

        $this->assertSame(0, Payment::query()->count());
    }

    /**
     * Plans billed in dirhams: the payment and its invoice carry the
     * table's 1.02, and the receivable states 900 AED of VAT as 918 SAR.
     */
    public function test_a_dirham_plan_payment_keeps_rate(): void
    {
        config(['billing.currency' => 'AED']);
        app(ExchangeRates::class)->record('AED', CarbonImmutable::parse('2026-10-01'), '1.02', null);
        License::createWithCredentials([
            'org_id' => $this->acme->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'ops@acme.test',
            'tier' => LicenseTier::Starter, 'status' => LicenseStatus::Active, 'expires_at' => now()->addDays(10),
        ]);

        $payment = app(Billing::class)->record($this->acme, LicenseTier::Starter, 12, '6000', PaymentMethod::BankTransfer, 'TRF-AED', CarbonImmutable::now(), null, (string) $this->operations->id);

        $receivable = Receivable::query()->sole();
        $this->assertSame('1.020000', (string) $payment->exchange_rate);
        $this->assertSame('1.020000', (string) Invoice::withoutTenantScope(fn () => Invoice::query()->findOrFail($receivable->invoice_id))->exchange_rate);
        $this->assertSame(['1.020000', '918.00', '0.00'], [(string) $receivable->exchange_rate, (string) $receivable->vat_amount_tax, (string) $receivable->allocations()->sole()->fx_difference]);
    }

    public function test_the_billing_page_records_a_transfer(): void
    {
        $receivable = $this->invoice('1000');

        $this->actingAs($this->operations)->post(route('admin.billing.transfers.store', $this->acme->id), [
            'amount' => '1150', 'received_on' => '2026-10-01', 'reference' => 'TRF-9',
            'allocations' => [(string) $receivable->id => '1150'],
        ])->assertRedirect(route('admin.billing.show', $this->acme->id))->assertSessionHas('success');

        $this->assertSame(ReceivableStatus::Paid, $receivable->fresh()->status);

        $this->actingAs($this->operations)->get(route('admin.billing.receivables.show', [$this->acme->id, $receivable->id]))
            ->assertOk()->assertSee('TRF-9');
    }

    /**
     * The gateway's page approves it; coming back records it once, against
     * the invoice, with the fee the gateway kept.
     */
    public function test_a_card_payment_through_the_simulator(): void
    {
        config(['billing.gateway' => BillingServiceProvider::SIMULATOR, 'billing.simulator.fee_percent' => 2.5]);
        $receivable = $this->invoice('1000');
        $owner = $this->owner();

        $redirect = $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.billing.pay', $receivable->id))->assertRedirect()->headers->get('Location');
        $session = basename((string) parse_url((string) $redirect, PHP_URL_PATH));

        $this->actingAs($owner)->get(route('billing.simulator.show', $session))->assertOk()->assertSee('1,150.00');
        $back = $this->actingAs($owner)->post(route('billing.simulator.decide', $session), ['outcome' => 'approve'])->headers->get('Location');

        $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])->get((string) $back)
            ->assertRedirect(route('portal.billing'))->assertSessionHas('success');
        // Back twice, recorded once.
        $this->actingAs($owner)->withSession(['portal_org_id' => $this->acme->id])->get((string) $back);

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentChannel::Gateway, $payment->channel);
        $this->assertSame('txn_'.$session, $payment->gateway_reference);
        $this->assertSame('28.75', (string) $payment->fee_amount);
        $this->assertSame(ReceivableStatus::Paid, $receivable->fresh()->status);
    }

    public function test_a_declined_card_records_nothing(): void
    {
        config(['billing.gateway' => BillingServiceProvider::SIMULATOR]);
        $receivable = $this->invoice('1000');
        $gateway = app(SimulatedPaymentGateway::class);

        $session = app(Payments::class)->startCard($receivable, 'https://example.test/back');
        $gateway->decide($session->id, false);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('did not go through');

        try {
            app(Payments::class)->finishCard($session->id, (string) $this->acme->id);
        } finally {
            $this->assertSame(0, Payment::query()->count());
        }
    }

    /** No gateway set up: cards are not offered; the simulator never in production. */
    public function test_the_gateway_follows_configuration(): void
    {
        config(['billing.gateway' => null]);
        $this->assertInstanceOf(UnavailablePaymentGateway::class, app(PaymentGateway::class));
        $this->assertFalse(app(Payments::class)->cardsAccepted());

        config(['billing.gateway' => BillingServiceProvider::SIMULATOR]);
        $this->assertInstanceOf(SimulatedPaymentGateway::class, app(PaymentGateway::class));

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must not be used when APP_ENV=production');
            app(PaymentGateway::class);
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    /**
     * Paid in full, then partly refunded: a credit note for 230 with VAT
     * against the invoice, and the 230 paid beyond what it now owes kept
     * to the customer's credit.
     */
    public function test_a_refund_is_a_credit_note(): void
    {
        $receivable = $this->invoice('1000');
        $this->transfer('1150');

        $refund = app(Refunds::class)->refund($receivable, '200', 'Service not delivered for six days', 'لم تُقدَّم الخدمة لستة أيام', Refund::TO_CREDIT, null, (string) $this->operations->id);

        $note = Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->findOrFail($refund->credit_note_id));
        $this->assertSame('credit_note', $note->document_type->value);
        $this->assertSame('230.00', (string) $note->total);
        $this->assertStringContainsString('Service not delivered', (string) $note->adjustment_reason);
        $this->assertSame('230.00', (string) $receivable->fresh()->amount_credited);
        $this->assertSame('230.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
        $this->assertSame(BillingCredit::CREDIT_NOTE, BillingCredit::query()->sole()->source);

        // On the statement: charged 1150, paid 1150, credited 230.
        $statement = app(Statements::class)->statement($this->acme, 'SAR', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertSame('-230.00', $statement['closing']);
    }

    /** What a credit note leaves over can go back by transfer instead. */
    public function test_a_refund_can_be_paid_back(): void
    {
        $receivable = $this->invoice('1000');
        $this->transfer('1150');

        $refund = app(Refunds::class)->refund($receivable, '1000', 'Cancelled', 'أُلغي', 'bank_transfer', 'OUT-1', null);

        $this->assertSame('1150.00', (string) $refund->payout_amount);
        $this->assertSame('0.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
        $statement = app(Statements::class)->statement($this->acme, 'SAR', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
        $this->assertSame('0.00', $statement['closing']);
    }

    /**
     * A second click while the first refund's credit note is being issued:
     * the first holds all 1150 of the invoice, so the second (1.15) is
     * refused before any note is issued. One note, credited once.
     */
    public function test_concurrent_refunds_cannot_both_credit(): void
    {
        $race = false;
        $raced = null;
        $receivable = null;
        // A closure, not an arrow function: the flags are shared by reference.
        $this->mock(InvoiceIssuer::class, function ($mock) use (&$race, &$raced, &$receivable): void {
            $mock->shouldReceive('issue')->andReturnUsing(function () use (&$race, &$raced, &$receivable): IssueOutcome {
                if ($race) {
                    $race = false;

                    try {
                        app(Refunds::class)->refund($receivable->fresh(), '1', 'Second click', 'نقرة ثانية', Refund::TO_CREDIT, null, null);
                    } catch (DomainException $e) {
                        $raced = $e->getMessage();
                    }
                }

                return IssueOutcome::issuedOnly();
            });
        });
        $receivable = $this->invoice('1000');
        $this->transfer('1150');

        $race = true;
        app(Refunds::class)->refund($receivable, '1000', 'Cancelled', 'أُلغي', Refund::TO_CREDIT, null, null);

        $this->assertSame('That is more than is left to credit on this invoice.', $raced);
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->where('document_type', 'credit_note')->count()));
        $this->assertSame('1150.00', (string) $receivable->fresh()->amount_credited);
    }

    public function test_no_more_than_owed_is_credited(): void
    {
        $receivable = $this->invoice('1000');

        $this->expectException(DomainException::class);

        app(Refunds::class)->refund($receivable, '1000.01', 'Too much', 'أكثر من اللازم', Refund::TO_CREDIT, null, null);
    }

    /** An overpayment by card goes back to the card. */
    public function test_credit_goes_back_to_cards(): void
    {
        config(['billing.gateway' => BillingServiceProvider::SIMULATOR]);
        $receivable = $this->invoice('1000');
        $session = app(Payments::class)->startCard($receivable, 'https://example.test/back');
        app(SimulatedPaymentGateway::class)->decide($session->id, true);
        $payment = app(Payments::class)->finishCard($session->id, (string) $this->acme->id);
        app(Credits::class)->grant((string) $this->acme->id, 'SAR', '40', BillingCredit::OVERPAYMENT, (string) $payment->id, 'Overpaid');
        $credit = BillingCredit::query()->sole();

        $refund = app(Refunds::class)->payOutCredit($credit, '40', 'card', null, 'Customer asked', null);

        $this->assertStringStartsWith('rfd_', (string) $refund->gateway_reference);
        $this->assertSame('0.00', app(Credits::class)->left($credit));
    }

    /**
     * 1150 paid by card, then all 1000 (1150 with VAT) refunded to it. The
     * gateway is asked outside any transaction; recording what it said
     * fails once. The intent keeps the note and the 1150 owed back, and a
     * retry records it with the same gateway refund - one refund, not two.
     */
    public function test_card_refund_survives_failure_after_gateway(): void
    {
        config(['billing.gateway' => BillingServiceProvider::SIMULATOR]);
        $simulator = app(SimulatedPaymentGateway::class);
        $spy = new class($simulator) implements PaymentGateway
        {
            /** @var list<array{level: int, id: ?string}> */
            public array $refunds = [];

            public function __construct(private readonly SimulatedPaymentGateway $inner) {}

            public function available(): bool
            {
                return true;
            }

            public function checkout(CardCheckout $checkout): CheckoutSession
            {
                return $this->inner->checkout($checkout);
            }

            public function charge(string $sessionId): CardCharge
            {
                return $this->inner->charge($sessionId);
            }

            public function refund(string $transactionId, string $amount, string $currency, string $reference): CardRefund
            {
                $refund = $this->inner->refund($transactionId, $amount, $currency, $reference);
                $this->refunds[] = ['level' => DB::transactionLevel(), 'id' => $refund->refundId];

                return $refund;
            }
        };
        $this->app->instance(PaymentGateway::class, $spy);
        $outside = DB::transactionLevel();

        $receivable = $this->invoice('1000');
        $session = app(Payments::class)->startCard($receivable, 'https://example.test/back');
        $simulator->decide($session->id, true);
        app(Payments::class)->finishCard($session->id, (string) $this->acme->id);

        $fail = true;
        Refund::creating(function () use (&$fail): void {
            if ($fail) {
                $fail = false;

                throw new RuntimeException('The database went away.');
            }
        });

        try {
            app(Refunds::class)->refund($receivable, '1000', 'Cancelled', 'أُلغي', 'card', null, null);
            $this->fail('The failure after the gateway was swallowed.');
        } catch (RuntimeException) {
        }

        $intent = RefundIntent::query()->sole();
        $this->assertSame([RefundIntent::PAYING, '1150.00'], [$intent->status, (string) $intent->payout_amount]);
        $this->assertNotNull($intent->credit_note_id);
        $this->assertSame('1150.00', (string) $receivable->fresh()->amount_credited);
        $this->assertSame(0, Refund::query()->count());

        $this->travel(2)->minutes();
        $this->assertSame(1, app(Refunds::class)->finishOpen());

        $refund = Refund::query()->sole();
        $this->assertSame(['card', '1150.00'], [$refund->payout_method, (string) $refund->payout_amount]);
        $this->assertSame([$outside, $outside], array_column($spy->refunds, 'level'));
        $this->assertCount(1, array_unique(array_column($spy->refunds, 'id')));
        $this->assertSame((string) $spy->refunds[0]['id'], (string) $refund->gateway_reference);
        $this->assertSame('0.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
    }

    /**
     * A crash left all 1150 of an invoice held, with no note issued. Within
     * the safe age a 1 refund is still refused; after it, the hold is
     * released, on the audit record, and the refund goes through.
     */
    public function test_a_crashed_hold_is_released(): void
    {
        $receivable = $this->invoice('1000');
        $held = $this->crashedHold($receivable);

        $this->assertSame(0, app(Refunds::class)->finishOpen());
        $this->expectRefused(fn () => app(Refunds::class)->refund($receivable->fresh(), '1', 'Goodwill', 'حسن نية', Refund::TO_CREDIT, null, null));

        $this->travel(16)->minutes();
        $this->assertSame(1, app(Refunds::class)->finishOpen());

        $this->assertSame(RefundIntent::FAILED, $held->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.refund.released')->where('entity_id', $held->id)->exists());
        $this->assertSame('1.15', (string) app(Refunds::class)->refund($receivable->fresh(), '1', 'Goodwill', 'حسن نية', Refund::TO_CREDIT, null, null)->credited_amount);
    }

    /**
     * A crash after the note was issued and before the hold heard of it:
     * after the safe age the issued note is found and the refund finished
     * with it - 1150 credited once, the same note, no second one.
     */
    public function test_a_crashed_hold_finds_its_note(): void
    {
        $receivable = $this->invoice('1000');
        $this->transfer('1150');
        $crash = true;
        RefundIntent::updating(function (RefundIntent $intent) use (&$crash): void {
            if ($crash && $intent->status === RefundIntent::NOTED) {
                $crash = false;

                throw new RuntimeException('The worker died.');
            }
        });

        try {
            app(Refunds::class)->refund($receivable, '1000', 'Cancelled', 'أُلغي', Refund::TO_CREDIT, null, null);
            $this->fail('The crash was swallowed.');
        } catch (RuntimeException) {
        }

        $note = Invoice::withoutTenantScope(fn () => Invoice::query()->where('document_type', 'credit_note')->sole());
        AuthorityAnswer::record($note, 'accepted', ['invoice_number' => 'PLT-CN-1', 'hash' => base64_encode(hash('sha256', 'PLT-CN-1', true))]);
        $this->assertSame(RefundIntent::RESERVED, RefundIntent::query()->sole()->status);

        $this->travel(16)->minutes();
        $this->assertSame(1, app(Refunds::class)->finishOpen());

        $refund = Refund::query()->sole();
        $this->assertSame([(string) $note->id, '1150.00'], [(string) $refund->credit_note_id, (string) $receivable->fresh()->amount_credited]);
        $this->assertSame(1, Invoice::withoutTenantScope(fn () => Invoice::query()->where('document_type', 'credit_note')->count()));
        $this->assertSame('1150.00', app(Credits::class)->balance((string) $this->acme->id, 'SAR'));
    }

    /** Under a policy, a write-off waits for a second admin; then it is done. */
    public function test_a_write_off_waits_for_approval(): void
    {
        $receivable = $this->invoice('1000');
        $this->transfer('150');
        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
        $owner->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        app(ApprovalPolicies::class)->save(FinanceActions::WRITE_OFF, ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 72], null);

        $this->actingAs($this->operations)
            ->post(route('admin.billing.receivables.write-off', [$this->acme->id, $receivable->id]), ['reason' => 'Customer closed'])
            ->assertRedirect();

        $this->assertSame(ReceivableStatus::PartlyPaid, $receivable->fresh()->status);
        $request = ApprovalRequest::query()->where('action', FinanceActions::WRITE_OFF)->sole();

        $this->actingAs($owner)->post(route('admin.approvals.approve', $request->id))->assertRedirect();

        $written = $receivable->fresh();
        $this->assertSame(ReceivableStatus::WrittenOff, $written->status);
        $this->assertSame('1000.00', (string) $written->amount_written_off);
        $this->assertSame('Customer closed', $written->write_off_reason);
        $this->assertSame((string) $this->operations->id, (string) $written->written_off_by);
        $this->assertSame([], app(Statements::class)->ageing(CarbonImmutable::now()));
    }

    /**
     * An invoice to the customer, issued as the stubbed issuer leaves it and
     * then given the number and hash a real issue would.
     */
    private function invoice(string $amount, ?string $rate = null): Receivable
    {
        $receivable = app(Receivables::class)->issue($this->acme, new ReceivableDraft('Subscription', 'اشتراك', $amount, exchangeRate: $rate), null);
        $number = 'PLT-'.(Receivable::query()->count());

        AuthorityAnswer::record(Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->sole()), 'accepted', ['invoice_number' => $number, 'hash' => base64_encode(hash('sha256', $number, true))]);

        return $receivable;
    }

    /**
     * @param  array<string, string>  $allocations
     */
    private function transfer(string $amount, ?string $fee = null, array $allocations = [], string $currency = 'SAR', ?string $rate = null): Payment
    {
        return app(Payments::class)->recordTransfer($this->acme, new ReceivedPayment(
            amount: $amount,
            currency: $currency,
            receivedOn: CarbonImmutable::now(),
            reference: 'TRF-77',
            fee: $fee,
            exchangeRate: $rate,
            allocations: $allocations,
        ), (string) $this->operations->id);
    }

    /** All of the invoice held by a refund whose worker died before issuing its note. */
    private function crashedHold(Receivable $receivable): RefundIntent
    {
        $intent = new RefundIntent([
            'org_id' => $receivable->org_id, 'receivable_id' => $receivable->id, 'currency' => 'SAR', 'credited_amount' => '1150.00',
            'payout_method' => Refund::TO_CREDIT, 'reason' => 'Cancelled', 'status' => RefundIntent::RESERVED,
        ]);
        RefundIntent::withoutTenantScope(fn () => $intent->save());

        return $intent;
    }

    private function expectRefused(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('It went ahead.');
        } catch (DomainException $e) {
            $this->assertSame('That is more than is left to credit on this invoice.', $e->getMessage());
        }
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        return $owner;
    }
}
