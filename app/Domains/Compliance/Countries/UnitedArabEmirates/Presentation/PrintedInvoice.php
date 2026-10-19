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
            'meta' => $this->meta($document, $uuid),
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

    /** Art. 59(1)(a): the words "Tax Invoice", or the document's own kind. */
    private function title(PintDocumentCategory $category): PrintLabel
    {
        return match ($category) {
            PintDocumentCategory::TaxInvoice => new PrintLabel('Tax Invoice', 'فاتورة ضريبية'),
            PintDocumentCategory::TaxCreditNote => new PrintLabel('Tax Credit Note', 'إشعار دائن ضريبي'),
            PintDocumentCategory::SelfBilledInvoice => new PrintLabel('Tax Invoice (self-billed)', 'فاتورة ضريبية (فوترة ذاتية)'),
            PintDocumentCategory::SelfBilledCreditNote => new PrintLabel('Tax Credit Note (self-billed)', 'إشعار دائن ضريبي (فوترة ذاتية)'),
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
    private function meta(PintDocument $document, ?string $uuid): array
    {
        return array_values(array_filter([
            new ReceiptRow(new PrintLabel('Invoice number', 'رقم الفاتورة'), $document->number, true),
            new ReceiptRow(new PrintLabel('Issue date', 'تاريخ الإصدار'), $document->issueDate),
            $document->taxPointDate ? new ReceiptRow(new PrintLabel('Date of supply', 'تاريخ التوريد'), $document->taxPointDate) : null,
            $document->periodStart && $document->periodEnd ? new ReceiptRow(new PrintLabel('Billing period', 'فترة الفوترة'), $document->periodStart.' - '.$document->periodEnd) : null,
            $document->dueDate ? new ReceiptRow(new PrintLabel('Due date', 'تاريخ الاستحقاق'), $document->dueDate) : null,
            $document->billingReferences !== [] ? new ReceiptRow(new PrintLabel('Invoice credited', 'الفاتورة الأصلية'), implode(', ', $document->billingReferences)) : null,
            $document->creditReason ? new ReceiptRow(new PrintLabel('Reason', 'السبب'), $document->creditReason) : null,
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
     * Art. 59(1)(l): where the recipient accounts for the tax, a statement
     * that it does.
     *
     * @return list<PrintLabel>
     */
    private function statements(PintDocument $document): array
    {
        foreach ($document->lines as $line) {
            if ($line->category === PintTaxCategory::ReverseCharge) {
                return [new PrintLabel(
                    'Reverse charge: the recipient is required to account for the VAT on this supply under the VAT Decree-Law.',
                    'احتساب عكسي: يلتزم المتلقي باحتساب ضريبة القيمة المضافة على هذا التوريد وفقاً للمرسوم بقانون بشأن ضريبة القيمة المضافة.',
                )];
            }
        }

        return [];
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
