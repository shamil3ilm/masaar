<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Support\CountryClock;
use Carbon\Carbon;

/**
 * The UAE's 14 days to issue an e-invoice.
 *
 * MD 243/2025 Art. 6(5): an electronic invoice or credit note is issued and
 * transmitted within 14 days of the Date of Business Transaction - the
 * earlier of the transaction and the receipt of payment. Art. 6(4): a VAT
 * registrant keeps to the VAT Law's timeline, which for a tax invoice is
 * also 14 days from the date of supply.
 *
 * The platform does not record payments, so the transaction is the supply
 * date, or the issue date when there is none. A summary invoice is the
 * exception: its 14 days run from the end of the calendar month it covers
 * (Executive Regulation Art 59(13)(b)), its supply end date. All are civil
 * dates; the moment of issue is read as a date on the UAE's clock.
 *
 * Late issuing is still better than not issuing, so a late invoice is
 * issued and sent as any other, and only recorded as late.
 */
final class IssueDeadline
{
    public const DAYS = 14;

    public function isLate(Invoice $invoice): bool
    {
        $transaction = self::summary($invoice) && $invoice->supply_end_date !== null
            ? $invoice->supply_end_date
            : $invoice->supply_date ?? $invoice->issue_date;

        if ($transaction === null) {
            return false;
        }

        $issued = Carbon::parse(
            $invoice->issuedMoment()->setTimezone(CountryClock::timeZone('AE'))->toDateString()
        );

        // Signed (Carbon 3): an issue before the transaction date is not late.
        return Carbon::parse($transaction->toDateString())->diffInDays($issued, false) > self::DAYS;
    }

    /** The summary flag (PintAe::FLAG_SUMMARY) set on the invoice. */
    private static function summary(Invoice $invoice): bool
    {
        $flags = (string) (((array) $invoice->jurisdiction_data)['transaction_flags'] ?? PintAe::NO_FLAGS);

        return ($flags[PintAe::FLAG_SUMMARY] ?? '0') === '1';
    }

    /**
     * What the API and the pipeline say about a late document.
     *
     * @return list<string>
     */
    public static function warnings(?PeppolDocument $document): array
    {
        if ($document === null || ! $document->issued_late) {
            return [];
        }

        return [__('Issued late: more than 14 days after the date of supply. A UAE e-invoice is issued and sent within 14 days of the transaction (Ministerial Decision No. 243 of 2025, Article 6).')];
    }
}
