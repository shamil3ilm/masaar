<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionRate;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Submissions are capped per device, under a ceiling for the organization.
 *
 * The caps were per organization alone, so a chain of shops shared one
 * budget across every till: a busy branch used up its neighbours' minute,
 * and the day's allowance ran out by mid-afternoon. Each device now has its
 * own budget, and the organization's ceiling still bounds them all.
 */
class SubmissionRateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Branch $jeddah;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fatoora.rate_limits.device_per_minute' => 3,
            'fatoora.rate_limits.device_per_day' => 5,
            'fatoora.rate_limits.per_minute' => 4,
            'fatoora.rate_limits.per_day' => 8,
        ]);

        $this->acme = Organization::create(['name' => 'Acme Retail', 'country' => 'SA']);
        $this->jeddah = Branch::withoutTenantScope(fn () => Branch::create([
            'org_id' => $this->acme->id,
            'name' => 'Jeddah Till',
            'device_serial' => 'EGS-JED-1',
            'street' => 'Corniche Road',
            'building_number' => '4321',
            'district' => 'Al Hamra',
            'city' => 'Jeddah',
            'postal_code' => '23234',
        ]));
    }

    public function test_a_busy_device_is_capped(): void
    {
        $this->submitted(3, $this->acme->id);

        $this->assertRefused(ErrorCode::RATE_LIMIT_EXCEEDED, $this->invoice($this->acme->id), 'this device');
    }

    public function test_another_device_keeps_its_budget(): void
    {
        $this->submitted(3, $this->acme->id);

        app(SubmissionRate::class)->assertWithin($this->invoice($this->jeddah->id));

        $this->addToAssertionCount(1);
    }

    public function test_the_organization_ceiling_still_holds(): void
    {
        $this->submitted(2, $this->acme->id);
        $this->submitted(2, $this->jeddah->id);

        $this->assertRefused(ErrorCode::RATE_LIMIT_EXCEEDED, $this->invoice($this->jeddah->id), 'this organization');
    }

    public function test_a_device_day_is_capped(): void
    {
        $this->submitted(5, $this->acme->id, now()->subMinutes(5));

        $this->assertRefused(ErrorCode::RATE_DAILY_LIMIT, $this->invoice($this->acme->id), 'this device');
    }

    public function test_the_organization_day_still_holds(): void
    {
        $this->submitted(4, $this->acme->id, now()->subMinutes(5));
        $this->submitted(4, $this->jeddah->id, now()->subMinutes(5));

        $this->assertRefused(ErrorCode::RATE_DAILY_LIMIT, $this->invoice($this->jeddah->id), 'this organization');
    }

    private function assertRefused(ErrorCode $code, Invoice $invoice, string $whose): void
    {
        try {
            app(SubmissionRate::class)->assertWithin($invoice);
            $this->fail('The submission was allowed past its cap.');
        } catch (FatooraException $e) {
            $this->assertSame($code, $e->getErrorCode());
            $this->assertStringContainsString($whose, $e->getMessage());
        }
    }

    /**
     * $count submissions of issued invoices from one device.
     */
    private function submitted(int $count, string $device, mixed $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $invoice = $this->invoice($device);

            DB::table('invoice_submissions')->insert([
                'id' => (string) Str::uuid(),
                'invoice_id' => $invoice->id,
                'org_id' => $this->acme->id,
                'state' => 'reported',
                'created_at' => $at ?? now(),
                'updated_at' => $at ?? now(),
            ]);
        }
    }

    private function invoice(string $device): Invoice
    {
        $icv = ++$this->counter;

        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'branch_id' => $device === $this->acme->id ? null : $device,
            'device_id' => $device,
            'icv' => $icv,
            'invoice_number' => 'INV-'.$icv,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));
    }
}
