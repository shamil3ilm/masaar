<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\DTOs\ReceivedPayment;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Services\Credits;
use App\Domains\Billing\Services\Payments;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Refunds;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Actions\FinanceActions;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Money from and back to one customer, for the admins who take payment
 * (billing.manage): a bank transfer that arrived, set against its
 * invoices; a refund through a credit note; a write-off, which an approval
 * policy can hold for a second admin; and the customer's credit, applied
 * or paid back.
 */
class AdminPaymentsController extends Controller
{
    use HoldsForApproval;

    public function __construct(
        private readonly Payments $payments,
        private readonly Receivables $receivables,
        private readonly Refunds $refunds,
        private readonly Credits $credits,
        private readonly Billing $billing,
        private readonly FinanceSettings $settings,
        private readonly Approvals $approvals,
    ) {}

    public function transfer(Request $request, string $id): RedirectResponse
    {
        $customer = $this->customer($request, $id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $payment = $this->payments->recordTransfer($customer, new ReceivedPayment(
                amount: (string) $data['amount'],
                currency: $this->settings->currencyFor($id),
                receivedOn: CarbonImmutable::parse($data['received_on']),
                reference: $data['reference'] ?? null,
                bankAccount: $data['bank_account'] ?? null,
                fee: isset($data['fee']) ? (string) $data['fee'] : null,
                exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
                notes: $data['notes'] ?? null,
                allocations: array_map(fn ($amount): string => (string) $amount, array_filter((array) ($data['allocations'] ?? []), fn ($amount): bool => $amount !== null && $amount !== '')),
            ), (string) Auth::id());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.billing.show', $id)->with('success', __('Transfer of :amount recorded.', ['amount' => $payment->total.' '.$payment->currency]));
    }

    public function writeOff(Request $request, string $id, string $receivableId): RedirectResponse
    {
        $this->customer($request, $id);
        $receivable = $this->receivables->find($id, $receivableId) ?? abort(404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            return $this->holdOrRun($this->approvals, $request, FinanceActions::WRITE_OFF, [
                'organization_id' => $id,
                'receivable_id' => (string) $receivable->id,
                'reason' => $data['reason'],
            ], fn (ActionResult $done): RedirectResponse => redirect()->route('admin.billing.receivables.show', [$id, $receivableId])->with('success', $done->message), $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function refund(Request $request, string $id, string $receivableId): RedirectResponse
    {
        $this->customer($request, $id);
        $receivable = $this->receivables->find($id, $receivableId) ?? abort(404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:240'],
            'reason_ar' => ['required', 'string', 'max:240'],
            'payout' => ['required', Rule::in(Refunds::PAYOUTS)],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $refund = $this->refunds->refund($receivable, (string) $data['amount'], $data['reason'], $data['reason_ar'], $data['payout'], $data['reference'] ?? null, (string) Auth::id());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.billing.receivables.show', [$id, $receivableId])->with('success', __('Credit note for :amount issued.', [
            'amount' => $refund->credited_amount.' '.$refund->currency,
        ]));
    }

    /** The customer's credit, taken by its open invoices, the one due first first. */
    public function applyCredit(Request $request, string $id): RedirectResponse
    {
        $this->customer($request, $id);
        $taken = 0.0;

        foreach ($this->receivables->outstanding($id, $this->settings->currencyFor($id)) as $receivable) {
            $taken += (float) $this->receivables->applyCredits($receivable, (string) Auth::id());
        }

        return redirect()->route('admin.billing.show', $id)->with('success', __('Credit applied: :amount.', ['amount' => number_format($taken, 2, '.', '').' '.$this->settings->currencyFor($id)]));
    }

    public function payOutCredit(Request $request, string $id, string $creditId): RedirectResponse
    {
        $this->customer($request, $id);
        $credit = $this->credits->find($id, $creditId) ?? abort(404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['bank_transfer', 'card'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->refunds->payOutCredit($credit, (string) $data['amount'], $data['method'], $data['reference'] ?? null, $data['reason'], (string) Auth::id());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.billing.show', $id)->with('success', __('Credit paid back.'));
    }

    private function customer(Request $request, string $id): Organization
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        return $this->billing->organization($id) ?? abort(404);
    }
}
