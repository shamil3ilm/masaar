<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\RecurringSupply;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\ScheduleRunner;
use App\Domains\Invoice\Services\Schedules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A UAE schedule's invoice: a continuous supply, its invoicing period and
 * billing frequency stated (UAE Electronic Invoicing Guidelines v1.1 p27;
 * BTAE-02, IBG-14, BTAE-06). PintConformanceTest puts it before the
 * official rules.
 */
class ScheduledSupplyTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_a_run_states_period_and_frequency(): void
    {
        $xml = $this->runXml('monthly', null);

        $this->assertStringContainsString('<cbc:ProfileExecutionID>00001000</cbc:ProfileExecutionID>', $xml);
        $this->assertMatchesRegularExpression('#<cac:InvoicePeriod>\s*<cbc:StartDate>2026-11-01</cbc:StartDate>\s*<cbc:EndDate>2026-11-30</cbc:EndDate>\s*<cbc:DescriptionCode>MTH</cbc:DescriptionCode>#', $xml);
        $this->assertStringNotContainsString('<cbc:Note>', $xml);
    }

    public function test_a_term_is_others_in_words(): void
    {
        $xml = $this->runXml('termly', [['start_date' => '2026-11-01', 'end_date' => '2027-01-31']]);

        $this->assertStringContainsString('<cbc:DescriptionCode>OTH</cbc:DescriptionCode>', $xml);
        $this->assertStringContainsString('<cbc:Note>'.RecurringSupply::TERM_NOTE.'</cbc:Note>', $xml);
    }

    /**
     * @param  list<array{start_date: string, end_date: string}>|null  $terms
     */
    private function runXml(string $frequency, ?array $terms): string
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
        $organization = $this->uaeOrganization();

        app(Schedules::class)->create($organization, [
            'name' => 'Noor maintenance', 'frequency' => $frequency, 'start_date' => '2026-11-01', 'terms' => $terms, 'due_days' => 30,
        ], [
            'document_type' => 'invoice', 'document_category' => 'tax', 'currency' => 'AED', 'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC', 'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'],
            'buyer_endpoint' => '0235:1007654321', 'buyer_subdivision' => 'AUH', 'transaction_flags' => '00000000',
            'lines' => [['item_name' => 'Maintenance', 'quantity' => 1, 'unit_code' => 'MON', 'unit_price' => '1200.00', 'tax_category' => 'S', 'tax_rate' => 5]],
        ]);
        app(ScheduleRunner::class)->runDue();

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->sole());
        $invoice->forceFill(['invoice_number' => 'AE-SCHEDULED-1', 'status' => 'issued'])->save();

        return app(PeppolPreparer::class)->prepare(Invoice::withoutTenantScope(fn () => $invoice->fresh(['lines', 'org'])))->xml;
    }
}
