<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Models\Voucher;
use App\Domains\Books\Models\VoucherRedemption;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Vouchers with a face value: sold, then used to pay for what the holder
 * buys.
 *
 * Selling one is not a supply in either country's law (SpecialSupplies::
 * vouchers): no tax invoice is made and no VAT is due on the money taken,
 * which is held for the holder until the voucher is used or expires. Using
 * it pays an issued invoice - the supply, taxed there - and is recorded as
 * that invoice's customer payment, by voucher, naming its code.
 *
 * Sold at its face value only. Consideration above it is a supply of
 * services for the excess (KSA IR Art 19(2); UAE Decree-Law Art 7(1), Art
 * 40), to be invoiced on its own; below it, the UAE treats the difference as
 * a discount on the supply it pays for (Executive Regulation Art 28(5)),
 * which is not offered.
 */
class Vouchers
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly CustomerPayments $payments,
    ) {}

    /** @return array<string, list<mixed>> */
    public function saleRules(Organization $organization): array
    {
        return [
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('vouchers', 'code')->where('org_id', $organization->id)],
            'face_value' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'sold_for' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'sold_on' => ['required', 'date'],
            'expires_on' => ['nullable', 'date', 'after:sold_on'],
            'customer_id' => ['nullable', 'string', 'uuid', Rule::exists('customers', 'id')->where('org_id', $organization->id)->whereNull('deleted_at')],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Voucher])],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Record a voucher sold, as validated by saleRules(). Coded V- and ten
     * letters and digits when no code is given.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws DomainException
     */
    public function sell(Organization $organization, array $validated, ?string $branchId = null): Voucher
    {
        $rules = $this->jurisdictions->forRules($organization)->specialSupplies()->vouchers();
        $face = number_format((float) $validated['face_value'], 2, '.', '');

        if ($rules->saleIsSupply) {
            throw new DomainException(__('Selling a voucher is taxed in your country, which is not offered yet.'));
        }

        if (isset($validated['sold_for']) && bccomp(number_format((float) $validated['sold_for'], 2, '.', ''), $face, 2) > 0) {
            throw new DomainException(__('A price above the face value is a supply of services for the difference (:source): sell the voucher at its face value and invoice any fee on its own.', ['source' => $rules->source]));
        }

        if (isset($validated['sold_for']) && bccomp(number_format((float) $validated['sold_for'], 2, '.', ''), $face, 2) < 0) {
            throw new DomainException(__('A voucher is sold at its face value. One sold for less changes the value of what it later pays for, which is not offered.'));
        }

        return Voucher::withoutTenantScope(fn () => Voucher::query()->create([
            'org_id' => $organization->id,
            'branch_id' => $branchId,
            'code' => filled($validated['code'] ?? null) ? strtoupper((string) $validated['code']) : 'V-'.strtoupper(Str::random(10)),
            'face_value' => $face,
            'currency' => $this->jurisdictions->forRules($organization)->currency(),
            'sold_on' => $validated['sold_on'],
            'expires_on' => $validated['expires_on'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'holder_name' => $validated['holder_name'] ?? null,
            'payment_method' => $validated['payment_method'],
            'reference' => $validated['reference'] ?? null,
        ]));
    }

    /** @return Collection<int, Voucher> */
    public function list(string $organizationId): Collection
    {
        return Voucher::withoutTenantScope(fn () => Voucher::query()->where('org_id', $organizationId)->with('redemptions')->latest('created_at')->limit(500)->get());
    }

    public function find(string $organizationId, string $code): ?Voucher
    {
        return Voucher::withoutTenantScope(fn () => Voucher::query()->where('org_id', $organizationId)->where('code', strtoupper($code))->with('redemptions')->first());
    }

    /** An issued invoice of the organization's a voucher can pay, by its id or its number. */
    public function payable(string $organizationId, ?string $invoiceId, ?string $invoiceNumber): ?Invoice
    {
        if ($invoiceId === null && filled($invoiceNumber)) {
            $invoiceId = Invoice::withoutTenantScope(fn () => Invoice::query()->where('org_id', $organizationId)->where('invoice_number', $invoiceNumber)->value('id'));
        }

        return $invoiceId === null ? null : $this->payments->payable($organizationId, (string) $invoiceId);
    }

    /** What is left to use: the face value less its redemptions. */
    public function balance(Voucher $voucher): string
    {
        $used = VoucherRedemption::query()->where('voucher_id', $voucher->id)->sum('amount');

        return bcsub((string) $voucher->face_value, number_format((float) $used, 2, '.', ''), 2);
    }

    /** active, used (nothing left) or expired, as at a day. */
    public function status(Voucher $voucher, ?CarbonImmutable $on = null): string
    {
        $on ??= CarbonImmutable::today();

        return match (true) {
            bccomp($this->balance($voucher), '0', 2) <= 0 => 'used',
            $voucher->expires_on !== null && $on->gt(CarbonImmutable::parse($voucher->expires_on)->endOfDay()) => 'expired',
            default => 'active',
        };
    }

    /**
     * Pay an issued invoice with a voucher: up to what is left on it and
     * what the invoice still owes - all it can when no amount is given - on
     * a day it has not expired. Recorded as the invoice's payment by voucher.
     *
     * @throws DomainException
     */
    public function redeem(Voucher $voucher, Invoice $invoice, ?string $amount, CarbonImmutable $on): VoucherRedemption
    {
        return DB::transaction(function () use ($voucher, $invoice, $amount, $on): VoucherRedemption {
            Voucher::withoutTenantScope(fn () => Voucher::query()->whereKey($voucher->id)->lockForUpdate()->first());

            if ($on->lt(CarbonImmutable::parse($voucher->sold_on)->startOfDay())) {
                throw new DomainException(__('A voucher cannot be used before it was sold, :date.', ['date' => CarbonImmutable::parse($voucher->sold_on)->toDateString()]));
            }

            if ($voucher->expires_on !== null && $on->gt(CarbonImmutable::parse($voucher->expires_on)->endOfDay())) {
                throw new DomainException(__('Voucher :code expired on :date.', ['code' => $voucher->code, 'date' => CarbonImmutable::parse($voucher->expires_on)->toDateString()]));
            }

            if ($voucher->currency !== $invoice->currency) {
                throw new DomainException(__('Voucher :code is in :currency; this invoice is not.', ['code' => $voucher->code, 'currency' => $voucher->currency]));
            }

            $left = $this->balance($voucher);
            $paying = $amount === null ? $left : number_format((float) $amount, 2, '.', '');

            if (bccomp($paying, '0', 2) <= 0 || bccomp($paying, $left, 2) > 0) {
                throw new DomainException(__('Voucher :code has :left left.', ['code' => $voucher->code, 'left' => $left]));
            }

            $payment = $this->payments->record($invoice, $paying, $on->startOfDay(), PaymentMethod::Voucher, $voucher->code, __('Paid with voucher :code', ['code' => $voucher->code]));

            return VoucherRedemption::query()->create([
                'voucher_id' => $voucher->id,
                'invoice_id' => $invoice->id,
                'invoice_payment_id' => $payment->id,
                'amount' => $paying,
                'redeemed_on' => $on->toDateString(),
            ]);
        });
    }

    /**
     * What a voucher is, for a list or the API.
     *
     * @return array<string, mixed>
     */
    public function present(Voucher $voucher): array
    {
        return [
            ...$voucher->only(['id', 'code', 'face_value', 'currency', 'sold_on', 'expires_on', 'customer_id', 'holder_name', 'payment_method']),
            'balance' => $this->balance($voucher),
            'status' => $this->status($voucher),
            'redemptions' => $voucher->redemptions->map(fn (VoucherRedemption $redemption): array => $redemption->only(['invoice_id', 'amount', 'redeemed_on']))->values()->all(),
        ];
    }
}
