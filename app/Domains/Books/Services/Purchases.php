<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Audit\Attribution\Channel;
use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Services\ExpenseReceipts;
use App\Domains\Books\DTOs\PurchaseEntry;
use App\Domains\Books\DTOs\PurchaseLineEntry;
use App\Domains\Books\Enums\PurchaseTreatment;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Books\Models\PurchaseLine;
use App\Domains\Books\Models\Supplier;
use App\Domains\Organization\Models\Organization;
use App\Support\CountryNames;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An organization's purchase invoices and supplier credit notes (Books):
 * recorded with their lines and the supplier's file, and void when entered
 * by mistake - never changed otherwise, never deleted.
 *
 * A purchase is the VAT return's in the period of the day it was received,
 * never before the document's own date (PurchaseLedger).
 *
 * Each line is kept in the currency billed and in the tax currency at the
 * document's own rate, rounded per line (KSA VAT Implementing Regulations
 * Art 61: an amount in another currency is converted at the rate of the
 * day). The input VAT the organization may deduct is the VAT it was
 * charged or assessed itself, times its deductible share - 100% unless the
 * purchase is partly for exempt or private use (KSA Art 49-51; UAE Federal
 * Decree-Law No. 8 of 2017 Art 54-55, Cabinet Decision No. 52 of 2017
 * Art 53, 55).
 *
 * A reverse-charge line carries no VAT from the supplier: the organization
 * assesses it at the line's rate (KSA Art 47(1); UAE Decree-Law Art 48(1)).
 * The same supplier's same number is refused twice while the first is not
 * void.
 */
class Purchases
{
    /** Where a purchase's file is kept, under the receipts disk. */
    private const FOLDER = 'purchases';

    public function __construct(
        private readonly BooksCountry $country,
        private readonly ExpenseReceipts $files,
        private readonly AuditService $audit,
        private readonly VatPeriods $periods,
    ) {}

    public function find(string $organizationId, string $id): ?PurchaseInvoice
    {
        return PurchaseInvoice::query()->where('org_id', $organizationId)->with(['lines', 'supplier', 'original'])->find($id);
    }

    /**
     * Record it, from the form or - $via import - a CSV.
     *
     * @throws ValidationException|DomainException
     */
    public function record(Organization $organization, PurchaseEntry $entry, ?UploadedFile $file = null, ?Channel $via = null): PurchaseInvoice
    {
        $values = $this->figures($organization, $entry);

        return DB::transaction(function () use ($organization, $values, $file, $via): PurchaseInvoice {
            $purchase = new PurchaseInvoice(['org_id' => (string) $organization->id, ...$values['invoice']]);
            $purchase->status = PurchaseInvoice::RECORDED;

            if ($via !== null) {
                $purchase->forceFill(['created_via' => $via->value]);
            }
            $purchase->save();

            foreach ($values['lines'] as $position => $line) {
                PurchaseLine::query()->create(['purchase_invoice_id' => $purchase->id, 'position' => $position + 1, ...$line]);
            }

            if ($file !== null) {
                $stored = $this->files->store($file, self::FOLDER.'/'.$organization->id.'/'.$purchase->id);
                $purchase->forceFill([
                    'file_path' => $stored['receipt_path'],
                    'file_name' => $stored['receipt_name'],
                    'file_mime' => $stored['receipt_mime'],
                    'file_size' => $stored['receipt_size'],
                ])->save();
            }

            $this->audit->logChange('books.purchase.recorded', 'PurchaseInvoice', (string) $purchase->id, null, $values['invoice'], [], null, (string) $organization->id);

            return $purchase->load('lines');
        });
    }

    /**
     * Void one entered by mistake, saying why. An invoice a credit note
     * still answers is voided only once that note is.
     *
     * @throws DomainException
     */
    public function void(PurchaseInvoice $purchase, string $reason, ?string $actorId): PurchaseInvoice
    {
        if ($purchase->isVoid()) {
            throw new DomainException(__('This purchase is already void.'));
        }

        $this->periods->assertOpen((string) $purchase->org_id, CarbonImmutable::parse($purchase->received_on), __('This purchase'));

        $answered = PurchaseInvoice::query()->where('org_id', $purchase->org_id)->where('original_id', $purchase->id)
            ->where('status', PurchaseInvoice::RECORDED)->exists();

        if ($answered) {
            throw new DomainException(__('A credit note is recorded against this invoice. Void the credit note first.'));
        }

        $purchase->forceFill([
            'status' => PurchaseInvoice::VOID,
            'void_reason' => trim($reason),
            'voided_by' => $actorId,
            'voided_at' => now(),
        ])->save();

        $this->audit->logChange('books.purchase.voided', 'PurchaseInvoice', (string) $purchase->id, ['status' => PurchaseInvoice::RECORDED],
            ['status' => PurchaseInvoice::VOID, 'reason' => trim($reason)], [], $actorId, (string) $purchase->org_id);

        return $purchase;
    }

    /**
     * The supplier told the organization it wrote off what is unpaid: from
     * that day's period the input tax recovered on the unpaid part is taken
     * back (UAE Federal Decree-Law No. 8 of 2017 Art 64(2)). Once.
     *
     * @throws DomainException
     */
    public function writeOff(Organization $organization, PurchaseInvoice $purchase, CarbonImmutable $notifiedOn, ?string $actorId): PurchaseInvoice
    {
        if (! $this->country->of($organization)->vatReturn()->inputTaxOnPayment()) {
            throw new DomainException(__('Here input tax does not depend on paying the supplier.'));
        }

        if ($purchase->isVoid() || $purchase->document_type !== PurchaseInvoice::INVOICE || $purchase->written_off_on !== null) {
            throw new DomainException(__('Only a recorded supplier invoice not yet written off can be written off.'));
        }

        if ($notifiedOn->lt(CarbonImmutable::parse($purchase->received_on))) {
            throw new DomainException(__('The write-off cannot come before the invoice was received.'));
        }

        $this->periods->assertOpen((string) $purchase->org_id, $notifiedOn, __('The write-off'));

        $purchase->forceFill(['written_off_on' => $notifiedOn->toDateString()])->save();
        $this->audit->logChange('books.purchase.written_off', 'PurchaseInvoice', (string) $purchase->id, null,
            ['written_off_on' => $notifiedOn->toDateString()], [], $actorId, (string) $purchase->org_id);

        return $purchase;
    }

    /** The supplier's recorded invoice with this number, which a credit note names by it. */
    public function invoiceNumbered(string $organizationId, string $supplierId, string $number): ?PurchaseInvoice
    {
        return PurchaseInvoice::query()->where('org_id', $organizationId)->where('supplier_id', $supplierId)
            ->where('document_type', PurchaseInvoice::INVOICE)->where('status', PurchaseInvoice::RECORDED)
            ->where('supplier_invoice_number', trim($number))->first();
    }

    public function filePath(PurchaseInvoice $purchase): ?string
    {
        return $this->files->exists($purchase->file_path) ? $this->files->absolutePath((string) $purchase->file_path) : null;
    }

    /**
     * The entry checked, and what is saved: the invoice's totals and each
     * line's amounts in both currencies.
     *
     * @return array{invoice: array<string, mixed>, lines: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function figures(Organization $organization, PurchaseEntry $entry): array
    {
        $organizationId = (string) $organization->id;
        $supplier = Supplier::query()->where('org_id', $organizationId)->find($entry->supplierId)
            ?? throw ValidationException::withMessages(['supplier_id' => __('Choose a supplier from the list.')]);
        $number = trim($entry->supplierInvoiceNumber);
        $taxCurrency = $this->country->currency($organization);
        $currency = strtoupper(trim($entry->currency));
        $type = $entry->documentType === PurchaseInvoice::CREDIT_NOTE ? PurchaseInvoice::CREDIT_NOTE : PurchaseInvoice::INVOICE;

        if ($number === '' || mb_strlen($number) > 100) {
            throw ValidationException::withMessages(['supplier_invoice_number' => __('Enter the number on the supplier\'s document.')]);
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages(['currency' => __('The currency is a three-letter code, such as :code.', ['code' => $taxCurrency])]);
        }

        $received = $entry->receivedOn ?? $entry->invoiceDate;

        if ($received->lt($entry->invoiceDate)) {
            throw ValidationException::withMessages(['received_on' => __('It cannot have been received before its date, :date.', ['date' => $entry->invoiceDate->toDateString()])]);
        }

        // Received in a filed period, it is taken account of in the next
        // open one: input tax may be deducted later than the supply's period
        // (KSA Art 49(8); UAE Decree-Law Art 55(2)) - and a credit note then
        // corrects the period it is recorded in (VatPeriods).
        $received = $this->periods->openDay($organizationId, $received);

        if ($entry->dueOn !== null && $entry->dueOn->lt($entry->invoiceDate)) {
            throw ValidationException::withMessages(['due_on' => __('It cannot be due before its date, :date.', ['date' => $entry->invoiceDate->toDateString()])]);
        }

        if ($entry->paidLater && ($type !== PurchaseInvoice::INVOICE || ! $this->country->of($organization)->vatReturn()->inputTaxOnPayment())) {
            throw ValidationException::withMessages(['paid_later' => __('Only a supplier invoice, where input tax waits on payment, is marked as paid later.')]);
        }

        $rate = $this->rate($currency, $taxCurrency, $entry->exchangeRate);
        $share = $this->share($entry->deductibleShare);
        $this->duplicateCheck($organizationId, (string) $supplier->id, $number, $type);
        $original = $this->original($organizationId, $type, $entry->originalId, (string) $supplier->id);

        if ($entry->lines === []) {
            throw ValidationException::withMessages(['lines' => __('Add at least one line.')]);
        }

        // Reverse charge is for a supplier not resident in the country: KSA
        // VAT Implementing Regulations Art 47(1) (a Nonresident Supplier);
        // UAE Federal Decree-Law No. 8 of 2017 Art 48(1) (imported Concerned
        // Services). A supplier with no country, or the organization's own,
        // is resident as far as the books know.
        $home = strtoupper($this->country->of($organization)->code());
        $abroad = filled($supplier->country_code) && strtoupper((string) $supplier->country_code) !== $home;

        // Domestic reverse charge: specified goods from a registered supplier
        // in the country, to a buyer registered for VAT (the country's list,
        // empty where it has none or the buyer is not registered).
        $goods = $this->country->of($organization)->vatReturn()->domesticReverseChargeGoods($organizationId);

        foreach ($entry->lines as $index => $line) {
            if ($line->treatment === PurchaseTreatment::DomesticReverseCharge) {
                $problem = match (true) {
                    $goods === [] => __('Line :line: domestic reverse charge is only for a business registered for VAT where the return has it.', ['line' => $index + 1]),
                    ! array_key_exists((string) $line->goodsType, $goods) => __('Line :line: choose which specified goods these are.', ['line' => $index + 1]),
                    $abroad || blank($supplier->vat_number) => __('Line :line: domestic reverse charge is for goods from a supplier registered for VAT in :country; give :supplier its VAT number and country.', ['line' => $index + 1, 'country' => CountryNames::name($home), 'supplier' => $supplier->name]),
                    default => null,
                };

                if ($problem !== null) {
                    throw ValidationException::withMessages(['lines.'.$index.'.goods_type' => $problem]);
                }
            }

            if ($line->treatment === PurchaseTreatment::ReverseCharge && ! $abroad) {
                throw ValidationException::withMessages(['lines.'.$index.'.treatment' => __('Line :line: reverse charge is only for a supplier outside :country, and :supplier is not set as one. Give the supplier its country, or choose the VAT the supplier charged.', [
                    'line' => $index + 1, 'country' => CountryNames::name($home), 'supplier' => $supplier->name,
                ])]);
            }
        }

        $standard = $this->country->standardRate($organization);
        $lines = [];

        foreach ($entry->lines as $index => $line) {
            $lines[] = $this->line($index + 1, $line, $standard, $rate);
        }

        $sum = fn (string $key, bool $chargedOnly = false): float => array_sum(array_map(
            fn (array $line): float => ! $chargedOnly || $line['treatment']->charged() ? (float) $line[$key] : 0.0,
            $lines,
        ));
        $deductible = array_sum(array_map(fn (array $line): float => $line['treatment']->deductible() ? (float) $line['vat_tax'] : 0.0, $lines));
        $net = $sum('net');
        $vat = $sum('vat', true);
        $netTax = $sum('net_tax');
        $vatTax = $sum('vat_tax', true);

        return [
            'invoice' => [
                'supplier_id' => (string) $supplier->id,
                'document_type' => $type,
                'original_id' => $original?->id,
                'supplier_invoice_number' => $number,
                'invoice_date' => $entry->invoiceDate->toDateString(),
                'received_on' => $received->toDateString(),
                'due_on' => $entry->dueOn?->toDateString(),
                'paid_later' => $entry->paidLater,
                'currency' => $currency,
                'exchange_rate' => $rate === 1.0 ? null : number_format($rate, 6, '.', ''),
                'net' => self::money($net),
                'vat' => self::money($vat),
                'gross' => self::money($net + $vat),
                'net_tax' => self::money($netTax),
                'vat_tax' => self::money($vatTax),
                'gross_tax' => self::money($netTax + $vatTax),
                'deductible_share' => number_format($share, 2, '.', ''),
                'deductible_vat_tax' => self::money($deductible * $share / 100),
                'description' => ($description = trim((string) $entry->description)) === '' ? null : mb_substr($description, 0, 500),
            ],
            'lines' => $lines,
        ];
    }

    public static function money(float $amount): string
    {
        return number_format(round($amount, 2) + 0.0, 2, '.', '');
    }

    /**
     * A figure as the pages show money (x-ui.amount): two decimals with
     * grouped thousands, the same in English and Arabic, where amounts stay
     * in Western digits left to right.
     */
    public static function shown(float|string|null $amount): string
    {
        return number_format(round((float) $amount, 2) + 0.0, 2);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function line(int $position, PurchaseLineEntry $line, string $standard, float $rate): array
    {
        $field = 'lines.'.($position - 1);
        $treatment = $line->treatment;
        $description = trim($line->description);

        if ($description === '') {
            throw ValidationException::withMessages([$field.'.description' => __('Line :line: say what was bought.', ['line' => $position])]);
        }

        if (! is_numeric($line->net) || (float) $line->net <= 0) {
            throw ValidationException::withMessages([$field.'.net' => __('Line :line: the amount before VAT is more than zero.', ['line' => $position])]);
        }

        $net = round((float) $line->net, 2);
        $vatRate = $treatment->untaxed() ? 0.0 : (float) (($line->vatRate ?? '') === '' ? $standard : $line->vatRate);

        if ($vatRate < 0 || $vatRate > 100) {
            throw ValidationException::withMessages([$field.'.vat_rate' => __('Line :line: the VAT rate is between 0 and 100.', ['line' => $position])]);
        }

        $typed = ($line->vat ?? '') === '' ? null : $line->vat;

        if ($typed !== null && (! is_numeric($typed) || (float) $typed < 0)) {
            throw ValidationException::withMessages([$field.'.vat' => __('Line :line: VAT is zero or more.', ['line' => $position])]);
        }

        if ($typed !== null && (float) $typed > 0 && ! $treatment->charged()) {
            throw ValidationException::withMessages([$field.'.vat' => __('Line :line: :treatment carries no VAT from the supplier.', ['line' => $position, 'treatment' => $treatment->label()])]);
        }

        // Charged: as the supplier's document says, or worked out from the
        // rate. Reverse charge: assessed here on the amount.
        $vat = match (true) {
            $treatment->untaxed() => 0.0,
            $treatment->charged() && $typed !== null => round((float) $typed, 2),
            default => round($net * $vatRate / 100, 2),
        };
        $netTax = round($net * $rate, 2);
        $vatTax = $treatment->selfAssessed() ? round($netTax * $vatRate / 100, 2) : round($vat * $rate, 2);

        return [
            'description' => mb_substr($description, 0, 500),
            'treatment' => $treatment,
            'vat_rate' => number_format($vatRate, 2, '.', ''),
            'net' => self::money($net),
            'vat' => self::money($vat),
            'net_tax' => self::money($netTax),
            'vat_tax' => self::money($vatTax),
            'is_capital_asset' => $line->capitalAsset,
            'goods_type' => $treatment === PurchaseTreatment::DomesticReverseCharge ? $line->goodsType : null,
        ];
    }

    /**
     * 1 for the tax currency; otherwise the rate typed, which must be there.
     *
     * @throws ValidationException
     */
    private function rate(string $currency, string $taxCurrency, ?string $typed): float
    {
        if ($currency === $taxCurrency) {
            return 1.0;
        }

        if (! is_numeric($typed) || (float) $typed <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => __('Enter the rate from :from to :to on the document\'s date.', ['from' => $currency, 'to' => $taxCurrency])]);
        }

        return round((float) $typed, 6);
    }

    /** @throws ValidationException */
    private function share(string $share): float
    {
        if (! is_numeric($share) || (float) $share < 0 || (float) $share > 100) {
            throw ValidationException::withMessages(['deductible_share' => __('The deductible share is a percentage from 0 to 100.')]);
        }

        return round((float) $share, 2);
    }

    /** @throws ValidationException */
    private function duplicateCheck(string $organizationId, string $supplierId, string $number, string $type): void
    {
        $duplicate = PurchaseInvoice::query()->where('org_id', $organizationId)->where('supplier_id', $supplierId)
            ->where('supplier_invoice_number', $number)->where('document_type', $type)
            ->where('status', PurchaseInvoice::RECORDED)->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages(['supplier_invoice_number' => __('Number :number from this supplier is already recorded, dated :date. Void that one first if it was a mistake.', [
                'number' => $number, 'date' => $duplicate->invoice_date->toDateString(),
            ])]);
        }
    }

    /**
     * The supplier's invoice a credit note answers, when it names one.
     *
     * @throws ValidationException
     */
    private function original(string $organizationId, string $type, ?string $originalId, string $supplierId): ?PurchaseInvoice
    {
        if ($type !== PurchaseInvoice::CREDIT_NOTE || blank($originalId)) {
            return null;
        }

        return PurchaseInvoice::query()->where('org_id', $organizationId)->where('supplier_id', $supplierId)
            ->where('document_type', PurchaseInvoice::INVOICE)->where('status', PurchaseInvoice::RECORDED)->find($originalId)
            ?? throw ValidationException::withMessages(['original_id' => __('Choose an invoice of this supplier\'s that is recorded and not void.')]);
    }
}
