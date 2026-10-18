<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Mail\LateReportingMail;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * What a seller must do when a simplified invoice is not reported within
 * twenty-four hours of issuance, as ZATCA's Detailed Guidelines for
 * E-Invoicing set it out (p48):
 *
 *   "In case the seller does not manage to share the invoice within 24
 *   hours, he should notify ZATCA via a dedicated form on ZATCA's website."
 *   "Once the connection is restored, merchant should notify ZATCA again."
 *
 * The platform keeps trying meanwhile (OfflineQueue) and keeps the evidence
 * (ReportingAttempts). This tells the organization's administrators, in
 * Arabic and English, when a document passes twenty-four hours unreported
 * and again once it is reported, and records - audited - when someone says
 * they notified ZATCA. late_reports holds one row per document.
 */
class LateReporting
{
    /** What the evidence log is kept and shown for at most, per sweep and page. */
    private const MOST = 500;

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Find documents newly past twenty-four hours, and documents late before
     * that are now reported, and tell their organizations. Run hourly by
     * fatoora:overdue-submissions.
     *
     * @return array{late: int, resolved: int}
     */
    public function sweep(): array
    {
        $late = $this->recordNewlyLate();
        $resolved = $this->recordReported();

        foreach ($this->pending('alerted_at')->groupBy('org_id') as $organizationId => $rows) {
            $this->tell((string) $organizationId, $rows, resolved: false);
            DB::table('late_reports')->whereIn('id', $rows->pluck('id'))->update(['alerted_at' => now(), 'updated_at' => now()]);
        }

        foreach ($this->pending('resolved_alerted_at', reported: true)->groupBy('org_id') as $organizationId => $rows) {
            $this->tell((string) $organizationId, $rows, resolved: true);
            DB::table('late_reports')->whereIn('id', $rows->pluck('id'))->update(['resolved_alerted_at' => now(), 'updated_at' => now()]);
        }

        return ['late' => $late, 'resolved' => $resolved];
    }

    /**
     * An organization's late documents, newest first.
     *
     * @return Collection<int, object>
     */
    public function for(string $organizationId): Collection
    {
        return DB::table('late_reports')
            ->join('invoices', 'invoices.id', '=', 'late_reports.invoice_id')
            ->where('late_reports.org_id', $organizationId)
            ->orderByDesc('late_reports.issued_at')
            ->limit(self::MOST)
            ->get(['late_reports.*', 'invoices.invoice_number']);
    }

    /**
     * Record that someone notified ZATCA through its form: of the delay, or
     * - once reported - that reporting resumed. Audited with who and when.
     *
     * @param  'delay'|'resumed'  $notice
     * @return int How many documents it was recorded for
     */
    public function recordNotified(string $organizationId, string $notice, string $userId): int
    {
        [$at, $by, $where] = $notice === 'resumed'
            ? ['zatca_renotified_at', 'zatca_renotified_by', fn ($query) => $query->whereNotNull('reported_at')]
            : ['zatca_notified_at', 'zatca_notified_by', fn ($query) => $query];

        $rows = DB::table('late_reports')
            ->where('org_id', $organizationId)
            ->whereNull($at)
            ->where($where)
            ->get(['id', 'invoice_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        DB::table('late_reports')->whereIn('id', $rows->pluck('id'))->update([$at => now(), $by => $userId, 'updated_at' => now()]);

        $this->audit->log(
            action: $notice === 'resumed' ? 'zatca.late_reporting.renotified' : 'zatca.late_reporting.notified',
            entity: Organization::query()->find($organizationId),
            newValues: ['invoice_ids' => $rows->pluck('invoice_id')->all(), 'notice' => $notice],
            metadata: ['org_id' => $organizationId, 'source' => 'ZATCA Detailed Guidelines for E-Invoicing, p48'],
        );

        return $rows->count();
    }

    /**
     * Simplified documents issued more than the deadline ago that ZATCA has
     * not accepted, given a row each.
     */
    private function recordNewlyLate(): int
    {
        $cutoff = now()->subMinutes((int) round((float) config('fatoora.reporting.deadline_hours', 24) * 60));

        $invoices = Invoice::withoutTenantScope(fn () => $this->unreported(Invoice::query())
            ->where(fn (Builder $query) => $query
                ->where('issued_at', '<=', $cutoff)
                ->orWhere(fn (Builder $older) => $older->whereNull('issued_at')->where('created_at', '<=', $cutoff)))
            ->whereNotIn('id', DB::table('late_reports')->select('invoice_id'))
            ->limit(self::MOST)
            ->get(['id', 'org_id', 'issued_at', 'created_at']));

        foreach ($invoices as $invoice) {
            DB::table('late_reports')->insertOrIgnore([
                'org_id' => $invoice->org_id,
                'invoice_id' => $invoice->id,
                'issued_at' => $invoice->issuedMoment(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $invoices->count();
    }

    /** Late documents ZATCA has since accepted. */
    private function recordReported(): int
    {
        $stillLate = $this->unreported(Invoice::withoutGlobalScopes())->select('id');

        return DB::table('late_reports')
            ->whereNull('reported_at')
            ->whereNotIn('invoice_id', $stillLate)
            ->update(['reported_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Issued simplified documents ZATCA has not accepted: issued or sent
     * and not answered, as DeadlineReport counts them. A standard invoice is
     * cleared before it reaches the buyer, so no clock runs on it.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function unreported(Builder $query): Builder
    {
        return $query
            ->where('type', 'simplified')
            ->whereNotNull('hash')
            ->whereIn('status', ['issued', 'submitted']);
    }

    /**
     * @return Collection<int, object>
     */
    private function pending(string $column, bool $reported = false): Collection
    {
        return DB::table('late_reports')
            ->join('invoices', 'invoices.id', '=', 'late_reports.invoice_id')
            ->whereNull('late_reports.'.$column)
            ->when($reported, fn ($query) => $query->whereNotNull('late_reports.reported_at'))
            ->orderBy('late_reports.issued_at')
            ->limit(self::MOST)
            ->get(['late_reports.id', 'late_reports.org_id', 'late_reports.issued_at', 'invoices.invoice_number']);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function tell(string $organizationId, Collection $rows, bool $resolved): void
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return;
        }

        $admins = $organization->users()
            ->wherePivotIn('role', MemberRole::ADMINISTERING)
            ->wherePivot('status', 'active')
            ->where('users.status', 'active')
            ->get();

        $documents = $rows->map(fn (object $row): array => ['number' => (string) $row->invoice_number, 'issued_at' => (string) $row->issued_at])->values()->all();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin)->send(new LateReportingMail(
                    organizationName: (string) $organization->name,
                    documents: $documents,
                    resolved: $resolved,
                    url: route('portal.zatca.late'),
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
