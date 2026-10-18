<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\LateReportingMail;
use App\Domains\Compliance\Countries\SaudiArabia\Models\OfflineItem;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A simplified invoice not reported within twenty-four hours, handled as
 * ZATCA's Detailed Guidelines for E-Invoicing ask:
 *
 * - keep trying at regular intervals, and keep evidence of the attempts
 *   ("TP should keep evidence of trying to clear the invoice to ZATCA",
 *   p46, p49);
 * - past twenty-four hours, the seller notifies ZATCA through the dedicated
 *   form on its website, and again once reported (p48).
 *
 * The organization's administrators are told in Arabic and English, and
 * someone's record that they notified ZATCA is audited.
 */
class LateReportingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->acme = Organization::create(['name' => 'Acme Retail', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_a_late_invoice_alerts_bilingually(): void
    {
        $this->issued('TILL-1', hoursAgo: 25);
        $this->issued('TILL-2', hoursAgo: 2);

        Artisan::call('fatoora:overdue-submissions', ['--alert' => true]);

        Mail::assertSent(LateReportingMail::class, function (LateReportingMail $mail): bool {
            $html = $mail->render();

            return ! $mail->resolved
                && array_column($mail->documents, 'number') === ['TILL-1']
                && str_contains($html, 'zatca.gov.sa')
                && str_contains($html, 'أبلغ الهيئة الآن')
                && str_contains($html, 'Notify ZATCA now');
        });
        $this->assertSame(1, DB::table('late_reports')->whereNotNull('alerted_at')->count());

        Artisan::call('fatoora:overdue-submissions', ['--alert' => true]);
        Mail::assertSent(LateReportingMail::class, 1);
    }

    /** Reported at last, the organization is asked to notify ZATCA again. */
    public function test_once_reported_it_asks_again(): void
    {
        $invoice = $this->issued('TILL-1', hoursAgo: 30);
        Artisan::call('fatoora:overdue-submissions', ['--alert' => true]);

        $invoice->forceFill(['status' => 'accepted'])->saveQuietly();
        Artisan::call('fatoora:overdue-submissions', ['--alert' => true]);

        Mail::assertSent(LateReportingMail::class, fn (LateReportingMail $mail): bool => $mail->resolved && str_contains($mail->render(), 'notify ZATCA again'));
        $this->assertNotNull(DB::table('late_reports')->value('reported_at'));
    }

    public function test_notices_are_recorded_and_audited(): void
    {
        $invoice = $this->issued('TILL-1', hoursAgo: 30);
        Artisan::call('fatoora:overdue-submissions', ['--alert' => true]);
        $portal = fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id]);

        $portal()->get(route('portal.zatca.late'))->assertOk()->assertSee('TILL-1')->assertSee('zatca.gov.sa');

        // Not reported yet: there is nothing to notify again about.
        $portal()->post(route('portal.zatca.late.notified'), ['notice' => 'resumed'])->assertRedirect();
        $this->assertNull(DB::table('late_reports')->value('zatca_renotified_at'));

        $portal()->post(route('portal.zatca.late.notified'), ['notice' => 'delay'])->assertRedirect(route('portal.zatca.late'));
        $row = DB::table('late_reports')->first();
        $this->assertNotNull($row->zatca_notified_at);
        $this->assertSame($this->admin->id, $row->zatca_notified_by);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'zatca.late_reporting.notified')->where('user_id', $this->admin->id)->count());

        $invoice->forceFill(['status' => 'accepted'])->saveQuietly();
        Artisan::call('fatoora:overdue-submissions', ['--alert' => true]);
        $portal()->post(route('portal.zatca.late.notified'), ['notice' => 'resumed'])->assertRedirect();
        $this->assertNotNull(DB::table('late_reports')->value('zatca_renotified_at'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'zatca.late_reporting.renotified')->count());
    }

    public function test_only_an_administrator_records(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.zatca.late.notified'), ['notice' => 'delay'])
            ->assertForbidden();
    }

    /**
     * ZATCA unreachable: the queue tries again, at an interval that stops
     * growing at fifteen minutes, until the document is filed - never giving
     * up on a retryable failure - and every attempt is evidence.
     */
    public function test_retrying_never_stops_and_is_evidence(): void
    {
        $invoice = $this->issued('TILL-1', hoursAgo: 26);
        $queue = app(OfflineQueue::class);
        $item = OfflineItem::findOrFail($queue->queue($invoice, '<Invoice/>', 'HASH', 'QR')['queue_id']);

        foreach (range(1, 12) as $ignored) {
            $queue->markFailed($item->id, 'ZATCA unreachable: connection timed out');
        }

        $item->refresh();
        $this->assertSame(OfflineItem::PENDING, $item->state);
        $this->assertSame(12, $item->attempts);
        $this->assertLessThanOrEqual(15 * 60 + 5, now()->diffInSeconds($item->next_attempt_at));

        $queue->markCompleted($item->id, ['reportingStatus' => 'REPORTED']);
        $this->assertSame(['failed' => 12, 'filed' => 1], DB::table('reporting_attempts')->where('invoice_id', $invoice->id)
            ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n) => (int) $n)->sortKeys()->all());

        Artisan::call('fatoora:overdue-submissions', ['--alert' => true]);
        $csv = $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.zatca.late.evidence'))->assertOk()->streamedContent();
        $this->assertSame(14, substr_count($csv, "\n"), 'a header and one line for each attempt');
        $this->assertStringContainsString('ZATCA unreachable: connection timed out', $csv);
    }

    private function issued(string $number, int $hoursAgo): Invoice
    {
        return Invoice::withoutTenantScope(fn () => tap(Invoice::query()->create([
            'org_id' => $this->acme->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->subHours($hoursAgo)->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]), fn (Invoice $invoice) => $invoice->forceFill([
            'status' => 'issued',
            'hash' => base64_encode($number),
            'icv' => 1,
            'issued_at' => now()->subHours($hoursAgo),
        ])->saveQuietly()));
    }
}
