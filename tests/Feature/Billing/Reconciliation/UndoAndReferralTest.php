<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Billing\Models\AccountCredit;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Services\Reconciliation;
use App\Domains\Billing\Services\AccountCredits;
use App\Domains\Referral\DTOs\ReferralTerms;
use App\Domains\Referral\Enums\ReferralStatus;
use App\Domains\Referral\Enums\RewardStatus;
use App\Domains\Referral\Models\Referral;
use App\Domains\Referral\Models\ReferralReward;
use App\Domains\Referral\Services\Referrals;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Undoing a match whose payment was the customer's only paid invoice:
 * a referral that qualified on that first paid invoice is taken back -
 * or, when its reward's credit was already used, left as it is, on the
 * record and flagged for an admin, the undo going through either way.
 * And an undo refused for a spent credit names the invoice that spent it.
 */
class UndoAndReferralTest extends TestCase
{
    use ReconcilesStatements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReconciliation();
    }

    public function test_undo_takes_back_the_referral(): void
    {
        $referral = $this->referral();
        $reward = $this->reward($referral, used: false);
        $match = $this->paidByStatement();

        app(Reconciliation::class)->unmatch($match, 'Another customer\'s transfer', (string) $this->operations->id);

        $this->assertSame(ReferralStatus::Reversed, $referral->fresh()->status);
        $this->assertSame(Referrals::PAYMENT_REVERSED, $referral->fresh()->reversal_reason);
        $this->assertSame(RewardStatus::Reversed, $reward->fresh()->status);
        $this->assertNull($referral->fresh()->review_flagged_at);
    }

    public function test_a_used_reward_flags_not_blocks(): void
    {
        $referral = $this->referral();
        $reward = $this->reward($referral, used: true);
        $match = $this->paidByStatement();

        app(Reconciliation::class)->unmatch($match, 'Another customer\'s transfer', (string) $this->operations->id);

        $this->assertSame(MatchStatus::Reversed, $match->fresh()->status);
        $this->assertSame(ReferralStatus::Rewarded, $referral->fresh()->status);
        $this->assertSame(RewardStatus::Granted, $reward->fresh()->status);
        $this->assertNotNull($referral->fresh()->review_flagged_at);
        $this->assertStringContainsString('used on an invoice', (string) $referral->fresh()->review_reason);
        $this->assertTrue(AuditLog::query()->where('action', 'referral.review_flagged')->where('entity_id', $referral->id)->exists());

        $this->actingAs($this->operations)->get(route('admin.referrals.index'))->assertOk()->assertSee(__('Needs review'));
        $this->actingAs($this->operations)->get(route('admin.referrals.show', $referral->id))->assertOk()->assertSee(Referrals::PAYMENT_REVERSED);
    }

    /** Another invoice still paid: the referral stands. */
    public function test_another_paid_invoice_keeps_the_referral(): void
    {
        $referral = $this->referral();
        $this->invoice('100');
        $this->statement([['2026-10-02', '115.00', 'PLT-1']]);
        $match = $this->paidByStatement('PLT-2');

        app(Reconciliation::class)->unmatch($match, 'Wrong', (string) $this->operations->id);

        $this->assertSame(ReferralStatus::Rewarded, $referral->fresh()->status);
    }

    public function test_a_spent_credit_names_its_invoice(): void
    {
        $this->invoice('1000');
        $this->statement([['2026-10-03', '1200.00', 'PLT-1']]);
        $match = ReconciliationMatch::query()->sole();
        app(Reconciliation::class)->confirm($match, (string) $this->operations->id, checked: true);
        $this->invoice('100');

        try {
            app(Reconciliation::class)->unmatch($match, 'Wrong', (string) $this->operations->id);
            $this->fail('An undo whose credit was spent went through.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('PLT-2', $e->getMessage());
        }
    }

    /** Beta referred Acme, rewarded on Acme's first paid invoice. */
    private function referral(): Referral
    {
        $referral = new Referral([
            'referrer_org_id' => $this->beta->id, 'org_id' => $this->acme->id, 'code' => 'BETA2026',
            'status' => ReferralStatus::Rewarded, 'terms' => [...ReferralTerms::DEFAULTS, 'enabled' => true, 'qualifying_event' => 'first_paid'],
        ]);
        $referral->save();

        return $referral;
    }

    private function reward(Referral $referral, bool $used): ReferralReward
    {
        $credit = app(AccountCredits::class)->grant((string) $this->beta->id, 'amount', '100.00', null, 'SAR', 'Referral reward', 'referral', (string) $referral->id, null, null);

        if ($used) {
            app(AccountCredits::class)->redeem((string) $credit->id, '0199a000-0000-7000-8000-000000000001', '100.00');
        }

        $reward = new ReferralReward([
            'referral_id' => $referral->id, 'org_id' => $this->beta->id, 'side' => ReferralReward::REFERRER, 'kind' => 'credit',
            'amount' => '100.00', 'currency' => 'SAR', 'status' => RewardStatus::Granted,
        ]);
        $reward->forceFill(['credit_id' => $credit->id])->save();

        $this->assertSame($used ? AccountCredit::REDEEMED : AccountCredit::AVAILABLE, $credit->fresh()->status);

        return $reward;
    }

    /** The customer's invoice paid by a statement line, matched by itself. */
    private function paidByStatement(string $number = 'PLT-1'): ReconciliationMatch
    {
        $this->invoice('1000');
        $this->statement([['2026-10-03', '1150.00', $number]]);

        return ReconciliationMatch::query()->where('status', MatchStatus::Confirmed->value)->latest('confirmed_at')->get()
            ->first(fn (ReconciliationMatch $match) => $match->targets_amount == '1150.00');
    }
}
