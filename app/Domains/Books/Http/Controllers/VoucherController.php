<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Services\Vouchers;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vouchers through the API (Vouchers): sell one - no invoice, no VAT - look
 * one up by its code, and use it to pay an issued invoice.
 *
 * GET/POST /api/vouchers, GET /api/vouchers/{code}, POST
 * /api/vouchers/{code}/redeem; the partner API's /v1/... the same.
 */
class VoucherController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Vouchers $vouchers,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(['vouchers' => $this->vouchers->list((string) $this->tenant->getOrganizationId())->map(fn ($voucher): array => $this->vouchers->present($voucher))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization() ?? abort(404);

        try {
            $voucher = $this->vouchers->sell($organization, $request->validate($this->vouchers->saleRules($organization)));
        } catch (DomainException $e) {
            return ApiResponse::validationError(['face_value' => [$e->getMessage()]]);
        }

        return ApiResponse::created(['voucher' => $this->vouchers->present($voucher)], 'Voucher sold. No tax invoice: selling a voucher is not a supply.');
    }

    public function show(string $code): JsonResponse
    {
        $voucher = $this->vouchers->find((string) $this->tenant->getOrganizationId(), $code) ?? abort(404);

        return ApiResponse::success(['voucher' => $this->vouchers->present($voucher)]);
    }

    /** POST /api/vouchers/{code}/redeem {invoice_id or invoice_number, amount?, redeemed_on?} */
    public function redeem(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['nullable', 'uuid', 'required_without:invoice_number'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'redeemed_on' => ['nullable', 'date'],
        ]);
        $organizationId = (string) $this->tenant->getOrganizationId();
        $voucher = $this->vouchers->find($organizationId, $code) ?? abort(404);
        $invoice = $this->vouchers->payable($organizationId, $data['invoice_id'] ?? null, $data['invoice_number'] ?? null);

        if ($invoice === null) {
            return ApiResponse::validationError(['invoice_id' => [__('There is no issued invoice of yours with that id or number.')]]);
        }

        try {
            $this->vouchers->redeem($voucher, $invoice, isset($data['amount']) ? (string) $data['amount'] : null,
                isset($data['redeemed_on']) ? CarbonImmutable::parse($data['redeemed_on']) : CarbonImmutable::today());
        } catch (DomainException $e) {
            return ApiResponse::validationError(['amount' => [$e->getMessage()]]);
        }

        return ApiResponse::success(['voucher' => $this->vouchers->present($voucher->fresh('redemptions'))], 'Voucher used: recorded as a payment of the invoice, which bears the VAT.');
    }
}
