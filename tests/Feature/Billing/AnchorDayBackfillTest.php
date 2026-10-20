<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domains\Billing\Services\AnchorDayBackfill;
use App\Domains\Billing\Services\Suppliers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Migration 1595's backfill: recurring costs saved before they kept their
 * day of the month take it from their first drafted bill, or from their
 * next date when they drafted none.
 */
class AnchorDayBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_takes_first_bill_else_next(): void
    {
        $supplier = app(Suppliers::class)->save(null, ['name' => 'Cloud Hosting Ltd', 'country' => 'SA', 'currency' => 'SAR', 'payment_terms_days' => 0, 'category' => 'hosting'], null);

        // As 1560 left them: no anchor day. The first drifted from the 31st
        // to the 28th after February; the second never drafted a bill.
        $drifted = $this->recurring((string) $supplier->id, '2027-04-28');
        $fresh = $this->recurring((string) $supplier->id, '2027-06-30');

        foreach (['2027-03-28', '2027-01-31', '2027-02-28'] as $date) {
            $this->draft((string) $supplier->id, $drifted, $date);
        }

        (new AnchorDayBackfill)();

        $this->assertSame([31, 30], [
            (int) DB::table('recurring_expenses')->where('id', $drifted)->value('anchor_day'),
            (int) DB::table('recurring_expenses')->where('id', $fresh)->value('anchor_day'),
        ]);
    }

    private function recurring(string $supplierId, string $next): string
    {
        $id = (string) Str::uuid();
        DB::table('recurring_expenses')->insert([
            'id' => $id, 'supplier_id' => $supplierId, 'description' => 'Servers', 'category' => 'hosting', 'currency' => 'SAR',
            'net' => '100.00', 'vat' => '15.00', 'vat_treatment' => 'recoverable', 'frequency' => 'monthly', 'next_due_on' => $next,
            'active' => true, 'anchor_day' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function draft(string $supplierId, string $recurringId, string $date): void
    {
        DB::table('expenses')->insert([
            'id' => (string) Str::uuid(), 'supplier_id' => $supplierId, 'recurring_expense_id' => $recurringId, 'status' => 'draft',
            'expense_date' => $date, 'due_date' => $date, 'description' => 'Servers', 'category' => 'hosting', 'currency' => 'SAR',
            'net' => '100.00', 'vat' => '15.00', 'gross' => '115.00', 'tax_currency' => 'SAR', 'net_tax' => '100.00', 'vat_tax' => '15.00',
            'gross_tax' => '115.00', 'vat_treatment' => 'recoverable', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
