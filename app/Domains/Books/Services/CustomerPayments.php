<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\BranchScope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What customers paid against the organization's issued invoices: recorded
 * - all of what is owed, or part of it - and reversed when recorded in
 * error, never edited or deleted.
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
     * @throws DomainException
     */
    public function record(Invoice $invoice, string $amount, CarbonImmutable $paidOn, PaymentMethod $method, ?string $reference = null, ?string $notes = null): InvoicePayment
    {
        if (! is_numeric($amount) || round((float) $amount, 2) <= 0) {
            throw new DomainException(__('The amount paid is more than zero.'));
        }

        if ($paidOn->lt(CarbonImmutable::parse($invoice->issue_date)->startOfDay())) {
            throw new DomainException(__('A payment cannot be dated before the invoice, :date.', ['date' => CarbonImmutable::parse($invoice->issue_date)->toDateString()]));
        }

        return DB::transaction(function () use ($invoice, $amount, $paidOn, $method, $reference, $notes): InvoicePayment {
            Invoice::withoutTenantScope(fn () => Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first());
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
            ]);

            $this->badDebts->recover($invoice, $paying - max(0.0, $balance), $paidOn);

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
     * @throws DomainException
     */
    public function reverse(InvoicePayment $payment, string $reason): InvoicePayment
    {
        if ($payment->isReversal()) {
            throw new DomainException(__('A reversal cannot itself be reversed.'));
        }

        return DB::transaction(function () use ($payment, $reason): InvoicePayment {
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
            ]);

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

    private function optional(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
