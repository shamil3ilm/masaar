<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Invoice\Enums\DocumentType;
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

    public function __construct(
        private readonly InvoiceValidator $validator,
    ) {}

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

        // One note may correct several invoices: BT-25 is text, and ZATCA's
        // guidelines (4.3) let it carry more than one reference.
        $references = $this->references((string) $note->billing_ref);

        if (count($references) > 1) {
            return $this->validateSeveral($note, $references);
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

        $refusal = $this->refusalAgainst($note, $originalInvoice);
        if ($refusal !== null) {
            return ['valid' => false, 'warning' => $refusal, 'suggested_period' => null];
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
     * Why this note cannot stand against this original, or null if it can.
     *
     * ZATCA's Detailed Guidelines (4.3): "The type of credit/debit note
     * follows the type of invoice that they are issued against" - a
     * simplified note against a standard invoice is reported where the
     * original was cleared, or the other way round. A note corrects an
     * invoice, not another note. A rejected original was never accepted, so
     * there is nothing on record to correct: it is reissued, not adjusted.
     */
    private function refusalAgainst(Invoice $note, Invoice $original): ?string
    {
        if ($original->status === InvoiceStatus::Rejected) {
            return "Original invoice {$note->billing_ref} was rejected by ZATCA, so there is nothing on record to adjust";
        }

        if (($refusal = $this->notYetAccepted($original, (string) $note->billing_ref)) !== null) {
            return $refusal;
        }

        if ($original->document_type?->requiresBillingReference() === true) {
            return "{$note->billing_ref} is itself a credit or debit note; a note must reference the original invoice";
        }

        if ($note->type !== $original->type) {
            return "This note is {$note->type?->value} but original invoice {$note->billing_ref} is {$original->type?->value}; ".
                'a credit or debit note takes the type of the invoice it is issued against';
        }

        if ($note->document_type === DocumentType::CreditNote && ! $this->creditFits($note, $original)) {
            return "Credit notes against {$note->billing_ref} would exceed its total of {$original->total}";
        }

        return null;
    }

    /**
     * Whether this credit note, with the credit notes already issued against
     * the same original, stays within the original's total.
     *
     * Every other issued credit note counts, filed or not: once issued it is
     * on the record. Drafts are not documents yet and rejected ones never
     * stood, so neither uses up the original.
     */
    private function creditFits(Invoice $note, Invoice $original): bool
    {
        $references = array_values(array_filter([$original->invoice_number, $original->id]));

        $previous = Invoice::withoutTenantScope(fn () => Invoice::where('org_id', $note->org_id)
            ->where('document_type', DocumentType::CreditNote->value)
            ->whereIn('billing_ref', $references)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Rejected->value])
            ->when($note->exists, fn ($query) => $query->whereKeyNot($note->getKey()))
            ->sum('total'));

        return $this->validator->validateCreditNoteTotal((float) $note->total, (float) $original->total, (float) $previous)
            && $this->fitsAcrossNotes($note, [$original]);
    }

    /**
     * Whether this credit note fits with every other credit note that shares
     * an original with it, a note on one invoice and a note on several alike.
     *
     * Each kind used to be capped against its own kind only: notes naming
     * exactly this invoice, or notes naming exactly this set. So an invoice
     * credited in full by a note on it alone could be credited again inside a
     * note on it and another, and the other way round - the originals were
     * credited for more than they charged (IR Art 54(1): a note adjusts the
     * supply the Tax Invoice was issued for, not more). A note on several
     * does not say how much falls to each, so the check is on the group: the
     * originals reached through any note sharing one, which together may not
     * be credited for more than they charged.
     *
     * @param  list<Invoice>  $originals
     */
    private function fitsAcrossNotes(Invoice $note, array $originals): bool
    {
        $group = [];
        $frontier = [];

        foreach ($originals as $original) {
            $group[$original->id] = $original;
            $frontier[] = $original;
        }

        $notes = [];

        while ($frontier !== []) {
            $original = array_pop($frontier);

            $sharing = Invoice::withoutTenantScope(fn () => Invoice::where('org_id', $note->org_id)
                ->where('document_type', DocumentType::CreditNote->value)
                ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Rejected->value])
                ->when($note->exists, fn ($query) => $query->whereKeyNot($note->getKey()))
                ->where(fn ($query) => $query
                    ->where('billing_ref', 'like', '%'.$original->invoice_number.'%')
                    ->orWhere('billing_ref', 'like', '%'.$original->id.'%'))
                ->get(['id', 'billing_ref', 'total']));

            foreach ($sharing as $credit) {
                $named = array_map(fn (string $reference): ?Invoice => $this->lookup((string) $note->org_id, $reference), $this->references((string) $credit->billing_ref));

                // A loose match on the text, kept only when the note really
                // names this original.
                if (! in_array($original->id, array_map(fn (?Invoice $invoice): ?string => $invoice?->id, $named), true)) {
                    continue;
                }

                $notes[$credit->id] = (float) $credit->total;

                foreach ($named as $invoice) {
                    if ($invoice !== null && ! isset($group[$invoice->id])) {
                        $group[$invoice->id] = $invoice;
                        $frontier[] = $invoice;
                    }
                }
            }
        }

        $charged = array_sum(array_map(fn (Invoice $original): float => (float) $original->total, $group));

        return $this->validator->validateCreditNoteTotal((float) $note->total, $charged, array_sum($notes));
    }

    /**
     * The references a note's BT-25 holds, separated by commas or semicolons.
     *
     * @return list<string>
     */
    private function references(string $billingRef): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;]/', $billingRef) ?: []), fn (string $ref): bool => $ref !== '')));
    }

    /**
     * A note against several invoices: each must stand as an original on its
     * own, and a credit note must fit within their combined total.
     *
     * @param  list<string>  $references
     * @return array{valid: bool, warning: string|null, suggested_period: string|null}
     */
    private function validateSeveral(Invoice $note, array $references): array
    {
        $originals = [];

        foreach ($references as $reference) {
            $original = $this->lookup((string) $note->org_id, $reference);

            if ($original === null) {
                return ['valid' => false, 'warning' => "Original invoice {$reference} was not found in this organization", 'suggested_period' => null];
            }

            if ($original->status === InvoiceStatus::Draft) {
                return ['valid' => false, 'warning' => "Original invoice {$reference} has not been issued, so there is nothing to adjust", 'suggested_period' => null];
            }

            $refusal = $this->standingRefusal($note, $original, $reference);

            if ($refusal !== null) {
                return ['valid' => false, 'warning' => $refusal, 'suggested_period' => null];
            }

            $originals[] = $original;
        }

        if ($note->document_type === DocumentType::CreditNote) {
            $combined = array_sum(array_map(fn (Invoice $original): float => (float) $original->total, $originals));

            $previous = Invoice::withoutTenantScope(fn () => Invoice::where('org_id', $note->org_id)
                ->where('document_type', DocumentType::CreditNote->value)
                ->where('billing_ref', $note->billing_ref)
                ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Rejected->value])
                ->when($note->exists, fn ($query) => $query->whereKeyNot($note->getKey()))
                ->sum('total'));

            if (! $this->validator->validateCreditNoteTotal((float) $note->total, $combined, (float) $previous) || ! $this->fitsAcrossNotes($note, $originals)) {
                return ['valid' => false, 'warning' => 'Credit notes against '.implode(', ', $references).' would exceed their combined total of '.number_format($combined, 2, '.', ''), 'suggested_period' => null];
            }
        }

        // Reported against the earliest original, which is the period most
        // likely to have closed.
        usort($originals, fn (Invoice $a, Invoice $b): int => $a->issue_date <=> $b->issue_date);
        $reporting = $this->determineReportingPeriod($note, $originals[0]);

        return $reporting['is_cross_period']
            ? ['valid' => true, 'warning' => "Cross-period adjustment: earliest original invoice from {$reporting['original_period']}, note will be reported in {$reporting['report_in_period']}", 'suggested_period' => $reporting['report_in_period']]
            : ['valid' => true, 'warning' => null, 'suggested_period' => null];
    }

    /**
     * The rules every original must meet, the credit cap aside.
     */
    private function standingRefusal(Invoice $note, Invoice $original, string $reference): ?string
    {
        if ($original->status === InvoiceStatus::Rejected) {
            return "Original invoice {$reference} was rejected by ZATCA, so there is nothing on record to adjust";
        }

        if (($refusal = $this->notYetAccepted($original, $reference)) !== null) {
            return $refusal;
        }

        if ($original->document_type?->requiresBillingReference() === true) {
            return "{$reference} is itself a credit or debit note; a note must reference the original invoice";
        }

        if ($note->type !== $original->type) {
            return "This note is {$note->type?->value} but original invoice {$reference} is {$original->type?->value}; ".
                'a credit or debit note takes the type of the invoice it is issued against';
        }

        return null;
    }

    /**
     * A note adjusts an invoice ZATCA has accepted: a standard one cleared,
     * a simplified one reported, with or without warnings (InvoiceStatus::
     * Accepted either way). Before that there is nothing on the authority's
     * record for it to correct (IR Art 54(1): a note adjusts "a Tax Invoice
     * issued"; Detailed Guidelines 4.3), and a note against a standard
     * invoice that is then refused would adjust nothing. It waits, a draft.
     */
    private function notYetAccepted(Invoice $original, string $reference): ?string
    {
        if ($original->status === InvoiceStatus::Accepted) {
            return null;
        }

        $state = $original->status instanceof InvoiceStatus ? $original->status->value : (string) $original->status;
        $awaited = $original->type?->value === 'standard' ? 'cleared' : 'reported';

        return "Original invoice {$reference} is {$state}, not yet accepted by ZATCA; issue the note once it is {$awaited}";
    }

    private function lookup(string $organizationId, string $reference): ?Invoice
    {
        return Invoice::withoutTenantScope(fn () => Invoice::where('org_id', $organizationId)
            ->where(fn ($query) => $query
                ->where('invoice_number', $reference)
                ->orWhere('id', $reference))
            ->orderByRaw('invoice_number = ? desc', [$reference])
            ->first());
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
