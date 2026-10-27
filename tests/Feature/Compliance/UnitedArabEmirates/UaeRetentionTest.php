<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Issuing\InvoiceIssuer;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Issued UAE invoices and their XML are kept: data on the issuance,
 * transmission and receipt of e-invoices is retained 5 years after its tax
 * period, more under audit or dispute (UAE Electronic Invoicing Guidelines
 * v1.1, 5.4), retrievable and intact wherever it is stored (Appendix 4,
 * Article 11 of Ministerial Decision No. 243 of 2025) - and an invoice kept
 * before e-invoicing started is the taxpayer's only record of it. Nothing
 * the platform schedules prunes them, and neither model can be pruned.
 */
class UaeRetentionTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_models_are_not_prunable(): void
    {
        foreach ([Invoice::class, InvoiceLine::class, PeppolDocument::class] as $model) {
            $traits = class_uses_recursive($model);

            $this->assertNotContains(Prunable::class, $traits, $model.' must never be pruned.');
            $this->assertNotContains(MassPrunable::class, $traits, $model.' must never be pruned.');
        }
    }

    public function test_scheduled_pruning_keeps_issued_invoices(): void
    {
        Queue::fake();
        $kept = app(InvoiceIssuer::class)->issue($this->draft($this->uaeOrganization(['einvoicing_start' => null], appointed: false)));
        $sent = app(InvoiceIssuer::class)->issue($this->draft($this->uaeOrganization()));

        // Past every keeping period the platform prunes anything by.
        $this->travel(10 * 366)->days();

        $ran = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (preg_match("/artisan[\"']?\\s+(\\S*(?:prune|clean)\\S*.*)$/", (string) $event->command, $match) !== 1 || str_starts_with($match[1], 'backup:')) {
                continue;
            }

            Artisan::call($match[1]);
            $ran[] = $match[1];
        }

        $this->assertNotEmpty($ran, 'No scheduled pruning was found to run.');

        foreach ([$kept, $sent] as $document) {
            $stored = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->find($document->id));
            $this->assertNotNull($stored, 'An issued UAE e-invoice was pruned by: '.implode(', ', $ran));
            $this->assertSame($document->xml, $stored->xml);
            $this->assertNotNull(Invoice::withoutTenantScope(fn () => Invoice::query()->find($document->invoice_id)));
        }
    }

    private function draft(Organization $organization): Invoice
    {
        $invoice = $this->uaeInvoice($organization, ['invoice_number' => null, 'subtotal' => '1000.00', 'tax_amount' => '50.00', 'total' => '1050.00'], issued: false);

        return Invoice::query()->findOrFail($invoice->id);
    }
}
