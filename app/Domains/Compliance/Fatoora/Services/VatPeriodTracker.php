<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * VAT Period Tracker.
 *
 * Handles cross-period VAT adjustments for:
 * - Credit notes issued after original invoice's VAT period closed
 * - Debit notes across periods
 * - VAT return reconciliation
 *
 * Per ZATCA: Returns/corrections after VAT period closure must be
 * adjusted in the NEXT VAT return, not the original period.
 */
class VatPeriodTracker
{
    /**
     * VAT period types.
     */
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_QUARTERLY = 'quarterly';

    /**
     * Get VAT period for a given date.
     *
     * @param  Carbon|string  $date  Invoice date
     * @param  string  $periodType  'monthly' or 'quarterly'
     * @return array{start: string, end: string, period_key: string}
     */
    public function getPeriodForDate(Carbon|string $date, string $periodType = self::PERIOD_MONTHLY): array
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        if ($periodType === self::PERIOD_QUARTERLY) {
            $quarter = ceil($date->month / 3);
            $startMonth = (($quarter - 1) * 3) + 1;
            $start = $date->copy()->setMonth($startMonth)->startOfMonth();
            $end = $start->copy()->addMonths(2)->endOfMonth();
            $periodKey = $date->year.'-Q'.$quarter;
        } else {
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();
            $periodKey = $date->format('Y-m');
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'period_key' => $periodKey,
        ];
    }

    /**
     * Check if an invoice's VAT period is still open.
     *
     * A period is considered "open" until the filing deadline (typically 28th of following month).
     *
     * @param  Carbon|string  $invoiceDate  Original invoice date
     * @param  int  $filingDeadlineDay  Day of month when VAT return is due (default: 28)
     * @return bool True if period is still open for modifications
     */
    public function isPeriodOpen(Carbon|string $invoiceDate, int $filingDeadlineDay = 28): bool
    {
        $invoiceDate = $invoiceDate instanceof Carbon ? $invoiceDate : Carbon::parse($invoiceDate);
        $period = $this->getPeriodForDate($invoiceDate);
        $periodEnd = Carbon::parse($period['end']);

        // Filing deadline is typically 28th of the month following period end
        $filingDeadline = $periodEnd->copy()->addMonth()->setDay($filingDeadlineDay);

        // Compared as dates on the Kingdom's calendar, which is what a filing
        // deadline is. Two things were wrong with comparing instants against a
        // parsed midnight: for the three hours after midnight in Riyadh the
        // application's clock still named the previous day, so a period read
        // as open after the deadline had passed; and a deadline at 00:00 on
        // its own day closed the period before that day began, which is not
        // what a deadline of the 28th means. A period is open through the end
        // of the deadline day.
        return FatooraTime::saudiNow()->toDateString() <= $filingDeadline->toDateString();
    }

    /**
     * Determine which VAT period a credit/debit note should be reported in.
     *
     * Per ZATCA: If original invoice's period is closed, CN/DN goes in current period.
     *
     * @param  Invoice  $adjustmentNote  The credit or debit note
     * @param  Invoice  $originalInvoice  The original invoice being adjusted
     * @return array{report_in_period: string, is_cross_period: bool, original_period: string}
     */
    public function determineReportingPeriod(Invoice $adjustmentNote, Invoice $originalInvoice): array
    {
        $originalPeriod = $this->getPeriodForDate($originalInvoice->issue_date);
        $adjustmentPeriod = $this->getPeriodForDate($adjustmentNote->issue_date);
        $isOriginalPeriodOpen = $this->isPeriodOpen($originalInvoice->issue_date);

        // If original period is still open, report in that period
        // Otherwise, report in the adjustment note's period
        $reportInPeriod = $isOriginalPeriodOpen
            ? $originalPeriod['period_key']
            : $adjustmentPeriod['period_key'];

        $isCrossPeriod = $reportInPeriod !== $originalPeriod['period_key'];

        if ($isCrossPeriod) {
            Log::info('Cross-period VAT adjustment detected', [
                'adjustment_note_id' => $adjustmentNote->id,
                'original_invoice_id' => $originalInvoice->id,
                'original_period' => $originalPeriod['period_key'],
                'report_in_period' => $reportInPeriod,
            ]);
        }

        return [
            'report_in_period' => $reportInPeriod,
            'is_cross_period' => $isCrossPeriod,
            'original_period' => $originalPeriod['period_key'],
            'adjustment_period' => $adjustmentPeriod['period_key'],
        ];
    }

    /**
     * Validate that an adjustment note is reported in the correct period.
     *
     * A credit or debit note raised after the original invoice's VAT period has
     * closed belongs in the current period, not the original one — the closed
     * period has already been filed.
     *
     * @param  Invoice  $note  The credit or debit note to validate
     * @return array{valid: bool, warning: ?string, suggested_period: ?string}
     */
    public function validateAdjustmentPeriod(Invoice $note): array
    {
        // Debit notes as well as credit notes. This tested for CreditNote
        // alone, so a debit note returned valid without being looked at —
        // including the reference check below — while the caller applies this
        // to anything requiring a billing reference, which is both.
        // determineReportingPeriod() was already written for either.
        if ($note->document_type?->requiresBillingReference() !== true) {
            return ['valid' => true, 'warning' => null, 'suggested_period' => null];
        }

        if (! $note->billing_ref) {
            return [
                'valid' => false,
                'warning' => 'Adjustment note must reference original invoice for VAT period validation',
                'suggested_period' => null,
            ];
        }

        $originalInvoice = $this->original($note);
        if (! $originalInvoice) {
            return [
                'valid' => false,
                'warning' => "Original invoice {$note->billing_ref} was not found in this organization",
                'suggested_period' => null,
            ];
        }

        if ($originalInvoice->status === InvoiceStatus::Draft) {
            return [
                'valid' => false,
                'warning' => "Original invoice {$note->billing_ref} has not been issued, so there is nothing to adjust",
                'suggested_period' => null,
            ];
        }

        $reporting = $this->determineReportingPeriod($note, $originalInvoice);

        if ($reporting['is_cross_period']) {
            return [
                'valid' => true,
                'warning' => "Cross-period adjustment: Original invoice from {$reporting['original_period']}, ".
                    "credit note will be reported in {$reporting['report_in_period']}",
                'suggested_period' => $reporting['report_in_period'],
            ];
        }

        return ['valid' => true, 'warning' => null, 'suggested_period' => null];
    }

    /**
     * The invoice a note adjusts, in the note's own organization.
     *
     * billing_ref is the original's invoice number: it is what the document
     * declares in BillingReference, what the partner guide tells an ERP to
     * send, and what the portal asks for. This looked it up as a row id, and
     * without the tenant scope, so a note referenced the documented way was
     * never found and could not be filed, while a row id resolved against any
     * organization's invoices. An id within the organization is still
     * accepted, for integrations that sent one.
     */
    private function original(Invoice $note): ?Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::where('org_id', $note->org_id)
            ->where(fn ($query) => $query
                ->where('invoice_number', $note->billing_ref)
                ->orWhere('id', $note->billing_ref))
            ->orderByRaw('invoice_number = ? desc', [$note->billing_ref])
            ->first());
    }
}
