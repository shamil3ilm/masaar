<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Engine\DTOs\PrintLabel;
use App\Domains\Compliance\Engine\DTOs\ReceiptRow;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\DisbursementLines;

/**
 * What a printed Saudi invoice says about the sale beyond its lines: that
 * the buyer is a government (B2G, KSA-2 position 9), the buyer's purchase
 * order (BT-13), the contract (BT-12) and the buyer's own reference (BT-10),
 * and when and how it is to be paid - the due date (BT-9) and the payment
 * terms (KSA-22), and what its disbursements come to, apart from the supply.
 * Each only when the invoice has it.
 *
 * On a sale an insurer pays for (InsuranceSplits), what its signed note
 * states (BT-22, SplitNote): on the patient's invoice the amounts "For
 * account of insurer" and "For account of patient", as ZATCA's General VAT
 * Guideline Healthcare (January 2023) sets them out in Example 10 (pp22-23),
 * the insurer they are for, and its claim, policy and member numbers. On the
 * insurer's invoice those numbers are its BT-13, BT-12 and BT-10, printed
 * under their own names rather than as a purchase order, with the patient
 * the care was for (Example 11, p24).
 *
 * One list for every layout - the full pages, the PDF and the receipt - so a
 * reference the signed document carries is never missing from one copy.
 */
final class PrintedTerms
{
    /**
     * @return list<ReceiptRow>
     */
    public static function of(Invoice $invoice): array
    {
        $split = $invoice->insurance_split_id === null ? null : $invoice->insuranceSplit()->withoutGlobalScopes()->first();
        $toInsurer = $split !== null && $invoice->split_role === InsuranceSplit::INSURER;

        $terms = [
            [new PrintLabel('Transaction', 'نوع المعاملة'), $invoice->is_government ? 'Government (B2G) / جهة حكومية' : null],
            [new PrintLabel('Purchase order', 'رقم أمر الشراء'), $toInsurer ? null : $invoice->order_reference],
            [new PrintLabel('Contract', 'رقم العقد'), $toInsurer ? null : $invoice->contract_reference],
            [new PrintLabel('Buyer reference', 'مرجع المشتري'), $toInsurer ? null : $invoice->buyer_reference],
            ...($split === null ? [] : self::split($invoice, $split)),
            [new PrintLabel('Due date', 'تاريخ الاستحقاق'), $invoice->due_date?->toDateString()],
            [new PrintLabel('Payment terms', 'شروط الدفع'), $invoice->payment_terms],
            // Costs paid in the buyer's name and for its account, apart from
            // the supply: out of scope, no VAT (DisbursementLines; GCC VAT
            // Agreement Art 26(6)(c)).
            [new PrintLabel('Disbursements, outside the scope of VAT', 'مبالغ مدفوعة نيابة عن العميل، خارج نطاق الضريبة'), self::disbursements($invoice)],
        ];

        return array_values(array_map(
            fn (array $term): ReceiptRow => new ReceiptRow($term[0], (string) $term[1]),
            array_filter($terms, fn (array $term): bool => filled($term[1])),
        ));
    }

    /** What the invoice's disbursement lines come to, with its currency; null when it has none. */
    private static function disbursements(Invoice $invoice): ?string
    {
        $total = DisbursementLines::total($invoice->lines);

        return bccomp($total, '0', 2) === 0 ? null : number_format((float) $total, 2, '.', ',').' '.$invoice->currency;
    }

    /**
     * @return list<array{PrintLabel, string|null}>
     */
    private static function split(Invoice $invoice, InsuranceSplit $split): array
    {
        $money = fn (mixed $amount): string => number_format((float) $amount, 2).' '.$split->currency;
        // A re-bill is the patient's own invoice, to the patient: the
        // references alone.
        $shares = match ($invoice->split_role) {
            InsuranceSplit::PATIENT => [
                [new PrintLabel('Insurer', 'شركة التأمين'), $split->insurer_name],
                [new PrintLabel('For the account of the insurer', 'على حساب شركة التأمين'), $money($split->insurer_share)],
                [new PrintLabel('For the account of the patient', 'على حساب المريض'), $money($split->patient_share)],
            ],
            InsuranceSplit::INSURER => [[new PrintLabel('Patient', 'المريض'), $split->patient_name_ar ?: $split->patient_name]],
            default => [],
        };

        return [
            ...$shares,
            [new PrintLabel('Claim or approval number', 'رقم المطالبة أو الموافقة'), $split->claim_reference],
            [new PrintLabel('Policy number', 'رقم الوثيقة'), $split->policy_number],
            [new PrintLabel('Member number', 'رقم العضوية'), $split->member_id],
        ];
    }
}
