<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\DeadlineReport;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The twenty-four hour reporting deadline, including the invoices that had no
 * row to be counted by.
 *
 * Every population in DeadlineReport read invoice_submissions, and the
 * offline path writes none - OfflineFallback::queueForOffline creates the
 * queue item and the signed document and stops. So an invoice filed during an
 * outage was invisible to this report, to fatoora:overdue-submissions and to
 * /portal/submissions: the three places anyone would look. The one population
 * that could not be seen was the one most likely to be late, because being
 * unable to reach the authority is what put it there.
 *
 * ZATCA's Detailed E-Invoicing Guidelines (v2, May 2023) make that a
 * compliance problem rather than a display one. Where the authority cannot be
 * reached the seller is to keep retrying at intervals, and if it still cannot
 * report within twenty-four hours it must notify ZATCA through the form on
 * their website. An obligation that falls due in silence cannot be met.
 */
class DeadlineReportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_an_unrecorded_invoice_is_counted(): void
    {
        $this->issued('POS-1', hoursAgo: 30);

        $breakdown = app(DeadlineReport::class)->breakdown();

        $this->assertSame(1, $breakdown['unrecorded_total'], 'an invoice with no submission row went uncounted');
        $this->assertSame('POS-1', $breakdown['unrecorded']->first()['invoice_number']);
        $this->assertGreaterThan(24.0, $breakdown['unrecorded']->first()['age_hours']);
    }

    /**
     * Inside the deadline it is not yet a breach.
     */
    public function test_a_recent_invoice_is_not_counted(): void
    {
        $this->issued('POS-2', hoursAgo: 2);

        $this->assertSame(0, app(DeadlineReport::class)->breakdown()['unrecorded_total']);
    }

    /**
     * A standard invoice is cleared before it is issued, so an unsubmitted one
     * has reached nobody and no clock is running on it.
     */
    public function test_a_standard_invoice_is_not_counted(): void
    {
        $this->issued('INV-1', hoursAgo: 30, type: 'standard');

        $this->assertSame(0, app(DeadlineReport::class)->breakdown()['unrecorded_total']);
    }

    /**
     * And one that does have a submission row is left to the populations that
     * read them, so the breach is not counted twice.
     */
    public function test_a_recorded_invoice_is_not_doubled(): void
    {
        $invoice = $this->issued('POS-3', hoursAgo: 30);

        InvoiceSubmission::withoutTenantScope(function () use ($invoice): void {
            $submission = InvoiceSubmission::create([
                'invoice_id' => $invoice->id,
                'org_id' => $this->organization->id,
                'state' => 'pending_submission',
                'submission_type' => 'reporting',
                'submission_mode' => 'sync',
                'state_changed_at' => now(),
            ]);

            // Backdated with the invoice. The submission populations measure
            // from their own created_at, so a row written now is not late
            // however old the invoice is - which is what the first version of
            // this test got wrong.
            $submission->forceFill(['created_at' => now()->subHours(30)])->save();
        });

        $breakdown = app(DeadlineReport::class)->breakdown();

        $this->assertSame(0, $breakdown['unrecorded_total'], 'an invoice was counted twice');
        $this->assertSame(1, $breakdown['overdue_total'], 'the submission populations stopped seeing it');
    }

    private function issued(string $number, int $hoursAgo, string $type = 'simplified'): Invoice
    {
        return Invoice::withoutTenantScope(function () use ($number, $hoursAgo, $type): Invoice {
            $invoice = Invoice::create([
                'org_id' => $this->organization->id,
                'invoice_number' => $number,
                'type' => $type,
                'status' => 'issued',
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'buyer_name' => 'Walk-in',
                'subtotal' => '100.00',
                'tax_amount' => '15.00',
                'total' => '115.00',
                'icv' => 1,
                'hash' => base64_encode(hash('sha256', $number, true)),
            ]);

            // created_at is what the deadline is measured from. Moved by a
            // query, not the model: an issued invoice refuses any change to a
            // column outside the mutable list, and this is the passage of
            // time, not an edit.
            Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)
                ->update(['created_at' => now()->subHours($hoursAgo)]));

            return $invoice->fresh();
        });
    }
}
