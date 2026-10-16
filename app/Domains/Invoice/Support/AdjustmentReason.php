<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

/**
 * Why a credit or debit note is issued (KSA-10).
 *
 * ZATCA's E-Invoice Data Dictionary (May 2023), row KSA-10, lists the five
 * instances in which Article 40(1) and Article 54(3) of the VAT Implementing
 * Regulations allow a credit or debit note. The texts below are copied from
 * that row, English and Arabic as the authority wrote them - not paraphrased,
 * because the reason is printed on the note and read against the regulation.
 *
 * The portal offers only these. The API still takes free text: an ERP may
 * already send its own wording, and BR-KSA-17 asks only that a reason is
 * given.
 */
final class AdjustmentReason
{
    /**
     * English => Arabic, as the data dictionary has them.
     *
     * @var array<string, string>
     */
    public const OFFICIAL = [
        'Cancellation or suspension of the supplies after its occurrence either wholly or partially' => 'تم إلغاء أو وقف التوريد بعد حدوثه أو اعتباره كلياً أو جزئياً',
        'In case of essential change or amendment in the supply, which leads to the change of the VAT due' => 'وجود تغيير أو تعديل جوهري في طبيعة التوريد بحيث يؤدي الى تغيير الضريبة المستحقة',
        'Amendment of the supply value which is pre-agreed upon between the supplier and consumer' => 'تم الاتفاق على تعديل قيمة التوريد مسبقاً',
        'In case of goods or services refund.' => 'عند ترجيع السلع أو الخدمات',
        "In case of change in Seller's or Buyer's information" => 'عند التعديل على بيانات المورد أو المشتري',
    ];

    /**
     * What is stored and printed: both languages, so the note reads the same
     * to the buyer and to the authority whichever language the portal is in.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            fn (string $english, string $arabic): string => "{$english} / {$arabic}",
            array_keys(self::OFFICIAL),
            array_values(self::OFFICIAL),
        );
    }
}
