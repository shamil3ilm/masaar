<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * An issued invoice is a tax document, and that includes its lines.
 *
 * The guard listed the fields that could not change, so anything left off the
 * list could: the five flags that make up the signed document's type name -
 * export, summary, nominal, third party, self-billed - and every column added
 * since. The list of what may change after issue was written and never read.
 * It is now the rule, so a new column is frozen until someone decides
 * otherwise. Lines had no guard at all.
 */
class FinalizedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_the_signed_type_flags_are_frozen(): void
    {
        $invoice = $this->invoice('issued');

        $this->expectException(RuntimeException::class);

        $invoice->update(['is_export' => true]);
    }

    public function test_the_authoritys_answer_still_lands(): void
    {
        $invoice = $this->invoice('issued');

        $invoice->update(['status' => 'accepted', 'zatca_response' => ['clearanceStatus' => 'CLEARED'], 'notes' => 'Paid']);

        $this->assertSame('Paid', $invoice->fresh()->notes);
    }

    public function test_an_issued_line_is_frozen(): void
    {
        $invoice = $this->invoice('draft');
        $line = $this->line($invoice);
        $invoice->update(['status' => 'issued']);

        $this->assertRefused(fn () => $line->update(['unit_price' => '1.00']));
        $this->assertRefused(fn () => $line->delete());
        $this->assertRefused(fn () => $this->line($line->invoice));

        $this->assertSame('100.00', (string) $line->fresh()->unit_price);
    }

    public function test_a_drafts_lines_can_change(): void
    {
        $line = $this->line($this->invoice('draft'));

        $line->update(['unit_price' => '90.00']);
        $line->delete();

        $this->assertNull($line->fresh());
    }

    private function assertRefused(callable $change): void
    {
        try {
            $change();
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('A change to an issued invoice was allowed.');
    }

    /** Accepted is final: no write takes it back to issued or rejected. */
    public function test_an_acceptance_cannot_be_undone(): void
    {
        $invoice = $this->invoice('accepted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'accepted' to 'rejected'");

        $invoice->update(['status' => 'rejected']);
    }

    public function test_an_issued_invoice_stays_issued(): void
    {
        $invoice = $this->invoice('issued');

        $this->expectException(RuntimeException::class);

        $invoice->update(['status' => 'draft']);
    }

    private function invoice(string $status): Invoice
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
        ]));
    }

    private function line(Invoice $invoice): InvoiceLine
    {
        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);
    }
}
