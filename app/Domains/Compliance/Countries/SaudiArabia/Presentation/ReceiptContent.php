<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Presentation;

use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Compliance\Engine\DTOs\PrintLabel;
use App\Domains\Compliance\Engine\DTOs\Receipt;
use App\Domains\Compliance\Engine\DTOs\ReceiptItem;
use App\Domains\Compliance\Engine\DTOs\ReceiptRow;
use App\Domains\Compliance\Engine\DTOs\ReceiptSection;
use App\Domains\Invoice\Enums\InvoiceType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Support\PrintedAmounts;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Organization\Models\Organization;
use BackedEnum;

/**
 * A Saudi invoice as a till's receipt.
 *
 * The same fields as the full page (countries/saudi-arabia/print/pdf.blade.php,
 * whose comments say why each is there) in one narrow column: nothing the
 * data dictionary marks required on a printed copy is dropped for want of
 * width. The captions are ZATCA's own Arabic from its illustrative invoices.
 */
class ReceiptContent
{
    private const TITLES = [
        'credit_note' => ['Credit note', 'إشعار دائن'],
        'debit_note' => ['Debit note', 'إشعار مدين'],
    ];

    public function build(Invoice $invoice, Organization $organization): Receipt
    {
        $simplified = $invoice->type === InvoiceType::Simplified;
        $documentType = $invoice->document_type instanceof BackedEnum ? $invoice->document_type->value : (string) $invoice->document_type;
        [$titleEn, $titleAr] = self::TITLES[$documentType]
            ?? ($simplified ? ['Simplified tax invoice', 'فاتورة ضريبية مبسطة'] : ['Tax invoice', 'فاتورة ضريبية']);
        // BT-146: a standard invoice prints the net price; a receipt may keep
        // the price its customer saw.
        $inclusivePrice = $simplified && $invoice->prices_include_vat;

        return new Receipt(
            title: new PrintLabel($titleEn, $titleAr),
            sections: [
                $this->seller($organization),
                $this->document($invoice, in_array($documentType, ['credit_note', 'debit_note'], true)),
                $this->buyer($invoice, $simplified),
            ],
            quantityLabel: new PrintLabel('Qty', 'الكمية'),
            unitPriceLabel: $inclusivePrice
                ? new PrintLabel('Unit price (incl. VAT)', 'سعر الوحدة شامل ضريبة القيمة المضافة')
                : new PrintLabel('Unit price', 'سعر الوحدة'),
            totalLabel: new PrintLabel('Total', 'المجموع'),
            items: $invoice->lines->map(fn (InvoiceLine $line): ReceiptItem => $this->item($invoice, $line, $simplified, $inclusivePrice))->values()->all(),
            // KSA-12 on a simplified invoice: the statement that the amounts
            // include VAT.
            itemsNote: $simplified ? new PrintLabel('Line totals are inclusive of VAT', 'المبالغ شاملة ضريبة القيمة المضافة') : null,
            totals: $this->totals($invoice),
            notes: $invoice->notes ? new ReceiptRow(new PrintLabel('Notes', 'ملاحظات'), (string) $invoice->notes) : null,
            qrPayload: (string) $invoice->qr_code,
            footer: $invoice->icv ? ['Counter '.$invoice->icv.' · '.$invoice->hash] : [],
        );
    }

    private function seller(Organization $organization): ReceiptSection
    {
        return new ReceiptSection(
            heading: new PrintLabel('Seller', 'المورد'),
            name: $organization->documentName(),
            rows: array_values(array_filter([
                $organization->vat_number ? new ReceiptRow(new PrintLabel('VAT number', 'الرقم الضريبي'), (string) $organization->vat_number) : null,
                $organization->cr_number ? new ReceiptRow(new PrintLabel('CR', 'السجل التجاري'), (string) $organization->cr_number) : null,
            ])),
            lines: $this->address([
                trim($organization->building_number.' '.$organization->street),
                $organization->additional_street,
                $organization->district,
                trim($organization->city.' '.$organization->postal_code),
                $organization->country,
            ]),
        );
    }

    private function document(Invoice $invoice, bool $adjusts): ReceiptSection
    {
        // The time the XML's IssueTime and the QR carry, on the Kingdom's clock.
        $issueTime = $invoice->created_at ? FatooraTime::toSaudiTime($invoice->issuedMoment())->format('H:i:s') : null;

        return new ReceiptSection(
            heading: null,
            name: null,
            rows: array_values(array_filter([
                new ReceiptRow(new PrintLabel('Invoice number', 'رقم الفاتورة'), (string) $invoice->invoice_number, true),
                new ReceiptRow(new PrintLabel('Issue date and time', 'تاريخ ووقت الإصدار'), trim($invoice->issue_date?->toDateString().' '.$issueTime)),
                $invoice->supply_date ? new ReceiptRow(new PrintLabel('Supply date', 'تاريخ التوريد'), $invoice->supply_date->toDateString()) : null,
                $adjusts ? new ReceiptRow(new PrintLabel('Adjusts', 'مرجع الفاتورة'), (string) $invoice->billing_ref) : null,
                $adjusts && $invoice->adjustment_reason ? new ReceiptRow(new PrintLabel('Reason', 'سبب الإصدار'), (string) $invoice->adjustment_reason) : null,
            ])),
        );
    }

    private function buyer(Invoice $invoice, bool $simplified): ReceiptSection
    {
        $scheme = FatooraConfig::BUYER_ID_SCHEME_LABELS[$invoice->buyer_id_scheme ?? '']
            ?? [(string) $invoice->buyer_id_scheme, 'رقم تعريف المشتري'];
        $address = (array) ($invoice->buyer_address ?? []);
        $name = $invoice->documentBuyerName();

        return new ReceiptSection(
            heading: new PrintLabel('Buyer', 'العميل'),
            name: $name !== '' ? $name : null,
            rows: array_values(array_filter([
                $invoice->buyer_vat_number ? new ReceiptRow(new PrintLabel('VAT number', 'الرقم الضريبي'), (string) $invoice->buyer_vat_number) : null,
                $invoice->buyer_id ? new ReceiptRow(new PrintLabel($scheme[0], $scheme[1]), (string) $invoice->buyer_id) : null,
            ])),
            // Required on a standard invoice; a simplified one is a receipt
            // for someone who gave no address.
            lines: $simplified ? [] : $this->address([
                trim(($address['building_number'] ?? '').' '.($address['street'] ?? '')),
                $address['additional_street'] ?? null,
                $address['district'] ?? null,
                trim(($address['city'] ?? '').' '.($address['postal_code'] ?? '')),
                $address['country_code'] ?? null,
            ]),
        );
    }

    private function item(Invoice $invoice, InvoiceLine $line, bool $simplified, bool $inclusivePrice): ReceiptItem
    {
        $details = [];
        $notes = [];

        if (! $simplified) {
            // BT-131, BT-152 and KSA-11, required on a standard invoice: the
            // net, and the rate with the VAT it gives, on two short lines.
            $rate = (string) (float) $line->tax_rate.'%';
            $details = [
                new ReceiptRow(new PrintLabel('Net', 'الإجمالي غير شامل'), $this->money($invoice, (float) $line->line_total - (float) $line->tax_amount)),
                new ReceiptRow(new PrintLabel('VAT '.$rate, 'ضريبة '.$rate), $this->money($invoice, $line->tax_amount)),
            ];

            // BT-120, with the authority's Arabic for the code.
            if ($line->exempt_reason) {
                $notes[] = (string) $line->exempt_reason;

                if ($arabic = FatooraConfig::EXEMPTION_REASONS_AR[$line->exempt_code ?? ''] ?? null) {
                    $notes[] = $arabic;
                }
            }
        }

        return new ReceiptItem(
            // The Arabic description only: a receipt keeps each line short,
            // and the Arabic is the document's.
            description: $line->documentDescription(),
            quantity: rtrim(rtrim((string) $line->quantity, '0'), '.').' '.UnitCode::printed($line->unit_code),
            unitPrice: $this->money($invoice, $line->priceAsEntered($inclusivePrice)),
            total: $this->money($invoice, $line->line_total),
            discount: ($discount = PrintedAmounts::lineDiscount($line)) === null ? null : '-'.$this->money($invoice, $discount),
            details: $details,
            notes: $notes,
        );
    }

    /**
     * @return list<ReceiptRow>
     */
    private function totals(Invoice $invoice): array
    {
        // BT-111, converted as InvoiceXmlData::convertToSar converts it.
        $inSar = $invoice->currency !== FatooraConfig::CURRENCY_SAR && $invoice->exchange_rate !== null;
        $rows = [];

        // The subtotal is before the document discount; the taxable amount
        // (BT-109) is after it.
        if ((float) $invoice->discount_amount > 0) {
            $rows[] = new ReceiptRow(new PrintLabel('Subtotal', 'المجموع'), $this->money($invoice, $invoice->subtotal));
            $rows[] = new ReceiptRow(new PrintLabel('Discount', 'الخصم'), '-'.$this->money($invoice, $invoice->discount_amount));
        }

        // Charges on the whole document, each with its VAT rate.
        foreach (PrintedAmounts::documentCharges($invoice) as $charge) {
            $rows[] = new ReceiptRow(new PrintLabel(trim($charge['reason'].' ('.$charge['rate'].'%)'), 'رسوم إضافية'), $this->money($invoice, $charge['amount']));
        }

        $rows[] = new ReceiptRow(new PrintLabel('Taxable amount', 'الإجمالي غير شامل الضريبة'), $this->money($invoice, (float) $invoice->subtotal - (float) $invoice->discount_amount));
        $rows[] = new ReceiptRow(new PrintLabel('VAT', 'مجموع ضريبة القيمة المضافة'), $this->money($invoice, $invoice->tax_amount));

        if ($inSar) {
            $rows[] = new ReceiptRow(new PrintLabel('VAT (SAR)', 'ضريبة القيمة المضافة بالريال'), number_format(round((float) $invoice->tax_amount * (float) $invoice->exchange_rate, 2), 2).' SAR');
        }

        $rows[] = new ReceiptRow(new PrintLabel('Total due', 'إجمالي المبلغ المستحق'), $this->money($invoice, $invoice->total), true);

        if ($inSar) {
            $rows[] = new ReceiptRow(new PrintLabel('Exchange rate', 'سعر الصرف'), '1 '.$invoice->currency.' = '.rtrim(rtrim((string) $invoice->exchange_rate, '0'), '.').' SAR');
        }

        return $rows;
    }

    private function money(Invoice $invoice, mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2).' '.$invoice->currency;
    }

    /**
     * Ordered the way a Saudi address is written; an empty part is skipped.
     *
     * @param  list<string|null>  $parts
     * @return list<string>
     */
    private function address(array $parts): array
    {
        $line = implode(', ', array_filter(array_map(fn ($part): string => trim((string) $part), $parts)));

        return $line === '' ? [] : [$line];
    }
}
