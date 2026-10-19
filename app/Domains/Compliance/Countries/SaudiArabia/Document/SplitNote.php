<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Document;

/**
 * BT-22 on a share of a split sale: which share the document is, and the
 * invoice for the other.
 *
 * ZATCA's data dictionary has no field for a companion invoice. The billing
 * reference (BT-25) is for credit and debit notes and is "NA" on a tax
 * invoice and a simplified one, and the document references are ICV, PIH
 * and QR only - so a note, which every document type may carry (at most
 * 1000 characters, in Arabic as the invoice's text is to be: Detailed
 * Guidelines, FAQ p62). The patient's share is issued first, so the
 * insurer's names its number; the patient's says the insurer's share is
 * invoiced apart. Both name the split on the platform, which links them
 * both ways, and the insurer's carries the claim as its order reference
 * (BT-13).
 *
 * Fixed text in both languages rather than the reader's: it is part of the
 * signed document, which reads the same whoever issues it.
 */
final class SplitNote
{
    /**
     * @param  string  $role  patient, insurer or rebill
     * @param  string|null  $patient  the patient's share's number, once issued
     * @param  string|null  $insurer  the insurer's share's number, once issued
     * @param  string|null  $credit  the credit note on the insurer's share, for a re-bill
     */
    public static function text(string $role, ?string $patient, ?string $insurer, ?string $credit): ?string
    {
        return match ($role) {
            'patient' => 'حصة المريض من بيع مقسوم مع شركة التأمين، وتصدر حصة شركة التأمين بفاتورة منفصلة.'
                ." / The patient's share of a sale split with the insurer, whose share is invoiced separately.",
            'insurer' => $patient === null
                ? 'حصة شركة التأمين من بيع مقسوم مع المريض، وتصدر حصة المريض بفاتورة منفصلة.'
                    ." / The insurer's share of a sale split with the patient, whose share is invoiced separately."
                : "حصة شركة التأمين من بيع مقسوم مع المريض، وحصة المريض بالفاتورة {$patient}."
                    ." / The insurer's share of a sale split with the patient, whose share is invoice {$patient}.",
            'rebill' => $credit === null || $insurer === null
                ? 'فاتورة المريض بما لم توافق عليه شركة التأمين من حصتها.'
                    ." / The patient's invoice for what the insurer declined of its share."
                : "فاتورة المريض بما لم توافق عليه شركة التأمين، المقيد لها بالإشعار الدائن {$credit} على الفاتورة {$insurer}."
                    ." / The patient's invoice for what the insurer declined, credited by credit note {$credit} against invoice {$insurer}.",
            default => null,
        };
    }
}
