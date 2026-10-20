<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Engine\DTOs\PrintedField;
use App\Domains\Compliance\Engine\DTOs\PrintLabel;

/**
 * What a Saudi invoice must show on paper.
 *
 * From ZATCA's E-Invoice Data Dictionary (20230519, vF): its columns
 * "Visibility on Invoice for Tax invoices and associated credit and debit
 * notes" and "Visibility on human readable formats for Simplified Tax
 * invoices and associated credit and debit notes" mark each field Required,
 * Required if populated, or Not Required, and map it to Annex 2 of the
 * E-Invoicing Resolution and to Article 53 of the VAT Implementing
 * Regulations: 53(5) for a tax invoice, 53(8) for a simplified one.
 *
 * Every field below is printed by print/page.blade.php, print/pdf.blade.php
 * and ReceiptContent, in every layout.
 */
final class PrintedFields
{
    private const BOTH = [PrintedField::STANDARD, PrintedField::SIMPLIFIED];

    private const STANDARD = [PrintedField::STANDARD];

    private const DICTIONARY = 'ZATCA E-Invoice Data Dictionary';

    private const ARTICLE = 'VAT Implementing Regulations Art. ';

    private const HEALTHCARE = 'ZATCA General VAT Guideline Healthcare (January 2023)';

    /** @return list<PrintedField> */
    public static function all(): array
    {
        $field = fn (string $key, string $en, string $ar, string $source, array $always = self::BOTH, array $whenGiven = []): PrintedField => new PrintedField($key, new PrintLabel($en, $ar), $source, $always, $whenGiven);

        return [
            $field('invoice_number', 'Invoice number', 'رقم الفاتورة', self::DICTIONARY.' BT-1 (E-Invoicing Resolution Annex 2, 2.1)'),
            $field('issue_date', 'Issue date', 'تاريخ الإصدار', self::ARTICLE.'53(5)(a); '.self::DICTIONARY.' BT-2'),
            $field('supply_date', 'Supply date', 'تاريخ التوريد', self::DICTIONARY.' KSA-5 (Annex 2, 3.3)', [], self::BOTH),
            $field('seller_name', 'Seller name', 'اسم المورد', self::ARTICLE.'53; '.self::DICTIONARY.' BT-27'),
            $field('seller_address', 'Seller address', 'عنوان المورد', self::ARTICLE.'53(5)(e); '.self::DICTIONARY.' BT-35, KSA-17, BT-37, BT-38, KSA-3, BT-40'),
            $field('seller_vat_number', 'Seller VAT number', 'الرقم الضريبي للمورد', self::ARTICLE.'53(5)(c); '.self::DICTIONARY.' BT-31'),
            $field('seller_other_id', 'Seller commercial registration', 'السجل التجاري للمورد', self::DICTIONARY.' BT-29 (Annex 2, 4.4)', [], self::STANDARD),
            $field('buyer_name', 'Buyer name', 'اسم العميل', self::ARTICLE.'53; '.self::DICTIONARY.' BT-44', self::STANDARD, self::BOTH),
            $field('buyer_address', 'Buyer address', 'عنوان العميل', self::ARTICLE.'53(5)(e); '.self::DICTIONARY.' BT-50, KSA-18, BT-52, BT-53, KSA-4, BT-55', self::STANDARD),
            $field('buyer_vat_number', 'Buyer VAT number', 'الرقم الضريبي للعميل', self::DICTIONARY.' BT-48 (Annex 2, 5.3)', [], self::STANDARD),
            $field('buyer_other_id', 'Buyer identifier', 'رقم تعريف العميل', self::DICTIONARY.' BT-46 (Annex 2, 5.4)', [], self::BOTH),
            $field('billing_reference', 'Invoice a note adjusts', 'مرجع الفاتورة', self::DICTIONARY.' BT-25 (Annex 2, 13.1)', [], self::BOTH),
            $field('adjustment_reason', 'Reason for the note', 'سبب الإصدار', self::DICTIONARY.' KSA-10 (Annex 2, 13.2)', [], self::BOTH),
            $field('line_description', 'Goods or services', 'تفاصيل السلع أو الخدمات', self::ARTICLE.'53(5)(f) and 53(8)(c); '.self::DICTIONARY.' BT-153'),
            $field('line_quantity', 'Quantity', 'الكمية', self::ARTICLE.'53; '.self::DICTIONARY.' BT-129'),
            $field('line_unit_price', 'Unit price', 'سعر الوحدة', self::ARTICLE.'53(5)(h); '.self::DICTIONARY.' BT-146'),
            $field(PrintedField::LINE_DISCOUNT, 'Line discount', 'خصم البند', self::ARTICLE.'53(5)(h); '.self::DICTIONARY.' BT-136', [], self::BOTH),
            $field('line_net', 'Line amount without VAT', 'مبلغ البند غير شامل الضريبة', self::DICTIONARY.' BT-131 (Annex 2, 7.7)', self::STANDARD),
            $field(PrintedField::LINE_VAT_RATE, 'VAT rate', 'نسبة الضريبة', self::ARTICLE.'53(5)(i); '.self::DICTIONARY.' BT-152', self::STANDARD),
            $field('line_vat', 'Line VAT amount', 'مبلغ ضريبة البند', self::ARTICLE.'53(5)(j); '.self::DICTIONARY.' KSA-11', self::STANDARD),
            $field('line_total', 'Line amount with VAT', 'مبلغ البند شامل الضريبة', self::ARTICLE.'53(8)(d), with a statement that it includes VAT; '.self::DICTIONARY.' KSA-12'),
            $field('exemption_reason', 'Why VAT is not charged at the standard rate', 'سبب عدم تطبيق النسبة الأساسية', self::ARTICLE.'53; '.self::DICTIONARY.' BT-120', [], self::STANDARD),
            $field('document_discount', 'Invoice discount', 'خصم الفاتورة', self::ARTICLE.'53; '.self::DICTIONARY.' BT-92, BT-107', [], self::BOTH),
            $field('document_charges', 'Invoice charges', 'رسوم الفاتورة', self::DICTIONARY.' BT-99, BT-108 (Annex 2, 8.6)', [], self::BOTH),
            $field('taxable_amount', 'Total without VAT', 'الإجمالي غير شامل الضريبة', self::ARTICLE.'53; '.self::DICTIONARY.' BT-109'),
            $field('vat_total', 'VAT total', 'مجموع ضريبة القيمة المضافة', self::ARTICLE.'53(5)(j); '.self::DICTIONARY.' BT-110', self::STANDARD),
            $field('vat_in_accounting_currency', 'VAT in riyals', 'ضريبة القيمة المضافة بالريال', self::DICTIONARY.' BT-111 (Annex 2, 8.4)', [], self::BOTH),
            $field('total_with_vat', 'Total with VAT', 'الإجمالي شامل الضريبة', self::ARTICLE.'53(8)(d); '.self::DICTIONARY.' BT-112'),
            $field('prepaid_amount', 'Prepaid amount', 'المبلغ المدفوع مقدماً', self::DICTIONARY.' BT-113 (Annex 2, 8.7)', [], self::BOTH),
            $field('amount_due', 'Amount due', 'المبلغ المستحق', self::DICTIONARY.' BT-115 (Annex 2, 8.9)', [], self::BOTH),
            $field('qr_code', 'QR code', 'رمز الاستجابة السريعة', self::DICTIONARY.' KSA-14 (Annex 2, 2.4)'),
            // A sale an insurer pays for (InsuranceSplits), printed as its
            // signed note (BT-22, SplitNote) states it (PrintedTerms).
            $field('split_accounts', 'Amounts for the account of the insurer and of the patient', 'المبالغ على حساب شركة التأمين وعلى حساب المريض', self::HEALTHCARE.' §5.2 p24, Example 10 pp22-23; '.self::DICTIONARY.' BT-22', [], self::BOTH),
            $field('split_references', 'Claim, policy and member numbers', 'أرقام المطالبة والوثيقة والعضوية', self::HEALTHCARE.' Examples 10 and 11, pp22-24; '.self::DICTIONARY.' BT-13, BT-12, BT-22', [], self::BOTH),
        ];
    }
}
