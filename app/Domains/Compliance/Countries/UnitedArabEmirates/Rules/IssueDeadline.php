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
 * date, or the issue date when there is none. Both are civil dates; the
 * moment of issue is read as a date on the UAE's clock.
 *
 * Late issuing is still better than not issuing, so a late invoice is
 * issued and sent as any other, and only recorded as late.
 */
final class IssueDeadline
{
    public const DAYS = 14;

    public function isLate(Invoice $invoice): bool
    {
        $transaction = $invoice->supply_date ?? $invoice->issue_date;

        if ($transaction === null) {
            return false;
        }

        $issued = Carbon::parse(
            $invoice->issuedMoment()->setTimezone(CountryClock::timeZone('AE'))->toDateString()
        );

        // Signed (Carbon 3): an issue before the transaction date is not late.
        return Carbon::parse($transaction->toDateString())->diffInDays($issued, false) > self::DAYS;
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
