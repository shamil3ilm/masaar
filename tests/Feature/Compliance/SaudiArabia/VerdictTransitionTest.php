<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\InvoiceVerdict;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Which answers may move an invoice, and where to.
 *
 * Any answer was written over any status. The case that matters: a filing
 * times out after ZATCA accepted it, the retry is told the invoice was
 * already submitted, and that error was recorded as a rejection - an accepted
 * invoice, cleared copy and all, now read rejected. Acceptance is final; a
 * verdict on a draft is a bug upstream and is refused.
 */
class VerdictTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_an_accepted_invoice_stays_accepted(): void
    {
        $invoice = $this->invoice('accepted', ['cleared_xml' => '<Invoice>STAMPED</Invoice>']);

        app(InvoiceVerdict::class)->record($invoice, FatooraResponse::fromApiResponse([
            'clearanceStatus' => 'NOT_CLEARED',
            'errorMessages' => [['code' => 'Invoice-Errors', 'message' => 'Invoice was already submitted']],
        ]));

        $fresh = $invoice->fresh();
        $this->assertSame(InvoiceStatus::Accepted, $fresh->status);
        $this->assertSame('<Invoice>STAMPED</Invoice>', $fresh->cleared_xml);
    }

    public function test_an_issued_invoice_takes_the_answer(): void
    {
        $invoice = $this->invoice('issued');

        app(InvoiceVerdict::class)->record($invoice, FatooraResponse::fromApiResponse(['reportingStatus' => 'REPORTED']));

        $this->assertSame(InvoiceStatus::Accepted, $invoice->fresh()->status);
    }

    public function test_a_draft_takes_no_verdict(): void
    {
        $invoice = $this->invoice('draft');

        $this->expectException(LogicException::class);

        app(InvoiceVerdict::class)->record($invoice, FatooraResponse::fromApiResponse(['reportingStatus' => 'REPORTED']));
    }

    private function invoice(string $status, array $attributes = []): Invoice
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
            ...$attributes,
        ]));
    }
}
