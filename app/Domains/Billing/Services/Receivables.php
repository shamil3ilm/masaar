<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Contracts\ChargeSource;
use App\Domains\Billing\Contracts\CreditSource;
use App\Domains\Billing\DTOs\AvailableCredit;
use App\Domains\Billing\DTOs\PendingCharge;
use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Events\InvoicePaid;
use App\Domains\Billing\Events\ReceivableIssued;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Cache\Repository;
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
        private readonly Repository $cache,
        private readonly CreditSource $creditSource,
        private readonly iterable $chargeSources,
    ) {}

    /** How long one customer's invoicing may hold its claim, in seconds. */
    private const ISSUE_LOCK_SECONDS = 600;

    /**
     * Invoice a customer.
     *
     * One invoice per customer at a time: its pending charges and credits
     * are claimed from before they are read until they are marked billed,
     * so two admins invoicing it at once cannot put the same charge on two
     * tax documents. The claim is an atomic lock, not a row lock: it must
     * hold across issuing and filing, which reach the tax authority and so
     * run outside any transaction. The second is refused before anything is
     * issued; the source's own row lock on marking a charge billed stays
     * behind it.
     *
     * @throws DomainException saying why nothing was issued
     */
    public function issue(Organization $customer, ReceivableDraft $draft, ?string $actorId): Receivable
    {
        $claim = $this->cache->lock('billing.issue.'.$customer->id, self::ISSUE_LOCK_SECONDS);

        if (! $claim->get()) {
            throw new DomainException(__(':customer is being invoiced right now. Try again in a moment.', ['customer' => $customer->name]));
        }

        try {
            return $this->issueClaimed($customer, $draft, $actorId);
        } finally {
            $claim->release();
        }
    }

    /**
     * @throws DomainException saying why nothing was issued
     */
    private function issueClaimed(Organization $customer, ReceivableDraft $draft, ?string $actorId): Receivable
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

        // What else the customer owes on this invoice (charges from every
        // ChargeSource: paid customizations), and what is taken off it
        // (discounts from the CreditSource: referral rewards).
        $charges = $this->charges((string) $customer->id, $currency);
        $body = $this->draft($customer, $seller, $draft, $currency, $rate['rate'] ?? null, $today, $due);
        $body['lines'] = [...$body['lines'], ...array_map(fn (array $charge): array => $charge['line'], $charges)];
        $discounts = $draft->applyCredit ? $this->discounts((string) $customer->id, $currency, $body['lines']) : [];

        if ($discounts !== []) {
            $body['discount_amount'] = Receivable::money(array_sum(array_column($discounts, 'amount')));
        }

        $invoice = $this->platform->issue($body, $seller, ['due_date' => $due->toDateString()]);

        // Issued - and filed, which cannot be undone, so it is not inside a
        // transaction. What follows is one step: each charge billed on it,
        // each credit redeemed, the receivable written and credit taken -
        // all of it or none, so no charge is billed without the receivable
        // that collects it. Should it fail, the invoice is listed as issued
        // without a receivable (FinanceReports::unrecorded).
        try {
            $receivable = DB::transaction(function () use ($charges, $discounts, $invoice, $customer, $draft, $rate, $due, $actorId): Receivable {
                foreach ($charges as $charge) {
                    $charge['source']->markBilled($charge['pending']->sourceId, (string) $invoice->id);
                }

                foreach ($discounts as $discount) {
                    $this->creditSource->redeem($discount['credit']->id, (string) $invoice->id, $discount['amount']);
                }

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

                return $receivable;
            });
        } catch (Throwable $e) {
            report($e);

            throw new DomainException(__('Invoice :number was issued, but no receivable was recorded for it: :reason. It is listed under invoices issued with no receivable in Finance › Reports.', [
                'number' => (string) $invoice->invoice_number, 'reason' => $e->getMessage(),
            ]), 0, $e);
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
            if (Receivable::acrossTenants()->where('invoice_id', $invoice->id)->exists()) {
                return;
            }

            $customer = $payment->organization ?? throw new DomainException('The organization that paid no longer exists.');
            $paidOn = CarbonImmutable::parse($payment->paid_at->toDateString());
            // The invoice's own rate, so its VAT in the tax currency is kept
            // as the invoice states it (BT-111).
            $rate = $invoice->exchange_rate === null ? null : (string) $invoice->exchange_rate;
            $fx = $rate === null || $payment->exchange_rate === null ? null
                : Receivable::money((float) $payment->total * ((float) $payment->exchange_rate - (float) $rate));

            $receivable = $this->record($customer, $invoice, [
                'license_id' => $payment->license_id,
                'description' => (string) ($invoice->lines->first()->description ?? __('Plan')),
                'period_start' => $payment->period_start?->toDateString(),
                'period_end' => $payment->period_end?->toDateString(),
                'exchange_rate' => $rate,
                'rate_source' => $rate === null ? null : FinanceSettings::RATE_TABLE,
                'rate_date' => $rate === null ? null : $paidOn->toDateString(),
                'due_date' => $invoice->issue_date->toDateString(),
                'status' => ReceivableStatus::Open,
            ], $payment->recorded_by === null ? null : (string) $payment->recorded_by);

            $this->settle($receivable, (string) $payment->total, $payment, null, $fx, $paidOn, $payment->recorded_by === null ? null : (string) $payment->recorded_by);
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
            $locked = Receivable::acrossTenants()->lockForUpdate()->findOrFail($receivable->id);
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
            $allocation->save();

            $locked->amount_paid = Receivable::money((float) $locked->amount_paid + (float) $amount);
            $wasPaid = $locked->status === ReceivableStatus::Paid;
            $this->restate($locked, $on);
            $locked->save();

            return [$allocation, ! $wasPaid && $locked->status === ReceivableStatus::Paid ? $locked : null];
        });

        $receivable->refresh();

        // Paid in full: what waits on a customer paying - a referral that
        // qualifies on the first paid invoice - moves on, after the commit.
        // The payment that settled it, or none when a credit did.
        if ($paid !== null) {
            InvoicePaid::dispatch((string) $paid->org_id, (string) ($payment?->id ?? ''));
        }

        return $allocation;
    }

    /**
     * Take back money set against an invoice in error: an allocation is
     * never changed, so another for the same amount below zero answers it,
     * and the invoice owes that much again - its status and the day it was
     * paid move back with it. Once per allocation.
     *
     * @throws DomainException when it was already taken back, or is itself a reversal
     */
    public function unsettle(PaymentAllocation $allocation, ?string $actorId): PaymentAllocation
    {
        return DB::transaction(function () use ($allocation, $actorId): PaymentAllocation {
            $locked = Receivable::acrossTenants()->lockForUpdate()->findOrFail($allocation->receivable_id);

            if ((float) $allocation->amount <= 0
                || PaymentAllocation::acrossTenants()->where('reverses_id', $allocation->id)->exists()) {
                throw new DomainException(__('That payment was already taken back from invoice :number.', ['number' => $this->number($locked)]));
            }

            $reversal = new PaymentAllocation([
                'org_id' => $locked->org_id,
                'receivable_id' => $locked->id,
                'payment_id' => $allocation->payment_id,
                'credit_id' => $allocation->credit_id,
                'amount' => Receivable::money(-(float) $allocation->amount),
                'fx_difference' => $allocation->fx_difference === null ? null : Receivable::money(-(float) $allocation->fx_difference),
                'allocated_on' => CarbonImmutable::now()->toDateString(),
                'reverses_id' => $allocation->id,
            ]);
            $reversal->forceFill(['created_by' => $actorId]);
            $reversal->save();

            $locked->amount_paid = Receivable::money((float) $locked->amount_paid - (float) $allocation->amount);
            $locked->status = $locked->statusOn(CarbonImmutable::now());

            if ($locked->status !== ReceivableStatus::Paid) {
                $locked->paid_on = null;
            }

            $locked->save();

            $this->audit->logFor((string) $locked->org_id, 'finance.allocation.reversed', $reversal, [
                'invoice_number' => $this->number($locked), 'amount' => (string) $allocation->amount, 'reverses' => (string) $allocation->id,
            ]);

            return $reversal;
        });
    }

    /**
     * A credit note against the invoice: it owes that much less. What was
     * already paid beyond what it now owes is handed back to the caller to
     * keep to the customer's credit or pay back.
     *
     * @param  string  $gross  the note's total, with its VAT
     * @return string what was paid beyond what is now owed, as "50.00"
     *
     * @throws DomainException when the note is more than is left to credit
     */
    public function credit(Receivable $receivable, string $gross): string
    {
        $excess = DB::transaction(function () use ($receivable, $gross): float {
            $locked = Receivable::acrossTenants()->lockForUpdate()->findOrFail($receivable->id);
            $creditable = (float) $locked->total - (float) $locked->amount_credited - (float) $locked->amount_written_off;

            if ((float) $gross <= 0 || round((float) $gross - $creditable, 2) > 0) {
                throw new DomainException(__('A credit note is for more than zero and no more than the :amount left to credit on invoice :number.', [
                    'amount' => Receivable::money(max(0.0, $creditable)), 'number' => $this->number($locked),
                ]));
            }

            $locked->amount_credited = Receivable::money((float) $locked->amount_credited + (float) $gross);
            $this->restate($locked, CarbonImmutable::now());
            $locked->save();

            return max(0.0, (float) $locked->amount_paid - ((float) $locked->total - (float) $locked->amount_credited - (float) $locked->amount_written_off));
        });

        $receivable->refresh();

        return Receivable::money($excess);
    }

    /**
     * Give up on what is left of an invoice: written off, with why and who.
     * Held for approval by finance.write_off (FinanceActions).
     *
     * @throws DomainException when nothing is left to write off
     */
    public function writeOff(Receivable $receivable, string $reason, ?string $actorId): Receivable
    {
        $written = DB::transaction(function () use ($receivable, $reason, $actorId): Receivable {
            $locked = Receivable::acrossTenants()->lockForUpdate()->findOrFail($receivable->id);
            $left = $locked->balance();

            if ((float) $left <= 0) {
                throw new DomainException(__('Nothing is left to write off on invoice :number.', ['number' => $this->number($locked)]));
            }

            $locked->amount_written_off = Receivable::money((float) $locked->amount_written_off + (float) $left);
            $locked->forceFill([
                'written_off_at' => now(),
                'written_off_by' => $actorId,
                'write_off_reason' => mb_substr($reason, 0, 500),
            ]);
            $this->restate($locked, CarbonImmutable::now());
            $locked->save();

            $this->audit->logFor((string) $locked->org_id, 'finance.receivable.written_off', $locked, [
                'invoice_number' => $this->number($locked), 'amount' => $left, 'currency' => $locked->currency, 'reason' => $reason,
            ]);

            return $locked;
        });

        $receivable->refresh();

        return $written;
    }

    /**
     * The customer's oldest invoices in a currency that still owe something,
     * the one due first first: what a payment with no allocation settles.
     *
     * @return Collection<int, Receivable>
     */
    public function outstanding(string $organizationId, string $currency): Collection
    {
        return Receivable::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('currency', strtoupper($currency))
            ->whereIn('status', ReceivableStatus::outstandingValues())
            ->orderBy('due_date')
            ->orderBy('issue_date')
            ->orderBy('created_at')
            ->get();
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
        Receivable::acrossTenants()
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

        return $moved;
    }

    /**
     * @return Collection<int, Receivable>
     */
    public function forCustomer(string $organizationId): Collection
    {
        return $this->query()
            ->where('org_id', $organizationId)
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->get();
    }

    public function find(string $organizationId, string $receivableId): ?Receivable
    {
        return $this->query()->where('org_id', $organizationId)->find($receivableId);
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
     * What settled an invoice and what was given back on it, oldest first.
     *
     * @return array{allocations: \Illuminate\Support\Collection<int, PaymentAllocation>, refunds: \Illuminate\Support\Collection<int, Refund>}
     */
    public function history(Receivable $receivable): array
    {
        return [
            'allocations' => PaymentAllocation::acrossTenants()
                ->with(['payment' => fn ($query) => $query->withoutGlobalScopes(), 'credit' => fn ($query) => $query->withoutGlobalScopes()])
                ->where('receivable_id', $receivable->id)
                ->orderBy('allocated_on')
                ->orderBy('created_at')
                ->get(),
            'refunds' => Refund::acrossTenants()->where('receivable_id', $receivable->id)->orderBy('created_at')->get(),
        ];
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

        $numbers = Invoice::acrossTenants()->whereKey(array_keys($byInvoice))->pluck('invoice_number', 'id');
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
        return (string) Invoice::acrossTenants()->whereKey($receivable->invoice_id)->value('invoice_number');
    }

    /**
     * The customer's unbilled charges in the currency from every charge
     * source, each as an invoice line in both languages.
     *
     * @return list<array{source: ChargeSource, pending: PendingCharge, line: array<string, string>}>
     */
    private function charges(string $organizationId, string $currency): array
    {
        $charges = [];

        foreach ($this->chargeSources as $source) {
            foreach ($source->unbilled($organizationId, $currency) as $pending) {
                $charges[] = ['source' => $source, 'pending' => $pending, 'line' => [
                    'description' => $pending->description.($pending->reference === '' ? '' : ' ('.$pending->reference.')'),
                    'description_ar' => 'خدمة متفق عليها: '.$pending->description.($pending->reference === '' ? '' : ' ('.$pending->reference.')'),
                    'quantity' => '1',
                    'unit_code' => 'C62',
                    'unit_price' => Receivable::money((float) $pending->amount),
                    'tax_rate' => (string) config('billing.vat_rate', 15),
                ]];
            }
        }

        return $charges;
    }

    /**
     * What the customer's credits take off the invoice, before VAT, as one
     * document-level allowance (BT-92): a percentage of its lines, and a sum
     * in its currency, oldest first, never past what the lines come to. A
     * price reduction, so the VAT is on what is left.
     *
     * @param  list<array<string, string>>  $lines
     * @return list<array{credit: AvailableCredit, amount: string}>
     */
    private function discounts(string $organizationId, string $currency, array $lines): array
    {
        $net = array_sum(array_map(fn (array $line): float => (float) $line['unit_price'] * (float) $line['quantity'], $lines));
        $left = $net;
        $discounts = [];

        foreach ($this->creditSource->available($organizationId, $currency) as $credit) {
            $take = $credit->kind === AvailableCredit::PERCENT
                ? round($net * (float) $credit->percent / 100, 2)
                : ($credit->currency === $currency ? (float) $credit->amount : 0.0);
            $take = min($take, $left);

            if ($take <= 0) {
                continue;
            }

            $discounts[] = ['credit' => $credit, 'amount' => Receivable::money($take)];
            $left -= $take;
        }

        return $discounts;
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

        $receivable->save();

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
