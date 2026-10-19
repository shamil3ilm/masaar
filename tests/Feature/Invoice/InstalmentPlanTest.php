<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Enums\ScheduleRunStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Services\ScheduleRunner;
use App\Domains\Invoice\Services\Schedules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;

/**
 * Instalment plans: one agreed total in parts. Each part an invoice for a
 * supply delivered, or, paid ahead of it, a prepayment invoice the last
 * part settles (E-Invoicing Detailed Guidelines 8.1, p40-43).
 */
class InstalmentPlanTest extends TestCase
{
    use MakesSchedules;
    use PintInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC'));
        $this->makeOrganization();
    }

    public function test_parts_add_up_to_the_total(): void
    {
        $this->makeSchedule(['start_date' => '2026-09-01', 'occurrences' => 3, 'instalment_model' => 'invoice']);

        $this->runUntil('2026-11-01');

        $invoices = $this->invoices();
        $this->assertSame(['333.33', '333.33', '333.34'], $invoices->map(fn (Invoice $invoice): string => (string) $invoice->subtotal)->all());
        $this->assertSame('1000.00', bcadd(bcadd((string) $invoices[0]->subtotal, (string) $invoices[1]->subtotal, 2), (string) $invoices[2]->subtotal, 2));
        $this->assertSame([DocumentType::Invoice], $invoices->pluck('document_type')->unique()->values()->all());
        $this->assertSame('Monthly retainer - instalment 3 of 3', $invoices[2]->lines[0]->description);
        $this->assertSame('اشتراك شهري - القسط 3 من 3', $invoices[2]->lines[0]->description_ar);
    }

    public function test_the_last_part_settles_the_advances(): void
    {
        $this->makeSchedule(['start_date' => '2026-09-01', 'occurrences' => 3, 'instalment_model' => 'prepayment', 'auto_issue' => true],
            ['lines' => [['description' => 'Annual tuition', 'description_ar' => 'الرسوم الدراسية السنوية', 'quantity' => 1, 'unit_price' => '30000.00']]]);

        $this->runUntil('2026-11-01');

        $invoices = $this->invoices();
        $this->assertSame([DocumentType::Prepayment, DocumentType::Prepayment, DocumentType::Invoice], $invoices->pluck('document_type')->all());
        $this->assertSame([ScheduleRunStatus::Issued], InvoiceScheduleRun::query()->pluck('status')->unique()->values()->all());

        $final = $invoices[2]->load('prepayments');
        // The whole supply and its VAT, both advances off it, the last
        // share to pay: 34500 less 2 x 11500.
        $this->assertSame(['34500.00', '23000.00', '11500.00'], [(string) $final->total, (string) $final->prepaid_amount, $final->payable()]);
        $this->assertSame([$invoices[0]->invoice_number, $invoices[1]->invoice_number], $final->prepayments->pluck('invoice_number')->all());
        $this->assertSame(['2026-09-01', '2026-11-30'], [$final->supply_date->toDateString(), $final->supply_end_date->toDateString()]);
    }

    public function test_an_unissued_advance_holds_the_final(): void
    {
        $this->makeSchedule(['start_date' => '2026-09-01', 'occurrences' => 2, 'instalment_model' => 'prepayment']);

        $this->runUntil('2026-10-01');

        $final = InvoiceScheduleRun::query()->where('period', 1)->sole();
        $this->assertSame(ScheduleRunStatus::Failed, $final->status);
        $this->assertStringContainsString('Instalment 1 has not been issued', (string) $final->error);
        $this->assertSame(InvoiceStatus::Draft, $this->invoices()->sole()->status);

        // Issued, the final is made on the next try, though the plan has
        // no period left to run.
        $this->invoices()->sole()->forceFill(['invoice_number' => 'ADV-1', 'status' => 'issued', 'hash' => base64_encode('ADV-1'), 'issued_at' => now()])->save();
        $this->travel(16)->minutes();
        app(ScheduleRunner::class)->runDue();

        $this->assertSame(ScheduleRunStatus::Drafted, $final->fresh()->status);
        $this->assertSame('ADV-1', $this->invoices()->last()->load('prepayments')->prepayments->sole()->invoice_number);
    }

    public function test_a_plan_has_two_plain_parts(): void
    {
        $this->assertRefused(['occurrences' => 1, 'instalment_model' => 'invoice'], [], 'occurrences');
        $this->assertRefused(['occurrences' => 3, 'instalment_model' => 'invoice'], ['discount_amount' => '10.00'], 'invoice.lines');
    }

    public function test_prepayments_only_where_issued(): void
    {
        $this->acme = $this->uaeOrganization();

        try {
            app(Schedules::class)->create($this->acme, ['name' => 'Fees', 'frequency' => 'monthly', 'start_date' => '2026-09-01', 'occurrences' => 3, 'instalment_model' => 'prepayment'], [
                'document_type' => 'invoice', 'document_category' => 'tax', 'currency' => 'AED',
                'lines' => [['item_name' => 'Fees', 'quantity' => 1, 'unit_price' => '900.00', 'tax_category' => 'S', 'tax_rate' => 5]],
            ]);
            $this->fail('the plan was made');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instalment_model', $e->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $invoice
     */
    private function assertRefused(array $attributes, array $invoice, string $field): void
    {
        try {
            $this->makeSchedule($attributes, $invoice);
            $this->fail('the plan was made');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    /** The runner, on the first of each month up to and including the date. */
    private function runUntil(string $date): void
    {
        for ($day = CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC'); $day->toDateString() <= $date; $day = $day->addMonthNoOverflow()) {
            $this->travelTo($day);
            app(ScheduleRunner::class)->runDue();
        }
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function invoices(): Collection
    {
        return Invoice::withoutTenantScope(fn () => Invoice::query()->with('lines')->orderBy('supply_end_date')->get());
    }
}
