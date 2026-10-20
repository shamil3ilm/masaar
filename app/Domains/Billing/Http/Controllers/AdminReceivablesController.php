<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\DTOs\ReceivableDraft;
use App\Domains\Billing\Http\BillingInvoiceResponse;
use App\Domains\Billing\Http\StatementCsv;
use App\Domains\Billing\Services\Billing;
use App\Domains\Billing\Services\ReceivableList;
use App\Domains\Billing\Services\Receivables;
use App\Domains\Billing\Services\Statements;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The platform's invoices to its customers: every customer's, with what is
 * owed by age, and one customer's - invoicing it, its own terms, and its
 * statement.
 *
 * Every admin reads them; invoicing and changing a customer's terms take
 * billing.manage.
 */
class AdminReceivablesController extends Controller
{
    public function __construct(
        private readonly Receivables $receivables,
        private readonly ReceivableList $list,
        private readonly Statements $statements,
        private readonly FinanceSettings $settings,
        private readonly Billing $billing,
        private readonly Lists $lists,
        private readonly InvoicePdf $pdf,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->listState($request, 'admin.finance.receivables.export');

        return view('admin.finance.receivables', [
            'receivables' => $this->list->page($list),
            'list' => $list,
            'ageing' => $this->statements->ageing(CarbonImmutable::now()),
            'headings' => Statements::headings(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->list->csv($this->listState($request), 'receivables-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Invoice the customer for the amount agreed with it.
     */
    public function issue(Request $request, string $id): RedirectResponse
    {
        $customer = $this->customer($id);
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:300'],
            'description_ar' => ['required', 'string', 'max:300'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'period_start' => ['nullable', 'date', 'required_with:period_end'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);

        try {
            $receivable = $this->receivables->issue($customer, new ReceivableDraft(
                description: $data['description'],
                descriptionAr: $data['description_ar'],
                amount: (string) $data['amount'],
                periodStart: isset($data['period_start']) ? CarbonImmutable::parse($data['period_start']) : null,
                periodEnd: isset($data['period_end']) ? CarbonImmutable::parse($data['period_end']) : null,
                exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
                licenseId: $this->billing->licenceOf($id)?->id,
                applyCredit: $request->boolean('apply_credit', true),
            ), (string) Auth::id());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.billing.show', $id)->with('success', __('Invoice :number issued, due :date.', [
            'number' => $this->receivables->number($receivable), 'date' => $receivable->due_date->toDateString(),
        ]));
    }

    /**
     * The customer's own terms. A field left empty follows the platform's
     * default again.
     */
    public function terms(Request $request, string $id): RedirectResponse
    {
        $this->customer($id);
        $request->attributes->set(AuditAdminAction::TARGET, ['Organization', $id]);

        $data = $request->validate([
            'currency' => ['nullable', 'string', Rule::in(FinanceSettings::billable())],
            'free_months' => ['nullable', 'integer'],
            'payment_terms_days' => ['nullable', 'integer'],
            'reminder_days_before' => ['nullable', 'string', 'max:100'],
            'reminder_days_after' => ['nullable', 'string', 'max:100'],
        ]);

        $this->settings->setForCustomer($id, [
            FinanceSettings::CURRENCY => $data['currency'] ?? null,
            FinanceSettings::FREE_MONTHS => $data['free_months'] ?? null,
            FinanceSettings::PAYMENT_TERMS => $data['payment_terms_days'] ?? null,
            FinanceSettings::REMINDERS_BEFORE => $data['reminder_days_before'] ?? null,
            FinanceSettings::REMINDERS_AFTER => $data['reminder_days_after'] ?? null,
        ], (string) Auth::id());

        return redirect()->route('admin.billing.show', $id)->with('success', __('The customer\'s terms are saved.'));
    }

    public function statement(Request $request, string $id): View
    {
        $customer = $this->customer($id);
        [$currency, $from, $to] = $this->period($request, $customer);

        return view('admin.finance.statement', [
            'organization' => $customer,
            'statement' => $this->statements->statement($customer, $currency, $from, $to),
            'currencies' => $this->statements->currencies($customer, $this->settings->currencyFor($id)),
            'ageing' => $this->statements->ageing(CarbonImmutable::now(), $id)[$currency] ?? null,
            'headings' => Statements::headings(),
        ]);
    }

    public function statementCsv(Request $request, string $id): StreamedResponse
    {
        $customer = $this->customer($id);
        [$currency, $from, $to] = $this->period($request, $customer);

        return StatementCsv::download($this->statements->statement($customer, $currency, $from, $to), 'statement-'.$from->toDateString().'-'.$to->toDateString().'.csv');
    }

    public function pdf(string $id, string $receivableId): Response|RedirectResponse
    {
        $receivable = $this->receivables->find($id, $receivableId) ?? abort(404);

        return BillingInvoiceResponse::of($receivable->invoice(), $this->billing->billingOrganization(), $this->pdf)
            ?? redirect()->route('admin.billing.show', $id)->with('error', __('That invoice cannot be downloaded yet: the tax authority has not cleared it.'));
    }

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function period(Request $request, Organization $customer): array
    {
        return StatementCsv::period($request, $this->settings->currencyFor((string) $customer->id));
    }

    private function listState(Request $request, ?string $export = null): ListState
    {
        return $this->lists->admin($this->list->spec('admin.finance.receivables'), $request, $export);
    }

    private function customer(string $id): Organization
    {
        return $this->billing->organization($id) ?? abort(404);
    }
}
