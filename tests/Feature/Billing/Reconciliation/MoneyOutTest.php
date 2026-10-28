<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatchItem;
use App\Domains\Billing\Reconciliation\Services\Reconciliation;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\StatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money out: set aside by itself, unless it is a refund the platform
 * recorded paying back - then suggested as that refund and, confirmed,
 * linked to it with nothing new recorded.
 */
class MoneyOutTest extends TestCase
{
    use ReconcilesStatements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReconciliation();
    }

    public function test_a_debit_matches_its_recorded_refund(): void
    {
        $refund = $this->refund('230.00', 'bank_transfer');

        $line = $this->line($this->statement([['2026-10-03', '-230.00', '', 'TRANSFER OUT', 'Acme Trading']]));

        $this->assertSame(LineStatus::Suggested, $line->status);
        $this->assertNull($line->ignored_at);
        $match = ReconciliationMatch::query()->sole();
        $this->assertTrue($match->items()->where('role', ReconciliationMatchItem::REFUND)->where('refund_id', $refund->id)->exists());

        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);

        $this->assertSame(LineStatus::Matched, $line->fresh()->status);
        $this->assertSame(1, Refund::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::query()->withoutGlobalScopes()->count());

        // Undone: set aside again, by itself.
        app(Reconciliation::class)->unmatch($match->fresh(), 'Wrong refund', (string) $this->operations->id);
        $this->assertSame(LineStatus::Ignored, $line->fresh()->status);
        $this->assertFalse($line->fresh()->setAsideByHand());
    }

    public function test_unmatched_money_out_stays_aside(): void
    {
        $this->refund('230.00', 'bank_transfer');

        $import = $this->statement([['2026-10-03', '-35.00', '', 'ACCOUNT CHARGES'], ['2026-10-30', '-230.00', '', 'LATE TRANSFER OUT']]);

        // Another amount, and the right amount outside the date window.
        $this->assertSame(LineStatus::Ignored, $this->line($import, 1)->status);
        $this->assertSame(LineStatus::Ignored, $this->line($import, 2)->status);
        $this->assertSame(0, ReconciliationMatch::query()->count());
    }

    public function test_a_rejected_refund_goes_back_aside(): void
    {
        $this->refund('230.00', 'bank_transfer');
        $line = $this->line($this->statement([['2026-10-03', '-230.00', '', 'TRANSFER OUT']]));

        app(Reconciliation::class)->reject(ReconciliationMatch::query()->sole(), (string) $this->operations->id);

        $this->assertSame(LineStatus::Ignored, $line->fresh()->status);
        $this->assertNotNull($line->fresh()->ignored_at);
    }

    /** A settlement's refund row carries the gateway's refund id. */
    public function test_settlement_refund_claims_by_gateway_id(): void
    {
        config(['billing.gateway' => 'simulator']);
        $refund = $this->refund('100.00', 'card', 'rfd_sim_01jbkx0000000000000000000c');

        $import = app(StatementImports::class)->import(StatementKind::Settlement, 'settlement.csv',
            (string) file_get_contents(base_path('tests/Fixtures/statements/settlement.csv')), null, null, (string) $this->operations->id);

        $this->assertSame(LineStatus::Matched, $this->line($import, 3)->status);
        $match = ReconciliationMatch::query()->where('status', MatchStatus::Confirmed->value)->sole();
        $this->assertTrue($match->auto_confirmed);
        $this->assertTrue($match->items()->where('refund_id', $refund->id)->exists());
    }

    private function refund(string $amount, string $method, ?string $gatewayReference = null): Refund
    {
        return Refund::acrossTenants()->create([
            'org_id' => $this->acme->id, 'currency' => 'SAR', 'credited_amount' => '0.00', 'payout_amount' => $amount,
            'payout_method' => $method, 'reference' => null, 'gateway_reference' => $gatewayReference,
            'reason' => 'Paid back an overpayment', 'refunded_on' => '2026-10-02',
        ]);
    }
}
