<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Engine\DTOs\PrintedField;
use App\Domains\Compliance\Engine\DTOs\PrintLabel;

/**
 * What a UAE tax invoice must show.
 *
 * Article 65 of the VAT Decree-Law (Federal Decree-Law No. 8 of 2017) and
 * Article 59 of the VAT Executive Regulation (Cabinet Decision No. 52 of
 * 2017): 59(1) lists a tax invoice's contents, 59(5) a simplified one's. The
 * UAE Electronic Invoicing Guidelines (v1.1) p23 and p27 point to the same
 * two articles for an electronic tax invoice.
 *
 * A buyer not yet on electronic invoicing is given these on paper or as a
 * PDF beside the e-invoice (Guidelines 10.2.2, p23), drawn by PrintedInvoice
 * from the e-invoice's own document.
 */
final class PrintedFields
{
    private const BOTH = [PrintedField::STANDARD, PrintedField::SIMPLIFIED];

    private const STANDARD = [PrintedField::STANDARD];

    private const ARTICLE = 'VAT Executive Regulation Art. ';

    /** @return list<PrintedField> */
    public static function all(): array
    {
        $field = fn (string $key, string $en, string $ar, string $source, array $always = self::BOTH, array $whenGiven = []): PrintedField => new PrintedField($key, new PrintLabel($en, $ar), $source, $always, $whenGiven);

        return [
            $field('title', 'The words "Tax Invoice"', 'عبارة "فاتورة ضريبية"', self::ARTICLE.'59(1)(a) and 59(5)(a)'),
            $field('seller_name', 'Supplier name', 'اسم المورد', self::ARTICLE.'59(1)(b) and 59(5)(b)'),
            $field('seller_address', 'Supplier address', 'عنوان المورد', self::ARTICLE.'59(1)(b) and 59(5)(b)'),
            $field('seller_vat_number', 'Supplier TRN', 'رقم التسجيل الضريبي للمورد', self::ARTICLE.'59(1)(b) and 59(5)(b)'),
            $field('buyer_name', 'Recipient name', 'اسم المتلقي', self::ARTICLE.'59(1)(c)', self::STANDARD),
            $field('buyer_address', 'Recipient address', 'عنوان المتلقي', self::ARTICLE.'59(1)(c)', self::STANDARD),
            $field('buyer_vat_number', 'Recipient TRN, where registered', 'رقم التسجيل الضريبي للمتلقي إن وجد', self::ARTICLE.'59(1)(c)', [], self::STANDARD),
            $field('invoice_number', 'Sequential invoice number', 'رقم الفاتورة التسلسلي', self::ARTICLE.'59(1)(d)', self::STANDARD),
            $field('issue_date', 'Issue date', 'تاريخ الإصدار', self::ARTICLE.'59(1)(e) and 59(5)(c)'),
            $field('supply_date', 'Supply date, if different', 'تاريخ التوريد إن اختلف', self::ARTICLE.'59(1)(f)', [], self::STANDARD),
            $field('line_description', 'Goods or services', 'وصف السلع أو الخدمات', self::ARTICLE.'59(1)(g) and 59(5)(d)'),
            $field('line_unit_price', 'Unit price', 'سعر الوحدة', self::ARTICLE.'59(1)(h)', self::STANDARD),
            $field('line_quantity', 'Quantity', 'الكمية', self::ARTICLE.'59(1)(h)', self::STANDARD),
            $field(PrintedField::LINE_VAT_RATE, 'VAT rate', 'نسبة الضريبة', self::ARTICLE.'59(1)(h)', self::STANDARD),
            $field('line_vat', 'VAT per line, in AED', 'ضريبة كل بند بالدرهم', 'Electronic Invoicing Guidelines v1.1, 12.2 item 16; '.self::ARTICLE.'59(1)(h)', self::STANDARD),
            $field('line_total', 'Amount payable per line, in AED', 'المبلغ المستحق لكل بند بالدرهم', self::ARTICLE.'59(1)(h); Electronic Invoicing Guidelines v1.1, 12.2 item 16', self::STANDARD),
            $field(PrintedField::LINE_DISCOUNT, 'Discount offered', 'الخصم الممنوح', self::ARTICLE.'59(1)(i)', [], self::STANDARD),
            $field('total_with_vat', 'Gross amount payable, in AED', 'إجمالي المبلغ المستحق بالدرهم', self::ARTICLE.'59(1)(j) and 59(5)(e)'),
            $field('vat_total', 'VAT payable, in AED', 'مبلغ الضريبة المستحقة بالدرهم', self::ARTICLE.'59(1)(k) and 59(5)(e)'),
            $field('vat_in_accounting_currency', 'Exchange rate applied', 'سعر الصرف المطبق', self::ARTICLE.'59(1)(k); VAT Decree-Law Art. 69', [], self::BOTH),
            $field('reverse_charge_statement', 'Statement that the recipient accounts for VAT', 'بيان بأن المتلقي يحتسب الضريبة', self::ARTICLE.'59(1)(l)', [], self::STANDARD),
        ];
    }
}
