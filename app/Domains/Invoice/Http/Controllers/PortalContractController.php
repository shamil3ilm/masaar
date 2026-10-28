<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Invoice\Models\Contract;
use App\Domains\Invoice\Services\ProgressBilling;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Contracts billed in stages with retention, from the browser
 * (ProgressBilling): record the contract and its stages, invoice a stage,
 * release retention, and read the contract's statement - the commercial
 * document that shows the retention calculation the invoices leave out.
 */
class PortalContractController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly ProgressBilling $billing,
    ) {}

    public function index(Request $request): View
    {
        return view('portal.invoices.contracts.index', ['contracts' => $this->billing->list((string) $request->attributes->get(PortalTenant::ORG_ID))]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);
        // The form's blank stage rows are not stages.
        $request->merge(['stages' => array_values(array_filter((array) $request->input('stages', []), fn (mixed $stage): bool => is_array($stage) && filled($stage['name'] ?? null)))]);

        try {
            $contract = $this->billing->create($organization, $request->validate($this->billing->contractRules($organization)));
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('portal.contracts.show', $contract->id)->with('success', __('Contract :reference recorded.', ['reference' => $contract->reference]));
    }

    public function show(Request $request, string $contractId): View
    {
        $contract = $this->contract($request, $contractId);

        return view('portal.invoices.contracts.show', ['contract' => $contract, 'statement' => $this->billing->statement($contract)]);
    }

    public function invoiceStage(Request $request, string $contractId, string $stageId): RedirectResponse
    {
        $contract = $this->contract($request, $contractId);

        try {
            $invoice = $this->billing->invoiceStage($this->tenant->getOrganization() ?? abort(404), $contract, $stageId, $this->issueDate($request));
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('portal.invoices.show', $invoice->id)->with('success', __('Stage invoice drafted. Check it and issue it.'));
    }

    public function release(Request $request, string $contractId): RedirectResponse
    {
        $contract = $this->contract($request, $contractId);
        $amount = $request->validate(['amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2']])['amount'] ?? null;

        try {
            $invoice = $this->billing->release($this->tenant->getOrganization() ?? abort(404), $contract, $amount === null ? null : (string) $amount, $this->issueDate($request));
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('portal.invoices.show', $invoice->id)->with('success', __('Retention release drafted. Check it and issue it.'));
    }

    private function contract(Request $request, string $contractId): Contract
    {
        return $this->billing->find((string) $request->attributes->get(PortalTenant::ORG_ID), $contractId) ?? abort(404);
    }

    private function issueDate(Request $request): CarbonImmutable
    {
        $date = $request->validate(['issue_date' => ['nullable', 'date']])['issue_date'] ?? null;

        return $date === null ? CarbonImmutable::today() : CarbonImmutable::parse($date);
    }
}
