<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Events\ReceivableIssued;
use App\Domains\Billing\Events\ReceivablePaid;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The platform invoicing its customers, and what each still owes.
 *
 * An invoice is in the customer's currency (Finance › Settings, or the
 * customer's own), for the amount agreed with it - there is no price list -
 * and issued from the billing organization through its country's module
 * (PlatformInvoices), Arabic and all. Where that currency is not the tax
 * currency the invoice records its exchange rate, and the module states its
 * VAT in the tax currency as well: ZATCA's XML Implementation Standard
 * requires the tax currency code (BT-6, BR-KSA-68) to be SAR
 * (BR-KSA-EN16931-02) and, with it, the invoice total VAT in that currency
 * (BT-111, BR-53), whatever the invoice currency (§9.1: "Tax totals must be
 * in SAR"). The receivable keeps the rate, where it came from, and the VAT
 * at it.
 *
 * Nothing is invoiced inside a customer's free time (FreePeriod); a credit
 * to the customer's name is taken by its next invoice in that currency.
 */
class Receivables
{
    /** UNTDID 4461 30, credit transfer: how an invoice to a business is paid. */
    private const PAYMENT_MEANS = '30';

    public function __construct(
        private readonly PlatformInvoices $platform,
        private readonly FinanceSettings $settings,
        private readonly ExchangeRates $rates,
        private readonly FreePeriod $freePeriod,
        private readonly Credits $credits,
        private readonly AuditService $audit,
    ) {}

    /**
     * Invoice a customer.
     *
     * @throws DomainException saying why nothing was issued
     */
    public function issue(Organization $customer, ReceivableDraft $draft, ?string $actorId): Receivable
    {
        $seller = $this->platform->readySeller();
        $today = CarbonImmutable::now()->startOfDay();
        $currency = $this->settings->currencyFor((string) $customer->id);

        if (! $this->settings->currencyEnabled($currency)) {
            throw new DomainException(__(':currency is switched off in Finance › Settings, so :customer cannot be invoiced in it.', ['currency' => $currency, 'customer' => $customer->name]));
        }

        if (! is_numeric($draft->amount) || (float) $draft->amount <= 0 || preg_match('/^\d+(\.\d{1,2})?$/', $draft->amount) !== 1) {
            throw new DomainException(__('The amount is the agreed price before VAT, more than zero, with at most two decimals.'));
        }

        $this->assertAfterFreePeriod($customer, $today, $draft->periodStart);

        $taxCurrency = $this->platform->taxCurrency();
        $rate = $currency === $taxCurrency ? null : $this->rate($currency, $taxCurrency, $draft->exchangeRate, $today);
        $due = $today->addDays($this->settings->paymentTermsDays((string) $customer->id));

        $invoice = $this->platform->issue(
            $this->draft($customer, $seller, $draft, $currency, $rate['rate'] ?? null, $today, $due),
            $seller,
            ['due_date' => $due->toDateString()],
        );

        $receivable = $this->record($customer, $invoice, [
            'license_id' => $draft->licenseId,
            'description' => $draft->description,
            'period_start' => $draft->periodStart?->toDateString(),
            'period_end' => $draft->periodEnd?->toDateString(),
            'exchange_rate' => $rate['rate'] ?? null,
            'rate_source' => $rate['source'] ?? null,
            'rate_date' => $rate['date'] ?? null,
            'due_date' => $due->toDateString(),
            'status' => ReceivableStatus::Open,
        ], $actorId);

        if ($draft->applyCredit) {
            $this->applyCredits($receivable, $actorId);
        }

        ReceivableIssued::dispatch($receivable);

        return $receivable->fresh() ?? $receivable;
    }

    /**
     * A plan payment that has its invoice now: the invoice on the customer's
     * account, settled by that payment. Once per invoice, whichever path
     * issued it, and never in the way of the payment itself - a failure here
     * is reported and the payment stands.
     */
    public function settledByPlanPayment(Payment $payment, Invoice $invoice): void
    {
        try {
            if (Receivable::withoutTenantScope(fn () => Receivable::query()->where('invoice_id', $invoice->id)->exists())) {
                return;
            }

            $customer = $payment->organization ?? throw new DomainException('The organization that paid no longer exists.');
            $paidOn = CarbonImmutable::parse($payment->paid_at->toDateString());

            $receivable = $this->record($customer, $invoice, [
                'license_id' => $payment->license_id,
                'description' => (string) ($invoice->lines->first()->description ?? __('Plan')),
                'period_start' => $payment->period_start?->toDateString(),
                'period_end' => $payment->period_end?->toDateString(),
                'exchange_rate' => null,
                'rate_source' => null,
                'rate_date' => null,
                'due_date' => $invoice->issue_date->toDateString(),
                'status' => ReceivableStatus::Open,
            ], $payment->recorded_by === null ? null : (string) $payment->recorded_by);

            $this->settle($receivable, (string) $payment->total, $payment, null, null, $paidOn, $payment->recorded_by === null ? null : (string) $payment->recorded_by);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Set money against an invoice: part of a payment, or of a credit, in
     * the invoice's currency and no more than it still owes. The invoice's
     * sums and status move with it, under a lock so two payments cannot
     * both take its last riyal.
     *
     * @throws DomainException when it is more than is owed, or not positive
     */
    public function settle(
        Receivable $receivable,
        string $amount,
        ?Payment $payment,
        ?BillingCredit $credit,
        ?string $fxDifference,
        CarbonImmutable $on,
        ?string $actorId,
    ): PaymentAllocation {
        [$allocation, $paid] = DB::transaction(function () use ($receivable, $amount, $payment, $credit, $fxDifference, $on, $actorId): array {
            $locked = Receivable::withoutTenantScope(fn () => Receivable::query()->lockForUpdate()->findOrFail($receivable->id));
            $amount = Receivable::money((float) $amount);

            if ((float) $amount <= 0) {
                throw new DomainException(__('An amount set against an invoice is more than zero.'));
            }

            if (bccomp($amount, $locked->balance(), 2) > 0) {
                throw new DomainException(__(':amount is more than the :balance still owed on invoice :number.', [
                    'amount' => $amount, 'balance' => $locked->balance(), 'number' => $this->number($locked),
                ]));
            }

            $allocation = new PaymentAllocation([
                'org_id' => $locked->org_id,
                'receivable_id' => $locked->id,
                'payment_id' => $payment?->id,
                'credit_id' => $credit?->id,
                'amount' => $amount,
                'fx_difference' => $fxDifference,
                'allocated_on' => $on->toDateString(),
            ]);
            $allocation->forceFill(['created_by' => $actorId]);
            PaymentAllocation::withoutTenantScope(fn () => $allocation->save());

            $locked->amount_paid = Receivable::money((float) $locked->amount_paid + (float) $amount);
            $wasPaid = $locked->status === ReceivableStatus::Paid;
            $this->restate($locked, $on);
            $locked->save();

            return [$allocation, ! $wasPaid && $locked->status === ReceivableStatus::Paid ? $locked : null];
        });

        $receivable->refresh();

        if ($paid !== null) {
            ReceivablePaid::dispatch($paid);
        }

        return $allocation;
    }

    /**
     * The customer's credit in the invoice's currency, taken by the invoice
     * until one of them runs out: the credit that lapses first, first.
     *
     * @return string what was taken, as "50.00"
     */
    public function applyCredits(Receivable $receivable, ?string $actorId): string
    {
        $taken = 0.0;

        foreach ($this->credits->available((string) $receivable->org_id, (string) $receivable->currency) as $row) {
            $owed = (float) $receivable->fresh()?->balance();

            if ($owed <= 0) {
                break;
            }

            $amount = min($owed, (float) $row['left']);
            $this->settle($receivable, Receivable::money($amount), null, $row['credit'], null, CarbonImmutable::now(), $actorId);
            $taken += $amount;
        }

        return Receivable::money($taken);
    }

    /**
     * Move every outstanding invoice to the status its due date now gives
     * it: open or part paid becomes overdue the day after it falls due.
     *
     * @return int how many moved
     */
    public function refresh(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now();
        $moved = 0;

        // A closure, not an arrow function: the count is shared by reference.
        Receivable::withoutTenantScope(function () use ($today, &$moved): void {
            Receivable::query()
                ->whereIn('status', ReceivableStatus::outstandingValues())
                ->chunkById(200, function ($receivables) use ($today, &$moved): void {
                    foreach ($receivables as $receivable) {
                        $status = $receivable->statusOn($today);

                        if ($status !== $receivable->status) {
                            $receivable->forceFill(['status' => $status])->save();
                            $moved++;
                        }
                    }
                });
        });

        return $moved;
    }

    /**
     * @return Collection<int, Receivable>
     */
    public function forCustomer(string $organizationId): Collection
    {
        return Receivable::withoutTenantScope(fn () => $this->query()
            ->where('org_id', $organizationId)
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->get());
    }

    public function find(string $organizationId, string $receivableId): ?Receivable
    {
        return Receivable::withoutTenantScope(fn () => $this->query()->where('org_id', $organizationId)->find($receivableId));
    }

    /**
     * Every receivable, across customers, for the console's lists.
     *
     * @return Builder<Receivable>
     */
    public function query(): Builder
    {
        return Receivable::query()->withoutGlobalScopes()->with('organization');
    }

    /**
     * The tax invoices' numbers for a list of receivables, by receivable id,
     * in one query.
     *
     * @param  iterable<Receivable>  $receivables
     * @return array<string, string>
     */
    public function numbers(iterable $receivables): array
    {
        $byInvoice = [];

        foreach ($receivables as $receivable) {
            $byInvoice[(string) $receivable->invoice_id] = (string) $receivable->id;
        }

        $numbers = Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey(array_keys($byInvoice))->pluck('invoice_number', 'id'));
        $result = [];

        foreach ($byInvoice as $invoiceId => $receivableId) {
            $result[$receivableId] = (string) ($numbers[$invoiceId] ?? '');
        }

        return $result;
    }

    /**
     * The tax invoice's number, for messages and lists.
     */
    public function number(Receivable $receivable): string
    {
        return (string) Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($receivable->invoice_id)->value('invoice_number'));
    }

    /**
     * @throws DomainException while the customer's free time runs
     */
    private function assertAfterFreePeriod(Organization $customer, CarbonImmutable $today, ?CarbonImmutable $periodStart): void
    {
        $ends = $this->freePeriod->endsFor($customer);

        if ($ends === null) {
            return;
        }

        if ($today->lt($ends->startOfDay()) || ($periodStart !== null && $periodStart->lt($ends->startOfDay()))) {
            throw new DomainException(__(':customer is in its free period until :date. Nothing is invoiced for it; the first invoice is for the time after.', [
                'customer' => $customer->name, 'date' => $ends->toDateString(),
            ]));
        }
    }

    /**
     * The rate to the tax currency: the one typed for the invoice, or the
     * table's for the day where that is the source.
     *
     * @return array{rate: string, source: string, date: string}
     *
     * @throws DomainException when there is none
     */
    private function rate(string $currency, string $taxCurrency, ?string $typed, CarbonImmutable $today): array
    {
        if ($typed !== null && $typed !== '') {
            if (! is_numeric($typed) || (float) $typed <= 0) {
                throw new DomainException(__('The exchange rate is a positive number.'));
            }

            return ['rate' => number_format((float) $typed, 6, '.', ''), 'source' => FinanceSettings::RATE_MANUAL, 'date' => $today->toDateString()];
        }

        $row = $this->settings->rateSource() === FinanceSettings::RATE_TABLE ? $this->rates->on($currency, $today) : null;

        if ($row === null) {
            throw new DomainException(__('An invoice in :currency states its VAT in :tax too, at its exchange rate. Enter the rate, or add the day\'s rate to the rate table.', [
                'currency' => $currency, 'tax' => $taxCurrency,
            ]));
        }

        return ['rate' => $row['rate'], 'source' => FinanceSettings::RATE_TABLE, 'date' => $row['date']];
    }

    /**
     * The invoice as InvoiceDrafter takes it: a standard tax invoice to a
     * company, every text in both languages, the Arabic first.
     *
     * @return array<string, mixed>
     */
    private function draft(Organization $customer, Organization $seller, ReceivableDraft $draft, string $currency, ?string $rate, CarbonImmutable $today, CarbonImmutable $due): array
    {
        $days = (int) $today->diffInDays($due);
        $accounts = collect($this->settings->bankAccounts($currency))
            ->map(fn (array $account): string => $account['bank_name'].' '.$account['iban'])
            ->implode('; ');

        return [
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => $today->toDateString(),
            // KSA-5 and KSA-24: the period a subscription covers.
            ...($draft->periodStart === null ? [] : [
                'supply_date' => $draft->periodStart->toDateString(),
                'supply_end_date' => ($draft->periodEnd ?? $draft->periodStart)->toDateString(),
            ]),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'prices_include_vat' => false,
            'payment_means_code' => self::PAYMENT_MEANS,
            // KSA-22, the payment terms, in both languages.
            'payment_terms' => 'تستحق في '.$due->toDateString().' ('.$days.' يومًا). / Due by '.$due->toDateString().' ('.$days.' days).',
            'buyer_name' => $customer->name,
            'buyer_name_ar' => $customer->name_ar,
            ...$this->platform->buyerIdentity($seller, $customer),
            'buyer_address' => [
                'street' => $customer->street,
                'building_number' => $customer->building_number,
                'district' => $customer->district,
                'city' => $customer->city,
                'postal_code' => $customer->postal_code,
                'country_code' => $customer->country ?: $seller->country,
            ],
            ...($accounts === '' ? [] : ['notes' => 'الدفع بالتحويل إلى: '.$accounts.' / Pay by transfer to: '.$accounts]),
            'lines' => [[
                'description' => $draft->description,
                'description_ar' => $draft->descriptionAr,
                'quantity' => '1',
                'unit_code' => 'C62',
                'unit_price' => $draft->amount,
                'tax_rate' => (string) config('billing.vat_rate', 15),
            ]],
        ];
    }

    /**
     * The receivable for an issued invoice, its sums the invoice's own.
     *
     * @param  array<string, mixed>  $fields
     */
    private function record(Organization $customer, Invoice $invoice, array $fields, ?string $actorId): Receivable
    {
        $rate = $fields['exchange_rate'] === null ? null : (float) $fields['exchange_rate'];

        $receivable = new Receivable([
            ...$fields,
            'org_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'currency' => (string) $invoice->currency,
            'subtotal' => (string) $invoice->subtotal,
            'vat_amount' => (string) $invoice->tax_amount,
            'total' => (string) $invoice->total,
            'tax_currency' => $this->platform->taxCurrency(),
            // As the document states BT-111: rounded once, to two places.
            'vat_amount_tax' => Receivable::money($rate === null ? (float) $invoice->tax_amount : (float) $invoice->tax_amount * $rate),
            'issue_date' => $invoice->issue_date->toDateString(),
        ]);

        if ($actorId !== null) {
            $receivable->forceFill(['created_by' => $actorId]);
        }

        Receivable::withoutTenantScope(fn () => $receivable->save());

        $this->audit->logFor((string) $customer->id, 'finance.receivable.issued', $receivable, [
            'invoice_number' => $invoice->invoice_number,
            'currency' => $receivable->currency,
            'total' => (string) $receivable->total,
            'exchange_rate' => $receivable->exchange_rate === null ? null : (string) $receivable->exchange_rate,
            'vat_amount_tax' => (string) $receivable->vat_amount_tax,
        ]);

        return $receivable;
    }

    /** Its status, and when it was paid off, from its sums on a day. */
    private function restate(Receivable $receivable, CarbonImmutable $on): void
    {
        $receivable->status = $receivable->statusOn(CarbonImmutable::now());

        if ($receivable->status === ReceivableStatus::Paid && $receivable->paid_on === null) {
            $receivable->paid_on = $on->toDateString();
        }
    }
}
