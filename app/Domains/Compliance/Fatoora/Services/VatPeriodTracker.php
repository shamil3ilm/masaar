<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Invoice\Enums\DocumentType;
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

        return now()->lte($filingDeadline);
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
     * Get VAT summary for a period.
     *
     * @param  string  $organizationId  Organization ID
     * @param  string  $periodKey  Period key (e.g., '2024-01' or '2024-Q1')
     * @return array VAT summary with totals
     */
    public function getPeriodSummary(string $organizationId, string $periodKey): array
    {
        $period = $this->parsePeriodKey($periodKey);

        $invoices = Invoice::where('org_id', $organizationId)
            ->whereBetween('issue_date', [$period['start'], $period['end']])
            ->whereIn('status', ['cleared', 'reported', 'warning'])
            ->get();

        $summary = [
            'period_key' => $periodKey,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'total_invoices' => 0,
            'total_credit_notes' => 0,
            'total_debit_notes' => 0,
            'gross_sales' => 0.0,
            'gross_adjustments' => 0.0,
            'net_taxable' => 0.0,
            'vat_collected' => 0.0,
            'vat_adjusted' => 0.0,
            'net_vat_payable' => 0.0,
            'cross_period_adjustments' => [],
        ];

        foreach ($invoices as $invoice) {
            $docType = $invoice->document_type;

            if ($docType === DocumentType::Invoice || $docType === DocumentType::Prepayment) {
                $summary['total_invoices']++;
                $summary['gross_sales'] += (float) $invoice->subtotal;
                $summary['vat_collected'] += (float) $invoice->tax_amount;
            } elseif ($docType === DocumentType::CreditNote) {
                $summary['total_credit_notes']++;
                $summary['gross_adjustments'] -= (float) $invoice->subtotal;
                $summary['vat_adjusted'] -= (float) $invoice->tax_amount;

                // Check if this is a cross-period adjustment
                if ($invoice->billing_ref) {
                    $originalInvoice = Invoice::find($invoice->billing_ref);
                    if ($originalInvoice) {
                        $originalPeriod = $this->getPeriodForDate($originalInvoice->issue_date);
                        if ($originalPeriod['period_key'] !== $periodKey) {
                            $summary['cross_period_adjustments'][] = [
                                'credit_note_id' => $invoice->id,
                                'original_invoice_id' => $originalInvoice->id,
                                'original_period' => $originalPeriod['period_key'],
                                'amount' => (float) $invoice->total,
                                'vat' => (float) $invoice->tax_amount,
                            ];
                        }
                    }
                }
            } elseif ($docType === DocumentType::DebitNote) {
                $summary['total_debit_notes']++;
                $summary['gross_adjustments'] += (float) $invoice->subtotal;
                $summary['vat_adjusted'] += (float) $invoice->tax_amount;
            }
        }

        $summary['net_taxable'] = $summary['gross_sales'] + $summary['gross_adjustments'];
        $summary['net_vat_payable'] = $summary['vat_collected'] + $summary['vat_adjusted'];

        return $summary;
    }

    /**
     * Get cross-period adjustments that need special handling.
     *
     * These are credit/debit notes where the original invoice's period has closed.
     *
     * @param  string  $organizationId  Organization ID
     * @param  string  $currentPeriodKey  Current reporting period
     * @return array List of cross-period adjustments
     */
    public function getCrossPeriodAdjustments(string $organizationId, string $currentPeriodKey): array
    {
        $period = $this->parsePeriodKey($currentPeriodKey);

        return Invoice::where('org_id', $organizationId)
            ->whereBetween('issue_date', [$period['start'], $period['end']])
            ->whereIn('document_type', [DocumentType::CreditNote, DocumentType::DebitNote])
            ->whereNotNull('billing_ref')
            ->with('billingReference')
            ->get()
            ->filter(function ($note) use ($currentPeriodKey) {
                if (! $note->billingReference) {
                    return false;
                }
                $originalPeriod = $this->getPeriodForDate($note->billingReference->issue_date);

                return $originalPeriod['period_key'] !== $currentPeriodKey;
            })
            ->map(function ($note) {
                $originalPeriod = $this->getPeriodForDate($note->billingReference->issue_date);

                return [
                    'note_id' => $note->id,
                    'note_number' => $note->invoice_number,
                    'note_type' => $note->document_type->value,
                    'note_date' => $note->issue_date->format('Y-m-d'),
                    'original_invoice_id' => $note->billing_ref,
                    'original_invoice_number' => $note->billingReference->invoice_number,
                    'original_period' => $originalPeriod['period_key'],
                    'amount' => (float) $note->total,
                    'vat' => (float) $note->tax_amount,
                    'reason' => $note->adjustment_reason,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Parse period key into start/end dates.
     */
    private function parsePeriodKey(string $periodKey): array
    {
        if (str_contains($periodKey, '-Q')) {
            // Quarterly: 2024-Q1
            [$year, $quarter] = explode('-Q', $periodKey);
            $startMonth = (((int) $quarter - 1) * 3) + 1;
            $start = Carbon::createFromDate((int) $year, $startMonth, 1)->startOfMonth();
            $end = $start->copy()->addMonths(2)->endOfMonth();
        } else {
            // Monthly: 2024-01
            $start = Carbon::parse($periodKey.'-01')->startOfMonth();
            $end = $start->copy()->endOfMonth();
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    /**
     * Validate that a credit note is being reported in the correct period.
     *
     * @param  Invoice  $creditNote  The credit note to validate
     * @return array{valid: bool, warning: ?string, suggested_period: ?string}
     */
    public function validateCreditNotePeriod(Invoice $creditNote): array
    {
        if ($creditNote->document_type !== DocumentType::CreditNote) {
            return ['valid' => true, 'warning' => null, 'suggested_period' => null];
        }

        if (! $creditNote->billing_ref) {
            return [
                'valid' => false,
                'warning' => 'Credit note must reference original invoice for VAT period validation',
                'suggested_period' => null,
            ];
        }

        $originalInvoice = Invoice::find($creditNote->billing_ref);
        if (! $originalInvoice) {
            return [
                'valid' => false,
                'warning' => 'Original invoice not found for credit note',
                'suggested_period' => null,
            ];
        }

        $reporting = $this->determineReportingPeriod($creditNote, $originalInvoice);

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
}
