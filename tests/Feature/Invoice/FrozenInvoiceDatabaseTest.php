<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The database refuses what the model refuses, for a write that never goes
 * through the model (migration 1814). MySQL only: the triggers are not
 * created on SQLite, where the model's guard is tested (FinalizedInvoiceTest).
 */
class FrozenInvoiceDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The invoice triggers exist on MySQL only.');
        }

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_a_direct_total_update_is_refused(): void
    {
        $invoice = $this->invoice('issued', ['hash' => 'aGFzaA==']);

        $this->assertRefused(fn () => $this->row($invoice)->update(['total' => '1.00']));
        $this->assertRefused(fn () => $this->row($invoice)->update(['notes' => 'Added later']));
        $this->assertRefused(fn () => $this->row($invoice)->update(['status' => 'draft']));

        $this->assertSame('115.00', (string) $invoice->fresh()->total);
    }

    public function test_the_stamp_is_written_once(): void
    {
        $invoice = $this->invoice('issued');

        $this->row($invoice)->update(['hash' => 'Zmlyc3Q=', 'signed_xml' => '<Invoice/>', 'qr_code' => 'UVI=']);

        $this->assertRefused(fn () => $this->row($invoice)->update(['hash' => 'c2Vjb25k']));
        $this->assertRefused(fn () => $this->row($invoice)->update(['signed_xml' => '<Invoice>2</Invoice>']));
        $this->assertRefused(fn () => $this->row($invoice)->update(['qr_code' => 'b3RoZXI=']));
    }

    public function test_clearance_lands_once(): void
    {
        $invoice = $this->invoice('issued', ['hash' => 'aGFzaA==', 'signed_xml' => '<Invoice/>', 'qr_code' => 'UVI=']);

        $this->row($invoice)->update(['status' => 'accepted', 'zatca_response' => json_encode(['ok' => true]), 'cleared_xml' => '<Cleared/>', 'qr_code' => 'Q0xFQVJFRA==']);

        $this->assertSame('Q0xFQVJFRA==', $invoice->fresh()->qr_code);
        $this->assertRefused(fn () => $this->row($invoice)->update(['cleared_xml' => '<Other/>']));
        $this->assertRefused(fn () => $this->row($invoice)->update(['qr_code' => 'UVI=']));
    }

    public function test_an_issued_row_is_not_deleted(): void
    {
        $invoice = $this->invoice('issued');

        $this->assertRefused(fn () => $this->row($invoice)->delete());

        $this->assertNotNull($invoice->fresh());
    }

    public function test_issued_lines_are_refused_directly(): void
    {
        $invoice = $this->invoice('draft');
        $line = InvoiceLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '15', 'tax_amount' => '15.00', 'line_total' => '115.00']);
        $this->row($invoice)->update(['status' => 'issued']);

        $this->assertRefused(fn () => DB::table('invoice_lines')->where('id', $line->id)->update(['unit_price' => '1.00']));
        $this->assertRefused(fn () => DB::table('invoice_lines')->where('id', $line->id)->delete());
        $this->assertRefused(fn () => DB::table('invoice_lines')->insert([
            'id' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'description' => 'More', 'quantity' => '1',
            'unit_price' => '1.00', 'tax_rate' => '15', 'tax_amount' => '0.15', 'line_total' => '1.15',
        ]));
    }

    public function test_a_draft_still_changes_freely(): void
    {
        $invoice = $this->invoice('draft');

        $this->row($invoice)->update(['total' => '230.00', 'notes' => 'Revised']);
        $this->row($invoice)->delete();

        $this->assertSame(0, $this->row($invoice)->count());
    }

    private function row(Invoice $invoice): Builder
    {
        return DB::table('invoices')->where('id', $invoice->id);
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString('45000', $e->getMessage());

            return;
        }

        $this->fail('The database allowed a change to an issued invoice.');
    }

    /** @param array<string, mixed> $extra */
    private function invoice(string $status, array $extra = []): Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'status' => $status,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            ...$extra,
        ]));
    }
}
