<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Services\Vouchers;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vouchers in Books, from the browser (Vouchers): those sold and what is
 * left on each, selling one, and using one to pay an issued invoice.
 */
class PortalVoucherController extends Controller
{
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Vouchers $vouchers,
    ) {}

    public function index(Request $request): View
    {
        return view('portal.books.vouchers', [
            'vouchers' => $this->vouchers->list($this->portalOrganizationId($request))->map(fn ($voucher): array => $this->vouchers->present($voucher)),
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->portalOrganization($this->tenant, $request);

        try {
            $voucher = $this->vouchers->sell($organization, $request->validate($this->vouchers->saleRules($organization)));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.books.vouchers')->with('success', __('Voucher :code sold for :amount. No tax invoice is issued for a voucher sale.', ['code' => $voucher->code, 'amount' => $voucher->face_value]));
    }

    public function redeem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'redeemed_on' => ['required', 'date'],
        ]);
        $organizationId = $this->portalOrganizationId($request);
        $voucher = $this->vouchers->find($organizationId, (string) $data['code']);
        $invoice = $this->vouchers->payable($organizationId, null, (string) $data['invoice_number']);

        if ($voucher === null || $invoice === null) {
            return back()->withInput()->with('error', $voucher === null ? __('There is no voucher with that code.') : __('There is no issued invoice of yours with that id or number.'));
        }

        try {
            $this->vouchers->redeem($voucher, $invoice, isset($data['amount']) ? (string) $data['amount'] : null, CarbonImmutable::parse($data['redeemed_on']));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.books.vouchers')->with('success', __('Voucher :code used on invoice :number.', ['code' => $voucher->code, 'number' => $invoice->invoice_number]));
    }
}
