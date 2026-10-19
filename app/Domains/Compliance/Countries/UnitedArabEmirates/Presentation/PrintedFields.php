<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\DTOs\PrintedField;
use App\Domains\Compliance\Engine\DTOs\PrintLabel;

/**
 * What a UAE tax invoice must show, checked against the current text.
 *
 * Source: the Executive Regulation of the VAT Decree-Law, Cabinet Decision
 * No. 52 of 2017 and its amendments to Cabinet Decision No. 149 of 2026, as
 * published by the Ministry of Finance (tax.gov.ae, "Law-No-8-of-2017-and-
 * its-amendments--09-2026.pdf", an unofficial translation): Article 59,
 * pages 51-54, Clause 1 (a) to (l) on page 51; Article 60, pages 54-55, for
 * a credit note. Article 65 of the Decree-Law (Federal Decree-Law No. 8 of
 * 2017 and its amendments, MoF text, page 32) has the Executive Regulation
 * specify these, and its Clause 5 requires a Registrant on the Electronic
 * Invoicing System to issue them as an Electronic Invoice.
 *
 * Only Clause 1 applies here. Clause 16 of Article 59 (page 54, added by
 * Cabinet Decision No. 100 of 2025) disapplies Clauses 2, 3, 5, 7, 8 and 15
 * to a Registrant issuing Tax Invoices as Electronic Invoices: no simplified
 * Tax Invoice (Clause 2) and no Authority relief from particulars under
 * Clause 7 - the relief FTA Decision No. 2 of 2025's clarification
 * procedure and Public Clarification VATP044 work through - for an
 * organization on this module, every one of which issues e-invoices.
 *
 * A buyer not yet on electronic invoicing is given these on paper or as a
 * PDF beside the e-invoice (Electronic Invoicing Guidelines v1.1, 10.2.2,
 * p23), drawn by PrintedInvoice from the e-invoice's own document.
 */
final class PrintedFields
{
    private const STANDARD = [PrintedField::STANDARD];

    private const ARTICLE = 'VAT Executive Regulation (Cabinet Decision No. 52 of 2017 as amended to No. 149 of 2026) Art. 59(1)';

    private const PAGE = ', p51';

    /** @return list<PrintedField> */
    public static function all(): array
    {
        $field = fn (string $key, string $en, string $ar, string $source, array $always = self::STANDARD, array $whenGiven = []): PrintedField => new PrintedField($key, new PrintLabel($en, $ar), $source, $always, $whenGiven);
        $clause = fn (string $paragraph): string => self::ARTICLE.'('.$paragraph.')'.self::PAGE;

        return [
            $field('title', 'The words "Tax Invoice"', 'عبارة "فاتورة ضريبية"', $clause('a').'; "Tax Credit Note", Art. 60(1)(a), p54'),
            $field('seller_name', 'Supplier name', 'اسم المورد', $clause('b')),
            $field('seller_address', 'Supplier address', 'عنوان المورد', $clause('b')),
            $field('seller_vat_number', 'Supplier TRN', 'رقم التسجيل الضريبي للمورد', $clause('b')),
            $field('buyer_name', 'Recipient name', 'اسم المتلقي', $clause('c')),
            $field('buyer_address', 'Recipient address', 'عنوان المتلقي', $clause('c')),
            $field('buyer_vat_number', 'Recipient TRN, where the recipient is a Registrant', 'رقم التسجيل الضريبي للمتلقي إن كان مسجلاً', $clause('c'), [], self::STANDARD),
            $field('invoice_number', 'Sequential or unique invoice number', 'رقم الفاتورة التسلسلي أو الفريد', $clause('d')),
            $field('issue_date', 'Date of issue', 'تاريخ الإصدار', $clause('e')),
            $field('supply_date', 'Date of supply, if different from the date of issue', 'تاريخ التوريد إن اختلف عن تاريخ الإصدار', $clause('f'), [], self::STANDARD),
            $field('line_description', 'Description of the goods or services', 'وصف السلع أو الخدمات', $clause('g')),
            $field('line_unit_price', 'Unit price, for each good or service', 'سعر الوحدة لكل سلعة أو خدمة', $clause('h')),
            $field('line_quantity', 'Quantity or volume supplied', 'الكمية أو الحجم المورّد', $clause('h')),
            $field(PrintedField::LINE_VAT_RATE, 'Rate of tax', 'نسبة الضريبة', $clause('h')),
            $field('line_vat', 'VAT per line, in AED', 'ضريبة كل بند بالدرهم', 'Electronic Invoicing Guidelines v1.1, 12.2 item 16, p36'),
            $field('line_total', 'Amount payable per line, in AED', 'المبلغ المستحق لكل بند بالدرهم', $clause('h').'; Electronic Invoicing Guidelines v1.1, 12.2 item 16, p36'),
            $field(PrintedField::LINE_DISCOUNT, 'Amount of any discount offered', 'مبلغ أي خصم ممنوح', $clause('i'), [], self::STANDARD),
            $field('total_with_vat', 'Gross amount payable, in AED', 'إجمالي المبلغ المستحق بالدرهم', $clause('j')),
            $field('vat_total', 'Tax amount charged, in AED', 'مبلغ الضريبة المحتسبة بالدرهم', $clause('k')),
            $field('vat_in_accounting_currency', 'Rate of exchange applied, where converted from another currency', 'سعر الصرف المطبق عند التحويل من عملة أخرى', $clause('k').'; VAT Decree-Law Art. 69, p33', [], self::STANDARD),
            $field('reverse_charge_statement', 'Statement that the recipient accounts for the tax, citing the Decree-Law provision', 'بيان بأن المتلقي يحتسب الضريبة مع الإشارة إلى نص المرسوم بقانون', $clause('l').'; VAT Decree-Law Art. 48, pp23-25', [], self::STANDARD),
            $field('credit_note_values', 'On a credit note: the value on the tax invoice, the correct value, the difference and its tax, in AED', 'في الإشعار الدائن: القيمة الواردة في الفاتورة الضريبية والقيمة الصحيحة والفرق وضريبته بالدرهم', 'VAT Executive Regulation Art. 60(1)(e), p54', [], self::STANDARD),
            $field('credit_note_reason', 'On a credit note: a brief explanation of why it is issued', 'في الإشعار الدائن: بيان موجز لسبب إصداره', 'VAT Executive Regulation Art. 60(1)(f), p54', [], self::STANDARD),
            $field('credit_note_reference', 'On a credit note: what identifies the supply it relates to', 'في الإشعار الدائن: ما يحدد التوريد الذي يتعلق به', 'VAT Executive Regulation Art. 60(1)(g), p55', [], self::STANDARD),
        ];
    }
}
