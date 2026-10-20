<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\PaymentChannel;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentGatewayException;
use App\Domains\Billing\Models\BillingCredit;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentAllocation;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Models\Refund;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Services\AdjustmentDraft;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Giving a customer money back.
 *
 * Against an invoice, a refund is a credit note: an issued invoice cannot
 * change, so what it charged is reduced by a note that names it (ZATCA
 * E-Invoicing Detailed Guidelines p63: an invoice is corrected by a credit
 * or debit note, never cancelled), issued from the billing organization
 * through its country's module like the invoice. The invoice then owes
 * that much less; what the customer had already paid beyond that is kept
 * to its credit, or paid back by bank transfer or to the card it paid with.
 *
 * A credit with no invoice behind it - an overpayment - is paid back
 * without a note: nothing was invoiced, so nothing is corrected.
 */
class Refunds
{
    public const PAYOUTS = [Refund::TO_CREDIT, 'bank_transfer', 'card'];

    public function __construct(
        private readonly PlatformInvoices $platform,
        private readonly Receivables $receivables,
        private readonly Credits $credits,
        private readonly PaymentGateway $gateway,
        private readonly AdjustmentDraft $adjustments,
        private readonly AuditService $audit,
    ) {}

    /**
     * Credit part or all of an invoice, and settle what that leaves paid
     * beyond what is owed.
     *
     * @param  string  $net  the amount credited before VAT
     * @param  string  $payout  credit, bank_transfer or card: where what was over goes
     *
     * @throws DomainException saying why nothing was credited
     */
    public function refund(Receivable $receivable, string $net, string $reason, string $reasonAr, string $payout, ?string $reference, ?string $actorId): Refund
    {
        if (! in_array($payout, self::PAYOUTS, true)) {
            throw new DomainException(__('A refund is kept as credit, or paid back by bank transfer or to the card.'));
        }

        if (! is_numeric($net) || (float) $net <= 0 || preg_match('/^\d+(\.\d{1,2})?$/', trim($net)) !== 1) {
            throw new DomainException(__('The amount to credit is a positive number with at most two decimals, before VAT.'));
        }

        $invoice = $receivable->invoice() ?? throw new DomainException(__('The invoice is not there to credit.'));
        $line = $invoice->lines->first() ?? throw new DomainException(__('The invoice is not there to credit.'));
        $rate = (string) (float) $line->tax_rate;
        $seller = $this->platform->readySeller();

        // The note is the invoice's own kind, currency and rate, naming it
        // (BT-25) and saying why (KSA-10), with one line for what is
        // credited: a refund of an agreed amount, not of a line's units.
        $draft = [
            ...$this->adjustments->fields($invoice, DocumentType::CreditNote),
            'issue_date' => CarbonImmutable::now()->toDateString(),
            'payment_means_code' => (string) ($invoice->payment_means_code ?: '30'),
            'adjustment_reason' => $reasonAr.' / '.$reason,
            'discount_amount' => null,
            'lines' => [[
                'description' => 'Refund: '.$receivable->description,
                'description_ar' => 'استرداد: '.$line->description_ar,
                'quantity' => '1',
                'unit_code' => 'C62',
                'unit_price' => number_format((float) $net, 2, '.', ''),
                'tax_rate' => $rate,
                'tax_category' => (string) ($line->tax_category ?: 'S'),
            ]],
        ];
        unset($draft['charges']);

        $gross = Receivable::money((float) $net * (1 + (float) $rate / 100));

        if (round((float) $gross - ((float) $receivable->total - (float) $receivable->amount_credited - (float) $receivable->amount_written_off), 2) > 0) {
            throw new DomainException(__('That is more than is left to credit on this invoice.'));
        }

        $note = $this->platform->issue($draft, $seller);
        $excess = $this->receivables->credit($receivable, (string) $note->total);

        return DB::transaction(function () use ($receivable, $note, $excess, $payout, $reason, $reference, $actorId): Refund {
            $paidOut = '0.00';
            $method = Refund::TO_CREDIT;
            $gatewayReference = null;

            if ((float) $excess > 0 && $payout === 'card' && ($card = $this->cardPaymentFor($receivable)) !== null) {
                $gatewayReference = $this->toCard($card, $excess, $this->receivables->number($receivable));
                [$paidOut, $method] = $gatewayReference === null ? ['0.00', Refund::TO_CREDIT] : [$excess, 'card'];
            } elseif ((float) $excess > 0 && $payout === 'bank_transfer') {
                [$paidOut, $method] = [$excess, 'bank_transfer'];
            }

            // Kept, or not paid back to a card that refused: the customer's.
            if ((float) $excess > 0 && $method === Refund::TO_CREDIT) {
                $this->credits->grant((string) $receivable->org_id, (string) $receivable->currency, $excess, BillingCredit::CREDIT_NOTE,
                    (string) $note->id, __('Credit note :number', ['number' => (string) $note->invoice_number]));
            }

            return $this->record([
                'org_id' => $receivable->org_id,
                'receivable_id' => $receivable->id,
                'credit_note_id' => $note->id,
                'currency' => $receivable->currency,
                'credited_amount' => (string) $note->total,
                'payout_amount' => $paidOut,
                'payout_method' => $method,
                'reference' => $reference,
                'gateway_reference' => $gatewayReference,
                'reason' => $reason,
            ], $actorId);
        });
    }

    /**
     * Pay back what is left of a credit: by bank transfer, or - for an
     * overpayment by card - to that card.
     *
     * @throws DomainException when it is more than is left, or the card refuses
     */
    public function payOutCredit(BillingCredit $credit, string $amount, string $method, ?string $reference, string $reason, ?string $actorId): Refund
    {
        if (! in_array($method, ['bank_transfer', 'card'], true)) {
            throw new DomainException(__('A credit is paid back by bank transfer or to the card.'));
        }

        return DB::transaction(function () use ($credit, $amount, $method, $reference, $reason, $actorId): Refund {
            $locked = BillingCredit::withoutTenantScope(fn () => BillingCredit::query()->lockForUpdate()->findOrFail($credit->id));
            $amount = Receivable::money((float) $amount);

            if ((float) $amount <= 0 || bccomp($amount, $this->credits->left($locked), 2) > 0) {
                throw new DomainException(__('That is more than is left of the credit.'));
            }

            $gatewayReference = null;

            if ($method === 'card') {
                $card = $locked->source === BillingCredit::OVERPAYMENT && $locked->source_id !== null
                    ? Payment::withoutTenantScope(fn () => Payment::query()->where('channel', PaymentChannel::Gateway->value)->find($locked->source_id))
                    : null;

                $gatewayReference = $card === null ? null : $this->toCard($card, $amount, (string) $card->reference);

                if ($gatewayReference === null) {
                    throw new DomainException(__('This credit cannot be paid back to a card. Pay it back by bank transfer.'));
                }
            }

            return $this->record([
                'org_id' => $locked->org_id,
                'credit_id' => $locked->id,
                'currency' => $locked->currency,
                'credited_amount' => '0.00',
                'payout_amount' => $amount,
                'payout_method' => $method,
                'reference' => $reference,
                'gateway_reference' => $gatewayReference,
                'reason' => $reason,
            ], $actorId);
        });
    }

    /** The card payment that settled an invoice, the latest first. */
    private function cardPaymentFor(Receivable $receivable): ?Payment
    {
        $paymentIds = PaymentAllocation::withoutTenantScope(fn () => PaymentAllocation::query()->where('receivable_id', $receivable->id)->whereNotNull('payment_id')->pluck('payment_id'));

        return Payment::withoutTenantScope(fn () => Payment::query()
            ->whereKey($paymentIds->all())
            ->where('method', PaymentMethod::Card->value)
            ->where('channel', PaymentChannel::Gateway->value)
            ->latest('paid_at')
            ->first());
    }

    /**
     * Back to the card: the gateway's refund id, or null when it refused.
     */
    private function toCard(Payment $payment, string $amount, string $reference): ?string
    {
        try {
            $refund = $this->gateway->refund((string) $payment->gateway_reference, $amount, (string) $payment->currency, $reference);
        } catch (PaymentGatewayException $e) {
            report($e);

            return null;
        }

        return $refund->refunded ? $refund->refundId : null;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function record(array $fields, ?string $actorId): Refund
    {
        $refund = new Refund([...$fields, 'reason' => mb_substr((string) $fields['reason'], 0, 500), 'refunded_on' => CarbonImmutable::now()->toDateString()]);

        if ($actorId !== null) {
            $refund->forceFill(['created_by' => $actorId]);
        }

        Refund::withoutTenantScope(fn () => $refund->save());

        $this->audit->logFor((string) $refund->org_id, 'finance.refund.recorded', $refund, [
            'currency' => $refund->currency,
            'credited' => (string) $refund->credited_amount,
            'paid_out' => (string) $refund->payout_amount,
            'payout_method' => $refund->payout_method,
            'reason' => $refund->reason,
        ]);

        return $refund;
    }
}
