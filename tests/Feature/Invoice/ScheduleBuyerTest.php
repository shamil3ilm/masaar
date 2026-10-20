<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\ScheduleRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;

/**
 * A schedule bills its saved customer as the customer stands at each run:
 * a new address or name reaches the next invoice, and an invoice already
 * issued keeps the buyer it was issued to.
 */
class ScheduleBuyerTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
        $this->customer = Customer::withoutTenantScope(fn () => Customer::query()->create([
            'org_id' => $this->acme->id, 'name' => 'Beta Industries', 'name_ar' => 'شركة بيتا للصناعات', 'vat_number' => '399999999800003',
            'street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA',
        ]));
    }

    public function test_each_run_takes_the_customer_now(): void
    {
        $this->makeSchedule(['customer_id' => $this->customer->id, 'auto_issue' => true], ['buyer_name' => 'Old name']);
        app(ScheduleRunner::class)->runDue();

        $this->customer->update(['name' => 'Beta Industries Holding', 'street' => 'King Abdullah Road', 'building_number' => '7788']);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00', 'UTC'));
        app(ScheduleRunner::class)->runDue();

        [$november, $december] = Invoice::withoutTenantScope(fn () => Invoice::query()->orderBy('supply_date')->get())->all();

        // The customer's, not the old name the template was made with.
        $this->assertSame(['Beta Industries', 'Olaya Street'], [$november->buyer_name, $november->buyer_address['street']]);
        $this->assertSame(['Beta Industries Holding', 'King Abdullah Road', '7788'], [$december->buyer_name, $december->buyer_address['street'], $december->buyer_address['building_number']]);
        $this->assertNotSame(InvoiceStatus::Draft, $november->status);
        $this->assertSame($this->customer->id, $december->customer_id);
    }

    public function test_a_removed_customer_leaves_the_snapshot(): void
    {
        $this->makeSchedule(['customer_id' => $this->customer->id]);
        $this->customer->delete();

        app(ScheduleRunner::class)->runDue();

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::query()->sole());
        $this->assertSame(['Beta Industries', 'Olaya Street'], [$invoice->buyer_name, $invoice->buyer_address['street']]);
    }
}
