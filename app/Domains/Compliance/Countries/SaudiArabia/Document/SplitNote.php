<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Document;

/**
 * BT-22 on an invoice for a sale an insurer pays for (InsuranceSplits): what
 * the invoice cannot say in any field of its own.
 *
 *   patient  the invoice names the patient, the recipient, and "may
 *            separately state that the amount is 'for the account of' or
 *            'due for payment by' the insurer" (ZATCA General VAT Guideline
 *            Healthcare, January 2023, §5.2 p24): the amounts for the
 *            account of the insurer and of the patient, as Example 10 sets
 *            them out (p23), and the insurer's claim, policy and member
 *            numbers.
 *   insurer  the invoice names the insurer, the recipient, and the patient
 *            the care was for ("Patient: Essam Mansur", Example 11 p24),
 *            with the member number, which ZATCA's document has no field
 *            for (BT-10); the claim and the policy are its BT-13 and BT-12.
 *   rebill   the patient's invoice for care the insurer did not cover
 *            (§5.3.2, p25), naming the credit note on the insurer's.
 *
 * A note is the place: ZATCA's data dictionary gives BT-25, the billing
 * reference, to notes only ("NA" on an invoice) and no other field to these,
 * and a note may be on every document (at most 1000 characters, BR-KSA-F-06),
 * in Arabic as the invoice's text is to be (Detailed Guidelines, FAQ p62),
 * English beside it. Fixed text, not the reader's language: it is signed.
 */
final class SplitNote
{
    private const MAX = 1000;

    /**
     * @param  string  $role  patient, insurer or rebill
     * @param  array<string, mixed>  $split  the split's insurer, patient, shares, currency and references
     * @param  string|null  $credit  the credit note on the insurer's invoice, for a re-bill
     * @param  string|null  $insurerInvoice  the insurer's invoice, for a re-bill
     */
    public static function text(string $role, array $split, ?string $credit = null, ?string $insurerInvoice = null): ?string
    {
        $text = match ($role) {
            'patient' => self::forPatient($split),
            'insurer' => self::forInsurer($split),
            'rebill' => $credit === null || $insurerInvoice === null
                ? 'فاتورة المريض لرعاية لم تغطها شركة التأمين. / The patient\'s invoice for care the insurer did not cover.'
                : "فاتورة المريض لرعاية لم تغطها شركة التأمين، المقيد لها بالإشعار الدائن {$credit} على الفاتورة {$insurerInvoice}."
                    ." / The patient's invoice for care the insurer did not cover, credited to it by credit note {$credit} against invoice {$insurerInvoice}.",
            default => null,
        };

        return $text === null ? null : mb_substr($text, 0, self::MAX);
    }

    /** @param  array<string, mixed>  $split */
    private static function forPatient(array $split): string
    {
        $currency = (string) ($split['currency'] ?? '');
        $insurer = trim((string) ($split['insurer_name'] ?? ''));
        $toInsurer = self::amount($split['insurer_share'] ?? '0').' '.$currency;
        $toPatient = self::amount($split['patient_share'] ?? '0').' '.$currency;

        return "يُسدَّد على حساب شركة التأمين {$insurer}: {$toInsurer}، وعلى حساب المريض: {$toPatient}."
            .self::references($split, 'ar')
            ." / For the account of the insurer {$insurer}: {$toInsurer}; for the account of the patient: {$toPatient}."
            .self::references($split, 'en');
    }

    /** @param  array<string, mixed>  $split */
    private static function forInsurer(array $split): ?string
    {
        $patient = trim((string) (($split['patient_name_ar'] ?? null) ?: ($split['patient_name'] ?? '')));
        $member = trim((string) ($split['member_id'] ?? ''));

        if ($patient === '' && $member === '') {
            return null;
        }

        $arabic = ($patient === '' ? '' : "المريض: {$patient}.").($member === '' ? '' : " رقم العضوية: {$member}.");
        $english = ($patient === '' ? '' : 'Patient: '.trim((string) (($split['patient_name'] ?? null) ?: $patient)).'.').($member === '' ? '' : " Member {$member}.");

        return trim($arabic).' / '.trim($english);
    }

    /** @param  array<string, mixed>  $split */
    private static function references(array $split, string $language): string
    {
        $labels = $language === 'ar'
            ? ['claim_reference' => 'رقم المطالبة', 'policy_number' => 'رقم الوثيقة', 'member_id' => 'رقم العضوية']
            : ['claim_reference' => 'Claim', 'policy_number' => 'Policy', 'member_id' => 'Member'];
        $text = '';

        foreach ($labels as $field => $label) {
            $value = trim((string) ($split[$field] ?? ''));

            if ($value !== '') {
                $text .= $language === 'ar' ? " {$label}: {$value}." : " {$label} {$value}.";
            }
        }

        return $text;
    }

    private static function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
