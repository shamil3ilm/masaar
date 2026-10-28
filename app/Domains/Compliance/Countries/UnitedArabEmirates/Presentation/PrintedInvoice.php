<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Presentation;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintAddress;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintDocument;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintLine;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintParty;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintDocumentCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeDocuments;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Engine\DTOs\PrintLabel;
use App\Domains\Compliance\Engine\DTOs\ReceiptRow;
use App\Domains\Compliance\Networks\Peppol\Sending\ParticipantDirectory;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\UnitCode;

/**
 * A UAE invoice in human-readable form: what its page, its PDF and its
 * receipt print.
 *
 * Drawn from the same PINT document the e-invoice's XML is built from
 * (PeppolPreparer::document), so the paper and the XML cannot state
 * different figures: every amount, the dirham conversions, the parties and
 * the dates are the document's.
 *
 * Why it is printed at all. The e-invoice is the tax invoice, and to a
 * buyer on electronic invoicing no other is needed; to one that is not, a
 * regular one is (Electronic Invoicing Guidelines v1.1, 10.2.2, p23):
 *
 *   "When issuing Tax Invoices to buyers that have not yet implemented
 *   Electronic Invoicing ..., regular Tax Invoices (e.g. in pdf) are
 *   required in addition to electronic Tax Invoices. In such scenarios
 *   where the buyer has not yet implemented Electronic Invoicing and does
 *   not have a Participant Identifier, the predefined endpoint
 *   (0235: 9900000098) should be mandatorily included by the supplier on
 *   the Electronic Invoice."
 *
 * and a buyer may ask for one (6.2, p15): "a separate Tax Invoice or
 * Commercial Invoice ... may be required by a buyer in respect of a
 * transaction if they have not yet implemented Electronic Invoicing".
 *
 * So status() says which this copy is: REQUIRED for a buyer at the reserved
 * endpoint or one the network lookup does not find; COPY for a buyer the
 * lookup finds, which is printed only on request and says so, the
 * e-invoice remaining the tax invoice; VERSION where it cannot be told -
 * no lookup answer, an export or a deemed supply - printed as the
 * human-readable version of the electronic invoice, as the guidelines' own
 * sample is titled (12.1, p34). It carries no QR code: the UAE's e-invoices
 * feature none (5.3, p12).
 */
final class PrintedInvoice
{
    public const REQUIRED = 'required';

    public const COPY = 'copy';

    public const VERSION = 'version';

    public function __construct(
        private readonly PeppolPreparer $preparer,
        private readonly UaeDocuments $documents,
        private readonly ParticipantDirectory $directory,
    ) {}

    /**
     * Whether the buyer must be given this printed invoice (REQUIRED), may
     * be given it as a copy (COPY), or neither can be told (VERSION).
     */
    public function status(Invoice $invoice): string
    {
        $endpoint = str_replace(' ', '', (string) ($invoice->jurisdiction_data['buyer_endpoint'] ?? ''));

        if ($endpoint === PintAe::SCHEME.':'.PintAe::BUYER_NOT_YET_ON_EINVOICING) {
            return self::REQUIRED;
        }

        return match ($endpoint === '' ? null : $this->directory->reachable($endpoint, (string) $invoice->org_id)) {
            false => self::REQUIRED,
            true => self::COPY,
            default => self::VERSION,
        };
    }

    /**
     * Everything the page prints, in order, from the e-invoice's document.
     *
     * @return array<string, mixed>
     */
    public function page(Invoice $invoice): array
    {
        $document = $this->preparer->document($invoice);
        $status = $invoice->exists ? $this->status($invoice) : self::REQUIRED;
        $uuid = $invoice->exists ? $this->documents->outboundFor((string) $invoice->org_id, (string) $invoice->id)?->document_uuid : null;
        $foreign = $document->taxCurrency !== null;
        $money = fn (?string $amount, ?string $currency = null): string => number_format((float) ($amount ?? 0), 2).' '.($currency ?? $document->currency);
        $lineIds = $invoice->lines->values()->map(fn ($line): string => (string) $line->id)->all();

        return [
            'document' => $document,
            'status' => $status,
            'title' => $this->title($document->category),
            'note' => $this->note($status),
            'meta' => $this->meta($document, $uuid, $invoice),
            'credit' => $this->credit($invoice, $document, $money),
            'seller' => $this->party($document->seller, true),
            'buyer' => $this->party($document->buyer, false),
            'foreign' => $foreign,
            'lines' => array_map(fn (PintLine $line, int $index): array => [
                'id' => $lineIds[$index] ?? '',
                'name' => $line->name,
                'description' => $line->description !== $line->name ? $line->description : '',
                'quantity' => $line->quantity,
                'unit' => UnitCode::printed($line->unitCode),
                'unit_en' => UnitCode::printedInEnglish($line->unitCode),
                'price' => $money($line->price),
                'discount' => $line->discount === null ? null : '-'.$money($line->discount),
                'net' => $money($line->netAmount),
                'rate' => $line->rate === null ? $this->categoryLabel($line->category)->en : rtrim(rtrim($line->rate, '0'), '.').'%',
                'vat' => $line->vatAed === null ? '-' : $money($line->vatAed, PintAe::TAX_CURRENCY),
                'total' => $money($line->totalAed, PintAe::TAX_CURRENCY),
                'exemption' => $line->exemptionReason,
            ], $document->lines, array_keys($document->lines)),
            'totals' => $this->totals($document, $money),
            'statements' => $this->statements($document),
            'payment' => array_values(array_filter([
                $document->paymentAccount ? new ReceiptRow(new PrintLabel('Account to pay into', 'الحساب المراد الدفع إليه'), $document->paymentAccount) : null,
                $document->paymentTerms ? new ReceiptRow(new PrintLabel('Payment terms', 'شروط الدفع'), $document->paymentTerms) : null,
            ])),
        ];
    }

    /**
     * The words the Executive Regulation has displayed (MoF text, 09-2026):
     * "Tax Invoice", Art. 59(1)(a) p51; "Tax Credit Note", Art. 60(1)(a)
     * p54; on one the buyer raises, "Tax Invoice raised by buyer", Art.
     * 59(9)(d) p53, and "Tax Credit Note created by buyer", Art. 60(4)(d)
     * p55. A commercial invoice - no Tax Invoice under the Decree-Law - is
     * named as the Electronic Invoicing Guidelines name it (10.2.1, p22).
     */
    private function title(PintDocumentCategory $category): PrintLabel
    {
        return match ($category) {
            PintDocumentCategory::TaxInvoice => new PrintLabel('Tax Invoice', 'فاتورة ضريبية'),
            PintDocumentCategory::TaxCreditNote => new PrintLabel('Tax Credit Note', 'إشعار دائن ضريبي'),
            PintDocumentCategory::SelfBilledInvoice => new PrintLabel('Tax Invoice raised by buyer', 'فاتورة ضريبية صادرة عن المشتري'),
            PintDocumentCategory::SelfBilledCreditNote => new PrintLabel('Tax Credit Note created by buyer', 'إشعار دائن ضريبي صادر عن المشتري'),
            PintDocumentCategory::CommercialInvoice => new PrintLabel('Commercial Invoice', 'فاتورة تجارية'),
            PintDocumentCategory::CommercialCreditNote => new PrintLabel('Credit Note', 'إشعار دائن'),
        };
    }

    private function note(string $status): PrintLabel
    {
        return match ($status) {
            self::REQUIRED => new PrintLabel(
                'Issued with the electronic invoice, as the buyer is not yet on electronic invoicing.',
                'صدرت مع الفاتورة الإلكترونية لأن المشتري لم ينضم بعد إلى نظام الفوترة الإلكترونية.',
            ),
            self::COPY => new PrintLabel(
                'Copy for information. The tax invoice is the electronic invoice delivered through the e-invoicing network.',
                'نسخة للاطلاع. الفاتورة الضريبية هي الفاتورة الإلكترونية المرسلة عبر شبكة الفوترة الإلكترونية.',
            ),
            default => new PrintLabel(
                'Human-readable version of the electronic invoice.',
                'نسخة مقروءة من الفاتورة الإلكترونية.',
            ),
        };
    }

    /**
     * @return list<ReceiptRow>
     */
    private function meta(PintDocument $document, ?string $uuid, Invoice $invoice): array
    {
        return array_values(array_filter([
            new ReceiptRow(new PrintLabel('Invoice number', 'رقم الفاتورة'), $document->number, true),
            new ReceiptRow(new PrintLabel('Issue date', 'تاريخ الإصدار'), $document->issueDate),
            $document->taxPointDate ? new ReceiptRow(new PrintLabel('Date of supply', 'تاريخ التوريد'), $document->taxPointDate) : null,
            $document->periodStart && $document->periodEnd ? new ReceiptRow(new PrintLabel('Billing period', 'فترة الفوترة'), $document->periodStart.' - '.$document->periodEnd) : null,
            $document->dueDate ? new ReceiptRow(new PrintLabel('Due date', 'تاريخ الاستحقاق'), $document->dueDate) : null,
            // Art. 60(1)(g) and (f), p54-55: what identifies the supply a
            // credit note relates to, and why it is issued.
            $document->billingReferences !== [] ? new ReceiptRow(new PrintLabel('Invoice credited', 'الفاتورة الأصلية'), implode(', ', $document->billingReferences)) : null,
            $document->category->isCreditNote() && ($reason = $this->reason($document, $invoice)) !== null ? new ReceiptRow(new PrintLabel('Reason', 'السبب'), $reason) : null,
            $document->orderReference ? new ReceiptRow(new PrintLabel('Purchase order', 'رقم أمر الشراء'), $document->orderReference) : null,
            $document->contractReference ? new ReceiptRow(new PrintLabel('Contract', 'رقم العقد'), $document->contractReference) : null,
            $document->buyerReference ? new ReceiptRow(new PrintLabel('Buyer reference', 'مرجع المشتري'), $document->buyerReference) : null,
            $uuid ? new ReceiptRow(new PrintLabel('E-invoice UUID', 'المعرّف الفريد للفاتورة الإلكترونية'), $uuid) : null,
        ]));
    }

    /**
     * Art. 59(1)(b) and (c): name, address and TRN.
     *
     * @return array{heading: PrintLabel, name: string, rows: list<ReceiptRow>, address: string}
     */
    private function party(PintParty $party, bool $seller): array
    {
        return [
            'heading' => $seller ? new PrintLabel('Supplier', 'المورد') : new PrintLabel('Recipient', 'المتلقي'),
            'name' => $party->name,
            'rows' => array_values(array_filter([
                $party->vatId ? new ReceiptRow(new PrintLabel('TRN', 'رقم التسجيل الضريبي'), $party->vatId) : null,
                $party->taxRegistrationId ? new ReceiptRow(new PrintLabel('TIN', 'رقم التعريف الضريبي'), $party->taxRegistrationId) : null,
                $party->legalId ? new ReceiptRow(new PrintLabel('Legal registration', 'التسجيل القانوني'), $party->legalId) : null,
                $seller ? null : new ReceiptRow(new PrintLabel('E-invoicing address', 'عنوان الفوترة الإلكترونية'), $party->endpointScheme.':'.$party->endpointId),
            ])),
            'address' => $this->address($party->address),
        ];
    }

    private function address(PintAddress $address): string
    {
        return implode(', ', array_filter([$address->street, $address->additionalStreet, $address->city, $address->subdivision, $address->postalCode, $address->countryCode], fn (?string $part): bool => trim((string) $part) !== ''));
    }

    /**
     * Art. 59(1)(i)-(k): the discount, the gross amount payable and the tax
     * in dirhams, with the rate a foreign currency was converted at.
     *
     * @return list<ReceiptRow>
     */
    private function totals(PintDocument $document, \Closure $money): array
    {
        $rows = [new ReceiptRow(new PrintLabel('Total of the lines', 'مجموع البنود'), $money($document->lineExtensionAmount))];

        if (bccomp($document->allowanceTotal, '0', 2) > 0) {
            $rows[] = new ReceiptRow(new PrintLabel('Discount', 'الخصم'), '-'.$money($document->allowanceTotal));
        }

        foreach ($document->charges as $charge) {
            $rows[] = new ReceiptRow(new PrintLabel($charge->reason, 'رسوم'), $money($charge->amount));
        }

        foreach ($document->subtotals as $subtotal) {
            $rows[] = new ReceiptRow(
                new PrintLabel('VAT '.$this->categoryLabel($subtotal->category)->en.($subtotal->rate !== null ? ' '.rtrim(rtrim($subtotal->rate, '0'), '.').'%' : '').' on '.$money($subtotal->taxableAmount), 'ضريبة القيمة المضافة - '.$this->categoryLabel($subtotal->category)->ar),
                $money($subtotal->taxAmount),
            );
        }

        $rows[] = new ReceiptRow(new PrintLabel('Total excluding VAT', 'الإجمالي غير شامل الضريبة'), $money($document->taxExclusiveAmount));
        $rows[] = new ReceiptRow(new PrintLabel('VAT', 'ضريبة القيمة المضافة'), $money($document->taxAmount));
        $rows[] = new ReceiptRow(new PrintLabel('Total including VAT', 'الإجمالي شامل الضريبة'), $money($document->payableAmount), true);

        if ($document->taxCurrency !== null) {
            $rows[] = new ReceiptRow(new PrintLabel('VAT in AED', 'ضريبة القيمة المضافة بالدرهم'), $money($document->taxAmountInTaxCurrency, PintAe::TAX_CURRENCY));
            $rows[] = new ReceiptRow(new PrintLabel('Total including VAT in AED', 'الإجمالي شامل الضريبة بالدرهم'), $money($document->totalInTaxCurrency, PintAe::TAX_CURRENCY), true);
            $rows[] = new ReceiptRow(new PrintLabel('Exchange rate', 'سعر الصرف'), '1 '.$document->currency.' = '.$document->exchangeRate.' '.PintAe::TAX_CURRENCY);
        }

        return $rows;
    }

    /**
     * Art. 59(1)(l), p51: where the recipient accounts for the tax, "a
     * statement that the Recipient is required to account for Tax, and a
     * reference to the relevant provision of the Decree-Law". The provision
     * is a clause of Article 48 of the Decree-Law (MoF text, pp23-25): Clause
     * 3 for crude or refined oil, natural gas and pure hydrocarbons between
     * Registrants, Clause 8 for goods or services the Cabinet has specified,
     * Clause 1 for imported concerned goods or services. A line's goods
     * type (BTAE-09: DL8.48.3.x, DL8.48.8.x) says which; with none, Article
     * 48 is cited without a clause.
     *
     * @return list<PrintLabel>
     */
    private function statements(PintDocument $document): array
    {
        $clauses = [];

        foreach ($document->lines as $line) {
            if ($line->category !== PintTaxCategory::ReverseCharge) {
                continue;
            }

            $clauses[] = preg_match('/^DL8\.48\.(\d+)\./', (string) $line->goodsType, $match) === 1 ? (int) $match[1] : 0;
        }

        if ($clauses === []) {
            return [];
        }

        $clauses = array_values(array_unique($clauses));
        sort($clauses);
        $named = array_values(array_filter($clauses));
        $where = $named === []
            ? new PrintLabel('Article 48', 'المادة 48')
            : new PrintLabel(
                (count($named) > 1 ? 'Clauses ' : 'Clause ').implode(' and ', $named).' of Article 48',
                (count($named) > 1 ? 'البندين ' : 'البند ').implode(' و', $named).' من المادة 48',
            );

        return [new PrintLabel(
            'Reverse charge: the Recipient is required to account for Tax on this supply under '.$where->en.' of Federal Decree-Law No. 8 of 2017 on Value Added Tax.',
            'احتساب عكسي: يلتزم المتلقي باحتساب الضريبة على هذا التوريد وفقاً ل'.$where->ar.' من المرسوم بقانون اتحادي رقم 8 لسنة 2017 بشأن ضريبة القيمة المضافة.',
        )];
    }

    /**
     * Art. 60(1)(e), p54: on a Tax Credit Note "the value of the supply shown
     * on the Tax Invoice, the correct amount of the value of the supply, the
     * difference between those two amounts, and the Tax charged that relates
     * to that difference in AED", the value shown being the one left by any
     * earlier credit note on the same invoice. The invoice credited is this
     * organization's, found by its number; a credit note naming none (a
     * volume discount) or one issued elsewhere states the difference and its
     * tax only.
     *
     * @return list<ReceiptRow>|null
     */
    private function credit(Invoice $invoice, PintDocument $document, \Closure $money): ?array
    {
        if (! $document->category->isCreditNote()) {
            return null;
        }

        $aed = fn (string $amount): string => $document->exchangeRate === null ? $amount : bcmul($amount, $document->exchangeRate, 2);
        $difference = $aed($document->taxExclusiveAmount);
        $tax = $document->taxAmountInTaxCurrency ?? $document->taxAmount;
        $rows = [];
        $original = $invoice->exists ? $this->originalValue($invoice) : null;

        if ($original !== null) {
            $rows[] = new ReceiptRow(new PrintLabel('Value of the supply on the tax invoice', 'قيمة التوريد في الفاتورة الضريبية'), $money($original, PintAe::TAX_CURRENCY));
            $rows[] = new ReceiptRow(new PrintLabel('Correct value of the supply', 'القيمة الصحيحة للتوريد'), $money(bcsub($original, $difference, 2), PintAe::TAX_CURRENCY));
        }

        $rows[] = new ReceiptRow(new PrintLabel('Difference', 'الفرق'), $money($difference, PintAe::TAX_CURRENCY));
        $rows[] = new ReceiptRow(new PrintLabel('Tax on the difference', 'الضريبة على الفرق'), $money($tax, PintAe::TAX_CURRENCY), true);

        return $rows;
    }

    /**
     * The value excluding tax, in AED, the credited invoice shows after any
     * earlier credit notes on it; null when it is not one of this
     * organization's invoices.
     */
    private function originalValue(Invoice $note): ?string
    {
        $reference = trim((string) $note->billing_ref);

        if ($reference === '') {
            return null;
        }

        $credited = Invoice::acrossTenants()
            ->with('lines.charges')
            ->where('org_id', $note->org_id)
            ->where('invoice_number', $reference)
            ->where('document_type', 'invoice')
            ->first();

        if ($credited === null) {
            return null;
        }

        $value = $this->valueInAed($this->preparer->document($credited));

        $earlier = Invoice::acrossTenants()
            ->with('lines.charges')
            ->where('org_id', $note->org_id)
            ->where('billing_ref', $reference)
            ->where('document_type', 'credit_note')
            ->where('status', '!=', 'draft')
            ->whereKeyNot($note->id)
            ->where('issued_at', '<', $note->issued_at ?? now())
            ->get();

        foreach ($earlier as $previous) {
            $value = bcsub($value, $this->valueInAed($this->preparer->document($previous)), 2);
        }

        return $value;
    }

    private function valueInAed(PintDocument $document): string
    {
        return $document->exchangeRate === null ? bcadd($document->taxExclusiveAmount, '0', 2) : bcmul($document->taxExclusiveAmount, $document->exchangeRate, 2);
    }

    /**
     * Art. 60(1)(f): a brief explanation. The reason typed, else the
     * Decree-Law case its code names (Art. 61(1)(a)-(e), MoF text pp29-30).
     */
    private function reason(PintDocument $document, Invoice $invoice): ?string
    {
        $typed = trim((string) $invoice->adjustment_reason);

        if ($typed !== '') {
            return $typed;
        }

        return match ($document->creditReason) {
            'DL8.61.1.A' => 'The supply was cancelled (Decree-Law Art. 61(1)(a))',
            'DL8.61.1.B' => 'The tax treatment changed with the nature of the supply (Art. 61(1)(b))',
            'DL8.61.1.C' => 'The agreed consideration was altered (Art. 61(1)(c))',
            'DL8.61.1.D' => 'Goods or services were returned and the consideration refunded (Art. 61(1)(d))',
            'DL8.61.1.E' => 'Tax was charged or a tax treatment applied in error (Art. 61(1)(e))',
            'VD' => 'Volume discount',
            null, '' => null,
            default => $document->creditReason,
        };
    }

    private function categoryLabel(PintTaxCategory $category): PrintLabel
    {
        return match ($category) {
            PintTaxCategory::Standard => new PrintLabel('standard rated', 'النسبة الأساسية'),
            PintTaxCategory::ZeroRated => new PrintLabel('zero rated', 'نسبة الصفر'),
            PintTaxCategory::Exempt => new PrintLabel('exempt', 'معفاة'),
            PintTaxCategory::OutOfScope => new PrintLabel('out of scope', 'خارج النطاق'),
            PintTaxCategory::ReverseCharge => new PrintLabel('reverse charge', 'احتساب عكسي'),
            PintTaxCategory::MarginScheme => new PrintLabel('margin scheme', 'نظام الهامش'),
        };
    }
}
