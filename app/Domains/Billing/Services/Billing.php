<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Compliance\Engine\DTOs\IssueOptions;
use App\Domains\Compliance\Engine\Services\ComplianceEngine;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Licensing\Enums\LicenseStatus;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Exceptions\LicenceStateException;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Organization\Services\TenantResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payments for plans: recorded by hand, renewing the licence, and invoiced
 * through the platform itself.
 *
 * The invoice is issued from the billing organization - the operator's own
 * company, onboarded with ZATCA like any customer - to the organization that
 * paid. A payment is kept whether or not that invoice could be issued yet;
 * the reason is kept beside it, and issuing can be tried again.
 */
class Billing
{
    public function __construct(
        private readonly InvoiceDrafter $drafter,
        private readonly ComplianceEngine $engine,
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly LanguageGrace $grace,
    ) {}

    /**
     * @param  list<string>  $ids
     * @return SupportCollection<string, Organization>
     */
    public function organizationsByIds(array $ids): SupportCollection
    {
        return Organization::query()->whereKey($ids)->get()->keyBy('id');
    }

    public function organization(string $organizationId): ?Organization
    {
        return Organization::query()->find($organizationId);
    }

    /**
     * The licence an organization's plan lives on: its newest.
     */
    public function licenceOf(string $organizationId): ?License
    {
        return License::query()->where('org_id', $organizationId)->latest('created_at')->first();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function payments(string $organizationId): Collection
    {
        return Payment::withoutTenantScope(fn () => Payment::query()
            ->where('org_id', $organizationId)
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->get());
    }

    public function payment(string $organizationId, string $paymentId): ?Payment
    {
        return Payment::withoutTenantScope(fn () => Payment::query()->where('org_id', $organizationId)->find($paymentId));
    }

    /**
     * Invoices issued this calendar month, against the plan's monthly limit.
     *
     * @return array{used: int, limit: int|null}
     */
    public function usage(string $organizationId, ?License $licence): array
    {
        $used = Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $organizationId)
            ->whereNotNull('icv')
            ->whereBetween('issue_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->count());

        $limit = $licence?->invoices_per_month;

        return ['used' => $used, 'limit' => $limit === null || (int) $limit <= 0 ? null : (int) $limit];
    }

    /**
     * Plans to chase: ending within the window, or lapsed within it.
     *
     * Payment is recorded by hand, so nothing renews on its own; this is
     * the list of who to ask before their invoicing stops, and who already
     * has.
     *
     * @return Collection<int, License>
     */
    public function renewals(int $days): Collection
    {
        return License::query()
            ->whereNotNull('org_id')
            ->whereNotNull('expires_at')
            ->whereIn('status', [LicenseStatus::Active, LicenseStatus::Expired])
            ->whereBetween('expires_at', [now()->subDays($days), now()->addDays($days)])
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Whether a payment received on this date is now invoiced late.
     *
     * A payment received before the supply is the tax point, and its invoice
     * is due within fifteen days of the end of that month (ZATCA's Guideline
     * for Tax Invoicing and Records, 4.5.1). The payment still counts and is
     * still invoiced; the admin is told it is late.
     */
    public function invoicedLate(CarbonImmutable $paidAt): bool
    {
        return now()->greaterThan($paidAt->endOfMonth()->addDays(15));
    }

    /**
     * The monthly price of a plan, excluding VAT.
     */
    public function monthlyPrice(LicenseTier $tier): int
    {
        return (int) config('billing.prices.'.$tier->value, 0);
    }

    /**
     * Record a payment: renew the licence, then invoice it.
     *
     * @throws DomainException when there is no licence to renew, or it may not be renewed
     */
    public function record(
        Organization $organization,
        LicenseTier $tier,
        int $months,
        string $amount,
        PaymentMethod $method,
        ?string $reference,
        CarbonImmutable $paidAt,
        ?string $notes,
        string $actorId,
    ): Payment {
        $licence = $this->licenceOf((string) $organization->id)
            ?? throw new DomainException('This organization has no licence. Approve it with a plan first.');

        // One line at one rate, so this is the VAT the invoice will carry.
        $vat = $this->money((float) $amount * (float) config('billing.vat_rate', 15) / 100);

        $payment = DB::transaction(function () use ($organization, $licence, $tier, $months, $amount, $vat, $method, $reference, $paidAt, $notes, $actorId): Payment {
            $start = License::renewalStart($licence);

            try {
                if ($licence->tier !== $tier) {
                    $licence->upgradeTier($tier, $actorId);
                }

                $licence->renewFor($months, $actorId);
            } catch (LicenceStateException $e) {
                throw new DomainException($e->getMessage());
            }

            return Payment::withoutTenantScope(fn () => Payment::query()->create([
                'org_id' => $organization->id,
                'license_id' => $licence->id,
                'tier' => $tier->value,
                'months' => $months,
                'period_start' => $start->toDateString(),
                'period_end' => $licence->fresh()?->expires_at?->toDateString() ?? $start->addMonths($months)->toDateString(),
                'amount' => $amount,
                'vat_amount' => $vat,
                'total' => $this->money((float) $amount + (float) $vat),
                'currency' => (string) config('billing.currency', 'SAR'),
                'method' => $method,
                'reference' => $reference,
                'paid_at' => $paidAt->toDateString(),
                'notes' => $notes,
                'recorded_by' => $actorId,
            ]));
        });

        $this->invoice($payment);

        return $payment->fresh() ?? $payment;
    }

    /**
     * Issue the platform's invoice for a payment that has none.
     *
     * Never throws: what stopped it is kept on the payment, where the console
     * shows it beside a button to try again.
     */
    public function invoice(Payment $payment): void
    {
        if ($payment->invoice_id !== null) {
            return;
        }

        try {
            $invoiceId = $this->issue($payment);
            $payment->forceFill(['invoice_id' => $invoiceId, 'invoice_error' => null])->save();
        } catch (Throwable $e) {
            Log::warning('Billing: payment recorded without an invoice', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            $payment->forceFill(['invoice_error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }

    /**
     * The billing organization, when one is set and it can issue.
     */
    public function billingOrganization(): ?Organization
    {
        $id = config('billing.organization_id');

        return is_string($id) && $id !== '' ? Organization::query()->find($id) : null;
    }

    /**
     * @throws DomainException
     */
    private function issue(Payment $payment): string
    {
        $seller = $this->billingOrganization()
            ?? throw new DomainException('No billing organization is set (BILLING_ORGANIZATION_ID), so no invoice was issued.');

        if (! $seller->zatca_onboarded) {
            throw new DomainException('The billing organization is not connected to ZATCA yet, so no invoice was issued.');
        }

        $buyer = $payment->organization ?? throw new DomainException('The organization that paid no longer exists.');
        $tier = LicenseTier::from((string) $payment->tier);
        // How the seller's country identifies the buyer, and what it needs of
        // the buyer's details: billing drafts directly, past the request
        // rules that check this for invoices entered by hand.
        $identity = $this->jurisdictions->forRules($seller)->invoiceRules()->billedBuyer($buyer);

        $draft = $this->draft($payment, $buyer, $tier, $identity);

        // The platform is a seller like any other: where its country requires
        // Arabic on the invoice (ZATCA's E-Invoicing Detailed Guidelines p60,
        // p63), its own name, the buyer's and every text are checked before
        // anything is drafted - unless an admin gave the billing organization
        // a grace period while those names are filled in.
        $missing = $this->jurisdictions->forRules($seller)->languageRules()->missingInDraft($draft, $seller);

        if ($missing !== [] && ! $this->grace->active($seller)) {
            throw new DomainException('The invoice lacks the Arabic its country requires ('.implode(', ', array_keys($missing)).'), so no invoice was issued. Add the Arabic names on the company pages, or give the billing organization a grace period.');
        }

        return $this->tenant->runAs((string) $seller->id, function () use ($draft, $seller): string {
            $invoice = $this->drafter->draft($draft, (string) $seller->id);

            $outcome = $this->engine->issue($invoice, $seller, new IssueOptions(file: true));

            if (! $outcome->issued) {
                throw new DomainException($outcome->errors[0] ?? 'The invoice could not be issued.');
            }

            return (string) $invoice->id;
        });
    }

    /**
     * The plan's invoice as InvoiceDrafter takes it: in both languages, the
     * Arabic first, as the seller's country prints it.
     *
     * @param  array<string, mixed>  $identity  how the seller's country identifies the buyer
     * @return array<string, mixed>
     */
    private function draft(Payment $payment, Organization $buyer, LicenseTier $tier, array $identity): array
    {
        return [
            // Always standard: the buyer is a company, and a simplified
            // invoice is for a natural person or a sale under SAR 1,000.
            'type' => 'standard',
            'document_type' => 'invoice',
            'issue_date' => now()->toDateString(),
            // KSA-5 and KSA-24: the subscription period the payment buys.
            'supply_date' => $payment->period_start->toDateString(),
            'supply_end_date' => $payment->period_end->toDateString(),
            'currency' => $payment->currency,
            'prices_include_vat' => false,
            'buyer_name' => $buyer->name,
            'buyer_name_ar' => $buyer->name_ar,
            ...$identity,
            'buyer_address' => [
                'street' => $buyer->street,
                'building_number' => $buyer->building_number,
                'district' => $buyer->district,
                'city' => $buyer->city,
                'postal_code' => $buyer->postal_code,
                'country_code' => $buyer->country ?: 'SA',
            ],
            'notes' => 'تم استلام الدفعة '.$payment->reference.' بتاريخ '.$payment->paid_at->toDateString().'. / Payment '.$payment->reference.' received '.$payment->paid_at->toDateString().'.',
            'lines' => [[
                'description' => $tier->getDisplayName().' plan, '.$payment->months.' '.($payment->months === 1 ? 'month' : 'months')
                    .' ('.$payment->period_start->toDateString().' to '.$payment->period_end->toDateString().')',
                'description_ar' => 'اشتراك باقة '.$tier->getDisplayName().' لمدة '.$payment->months.' شهر',
                'quantity' => '1',
                'unit_code' => 'C62',
                'unit_price' => (string) $payment->amount,
                'tax_rate' => (string) config('billing.vat_rate', 15),
            ]],
        ];
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
