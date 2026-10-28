<?php

declare(strict_types=1);

namespace Tests\Feature\Books;

use App\Domains\Books\Services\VatPeriods;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\VatReturn as SaudiReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnBox;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M13: the Saudi return says where each document in its figures stands with
 * ZATCA, and ties to box 6, so an accountant can match it to the filing
 * record before filing.
 *
 * March, all standard rated: 100 cleared, 200 sent with no answer yet, 50
 * not sent yet, 300 that did not get through, and 999 ZATCA rejected.
 */
class ReturnFilingStatusTest extends TestCase
{
    use BooksFixtures;
    use RefreshDatabase;

    private Organization $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = $this->saudi();
        $this->answered($this->sale($this->acme, [[100, 15]]), 'accepted', 'cleared');
        $this->answered($this->sale($this->acme, [[200, 15]]), 'issued', 'submitted');
        $this->sale($this->acme, [[50, 15]]);
        $this->answered($this->sale($this->acme, [[300, 15]]), 'issued', 'failed');
        $this->answered($this->sale($this->acme, [[999, 15]]), 'rejected', 'rejected');
    }

    public function test_return_splits_sales_by_filing(): void
    {
        $draft = $this->draft();

        $this->assertSame([100.0, 0.0, 15.0], $this->figures($draft->box('filing-accepted')));
        $this->assertSame([250.0, 0.0, 37.5], $this->figures($draft->box('filing-waiting')));
        $this->assertSame([300.0, 0.0, 45.0], $this->figures($draft->box('filing-held')));
        $this->assertSame($this->figures($draft->box('6')), $this->figures($draft->box('filing-total')));
        $this->assertSame([999.0, 0.0, 149.85], $this->figures($draft->box('filing-rejected')));
        $this->assertCount(2, $draft->box('filing-waiting')->entries, 'the waiting documents are not listed');
    }

    public function test_return_says_what_is_included(): void
    {
        $draft = $this->draft();

        $this->assertNotEmpty(array_filter($draft->notes, fn (string $note): bool => str_contains($note, 'Rejected documents are left out')));
        $this->assertNotEmpty(array_filter($draft->problems, fn (string $problem): bool => str_contains($problem, '3 documents in these figures are not yet cleared or reported by ZATCA')));
    }

    /** What ZATCA answers later is not a change to a filed return's figures. */
    public function test_filing_status_is_not_snapshotted(): void
    {
        $this->assertSame([], array_filter(array_keys(VatPeriods::figures($this->draft())['boxes']), fn (string $key): bool => str_starts_with($key, 'filing-')));
    }

    public function test_return_page_shows_filing_status(): void
    {
        $query = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];

        $this->as($this->acme, 'owner')->get(route('portal.books.vat-return', $query))->assertOk()
            ->assertSee('Filing status with ZATCA')
            ->assertSee('Rejected by ZATCA, left out of the return');
        $this->as($this->acme, 'owner')->get(route('portal.books.vat-return.box', ['box' => 'filing-held', ...$query]))->assertOk()
            ->assertSee('INV-4');
        $this->assertStringContainsString('filing-total', $this->as($this->acme, 'owner')->get(route('portal.books.vat-return.csv', $query))->streamedContent());
    }

    /** The sales report says the same for the branches it shows. */
    public function test_sales_report_shows_filing_status(): void
    {
        $this->as($this->acme, 'viewer')->get(route('portal.reports'))->assertOk()
            ->assertSee('Filing status with ZATCA')
            ->assertSee('Not filed: did not get through, to be sent again');
    }

    private function draft(): VatReturnDraft
    {
        return app(SaudiReturn::class)->draft((string) $this->acme->id, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth());
    }

    /** @return array{0: float|null, 1: float|null, 2: float|null} */
    private function figures(?VatReturnBox $box): array
    {
        $this->assertNotNull($box);

        return [$box->amount, $box->adjustment, $box->vat];
    }

    private function answered(Invoice $invoice, string $status, string $state): void
    {
        Invoice::withoutTenantScope(fn () => $status === 'issued' ? null : $invoice->forceFill(['status' => $status])->save());
        InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::create([
            'invoice_id' => $invoice->id, 'org_id' => $this->acme->id, 'state' => $state,
            'submission_type' => 'clearance', 'submission_mode' => 'async',
        ]));
    }
}
