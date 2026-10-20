<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Reconciliation;

use App\Domains\Auth\Models\User;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Reconciliation\Models\StatementImport;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Engine\DTOs\IssueOutcome;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Support\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * A platform that bills two customers, a bank account to be paid into,
 * a CSV profile for its statements, and invoices numbered PLT-1, PLT-2 ...
 * so statements can be written line by line in a test.
 */
trait ReconcilesStatements
{
    protected Organization $acme;

    protected Organization $beta;

    protected User $operations;

    protected string $account;

    protected string $profile;

    private int $statements = 0;

    protected function setUpReconciliation(): void
    {
        Storage::fake(StatementImports::DISK);
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
        $this->beta = Organization::create([
            'name' => 'Beta Logistics', 'name_ar' => 'بيتا للخدمات اللوجستية', 'country' => 'SA', 'vat_number' => '310987654300003',
            'street' => 'Tahlia Street', 'building_number' => '4321', 'district' => 'Rawdah', 'city' => 'Jeddah', 'postal_code' => '23435',
        ]);

        $this->mock(InvoiceIssuer::class, fn ($mock) => $mock->shouldReceive('issue')->andReturn(IssueOutcome::issuedOnly()));

        $this->operations = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);
        $this->operations->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        $settings = app(FinanceSettings::class);
        $settings->addBankAccount(['currency' => 'SAR', 'bank_name' => 'Riyadh Commercial Bank', 'account_name' => 'Platform Operator Co.', 'iban' => 'SA0380000000608010167519'], null);
        $this->account = $settings->bankAccounts()[0]['key'];
        $settings->addStatementProfile([
            'name' => 'Test bank', 'bank_account' => $this->account, 'delimiter' => 'comma', 'encoding' => 'UTF-8', 'date_format' => 'Y-m-d',
            'decimal' => '.', 'skip_rows' => '0', 'date' => 'Date', 'amount' => 'Amount', 'reference' => 'Reference',
            'description' => 'Description', 'counterparty' => 'Payer',
        ], null);
        $this->profile = $settings->statementProfiles()[0]['key'];
    }

    /** An invoice to the customer for a net amount (VAT on top), numbered PLT-n. */
    protected function invoice(string $net, ?Organization $customer = null, ?string $number = null): Receivable
    {
        $receivable = app(Receivables::class)->issue($customer ?? $this->acme, new ReceivableDraft('Subscription', 'اشتراك', $net), null);
        $number ??= 'PLT-'.Receivable::query()->withoutGlobalScopes()->count();

        Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->sole()
            ->forceFill(['invoice_number' => $number, 'status' => 'accepted', 'hash' => base64_encode(hash('sha256', $number, true))])->save());

        return $receivable->fresh();
    }

    /**
     * A bank statement of the given lines, imported (and matched).
     *
     * @param  list<array{0: string, 1: string, 2?: string, 3?: string, 4?: string}>  $lines  [date, amount, reference, description, payer]
     */
    protected function statement(array $lines): StatementImport
    {
        // A statement number in a column no profile reads, so two statements
        // alike are two files.
        $csv = "Date,Amount,Reference,Description,Payer,Statement\n";
        $this->statements++;

        foreach ($lines as $line) {
            $csv .= implode(',', [$line[0], $line[1], $line[2] ?? '', $line[3] ?? '', $line[4] ?? '', $this->statements])."\n";
        }

        return app(StatementImports::class)->import(StatementKind::Csv, 'statement.csv', $csv, null, $this->profile, (string) $this->operations->id);
    }

    protected function line(StatementImport $import, int $sequence = 1): StatementLine
    {
        return $import->lines()->where('sequence', $sequence)->sole();
    }
}
