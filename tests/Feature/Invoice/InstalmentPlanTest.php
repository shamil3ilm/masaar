<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Audit\Models\AuditLog;
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
use Tymon\JWTAuth\Facades\JWTAuth;

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
        $this->makeSchedule(['start_date' => '2026-09-01', 'occurrences' => 3, 'instalment_model' => 'periodic']);

        $this->runUntil('2026-11-01');

        $invoices = $this->invoices();
        $this->assertSame(['333.33', '333.33', '333.34'], $invoices->map(fn (Invoice $invoice): string => (string) $invoice->subtotal)->all());
        $this->assertSame('1000.00', bcadd(bcadd((string) $invoices[0]->subtotal, (string) $invoices[1]->subtotal, 2), (string) $invoices[2]->subtotal, 2));
        $this->assertSame([DocumentType::Invoice], $invoices->pluck('document_type')->unique()->values()->all());
        $this->assertSame('Monthly retainer - instalment 3 of 3', $invoices[2]->lines[0]->description);
        $this->assertSame('اشتراك شهري - القسط 3 من 3', $invoices[2]->lines[0]->description_ar);
    }

    /**
     * Delivered up front: one invoice for the whole on delivery (Unified VAT
     * Agreement 23(1), 23(2)), the instalments payments due against it.
     */
    public function test_delivered_is_one_invoice_and_payments(): void
    {
        $schedule = $this->makeSchedule(['start_date' => '2026-09-01', 'occurrences' => 3, 'instalment_model' => 'delivered'], ['discount_amount' => '100.00']);

        $this->runUntil('2026-11-01');

        $invoice = $this->invoices()->sole();
        // 1000 less 100, and 15% VAT on 900.
        $this->assertSame(['1035.00', '2026-09-01', null, DocumentType::Invoice], [
            (string) $invoice->total, $invoice->supply_date->toDateString(), $invoice->supply_end_date, $invoice->document_type,
        ]);

        $runs = InvoiceScheduleRun::query()->orderBy('period')->get();
        $this->assertSame([ScheduleRunStatus::Drafted, ScheduleRunStatus::Due, ScheduleRunStatus::Due], $runs->pluck('status')->all());
        $this->assertSame(['345.00', '345.00', '345.00'], $runs->map(fn (InvoiceScheduleRun $run): string => (string) $run->amount_due)->all());
        $this->assertSame([$invoice->id], $runs->pluck('invoice_id')->unique()->values()->all());

        $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.schedules.show', $schedule->id))->assertOk()->assertSee('Record paid')->assertSee('345.00'));

        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);
        $this->withToken($token)->postJson("/api/schedules/{$schedule->id}/runs/{$runs[1]->id}/paid")->assertOk();
        $this->withToken($token)->postJson("/api/schedules/{$schedule->id}/runs/{$runs[1]->id}/paid")->assertStatus(409);

        $this->assertNotNull($runs[1]->fresh()->paid_at);
        $this->assertSame('345.00', AuditLog::query()->where('action', 'schedule.instalment_paid')->sole()->metadata['amount']);
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
        $this->assertRefused(['occurrences' => 1, 'instalment_model' => 'periodic'], [], 'occurrences');
        $this->assertRefused(['occurrences' => 3, 'instalment_model' => 'periodic'], ['discount_amount' => '10.00'], 'invoice.lines');
        $this->assertRefused(['occurrences' => 3, 'instalment_model' => 'prepayment', 'bills_in' => 'arrears'], [], 'bills_in');
        $this->assertRefused(['occurrences' => 3, 'instalment_model' => 'delivered', 'bills_in' => 'arrears'], [], 'bills_in');
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
        return Invoice::acrossTenants()->with('lines')->orderBy('supply_end_date')->get();
    }
}
