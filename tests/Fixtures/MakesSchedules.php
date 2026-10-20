<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Organization\Models\Organization;

/**
 * A Saudi organization, its admin, and schedules billing one buyer.
 */
trait MakesSchedules
{
    private Organization $acme;

    private User $admin;

    private function makeOrganization(): void
    {
        $this->acme = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '399999999900003',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'cr_number' => '1010101010',
        ]);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $invoice
     */
    private function makeSchedule(array $attributes = [], array $invoice = []): InvoiceSchedule
    {
        return app(Schedules::class)->create($this->acme, [
            'name' => 'Beta retainer',
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            ...$attributes,
        ], [...$this->scheduledInvoice(), ...$invoice]);
    }

    /**
     * A standard invoice to a business, as the API takes one.
     *
     * @return array<string, mixed>
     */
    private function scheduledInvoice(): array
    {
        return [
            'type' => 'standard',
            'buyer_name' => 'Beta Industries',
            'buyer_name_ar' => 'شركة بيتا للصناعات',
            'buyer_vat_number' => '399999999800003',
            'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
            'lines' => [['description' => 'Monthly retainer', 'description_ar' => 'اشتراك شهري', 'quantity' => 1, 'unit_price' => '1000.00']],
        ];
    }
}
