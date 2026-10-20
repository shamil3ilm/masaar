<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin › Finance › Reconciliation through the console: every admin reads
 * it, billing.manage acts, and each action does what the workbench says.
 */
class ReconciliationScreensTest extends TestCase
{
    use ReconcilesStatements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReconciliation();
    }

    public function test_every_reconciliation_page_opens(): void
    {
        $this->invoice('1000');
        $line = $this->line($this->statement([['2026-10-04', '1150.00', '', 'INCOMING TRANSFER', 'Acme Trading'], ['2026-10-05', '35.00', '', 'CHARGES']]));
        $this->statement([['2026-10-06', '-20.00', '', 'FEE']]);

        $this->actingAs($this->operations);
        $this->get(route('admin.finance.reconciliation.imports'))->assertOk()->assertSee(__('Import a statement'));
        $this->get(route('admin.finance.reconciliation.lines', ['status' => 'suggested']))->assertOk()->assertSee('INCOMING TRANSFER');
        $this->get(route('admin.finance.reconciliation.line', [$line->id, 'customer' => $this->acme->id]))->assertOk()
            ->assertSee(__('Confirm a match that is :confidence% sure?', ['confidence' => ReconciliationMatch::query()->sole()->confidence]), false)
            ->assertSee('PLT-1');
        $this->get(route('admin.finance.reconciliation.exceptions'))->assertOk()->assertSee('INCOMING TRANSFER');
        $this->get(route('admin.finance.reconciliation.reports'))->assertOk()->assertSee('SAR');
        $this->get(route('admin.finance.reconciliation.lines.export'))->assertOk()->assertDownload();
    }

    public function test_workbench_confirms_rejects_and_undoes(): void
    {
        $invoice = $this->invoice('1000');
        $line = $this->line($this->statement([['2026-10-04', '1150.00', '', 'INCOMING TRANSFER']]));
        $match = ReconciliationMatch::query()->sole();
        $this->actingAs($this->operations);

        // Not said to be checked: refused, with why.
        $this->post(route('admin.finance.reconciliation.match.confirm', $match->id))->assertSessionHasErrors('reconciliation');
        $this->assertSame(MatchStatus::Suggested, $match->fresh()->status);

        $this->post(route('admin.finance.reconciliation.match.confirm', $match->id), ['checked' => 1])
            ->assertRedirect(route('admin.finance.reconciliation.line', $line->id));
        $this->assertSame(ReceivableStatus::Paid, $invoice->fresh()->status);

        $this->post(route('admin.finance.reconciliation.match.unmatch', $match->id), ['reason' => 'Another customer\'s transfer'])->assertSessionHasNoErrors();
        $this->assertSame(ReceivableStatus::Open, $invoice->fresh()->status);
        $this->assertSame(LineStatus::Unmatched, $line->fresh()->status);

        $this->post(route('admin.finance.reconciliation.line.suggest', $line->id))->assertSessionHasNoErrors();
        $again = ReconciliationMatch::query()->where('status', MatchStatus::Suggested->value)->sole();
        $this->post(route('admin.finance.reconciliation.match.reject', $again->id))->assertSessionHasNoErrors();
        $this->assertSame(MatchStatus::Rejected, $again->fresh()->status);
    }

    public function test_match_by_hand_and_split(): void
    {
        $first = $this->invoice('1000');
        $second = $this->invoice('500');
        $import = $this->statement([['2026-10-04', '500.00', '', 'PAYMENT'], ['2026-10-05', '1000.00', 'PLT-1 PLT-2']]);
        $this->actingAs($this->operations);

        $this->post(route('admin.finance.reconciliation.line.match', $this->line($import, 1)->id), [
            'organization' => $this->acme->id, 'amounts' => [(string) $first->id => '500.00'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('650.00', $first->fresh()->balance());

        $suggested = ReconciliationMatch::query()->where('status', MatchStatus::Suggested->value)->get()
            ->first(fn ($match) => $match->items()->where('statement_line_id', $this->line($import, 2)->id)->exists());
        $this->post(route('admin.finance.reconciliation.match.split', $suggested->id), [
            'amounts' => [(string) $first->id => '425.00', (string) $second->id => '575.00'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('225.00', $first->fresh()->balance());
        $this->assertSame(ReceivableStatus::Paid, $second->fresh()->status);
    }

    public function test_set_aside_and_bring_back(): void
    {
        $line = $this->line($this->statement([['2026-10-04', '9000.00', '', 'OWN ACCOUNT TRANSFER']]));
        $this->actingAs($this->operations);

        $this->post(route('admin.finance.reconciliation.line.ignore', $line->id), ['reason' => 'Between our own accounts'])->assertSessionHasNoErrors();
        $this->assertSame(LineStatus::Ignored, $line->fresh()->status);

        $this->post(route('admin.finance.reconciliation.line.restore', $line->id))->assertSessionHasNoErrors();
        $this->assertSame(LineStatus::Unmatched, $line->fresh()->status);
    }

    public function test_reading_is_everyones_acting_is_billings(): void
    {
        $this->invoice('1000');
        $line = $this->line($this->statement([['2026-10-04', '1150.00', '', 'INCOMING TRANSFER']]));
        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $support->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($support);

        $this->get(route('admin.finance.reconciliation.line', $line->id))->assertOk()->assertDontSee(__('Match by hand'));
        $this->post(route('admin.finance.reconciliation.match.confirm', ReconciliationMatch::query()->sole()->id), ['checked' => 1])->assertForbidden();
        $this->post(route('admin.finance.reconciliation.line.ignore', $line->id), ['reason' => 'x'])->assertForbidden();
        $this->assertSame(MatchStatus::Suggested, ReconciliationMatch::query()->sole()->status);
    }
}
