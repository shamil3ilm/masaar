<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Models\CustomerReceipt;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Books\Models\VoucherRedemption;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\BranchScope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What customers paid against the organization's issued invoices: recorded
 * - all of what is owed, or part of it - and reversed when recorded in
 * error, never edited or deleted. And what is the customer's: a credit an
 * invoice or a credit note holds, paid back (refund()), and money received
 * on account before any invoice (receive()), later set against invoices
 * (allocate()) or paid back (refundReceipt()).
 *
 * The invoice row is locked while a payment is checked against what it
 * still owes, so two people recording the same payment at once cannot
 * take it below zero. The invoice itself is not touched: an issued
 * invoice never changes, and what is owed on it is read (Receivables).
 */
class CustomerPayments
{
    private const PAYABLE = [DocumentType::Invoice, DocumentType::Prepayment, DocumentType::DebitNote];

    public function __construct(
        private readonly Receivables $receivables,
        private readonly SalesBadDebts $badDebts,
        private readonly AuditService $audit,
        private readonly BranchScope $branches,
    ) {}

    /** An issued invoice of the organization's that can be paid, or null. */
    public function payable(string $organizationId, string $invoiceId): ?Invoice
    {
        // Only an invoice of a branch the person may see (BranchScope).
        return $this->branches->applyAllowed(Invoice::query())->where('org_id', $organizationId)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Rejected])
            ->whereIn('document_type', self::PAYABLE)
            ->find($invoiceId);
    }

    /** @return Collection<int, InvoicePayment> */
    public function of(Invoice $invoice): Collection
    {
        return InvoicePayment::query()->where('org_id', $invoice->org_id)->where('invoice_id', $invoice->id)
            ->orderBy('paid_on')->orderBy('created_at')->get();
    }

    public function find(string $organizationId, string $id): ?InvoicePayment
    {
        return InvoicePayment::query()->where('org_id', $organizationId)
            ->whereIn('invoice_id', $this->branches->applyAllowed(Invoice::query()->select('id'))->where('org_id', $organizationId))
            ->find($id);
    }

    /**
     * An issued document of the organization's a refund can be paid on: one
     * that can be paid, or a credit note.
     */
    public function document(string $organizationId, string $invoiceId): ?Invoice
    {
        return $this->branches->applyAllowed(Invoice::query())->where('org_id', $organizationId)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Rejected])
            ->whereIn('document_type', [...self::PAYABLE, DocumentType::CreditNote])
            ->find($invoiceId);
    }

    /**
     * @throws DomainException
     */
    public function record(Invoice $invoice, string $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference = null, ?string $notes = null, ?CustomerReceipt $receipt = null): InvoicePayment
    {
        if (! is_numeric($amount) || round((float) $amount, 2) <= 0) {
            throw new DomainException(__('The amount paid is more than zero.'));
        }

        if ($paidOn->lt(CarbonImmutable::parse($invoice->issue_date)->startOfDay())) {
            throw new DomainException(__('A payment cannot be dated before the invoice, :date.', ['date' => CarbonImmutable::parse($invoice->issue_date)->toDateString()]));
        }

        return DB::transaction(function () use ($invoice, $amount, $paidOn, $method, $reference, $notes, $receipt): InvoicePayment {
            $this->lock((string) $invoice->id);
            // What is owed, and what was written off as a bad debt: paid
            // after all, the tax on it is due again (SalesBadDebts::recover).
            $balance = $this->receivables->outstanding($invoice);
            $written = $this->badDebts->active($invoice);
            $owed = $balance + $written;
            $paying = round((float) $amount, 2);

            if ($paying > $owed + 0.005) {
                throw new DomainException(__('This invoice owes :owed :currency; a payment of :amount is more than that.', [
                    'owed' => number_format($owed, 2), 'currency' => $invoice->currency, 'amount' => number_format($paying, 2),
                ]));
            }

            $payment = InvoicePayment::query()->create([
                'org_id' => (string) $invoice->org_id,
                'invoice_id' => (string) $invoice->id,
                'amount' => number_format($paying, 2, '.', ''),
                'currency' => (string) $invoice->currency,
                'paid_on' => $paidOn->toDateString(),
                'method' => $method,
                'reference' => $this->optional($reference, 100),
                'notes' => $this->optional($notes, 500),
                'receipt_id' => $receipt?->id,
            ]);

            $this->badDebts->recover($invoice, $paying - max(0.0, $balance), $paidOn, $payment);

            $this->audit->logChange('books.payment.recorded', 'InvoicePayment', (string) $payment->id, null,
                ['invoice_id' => (string) $invoice->id, 'amount' => (string) $payment->amount, 'paid_on' => $paidOn->toDateString(), 'method' => $method->value],
                [], null, (string) $invoice->org_id);

            return $payment;
        });
    }

    /**
     * Take back a payment recorded in error: a row for the same amount below
     * zero, dated the day it is taken back, naming the one it reverses.
     *
     * What the payment set off is taken back with it: the bad-debt
     * recovery it made (SalesBadDebts::undoRecovery), and a voucher's use -
     * a redemption below zero gives the voucher its value back.
     *
     * @throws DomainException
     */
    public function reverse(InvoicePayment $payment, string $reason): InvoicePayment
    {
        if ($payment->isReversal()) {
            throw new DomainException(__('A reversal cannot itself be reversed.'));
        }

        return DB::transaction(function () use ($payment, $reason): InvoicePayment {
            $invoice = $this->lock((string) $payment->invoice_id);
            $taken = InvoicePayment::query()->where('reverses_id', $payment->id)->lockForUpdate()->exists();

            if ($taken) {
                throw new DomainException(__('This payment is already reversed.'));
            }

            $reversal = InvoicePayment::query()->create([
                'org_id' => (string) $payment->org_id,
                'invoice_id' => (string) $payment->invoice_id,
                'amount' => number_format(-(float) $payment->amount, 2, '.', ''),
                'currency' => $payment->currency,
                'paid_on' => now()->toDateString(),
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $this->optional($reason, 500),
                'reverses_id' => (string) $payment->id,
                // Paid from money on account, the money goes back on account.
                'receipt_id' => $payment->receipt_id,
            ]);

            if ($invoice !== null) {
                $this->badDebts->undoRecovery($invoice, $payment, $reversal);
            }

            $redemption = VoucherRedemption::query()->where('invoice_payment_id', $payment->id)->first();

            if ($redemption !== null) {
                VoucherRedemption::query()->create([
                    'voucher_id' => $redemption->voucher_id,
                    'invoice_id' => $redemption->invoice_id,
                    'invoice_payment_id' => (string) $reversal->id,
                    'amount' => bcmul((string) $redemption->amount, '-1', 2),
                    'redeemed_on' => $reversal->paid_on->toDateString(),
                ]);
            }

            $this->audit->logChange('books.payment.reversed', 'InvoicePayment', (string) $payment->id, null,
                ['reversal_id' => (string) $reversal->id, 'reason' => trim($reason)], [], null, (string) $payment->org_id);

            return $reversal;
        });
    }

    /** Whether a payment has been taken back. */
    public function reversed(InvoicePayment $payment): bool
    {
        return InvoicePayment::query()->where('reverses_id', $payment->id)->exists();
    }

    /**
     * Pay back a credit the document holds - an invoice paid and then
     * credited, or a credit note naming no invoice found - up to that
     * credit: a row below zero on it, reversing nothing.
     *
     * @throws DomainException
     */
    public function refund(Invoice $document, string $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference = null, ?string $notes = null): InvoicePayment
    {
        $refunding = $this->positive($amount, __('The amount paid back is more than zero.'));

        if ($paidOn->lt(CarbonImmutable::parse($document->issue_date)->startOfDay())) {
            throw new DomainException(__('A refund cannot be dated before the document, :date.', ['date' => CarbonImmutable::parse($document->issue_date)->toDateString()]));
        }

        return DB::transaction(function () use ($document, $refunding, $paidOn, $method, $reference, $notes): InvoicePayment {
            $this->lock((string) $document->id);
            $credit = round(-$this->receivables->outstanding($document), 2);

            if ($refunding > $credit + 0.005) {
                throw new DomainException($credit < 0.005
                    ? __(':number holds no credit to pay back.', ['number' => $document->invoice_number])
                    : __(':number holds :credit :currency of credit; a refund of :amount is more than that.', [
                        'number' => $document->invoice_number, 'credit' => number_format($credit, 2), 'currency' => $document->currency, 'amount' => number_format($refunding, 2),
                    ]));
            }

            $refund = InvoicePayment::query()->create([
                'org_id' => (string) $document->org_id,
                'invoice_id' => (string) $document->id,
                'amount' => number_format(-$refunding, 2, '.', ''),
                'currency' => (string) $document->currency,
                'paid_on' => $paidOn->toDateString(),
                'method' => $method,
                'reference' => $this->optional($reference, 100),
                'notes' => $this->optional($notes, 500),
            ]);

            $this->audit->logChange('books.refund.recorded', 'InvoicePayment', (string) $refund->id, null,
                ['invoice_id' => (string) $document->id, 'amount' => (string) $refund->amount, 'paid_on' => $paidOn->toDateString(), 'method' => $method->value],
                [], null, (string) $document->org_id);

            return $refund;
        });
    }

    /**
     * Money a customer paid on account, with no invoice yet: set against
     * invoices later (allocate()), or paid back (refundReceipt()).
     *
     * @throws DomainException
     */
    public function receive(string $organizationId, Customer $customer, string $amount, string $currency, CarbonImmutable $receivedOn, PaymentMethod $method, ?string $reference = null, ?string $notes = null, ?string $branchId = null): CustomerReceipt
    {
        $receiving = $this->positive($amount, __('The amount received is more than zero.'));

        if ((string) $customer->org_id !== $organizationId) {
            throw new DomainException(__('Choose a customer of this organization.'));
        }

        $receipt = CustomerReceipt::query()->create([
            'org_id' => $organizationId,
            'branch_id' => $branchId,
            'customer_id' => (string) $customer->id,
            'kind' => CustomerReceipt::RECEIPT,
            'amount' => number_format($receiving, 2, '.', ''),
            'currency' => strtoupper($currency),
            'received_on' => $receivedOn->toDateString(),
            'method' => $method,
            'reference' => $this->optional($reference, 100),
            'notes' => $this->optional($notes, 500),
        ]);

        $this->audit->logChange('books.receipt.recorded', 'CustomerReceipt', (string) $receipt->id, null,
            ['customer_id' => (string) $customer->id, 'amount' => (string) $receipt->amount, 'received_on' => $receivedOn->toDateString()], [], null, $organizationId);

        return $receipt;
    }

    /**
     * Pay an invoice of the same customer, in the same currency, from what is
     * left of money on account: recorded as the invoice's payment naming the
     * receipt, so what the invoice owes caps it as any payment's.
     *
     * @throws DomainException
     */
    public function allocate(CustomerReceipt $receipt, Invoice $invoice, string $amount, CarbonImmutable $paidOn): InvoicePayment
    {
        $paying = $this->positive($amount, __('The amount paid is more than zero.'));

        if ((string) $invoice->customer_id !== (string) $receipt->customer_id || $invoice->currency !== $receipt->currency) {
            throw new DomainException(__('Money on account pays only an invoice of the same customer, in :currency.', ['currency' => $receipt->currency]));
        }

        if ($paidOn->lt(CarbonImmutable::parse($receipt->received_on)->startOfDay())) {
            throw new DomainException(__('Money cannot be used before it was received, :date.', ['date' => $receipt->received_on->toDateString()]));
        }

        return DB::transaction(function () use ($receipt, $invoice, $paying, $paidOn): InvoicePayment {
            CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first();
            $left = $this->unallocated($receipt);

            if ($paying > $left + 0.005) {
                throw new DomainException(__('Only :left :currency is left on account from this receipt.', ['left' => number_format($left, 2), 'currency' => $receipt->currency]));
            }

            return $this->record($invoice, number_format($paying, 2, '.', ''), $paidOn, $receipt->method, $receipt->reference,
                __('Paid from money on account received :date', ['date' => $receipt->received_on->toDateString()]), $receipt);
        });
    }

    /**
     * Pay back what is left of money on account.
     *
     * @throws DomainException
     */
    public function refundReceipt(CustomerReceipt $receipt, string $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference = null): CustomerReceipt
    {
        $refunding = $this->positive($amount, __('The amount paid back is more than zero.'));

        if ($paidOn->lt(CarbonImmutable::parse($receipt->received_on)->startOfDay())) {
            throw new DomainException(__('A refund cannot be dated before the money was received, :date.', ['date' => $receipt->received_on->toDateString()]));
        }

        return DB::transaction(function () use ($receipt, $refunding, $paidOn, $method, $reference): CustomerReceipt {
            CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->first();
            $left = $this->unallocated($receipt);

            if ($refunding > $left + 0.005) {
                throw new DomainException(__('Only :left :currency is left on account from this receipt.', ['left' => number_format($left, 2), 'currency' => $receipt->currency]));
            }

            $refund = $this->receiptRow($receipt, CustomerReceipt::REFUND, -$refunding, $paidOn, $method, $reference, null, null);

            $this->audit->logChange('books.receipt.refunded', 'CustomerReceipt', (string) $receipt->id, null,
                ['refund_id' => (string) $refund->id, 'amount' => (string) $refund->amount, 'paid_on' => $paidOn->toDateString()], [], null, (string) $receipt->org_id);

            return $refund;
        });
    }

    /**
     * Take back a receipt or a refund recorded in error, today: a receipt
     * only once nothing is set against it or paid back from it - those are
     * reversed first.
     *
     * @throws DomainException
     */
    public function reverseReceipt(CustomerReceipt $row, string $reason): CustomerReceipt
    {
        if ($row->kind === CustomerReceipt::REVERSAL) {
            throw new DomainException(__('A reversal cannot itself be reversed.'));
        }

        return DB::transaction(function () use ($row, $reason): CustomerReceipt {
            $root = $row->kind === CustomerReceipt::RECEIPT ? $row : CustomerReceipt::query()->whereKey($row->receipt_id)->firstOrFail();
            CustomerReceipt::query()->whereKey($root->id)->lockForUpdate()->first();

            if (CustomerReceipt::query()->where('reverses_id', $row->id)->exists()) {
                throw new DomainException(__('This is already reversed.'));
            }

            if ($row->kind === CustomerReceipt::RECEIPT && abs($this->unallocated($row) - (float) $row->amount) >= 0.005) {
                throw new DomainException(__('Reverse the payments made from this money, and its refunds, first.'));
            }

            $reversal = $this->receiptRow($root, CustomerReceipt::REVERSAL, -(float) $row->amount, CarbonImmutable::today(), $row->method, $row->reference, $reason, (string) $row->id);

            $this->audit->logChange('books.receipt.reversed', 'CustomerReceipt', (string) $row->id, null,
                ['reversal_id' => (string) $reversal->id, 'reason' => trim($reason)], [], null, (string) $row->org_id);

            return $reversal;
        });
    }

    /**
     * What is left of money on account as at a day (today when none): what
     * came in, less its refunds and what paid invoices, reversals counted.
     */
    public function unallocated(CustomerReceipt $receipt, ?CarbonImmutable $asAt = null): float
    {
        $until = ($asAt ?? CarbonImmutable::now()->addYears(50))->addDay()->toDateString();
        $held = (float) CustomerReceipt::query()->where('org_id', $receipt->org_id)
            ->where(fn ($query) => $query->whereKey($receipt->id)->orWhere('receipt_id', $receipt->id))
            ->where('received_on', '<', $until)->sum('amount');
        $used = (float) InvoicePayment::query()->where('org_id', $receipt->org_id)->where('receipt_id', $receipt->id)
            ->where('paid_on', '<', $until)->sum('amount');

        return round($held - $used, 2) + 0.0;
    }

    /**
     * A customer's money on account, oldest first, each with what is left
     * and the refunds and reversals under it.
     *
     * @return Collection<int, CustomerReceipt>
     */
    public function receipts(string $organizationId, string $customerId): Collection
    {
        $rows = $this->branches->applyAllowed(CustomerReceipt::query())->where('org_id', $organizationId)->where('customer_id', $customerId)
            ->orderBy('received_on')->orderBy('created_at')->get();
        $reversed = $rows->pluck('reverses_id')->filter()->map(fn ($id): string => (string) $id)->all();

        return $rows->where('kind', CustomerReceipt::RECEIPT)->values()->each(function (CustomerReceipt $receipt) use ($rows, $reversed): void {
            $receipt->setAttribute('left', $this->unallocated($receipt));
            $receipt->setAttribute('reversed', in_array((string) $receipt->id, $reversed, true));
            $receipt->setRelation('entries', $rows->where('receipt_id', $receipt->id)->values()
                ->each(fn (CustomerReceipt $entry) => $entry->setAttribute('reversed', in_array((string) $entry->id, $reversed, true))));
        });
    }

    /** A row of money on account of a branch the person may see, or null. */
    public function findReceipt(string $organizationId, string $id): ?CustomerReceipt
    {
        return $this->branches->applyAllowed(CustomerReceipt::query())->where('org_id', $organizationId)->find($id);
    }

    /** @throws DomainException */
    private function positive(string $amount, string $message): float
    {
        if (! is_numeric($amount) || round((float) $amount, 2) <= 0) {
            throw new DomainException($message);
        }

        return round((float) $amount, 2);
    }

    private function receiptRow(CustomerReceipt $receipt, string $kind, float $amount, CarbonImmutable $on, PaymentMethod $method, ?string $reference, ?string $notes, ?string $reverses): CustomerReceipt
    {
        return CustomerReceipt::query()->create([
            'org_id' => (string) $receipt->org_id,
            'branch_id' => $receipt->branch_id,
            'customer_id' => (string) $receipt->customer_id,
            'kind' => $kind,
            'receipt_id' => (string) $receipt->id,
            'reverses_id' => $reverses,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => (string) $receipt->currency,
            'received_on' => $on->toDateString(),
            'method' => $method,
            'reference' => $this->optional($reference, 100),
            'notes' => $this->optional($notes, 500),
        ]);
    }

    /** The invoice row, locked while what is owed on it is checked and changed. */
    private function lock(string $invoiceId): ?Invoice
    {
        return Invoice::acrossTenants()->whereKey($invoiceId)->lockForUpdate()->first();
    }

    private function optional(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
