<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Billing\Reconciliation\DTOs\ParsedLine;
use App\Domains\Billing\Reconciliation\LineStatus;
use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Reconciliation\StatementRejected;
use App\Domains\Billing\Settings\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bank statements (CSV by a bank's profile, camt.053, MT940) and the card
 * gateway's settlement report brought in: each line once, each statement
 * once, and only an account's own statement in its own currency.
 */
class StatementImportTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'SA0380000000608010167519';

    private User $operations;

    private string $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StatementImports::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));

        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $this->operations->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $settings = app(FinanceSettings::class);
        $settings->addBankAccount(['currency' => 'SAR', 'bank_name' => 'Riyadh Commercial Bank', 'account_name' => 'Platform Operator Co.', 'iban' => self::IBAN], null);
        $this->account = $settings->bankAccounts()[0]['key'];
    }

    public function test_camt053_keeps_booked_lines_only(): void
    {
        $import = $this->import(StatementKind::Camt053, 'camt053.xml');

        $this->assertSame('STMT-2026-10-001', $import->statement_reference);
        $this->assertSame('10000.00', (string) $import->opening_balance);
        $this->assertSame('11690.00', (string) $import->closing_balance);
        $this->assertSame('2026-10-01', $import->period_from->toDateString());
        $this->assertSame(3, $import->lines_new);

        $lines = $import->lines()->orderBy('sequence')->get();
        $this->assertSame(['1150.00', '575.00', '35.00'], $lines->map(fn ($line) => (string) $line->amount)->all());
        $this->assertSame('PLT-1', $lines[0]->reference);
        $this->assertSame('BNK26100300001', $lines[0]->bank_reference);
        $this->assertSame('Acme Trading', $lines[0]->counterparty_name);
        $this->assertSame('SA4420000001234567891234', $lines[0]->counterparty_account);
        $this->assertStringContainsString('Invoice PLT-1', (string) $lines[0]->description);
        $this->assertSame(LineStatus::Unmatched, $lines[0]->status);
        $this->assertSame(ParsedLine::DEBIT, $lines[2]->direction);
        $this->assertSame(LineStatus::Ignored, $lines[2]->status);
        $this->assertTrue(AuditLog::query()->where('action', 'finance.statement.imported')->where('entity_id', $import->id)->exists());
    }

    public function test_mt940_reads_references_and_details(): void
    {
        $import = $this->import(StatementKind::Mt940, 'mt940.txt');

        $this->assertSame('STMT261007/00214/001', $import->statement_reference);
        $this->assertSame(self::IBAN, $import->account_identifier);
        $lines = $import->lines()->orderBy('sequence')->get();
        $this->assertCount(3, $lines);
        $this->assertSame('PLT-1', $lines[0]->reference);
        $this->assertSame('BNK261003001', $lines[0]->bank_reference);
        $this->assertStringContainsString('ACME TRADING', (string) $lines[0]->description);
        $this->assertNull($lines[1]->reference);
        $this->assertSame('2026-10-04', $lines[1]->booked_on->toDateString());
        $this->assertSame(ParsedLine::DEBIT, $lines[2]->direction);
    }

    public function test_csv_profile_reads_decimal_commas(): void
    {
        $import = $this->import(StatementKind::Csv, 'statement.csv', profile: $this->profile());

        $lines = $import->lines()->orderBy('sequence')->get();
        $this->assertSame(['1150.00', '575.00', '35.00'], $lines->map(fn ($line) => (string) $line->amount)->all());
        $this->assertSame('2026-10-03', $lines[0]->booked_on->toDateString());
        $this->assertSame('SAR', $lines[0]->currency);
        $this->assertStringContainsString('تحويل وارد', (string) $lines[0]->description);
        $this->assertSame(ParsedLine::DEBIT, $lines[2]->direction);
    }

    public function test_csv_in_windows_1256_is_read(): void
    {
        $bytes = (string) iconv('UTF-8', 'Windows-1256', (string) file_get_contents($this->fixture('statement.csv')));

        $import = app(StatementImports::class)->import(StatementKind::Csv, 'statement.csv', $bytes, null, $this->profile(['encoding' => 'Windows-1256']), null);

        $this->assertStringContainsString('رسوم الحساب', (string) $import->lines()->orderBy('sequence')->get()[2]->description);
    }

    public function test_settlement_keeps_gross_fee_and_transaction(): void
    {
        config(['billing.gateway' => 'simulator']);

        $import = $this->import(StatementKind::Settlement, 'settlement.csv');

        $this->assertSame(StatementImport::GATEWAY, $import->source);
        $this->assertSame('STL-20261006-01', $import->statement_reference);
        $lines = $import->lines()->orderBy('sequence')->get();
        $this->assertSame('1150.00', (string) $lines[0]->amount);
        $this->assertSame('28.75', (string) $lines[0]->fee_amount);
        $this->assertSame('txn_sim_01jbkx0000000000000000000a', $lines[0]->gateway_reference);
        $this->assertSame(ParsedLine::DEBIT, $lines[2]->direction);
    }

    public function test_settlement_needs_a_card_gateway(): void
    {
        config(['billing.gateway' => null]);

        $this->expectException(StatementRejected::class);

        $this->import(StatementKind::Settlement, 'settlement.csv');
    }

    public function test_the_same_file_is_refused(): void
    {
        $this->import(StatementKind::Camt053, 'camt053.xml');

        try {
            $this->import(StatementKind::Camt053, 'camt053.xml');
            $this->fail('The same file was imported twice.');
        } catch (StatementRejected $e) {
            $this->assertStringContainsString('already imported', $e->getMessage());
        }

        $this->assertSame(1, StatementImport::query()->count());
        $this->assertSame(3, StatementLine::query()->count());
    }

    public function test_a_statement_id_is_imported_once(): void
    {
        $this->import(StatementKind::Camt053, 'camt053.xml');
        // The same statement re-exported: other bytes, the same id.
        $again = str_replace('<MsgId>MSG-20261007-0001</MsgId>', '<MsgId>MSG-20261008-0002</MsgId>', (string) file_get_contents($this->fixture('camt053.xml')));

        $this->expectException(StatementRejected::class);

        app(StatementImports::class)->import(StatementKind::Camt053, 'again.xml', $again, $this->account, null, null);
    }

    /** A statement overlapping one already in: its known lines are skipped. */
    public function test_overlapping_statements_skip_known_lines(): void
    {
        $this->import(StatementKind::Mt940, 'mt940.txt');
        $overlap = str_replace(
            [':20:STMT261007', ':28C:00214/001', ':62F:C261005SAR11690,00'],
            [':20:STMT261009', ':28C:00215/001', ":61:2610081008C200,00NTRFPLT-9//BNK261008004\n:86:BETA LLC PLT-9\n:62F:C261008SAR11890,00"],
            (string) file_get_contents($this->fixture('mt940.txt')),
        );

        $import = app(StatementImports::class)->import(StatementKind::Mt940, 'overlap.txt', $overlap, $this->account, null, null);

        $this->assertSame(1, $import->lines_new);
        $this->assertSame(3, $import->lines_duplicate);
        $this->assertSame(4, StatementLine::query()->count());
    }

    public function test_another_accounts_statement_is_refused(): void
    {
        $other = str_replace(self::IBAN, 'SA4420000001234567891234', (string) file_get_contents($this->fixture('camt053.xml')));

        try {
            app(StatementImports::class)->import(StatementKind::Camt053, 'other.xml', $other, $this->account, null, null);
            $this->fail('Another account\'s statement was imported.');
        } catch (StatementRejected $e) {
            $this->assertStringContainsString('SA4420000001234567891234', $e->getMessage());
        }

        $this->assertSame(0, StatementLine::query()->count());
    }

    public function test_another_currencys_statement_is_refused(): void
    {
        $dirhams = str_replace(['Ccy="SAR"', '<Ccy>SAR</Ccy>'], ['Ccy="AED"', '<Ccy>AED</Ccy>'], (string) file_get_contents($this->fixture('camt053.xml')));

        $this->expectException(StatementRejected::class);

        app(StatementImports::class)->import(StatementKind::Camt053, 'aed.xml', $dirhams, $this->account, null, null);
    }

    public function test_an_unbalanced_statement_is_refused(): void
    {
        $cut = str_replace(":61:2610041004C575,00NTRFNONREF//BNK261004002\n:86:ACME SUBSCRIPTION\n", '', str_replace("\r\n", "\n", (string) file_get_contents($this->fixture('mt940.txt'))));

        try {
            app(StatementImports::class)->import(StatementKind::Mt940, 'cut.txt', $cut, $this->account, null, null);
            $this->fail('A statement that does not add up was imported.');
        } catch (StatementRejected $e) {
            $this->assertStringContainsString('does not add up', $e->getMessage());
        }
    }

    public function test_profiles_are_kept_in_finance_settings(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.settings.profiles.store'), [
            'name' => 'Riyadh Commercial Bank CSV', 'bank_account' => $this->account, 'delimiter' => 'semicolon', 'encoding' => 'UTF-8',
            'date_format' => 'd/m/Y', 'decimal' => ',', 'skip_rows' => 1, 'date' => 'Date', 'credit' => 'Credit', 'debit' => 'Debit', 'description' => 'Description',
        ])->assertRedirect(route('admin.finance.settings'))->assertSessionHasNoErrors();

        $profile = app(FinanceSettings::class)->statementProfiles()[0];
        $this->assertSame('semicolon', $profile['delimiter']);
        $this->assertSame('Credit', $profile['credit']);

        $this->actingAs($this->operations)->get(route('admin.finance.settings'))->assertOk()->assertSee('Riyadh Commercial Bank CSV');

        // Neither a date nor an amount column: refused.
        $this->actingAs($this->operations)->post(route('admin.finance.settings.profiles.store'), [
            'name' => 'Broken', 'bank_account' => $this->account, 'delimiter' => 'comma', 'encoding' => 'UTF-8', 'date_format' => 'Y-m-d', 'decimal' => '.',
        ])->assertSessionHasErrors('date');
    }

    public function test_upload_is_kept_privately_then_pruned(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.reconciliation.imports.store'), [
            'kind' => 'camt053', 'bank_account' => $this->account,
            'file' => UploadedFile::fake()->createWithContent('camt053.xml', (string) file_get_contents($this->fixture('camt053.xml'))),
        ])->assertRedirect(route('admin.finance.reconciliation.imports'))->assertSessionHasNoErrors();

        $import = StatementImport::query()->sole();
        Storage::disk(StatementImports::DISK)->assertExists((string) $import->file_path);
        $this->assertSame((string) $this->operations->id, (string) $import->created_by);

        $this->actingAs($this->operations)->get(route('admin.finance.reconciliation.imports'))->assertOk()->assertSee('STMT-2026-10-001');

        $this->travel(91)->days();
        $this->artisan('finance:prune-statements')->assertSuccessful();

        Storage::disk(StatementImports::DISK)->assertMissing((string) $import->file_path);
        $this->assertNull($import->fresh()->file_path);
        $this->assertSame(3, StatementLine::query()->count());
    }

    public function test_a_refused_upload_says_why(): void
    {
        $this->actingAs($this->operations)->post(route('admin.finance.reconciliation.imports.store'), [
            'kind' => 'mt940', 'bank_account' => $this->account,
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Just some notes, not a statement.'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, StatementImport::query()->count());
        $this->assertSame([], Storage::disk(StatementImports::DISK)->allFiles());
    }

    private function import(StatementKind $kind, string $fixture, ?string $profile = null): StatementImport
    {
        return app(StatementImports::class)->import($kind, $fixture, (string) file_get_contents($this->fixture($fixture)),
            $kind === StatementKind::Settlement ? null : $this->account, $profile, (string) $this->operations->id);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function profile(array $overrides = []): string
    {
        app(FinanceSettings::class)->addStatementProfile([
            'name' => 'Bank CSV', 'bank_account' => $this->account, 'delimiter' => 'semicolon', 'encoding' => 'UTF-8', 'date_format' => 'd/m/Y',
            'decimal' => ',', 'skip_rows' => '1', 'date' => 'Date', 'value_date' => 'Value date', 'credit' => 'Credit', 'debit' => 'Debit',
            'reference' => 'Reference', 'description' => 'Description', ...$overrides,
        ], null);

        return (string) collect(app(FinanceSettings::class)->statementProfiles())->last()['key'];
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/statements/'.$name);
    }
}
