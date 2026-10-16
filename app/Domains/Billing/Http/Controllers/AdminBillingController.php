<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Http\BillingInvoiceResponse;
use App\Domains\Billing\Services\Billing;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One organization's plan and payments, for the admins who take payment.
 */
class AdminBillingController extends Controller
{
    private const RENEWAL_DAYS = 30;

    public function __construct(
        private readonly Billing $billing,
        private readonly InvoicePdf $pdf,
    ) {}

    /**
     * Every plan ending in the next 30 days or lapsed in the last 30.
     */
    public function renewals(): View
    {
        $licences = $this->billing->renewals(self::RENEWAL_DAYS);

        return view('admin.renewals', [
            'licences' => $licences,
            'organizations' => $this->billing->organizationsByIds($licences->pluck('org_id')->unique()->values()->all()),
            'days' => self::RENEWAL_DAYS,
        ]);
    }

    public function show(string $id): View
    {
        $organization = $this->organization($id);
        $licence = $this->billing->licenceOf($id);

        return view('admin.billing', [
            'organization' => $organization,
            'licence' => $licence,
            'usage' => $this->billing->usage($id, $licence),
            'payments' => $this->billing->payments($id),
            'tiers' => LicenseTier::cases(),
            'prices' => collect(LicenseTier::cases())->mapWithKeys(fn (LicenseTier $tier): array => [$tier->value => $this->billing->monthlyPrice($tier)])->all(),
            'methods' => PaymentMethod::cases(),
            'billingOrganization' => $this->billing->billingOrganization(),
        ]);
    }

    public function record(Request $request, string $id): RedirectResponse
    {
        $organization = $this->organization($id);
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $data = $request->validate([
            'tier' => ['required', Rule::enum(LicenseTier::class)],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $payment = $this->billing->record(
                $organization,
                LicenseTier::from($data['tier']),
                (int) $data['months'],
                number_format((float) $data['amount'], 2, '.', ''),
                PaymentMethod::from($data['method']),
                $data['reference'] ?? null,
                CarbonImmutable::parse($data['paid_at']),
                $data['notes'] ?? null,
                (string) $request->user()->id,
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = 'Payment recorded. The plan now runs to '.$payment->period_end->toDateString().'.';

        if ($this->billing->invoicedLate(CarbonImmutable::parse($data['paid_at']))) {
            $message .= ' This payment is past the fifteen days after its month in which ZATCA expects it invoiced.';
        }

        return redirect()->route('admin.billing.show', $id)->with(
            $payment->invoice_id === null ? 'error' : 'success',
            $payment->invoice_id === null ? $message.' No invoice was issued: '.$payment->invoice_error : $message.' Invoice issued.',
        );
    }

    public function invoice(Request $request, string $id, string $paymentId): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);
        $payment = $this->billing->payment($id, $paymentId) ?? abort(404);

        $this->billing->invoice($payment);
        $payment->refresh();

        return redirect()->route('admin.billing.show', $id)->with(
            $payment->invoice_id === null ? 'error' : 'success',
            $payment->invoice_id === null ? 'Still no invoice: '.$payment->invoice_error : 'Invoice issued.',
        );
    }

    public function download(string $id, string $paymentId): Response|RedirectResponse
    {
        $payment = $this->billing->payment($id, $paymentId) ?? abort(404);

        return BillingInvoiceResponse::for($payment, $this->billing, $this->pdf)
            ?? redirect()->route('admin.billing.show', $id)->with('error', 'That invoice cannot be downloaded yet: ZATCA has not cleared it.');
    }

    private function organization(string $id): Organization
    {
        return $this->billing->organization($id) ?? abort(404);
    }
}
