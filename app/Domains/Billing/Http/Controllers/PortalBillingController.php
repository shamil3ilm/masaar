<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Http\BillingInvoiceResponse;
use App\Domains\Billing\Http\StatementCsv;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Services\CustomerAccount;
use App\Domains\Billing\Services\PaymentHistory;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Statements;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An organization's plan, its usage this month, its invoices from the
 * platform, its statement, and what it has paid.
 *
 * Its owners and administrators, as with the company's other settings, and
 * its accountants, who read it and download the invoices. Never closed by
 * the account's standing: an account frozen for non-payment still reads and
 * exports all of this.
 */
class PortalBillingController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly Billing $billing,
        private readonly PaymentHistory $history,
        private readonly Lists $lists,
        private readonly TenantResolver $tenant,
        private readonly InvoicePdf $pdf,
        private readonly CustomerAccount $account,
        private readonly Receivables $receivables,
        private readonly Statements $statements,
        private readonly FinanceSettings $settings,
    ) {}

    public function show(Request $request): View
    {
        $organizationId = $this->organizationId($request);
        $licence = $this->billing->licenceOf($organizationId);
        $list = $this->list($request, 'portal.billing.export');

        return view('portal.billing', [
            'licence' => $licence,
            'usage' => $this->billing->usage($organizationId, $licence),
            'payments' => $this->history->page($organizationId, $list),
            'list' => $list,
            'account' => $this->account->summary($this->tenant->getOrganization() ?? abort(404)),
        ]);
    }

    /** Exactly the payments the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        return $this->history->csv($this->organizationId($request), $this->list($request), 'payments-'.now()->format('Y-m-d').'.csv');
    }

    public function download(Request $request, string $paymentId): Response|RedirectResponse
    {
        $payment = $this->billing->payment($this->organizationId($request), $paymentId) ?? abort(404);

        return BillingInvoiceResponse::for($payment, $this->billing, $this->pdf)
            ?? redirect()->route('portal.billing')->with('error', __('That invoice is not ready yet. It is available once the tax authority has cleared it.'));
    }

    public function statement(Request $request): View
    {
        $organizationId = $this->organizationId($request);
        $customer = $this->tenant->getOrganization() ?? abort(404);
        [$currency, $from, $to] = StatementCsv::period($request, $this->settings->currencyFor($organizationId));

        return view('portal.billing-statement', [
            'statement' => $this->statements->statement($customer, $currency, $from, $to),
            'currencies' => $this->statements->currencies($customer, $this->settings->currencyFor($organizationId)),
            'ageing' => $this->statements->ageing(CarbonImmutable::now(), $organizationId)[$currency] ?? null,
            'headings' => Statements::headings(),
            'accounts' => $this->settings->bankAccounts($currency),
        ]);
    }

    public function statementCsv(Request $request): StreamedResponse
    {
        $organizationId = $this->organizationId($request);
        $customer = $this->tenant->getOrganization() ?? abort(404);
        [$currency, $from, $to] = StatementCsv::period($request, $this->settings->currencyFor($organizationId));

        return StatementCsv::download($this->statements->statement($customer, $currency, $from, $to), 'statement-'.$from->toDateString().'-'.$to->toDateString().'.csv');
    }

    public function receivablePdf(Request $request, string $receivableId): Response|RedirectResponse
    {
        $receivable = $this->receivables->find($this->organizationId($request), $receivableId) ?? abort(404);

        return BillingInvoiceResponse::of($receivable->invoice(), $this->billing->billingOrganization(), $this->pdf)
            ?? redirect()->route('portal.billing')->with('error', __('That invoice is not ready yet. It is available once the tax authority has cleared it.'));
    }

    private function list(Request $request, ?string $export = null): ListState
    {
        // Its accountants too, as for the page itself.
        $this->organizationId($request);
        $organization = $this->tenant->getOrganization() ?? abort(404);

        return $this->lists->portal($this->history->spec($organization, 'portal.billing'), $request, (string) $organization->id, $export);
    }

    private function organizationId(Request $request): string
    {
        $organizationId = $this->portalOrganizationId($request);
        $role = $request->user()?->roleIn($organizationId);

        abort_unless(MemberRole::administers($role) || $role === MemberRole::Accountant->value, 403, __('Only an administrator of this organization can see its billing.'));

        return $organizationId;
    }
}
