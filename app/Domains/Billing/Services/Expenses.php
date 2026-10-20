<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\DTOs\ExpenseEntry;
use App\Domains\Billing\Enums\ExpenseStatus;
use App\Domains\Billing\Enums\VatTreatment;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Supplier;
use App\Domains\Billing\Settings\FinanceSettings;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The platform's own costs (Admin › Finance › Expenses): entered, kept as
 * drafts until their bill comes, changed while nothing is paid on them, and
 * void when entered by mistake - never deleted.
 *
 * Every amount is kept in the currency billed and in the tax currency at
 * the day's rate: the rate typed, or the rate table's on the expense's date
 * (VAT Implementing Regulations Art 61). The same supplier invoice number
 * from the same supplier is refused while the first is not void.
 */
class Expenses
{
    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly ExchangeRates $rates,
        private readonly PlatformInvoices $platform,
        private readonly ExpenseReceipts $receipts,
        private readonly AuditService $audit,
    ) {}

    public function find(string $id): ?Expense
    {
        return Expense::query()->with('supplier')->find($id);
    }

    /**
     * Enter an expense: recorded, or a draft until its bill comes.
     *
     * @throws ValidationException|DomainException
     */
    public function record(ExpenseEntry $entry, ?UploadedFile $receipt, bool $draft, ?string $actorId): Expense
    {
        $supplier = $this->supplier($entry->supplierId, null);
        $values = $this->figures($entry, $supplier, null);

        return DB::transaction(function () use ($values, $receipt, $draft, $actorId): Expense {
            $expense = new Expense($values);
            $expense->status = $draft ? ExpenseStatus::Draft : ExpenseStatus::Unpaid;
            $expense->save();
            $this->attach($expense, $receipt);

            $this->audit->logChange($draft ? 'finance.expense.drafted' : 'finance.expense.recorded', 'Expense', (string) $expense->id,
                null, $this->snapshot($expense), [], $actorId);

            return $expense;
        });
    }

    /**
     * Change one while nothing is paid on it. A new receipt replaces the
     * old one.
     *
     * @throws ValidationException|DomainException
     */
    public function update(Expense $expense, ExpenseEntry $entry, ?UploadedFile $receipt, ?string $actorId): Expense
    {
        if (! $expense->editable()) {
            throw new DomainException(__('This expense is :status, so it can no longer be changed.', ['status' => mb_strtolower($expense->status->label())]));
        }

        $supplier = $this->supplier($entry->supplierId, (string) $expense->supplier_id);
        $values = $this->figures($entry, $supplier, $expense);
        $before = $this->snapshot($expense);

        return DB::transaction(function () use ($expense, $values, $receipt, $before, $actorId): Expense {
            $expense->fill($values)->save();
            $this->attach($expense, $receipt);
            $this->audit->logChange('finance.expense.updated', 'Expense', (string) $expense->id, $before, $this->snapshot($expense), [], $actorId);

            return $expense;
        });
    }

    /**
     * A draft whose bill came: recorded as it stands, or as changed first.
     *
     * @throws DomainException
     */
    public function confirm(Expense $expense, ?string $actorId): Expense
    {
        if ($expense->status !== ExpenseStatus::Draft) {
            throw new DomainException(__('Only a draft is recorded.'));
        }

        $this->duplicateCheck((string) $expense->supplier_id, $expense->supplier_invoice_number, (string) $expense->id);
        $expense->status = ExpenseStatus::Unpaid;
        $expense->save();
        $this->audit->logChange('finance.expense.recorded', 'Expense', (string) $expense->id, ['status' => 'draft'], ['status' => 'unpaid'], [], $actorId);

        return $expense;
    }

    /**
     * Entered by mistake: kept, and counted nowhere.
     *
     * @throws DomainException
     */
    public function void(Expense $expense, string $reason, ?string $actorId): Expense
    {
        if (! $expense->editable()) {
            throw new DomainException(__('This expense is :status, so it cannot be void.', ['status' => mb_strtolower($expense->status->label())]));
        }

        $before = $expense->status->value;
        $expense->status = ExpenseStatus::Void;
        $expense->forceFill(['voided_at' => now(), 'voided_by' => $actorId, 'void_reason' => $reason])->save();
        $this->audit->logChange('finance.expense.voided', 'Expense', (string) $expense->id, ['status' => $before], ['status' => 'void', 'reason' => $reason], [], $actorId);

        return $expense;
    }

    /** Whether any expense names the supplier, void ones too. */
    public function names(Supplier $supplier): bool
    {
        return Expense::query()->where('supplier_id', $supplier->id)->exists();
    }

    /**
     * Another expense, not void, with this supplier's invoice number.
     */
    public function duplicateOf(string $supplierId, ?string $number, ?string $exceptId = null): ?Expense
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        return Expense::query()
            ->where('supplier_id', $supplierId)
            ->where('supplier_invoice_number', $number)
            ->where('status', '!=', ExpenseStatus::Void->value)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function duplicateCheck(string $supplierId, ?string $number, ?string $exceptId): void
    {
        $duplicate = $this->duplicateOf($supplierId, $number, $exceptId);

        if ($duplicate !== null) {
            throw ValidationException::withMessages(['supplier_invoice_number' => __('Invoice :number from this supplier is already entered, on :date. Void that one first if it was a mistake.', [
                'number' => trim((string) $number), 'date' => $duplicate->expense_date->toDateString(),
            ])]);
        }
    }

    /**
     * The supplier an expense names: one in the lists, or the one it
     * already named.
     *
     * @throws ValidationException
     */
    private function supplier(string $id, ?string $current): Supplier
    {
        $supplier = Supplier::withTrashed()->find($id);

        if ($supplier === null || ($id !== $current && ($supplier->trashed() || $supplier->archived()))) {
            throw ValidationException::withMessages(['supplier_id' => __('Choose a supplier from the list.')]);
        }

        return $supplier;
    }

    /**
     * What is saved: the entry checked, its due date, and its amounts in the
     * tax currency.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function figures(ExpenseEntry $entry, Supplier $supplier, ?Expense $current): array
    {
        $currency = strtoupper($entry->currency);
        $net = (float) $entry->net;
        $vat = (float) $entry->vat;

        if (! in_array($currency, FinanceSettings::expenseCurrencies(), true)) {
            throw ValidationException::withMessages(['currency' => __('Expenses are not kept in that currency.')]);
        }

        if (! array_key_exists($entry->category, $this->settings->expenseCategories()) && $entry->category !== $current?->category) {
            throw ValidationException::withMessages(['category' => __('Choose a category from the list.')]);
        }

        if ($entry->costCentre !== null && ! array_key_exists($entry->costCentre, $this->settings->costCentres()) && $entry->costCentre !== $current?->cost_centre) {
            throw ValidationException::withMessages(['cost_centre' => __('Choose a cost centre from the list.')]);
        }

        if ($net <= 0) {
            throw ValidationException::withMessages(['net' => __('The amount before VAT is more than zero.')]);
        }

        if ($vat < 0 || (! $entry->vatTreatment->charged() && $vat > 0)) {
            throw ValidationException::withMessages(['vat' => __('VAT is entered only when the supplier charged it: :treatment has none.', ['treatment' => $entry->vatTreatment->label()])]);
        }

        if ($current === null || $current->status !== ExpenseStatus::Draft) {
            $this->duplicateCheck((string) $supplier->id, $entry->supplierInvoiceNumber, $current?->id);
        }

        [$rate, $source, $rateDate] = $this->rate($currency, $entry);
        $factor = $rate === null ? 1.0 : (float) $rate;
        $vatTax = round($vat * $factor, 2);

        return [
            'supplier_id' => (string) $supplier->id,
            'expense_date' => $entry->expenseDate->toDateString(),
            'due_date' => ($entry->dueDate ?? $entry->expenseDate->addDays($supplier->payment_terms_days))->toDateString(),
            'supplier_invoice_number' => $this->optional($entry->supplierInvoiceNumber),
            'description' => trim($entry->description),
            'category' => $entry->category,
            'cost_centre' => $entry->costCentre,
            'currency' => $currency,
            'net' => Receivable::money($net),
            'vat' => Receivable::money($vat),
            'gross' => Receivable::money($net + $vat),
            'tax_currency' => $this->platform->taxCurrency(),
            'exchange_rate' => $rate,
            'rate_source' => $source,
            'rate_date' => $rateDate,
            'net_tax' => Receivable::money(round($net * $factor, 2)),
            'vat_tax' => Receivable::money($vatTax),
            'gross_tax' => Receivable::money(round($net * $factor, 2) + $vatTax),
            'vat_treatment' => $entry->vatTreatment,
            'recoverable_vat_tax' => Receivable::money($entry->vatTreatment === VatTreatment::Recoverable ? $vatTax : 0.0),
            'notes' => $this->optional($entry->notes),
        ];
    }

    /**
     * The rate to the tax currency: none for the tax currency itself, the
     * one typed, or the rate table's on the expense's date.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     *
     * @throws ValidationException
     */
    private function rate(string $currency, ExpenseEntry $entry): array
    {
        if ($currency === $this->platform->taxCurrency()) {
            return [null, null, null];
        }

        if ($entry->exchangeRate !== null && $entry->exchangeRate !== '') {
            if (! is_numeric($entry->exchangeRate) || (float) $entry->exchangeRate <= 0) {
                throw ValidationException::withMessages(['exchange_rate' => __('The rate is a positive number.')]);
            }

            return [number_format((float) $entry->exchangeRate, 6, '.', ''), FinanceSettings::RATE_MANUAL, $entry->expenseDate->toDateString()];
        }

        $table = $this->rates->on($currency, $entry->expenseDate)
            ?? throw ValidationException::withMessages(['exchange_rate' => __('The rate table has no :currency rate on or before :date. Enter the rate, or add it to the table in Finance › Settings.', [
                'currency' => $currency, 'date' => $entry->expenseDate->toDateString(),
            ])]);

        return [$table['rate'], FinanceSettings::RATE_TABLE, $table['date']];
    }

    /**
     * @throws DomainException
     */
    private function attach(Expense $expense, ?UploadedFile $receipt): void
    {
        if ($receipt === null) {
            return;
        }

        $expense->forceFill($this->receipts->store($receipt, (string) $expense->id))->save();
    }

    /** @return array<string, mixed> */
    private function snapshot(Expense $expense): array
    {
        return [
            'status' => $expense->status->value,
            'supplier_id' => (string) $expense->supplier_id,
            'expense_date' => $expense->expense_date->toDateString(),
            'supplier_invoice_number' => $expense->supplier_invoice_number,
            'currency' => $expense->currency,
            'net' => (string) $expense->net,
            'vat' => (string) $expense->vat,
            'vat_treatment' => $expense->vat_treatment->value,
            'exchange_rate' => $expense->exchange_rate === null ? null : (string) $expense->exchange_rate,
            'category' => $expense->category,
            'cost_centre' => $expense->cost_centre,
        ];
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
