<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\MatchRule;
use App\Domains\Billing\Reconciliation\MatchStatus;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Reconciliation\Services\Matcher;
use App\Domains\Billing\Reconciliation\Services\Reconciliation;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Reconciliation\StatementRejected;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What reconciliation must never do: import a statement twice, match a
 * line twice, record money twice, confirm by itself what it is not sure
 * of - or change an issued invoice. And how well it finds the right
 * invoice.
 */
class ReconciliationSafetyTest extends TestCase
{
    use ReconcilesStatements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReconciliation();
    }

    /** The same statement again, or one overlapping it: nothing new happens. */
    public function test_importing_again_changes_nothing(): void
    {
        $this->invoice('1000');
        $mt940 = (string) file_get_contents(base_path('tests/Fixtures/statements/mt940.txt'));
        $imports = app(StatementImports::class);
        $imports->import(StatementKind::Mt940, 'mt940.txt', $mt940, $this->account, null, null);
        $before = $this->counts();

        try {
            $imports->import(StatementKind::Mt940, 'mt940-copy.txt', $mt940, $this->account, null, null);
            $this->fail('The same statement was imported twice.');
        } catch (StatementRejected) {
        }

        // The next statement repeats the last days: its known lines are skipped.
        $overlap = str_replace([':20:STMT261007', ':28C:00214/001'], [':20:STMT261008', ':28C:00215/001'], $mt940);
        $import = $imports->import(StatementKind::Mt940, 'mt940-next.txt', $overlap, $this->account, null, null);

        $this->assertSame(0, $import->lines_new);
        $this->assertSame(3, $import->lines_duplicate);
        $this->assertSame($before, $this->counts());
        $this->assertSame(1, Payment::query()->withoutGlobalScopes()->count());
    }

    public function test_matching_again_changes_nothing(): void
    {
        $this->invoice('1000');
        $this->invoice('300');
        $this->statement([['2026-10-03', '1150.00', 'PLT-1'], ['2026-10-04', '345.00', '', 'TRANSFER']]);
        $before = $this->counts();

        app(Matcher::class)->suggestFor(StatementLine::query()->get());

        $this->assertSame($before, $this->counts());
    }

    /**
     * The issued tax invoice - its row, its lines, its XML and hash - is
     * the same after a match, its undoing, a match by hand and a split.
     */
    public function test_an_issued_invoice_is_never_changed(): void
    {
        $first = $this->invoice('1000');
        $second = $this->invoice('500');
        $before = $this->invoices();

        $import = $this->statement([['2026-10-03', '1150.00', 'PLT-1'], ['2026-10-04', '700.00', 'PLT-1 PLT-2']]);
        $auto = ReconciliationMatch::query()->where('status', MatchStatus::Confirmed->value)->sole();
        app(Reconciliation::class)->unmatch($auto, 'Checking', (string) $this->operations->id);
        app(Reconciliation::class)->manual([(string) $this->line($import, 1)->id], (string) $this->acme->id, [(string) $first->id => '1150.00'], [], (string) $this->operations->id);
        $suggested = ReconciliationMatch::query()->where('status', MatchStatus::Suggested->value)->first();
        app(Reconciliation::class)->split($suggested, [(string) $second->id => '575.00'], (string) $this->operations->id);

        $this->assertSame(Receivable::query()->withoutGlobalScopes()->count(), 2);
        $this->assertSame($before, $this->invoices());
    }

    /**
     * Who each line is from, case by case: the strongest evidence wins,
     * nothing ambiguous goes through by itself, and a near number is not
     * the number.
     */
    public function test_matching_finds_the_right_invoice(): void
    {
        $acmeLarge = $this->invoice('1000');                 // PLT-1, 1150.00
        $betaLarge = $this->invoice('1000', $this->beta);    // PLT-2, 1150.00
        $acmeSmall = $this->invoice('300', number: 'PLT-12'); // 345.00

        $cases = [
            // [line, expected confirmed (receivable or null), expected best suggestion (receivable or null)]
            'reference and amount' => [['2026-10-03', '1150.00', 'PLT-2'], $betaLarge, null],
            'reference written loosely' => [['2026-10-03', '345.00', '', 'payment plt 12 thank you'], $acmeSmall, null],
            'a near number is not the number' => [['2026-10-03', '575.00', 'PLT-120'], null, null],
            'the same amount twice is ambiguous' => [['2026-10-04', '1150.00', '', 'TRANSFER'], null, 'ambiguous'],
            'the payer named breaks the tie' => [['2026-10-04', '1150.00', '', 'TRANSFER', 'ACME TRADING CO'], null, $acmeLarge],
            'before the invoice and its window' => [['2026-09-01', '345.00', '', 'TRANSFER'], null, null],
        ];

        foreach ($cases as $name => [$row, $confirmed, $suggested]) {
            $line = $this->line($this->statement([$row]));
            $matches = ReconciliationMatch::query()->with('items')->whereHas('items', fn ($query) => $query->where('statement_line_id', $line->id))->orderByDesc('confidence')->get();
            $best = $matches->first();
            $target = fn (?ReconciliationMatch $match): ?string => $match?->items->firstWhere('role', 'receivable')?->receivable_id;

            if ($confirmed !== null) {
                $this->assertSame(LineStatus::Matched, $line->status, $name);
                $this->assertSame((string) $confirmed->id, (string) $target($best), $name);
                app(Reconciliation::class)->unmatch($best, 'Test case', null);
            } elseif ($suggested === 'ambiguous') {
                $this->assertGreaterThan(1, $matches->count(), $name);
                $this->assertTrue($matches->every(fn ($match) => $match->status === MatchStatus::Suggested && $match->confidence < Matcher::HIGH), $name);
            } elseif ($suggested !== null) {
                $this->assertSame(MatchStatus::Suggested, $best->status, $name);
                $this->assertSame((string) $suggested->id, (string) $target($best), $name);
                $this->assertContains('payer_name', $best->basis, $name);
            } else {
                $this->assertTrue($matches->every(fn ($match) => $match->rule !== MatchRule::Reference && ! in_array('invoice_number', (array) $match->basis, true)), $name);
                $this->assertNotSame(LineStatus::Matched, $line->status, $name);
            }

            // Each case on its own: what it suggested is set aside.
            if ($line->fresh()->status !== LineStatus::Matched) {
                app(Reconciliation::class)->ignore($line->fresh(), 'Next case', null);
            }
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'lines' => StatementLine::query()->count(),
            'matches' => ReconciliationMatch::query()->count(),
            'payments' => Payment::query()->withoutGlobalScopes()->count(),
            'allocations' => PaymentAllocation::query()->withoutGlobalScopes()->count(),
        ];
    }

    /**
     * Every issued invoice and its lines, each row's columns by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function invoices(): array
    {
        $rows = [];

        foreach (Invoice::withoutTenantScope(fn () => Invoice::query()->orderBy('id')->get()) as $invoice) {
            $attributes = $invoice->getRawOriginal();
            ksort($attributes);
            $rows[(string) $invoice->id] = $attributes;
        }

        foreach (InvoiceLine::query()->withoutGlobalScopes()->orderBy('id')->get() as $line) {
            $attributes = $line->getRawOriginal();
            ksort($attributes);
            $rows['line:'.$line->id] = $attributes;
        }

        return $rows;
    }
}
