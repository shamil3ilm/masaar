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
use App\Domains\Billing\Models\RefundIntent;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Services\AdjustmentDraft;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 *
 * A refund against an invoice is all of a piece. Before anything is issued,
 * what it credits is held against the invoice under a lock (a RefundIntent,
 * reserved), so a second click cannot credit the same money twice. The note
 * is issued - and filed, which cannot be undone - and then the invoice is
 * credited and what that leaves over kept or paid back by transfer, in one
 * transaction. Money back to a card is never sent inside a transaction: the
 * intent says it is being paid first, the gateway is asked, and what it
 * said is recorded after - under the intent's id as the refund's reference,
 * so a retry gets the same refund, never a second. Whatever step fails, the
 * intent keeps where it stopped, and complete() (finishOpen(), on a
 * schedule) carries it on.
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
        $intent = $this->reserve($receivable, $gross, $payout, $reference, $reason, $actorId);

        try {
            $note = $this->platform->issue($draft, $seller);
        } catch (Throwable $e) {
            $intent->forceFill(['status' => RefundIntent::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }

        $intent->forceFill(['status' => RefundIntent::NOTED, 'credit_note_id' => $note->id])->save();

        return $this->complete($intent);
    }

    /**
     * Carry a refund on from where it stopped, once however often it is
     * called: credit the invoice for its issued note and settle what that
     * leaves paid beyond what is owed; for the card, send it back - outside
     * any transaction - and record what the gateway said.
     *
     * @throws DomainException when it was never issued, or a credit's card refused
     */
    public function complete(RefundIntent $intent): Refund
    {
        $intent = $this->creditNote($intent);

        if ($intent->status === RefundIntent::PAYING) {
            $intent = $this->settlePayout($intent, $this->toCard($intent));
        }

        return Refund::withoutTenantScope(fn () => Refund::query()->findOrFail($intent->refund_id));
    }

    /** How long a hold may wait for its credit note before a crash is assumed. */
    private const HOLD_SAFE_MINUTES = 15;

    /**
     * Finish the refunds a failure left half done: a note issued and not
     * yet credited, or money sent to a card and not yet recorded. Those
     * younger than a minute may still be on their way, and are left.
     *
     * A hold whose note was never heard of (reserved) is left for
     * HOLD_SAFE_MINUTES - issuing takes seconds - then settled: the note it
     * issued before a crash is found and the refund finished with it, or,
     * none issued, the hold is released. Either way on the audit record.
     *
     * @return int how many were finished or released
     */
    public function finishOpen(): int
    {
        $finished = 0;

        foreach (RefundIntent::withoutTenantScope(fn () => RefundIntent::query()
            ->where('status', RefundIntent::RESERVED)
            ->where('updated_at', '<', now()->subMinutes(self::HOLD_SAFE_MINUTES))
            ->orderBy('created_at')
            ->get()) as $intent) {
            try {
                $this->settleHold($intent);
                $finished++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach (RefundIntent::withoutTenantScope(fn () => RefundIntent::query()
            ->whereIn('status', RefundIntent::OPEN)
            ->where('updated_at', '<', now()->subMinute())
            ->orderBy('created_at')
            ->get()) as $intent) {
            try {
                $this->complete($intent);
                $finished++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $finished;
    }

    /**
     * A hold a crash left: finished with the note it issued, if one was, or
     * released.
     */
    private function settleHold(RefundIntent $intent): void
    {
        $receivable = Receivable::withoutTenantScope(fn () => Receivable::query()->findOrFail($intent->receivable_id));
        $claimed = [
            ...Refund::withoutTenantScope(fn () => Refund::query()->whereNotNull('credit_note_id')->pluck('credit_note_id'))->map(fn ($id): string => (string) $id)->all(),
            ...RefundIntent::withoutTenantScope(fn () => RefundIntent::query()->whereNotNull('credit_note_id')->pluck('credit_note_id'))->map(fn ($id): string => (string) $id)->all(),
        ];
        $note = $this->platform->issuedNoteFor((string) $receivable->invoice_id, (string) $intent->credited_amount, $intent->created_at, $claimed);

        $settled = DB::transaction(function () use ($intent, $note): ?RefundIntent {
            $locked = RefundIntent::withoutTenantScope(fn () => RefundIntent::query()->lockForUpdate()->findOrFail($intent->id));

            if ($locked->status !== RefundIntent::RESERVED) {
                return null;
            }

            $locked->forceFill($note === null
                ? ['status' => RefundIntent::FAILED, 'error' => 'Released: no credit note was issued for it.']
                : ['status' => RefundIntent::NOTED, 'credit_note_id' => $note->id])->save();

            $this->audit->logFor((string) $locked->org_id, $note === null ? 'finance.refund.released' : 'finance.refund.resumed', $locked, [
                'credited' => (string) $locked->credited_amount, 'currency' => $locked->currency, 'credit_note' => $note?->invoice_number,
            ]);

            return $locked;
        });

        if ($settled !== null && $note !== null) {
            $this->complete($settled);
        }
    }

    /**
     * Pay back what is left of a credit: by bank transfer, or - for an
     * overpayment by card - to that card.
     *
     * The card is asked outside any transaction: what is sent is first held
     * against the credit (a RefundIntent, paying), so nothing else takes it
     * meanwhile, and the outcome is recorded after.
     *
     * @throws DomainException when it is more than is left, or the card refuses
     */
    public function payOutCredit(BillingCredit $credit, string $amount, string $method, ?string $reference, string $reason, ?string $actorId): Refund
    {
        if (! in_array($method, ['bank_transfer', 'card'], true)) {
            throw new DomainException(__('A credit is paid back by bank transfer or to the card.'));
        }

        $held = DB::transaction(function () use ($credit, $amount, $method, $reference, $reason, $actorId): Refund|RefundIntent {
            $locked = BillingCredit::withoutTenantScope(fn () => BillingCredit::query()->lockForUpdate()->findOrFail($credit->id));
            $amount = Receivable::money((float) $amount);

            if ((float) $amount <= 0 || bccomp($amount, $this->credits->left($locked), 2) > 0) {
                throw new DomainException(__('That is more than is left of the credit.'));
            }

            if ($method === 'bank_transfer') {
                return $this->record([
                    'org_id' => $locked->org_id,
                    'credit_id' => $locked->id,
                    'currency' => $locked->currency,
                    'credited_amount' => '0.00',
                    'payout_amount' => $amount,
                    'payout_method' => $method,
                    'reference' => $reference,
                    'reason' => $reason,
                ], $actorId);
            }

            $card = $locked->source === BillingCredit::OVERPAYMENT && $locked->source_id !== null
                ? Payment::withoutTenantScope(fn () => Payment::query()->where('channel', PaymentChannel::Gateway->value)->find($locked->source_id))
                : null;

            if ($card === null) {
                throw new DomainException(__('This credit cannot be paid back to a card. Pay it back by bank transfer.'));
            }

            $intent = new RefundIntent([
                'org_id' => $locked->org_id,
                'credit_id' => $locked->id,
                'currency' => $locked->currency,
                'payout_method' => 'card',
                'payout_amount' => $amount,
                'payment_id' => $card->id,
                'reference' => $reference,
                'reason' => mb_substr($reason, 0, 500),
                'status' => RefundIntent::PAYING,
            ]);
            $intent->forceFill(['created_by' => $actorId]);
            RefundIntent::withoutTenantScope(fn () => $intent->save());

            return $intent;
        });

        return $held instanceof Refund ? $held : $this->complete($held);
    }

    /**
     * The issued note credited to its invoice, in one transaction with
     * what follows from it: what is left over kept to the customer's
     * credit, or recorded as paid back by transfer - or, for the card, held
     * as paying, to be sent once this is committed.
     */
    private function creditNote(RefundIntent $intent): RefundIntent
    {
        return DB::transaction(function () use ($intent): RefundIntent {
            $locked = RefundIntent::withoutTenantScope(fn () => RefundIntent::query()->lockForUpdate()->findOrFail($intent->id));

            if (in_array($locked->status, [RefundIntent::PAYING, RefundIntent::DONE], true)) {
                return $locked;
            }

            if ($locked->status !== RefundIntent::NOTED) {
                throw new DomainException(__('This refund was not issued, so there is nothing to finish.'));
            }

            $receivable = Receivable::withoutTenantScope(fn () => Receivable::query()->findOrFail($locked->receivable_id));
            $note = $this->platform->find((string) $locked->credit_note_id) ?? throw new DomainException(__('This refund was not issued, so there is nothing to finish.'));
            $excess = $this->receivables->credit($receivable, (string) $note->total);
            $locked->credited_amount = (string) $note->total;

            if ((float) $excess > 0 && $locked->payout_method === 'card' && ($card = $this->cardPaymentFor($receivable)) !== null) {
                $locked->forceFill(['status' => RefundIntent::PAYING, 'payout_amount' => $excess, 'payment_id' => $card->id])->save();

                return $locked;
            }

            $paidOut = (float) $excess > 0 && $locked->payout_method === 'bank_transfer' ? $excess : '0.00';

            // Paid back by transfer, or kept: the customer's credit.
            return (float) $paidOut > 0
                ? $this->finish($locked, $paidOut, 'bank_transfer', null, '0.00')
                : $this->finish($locked, '0.00', Refund::TO_CREDIT, null, $excess);
        });
    }

    /**
     * What the card said, recorded once: sent back, or - refused - kept to
     * the customer's credit for a note's leftover; a credit's payout the
     * card refused is not made.
     *
     * @throws DomainException when a credit's card refused
     */
    private function settlePayout(RefundIntent $intent, ?string $gatewayReference): RefundIntent
    {
        $refused = false;

        $settled = DB::transaction(function () use ($intent, $gatewayReference, &$refused): RefundIntent {
            $locked = RefundIntent::withoutTenantScope(fn () => RefundIntent::query()->lockForUpdate()->findOrFail($intent->id));

            if ($locked->status !== RefundIntent::PAYING) {
                return $locked;
            }

            if ($gatewayReference !== null) {
                return $this->finish($locked, (string) $locked->payout_amount, 'card', $gatewayReference, '0.00');
            }

            if ($locked->credit_id !== null) {
                $refused = true;
                $locked->forceFill(['status' => RefundIntent::FAILED, 'error' => 'The card refused the refund.'])->save();

                return $locked;
            }

            return $this->finish($locked, '0.00', Refund::TO_CREDIT, null, (string) $locked->payout_amount);
        });

        if ($refused) {
            throw new DomainException(__('This credit cannot be paid back to a card. Pay it back by bank transfer.'));
        }

        return $settled;
    }

    /**
     * The refund recorded and the intent done, inside the caller's
     * transaction; what is kept is granted to the customer's credit.
     */
    private function finish(RefundIntent $intent, string $paidOut, string $method, ?string $gatewayReference, string $kept): RefundIntent
    {
        $note = $intent->credit_note_id === null ? null : $this->platform->find((string) $intent->credit_note_id);

        if ((float) $kept > 0 && $note !== null) {
            $this->credits->grant((string) $intent->org_id, (string) $intent->currency, $kept, BillingCredit::CREDIT_NOTE,
                (string) $note->id, __('Credit note :number', ['number' => (string) $note->invoice_number]));
        }

        $refund = $this->record([
            'org_id' => $intent->org_id,
            'receivable_id' => $intent->receivable_id,
            'credit_note_id' => $intent->credit_note_id,
            'credit_id' => $intent->credit_id,
            'currency' => $intent->currency,
            'credited_amount' => (string) $intent->credited_amount,
            'payout_amount' => $paidOut,
            'payout_method' => $method,
            'reference' => $intent->reference,
            'gateway_reference' => $gatewayReference,
            'reason' => $intent->reason,
        ], $intent->created_by === null ? null : (string) $intent->created_by);

        $intent->forceFill(['status' => RefundIntent::DONE, 'refund_id' => $refund->id])->save();

        return $intent;
    }

    /**
     * Hold what a refund credits against the invoice before its note is
     * issued: under a lock on the invoice, no more than is left to credit
     * after the notes already issued and those still being issued.
     *
     * @throws DomainException when it is more than is left
     */
    private function reserve(Receivable $receivable, string $gross, string $payout, ?string $reference, string $reason, ?string $actorId): RefundIntent
    {
        return DB::transaction(function () use ($receivable, $gross, $payout, $reference, $reason, $actorId): RefundIntent {
            $locked = Receivable::withoutTenantScope(fn () => Receivable::query()->lockForUpdate()->findOrFail($receivable->id));
            $held = (float) RefundIntent::withoutTenantScope(fn () => RefundIntent::query()
                ->where('receivable_id', $locked->id)
                ->whereIn('status', [RefundIntent::RESERVED, RefundIntent::NOTED])
                ->sum('credited_amount'));

            if (round((float) $gross - ((float) $locked->total - (float) $locked->amount_credited - (float) $locked->amount_written_off - $held), 2) > 0) {
                throw new DomainException(__('That is more than is left to credit on this invoice.'));
            }

            $intent = new RefundIntent([
                'org_id' => $locked->org_id,
                'receivable_id' => $locked->id,
                'currency' => $locked->currency,
                'credited_amount' => $gross,
                'payout_method' => $payout,
                'reference' => $reference,
                'reason' => mb_substr($reason, 0, 500),
                'status' => RefundIntent::RESERVED,
            ]);
            $intent->forceFill(['created_by' => $actorId]);
            RefundIntent::withoutTenantScope(fn () => $intent->save());

            return $intent;
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
     * Back to the card, never inside a transaction: the gateway's refund
     * id, or null when it refused. The intent's id is the refund's
     * reference, which the gateway takes as its idempotency key, so asking
     * again after a failure gets the same refund, never a second one. A
     * failure to reach it at all is thrown, and the intent stays paying for
     * a retry (finishOpen).
     */
    private function toCard(RefundIntent $intent): ?string
    {
        $payment = Payment::withoutTenantScope(fn () => Payment::query()->findOrFail($intent->payment_id));

        try {
            $refund = $this->gateway->refund((string) $payment->gateway_reference, (string) $intent->payout_amount, (string) $payment->currency, (string) $intent->id);
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
