<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
use App\Domains\Invoice\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The portal's invoice form, beyond the invoice it sends.
 *
 * It carries two things of its own. A token, made when the form is drawn:
 * a form sent twice - Back, then Save as draft again - names the draft it
 * already made, and gets that draft back rather than a copy. And, when it
 * edits a draft, which one: saving rewrites that draft in place.
 */
class DraftForm
{
    /** The input that names the form, kept as the draft's idempotency key. */
    public const TOKEN = 'form_token';

    /** The input that names the draft the form is editing. */
    public const REPLACES = 'replaces';

    public function __construct(
        private readonly InvoiceDrafter $drafter,
    ) {}

    /** The form's token: the one it was sent with, or a new one. */
    public function token(?string $sent): string
    {
        return is_string($sent) && Str::isUuid($sent) ? $sent : (string) Str::uuid();
    }

    /**
     * The draft this form already made, when it is sent again.
     */
    public function alreadySaved(Request $request, string $organizationId): ?Invoice
    {
        $token = $this->sentToken($request);

        return $token === null ? null : $this->drafter->createdUnder($organizationId, $token);
    }

    /** The token the form was sent with, when it is one this form made. */
    public function sentToken(Request $request): ?string
    {
        $token = $request->input(self::TOKEN);

        return is_string($token) && Str::isUuid($token) ? $token : null;
    }

    /** The id of the draft the form edits, or null for a new invoice. */
    public function replaces(Request $request): ?string
    {
        $id = $request->input(self::REPLACES);

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    /**
     * The form's fields, filled from a draft to edit it: what the
     * new-invoice form would have sent to make it.
     *
     * @return array<string, mixed>
     */
    public function fields(Invoice $draft): array
    {
        $draft->loadMissing(['lines.charges', 'charges', 'prepayments']);
        $inclusive = (bool) $draft->prices_include_vat;

        return [
            self::REPLACES => (string) $draft->id,
            'invoice_number' => (string) $draft->invoice_number,
            'type' => $draft->type?->value,
            'document_type' => $draft->document_type?->value,
            'issue_date' => $draft->issue_date?->toDateString(),
            'supply_date' => $draft->supply_date?->toDateString(),
            'supply_end_date' => $draft->supply_end_date?->toDateString(),
            'due_date' => $draft->due_date?->toDateString(),
            'currency' => (string) $draft->currency,
            'exchange_rate' => $draft->exchange_rate === null ? null : (string) (float) $draft->exchange_rate,
            'payment_means_code' => (string) $draft->payment_means_code,
            'payment_terms' => (string) $draft->payment_terms,
            'billing_ref' => (string) $draft->billing_ref,
            'order_reference' => (string) $draft->order_reference,
            'contract_reference' => (string) $draft->contract_reference,
            'buyer_reference' => (string) $draft->buyer_reference,
            'adjustment_reason' => (string) $draft->adjustment_reason,
            'prepayment_numbers' => $draft->prepayments->pluck('invoice_number')->unique()->implode(', '),
            'prices_include_vat' => $inclusive ? '1' : '0',
            'buyer_name' => (string) $draft->buyer_name,
            'buyer_name_ar' => (string) $draft->buyer_name_ar,
            'buyer_vat_number' => (string) $draft->buyer_vat_number,
            'buyer_id' => (string) $draft->buyer_id,
            'buyer_id_scheme' => (string) $draft->buyer_id_scheme,
            'buyer_address' => (array) ($draft->buyer_address ?? []),
            'discount_amount' => bccomp((string) $draft->discount_amount, '0', 2) > 0 ? (string) $draft->discount_amount : null,
            'notes' => (string) $draft->notes,
            // Ticked boxes only, as the form sends them.
            ...array_map(
                fn (): string => '1',
                array_filter($draft->only(['is_third_party', 'is_nominal', 'is_export', 'is_summary', 'is_self_billed', 'is_government'])),
            ),
            'lines' => $draft->lines->map(fn (InvoiceLine $line): array => [
                'description' => (string) $line->description,
                'description_ar' => (string) $line->description_ar,
                'product_id' => (string) $line->product_id,
                'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                'unit_price' => $line->priceAsEntered($inclusive),
                'tax_rate' => (string) (float) $line->tax_rate,
                'tax_category' => (string) $line->tax_category,
                'exempt_code' => (string) $line->exempt_code,
                'exempt_reason' => (string) $line->exempt_reason,
                'unit_code' => (string) $line->unit_code,
                ...($line->discount_amount === null ? [] : ['discount_amount' => (string) $line->discount_amount]),
                ...($line->charges->isEmpty() ? [] : ['charges' => $line->charges->map(fn (InvoiceCharge $charge): array => $this->charge($charge))->values()->all()]),
            ])->values()->all() ?: [[]],
            ...($draft->charges->isEmpty() ? [] : ['charges' => $draft->charges->map(fn (InvoiceCharge $charge): array => [
                ...$this->charge($charge),
                'tax_category' => (string) $charge->tax_category,
                'tax_rate' => (string) (float) $charge->tax_rate,
                'exempt_code' => (string) $charge->exempt_code,
                'exempt_reason' => (string) $charge->exempt_reason,
            ])->values()->all()]),
        ];
    }

    /**
     * A charge as the form takes it.
     *
     * @return array<string, string>
     */
    private function charge(InvoiceCharge $charge): array
    {
        return [
            'reason' => (string) $charge->reason,
            'reason_code' => (string) $charge->reason_code,
            ...($charge->percent !== null
                ? ['percent' => (string) (float) $charge->percent, 'base_amount' => (string) $charge->base_amount]
                : ['amount' => (string) $charge->amount]),
        ];
    }
}
