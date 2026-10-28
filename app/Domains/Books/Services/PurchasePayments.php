<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Books\Models\PurchaseInvoice;
use App\Domains\Books\Models\PurchasePayment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the organization paid its suppliers against purchase invoices:
 * recorded - all or part - and reversed when recorded in error, as customer
 * payments are (CustomerPayments). The purchase row is locked while a
 * payment is checked against what is still to pay.
 */
class PurchasePayments
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /** @return Collection<int, PurchasePayment> */
    public function of(PurchaseInvoice $purchase): Collection
    {
        return PurchasePayment::query()->where('org_id', $purchase->org_id)->where('purchase_invoice_id', $purchase->id)
            ->orderBy('paid_on')->orderBy('created_at')->get();
    }

    public function find(string $organizationId, string $id): ?PurchasePayment
    {
        return PurchasePayment::query()->where('org_id', $organizationId)->find($id);
    }

    /** What has been paid on it, in its currency, by a day (every day when null). */
    public function paid(PurchaseInvoice $purchase, ?CarbonImmutable $by = null): float
    {
        return round((float) PurchasePayment::acrossTenants()
            ->where('purchase_invoice_id', $purchase->id)
            ->when($by !== null, fn ($query) => $query->where('paid_on', '<', $by->addDay()->toDateString()))
            ->sum('amount'), 2);
    }

    /**
     * What is still to pay on it, in its currency: what it billed, less the
     * supplier's credit notes against it, less what was paid.
     */
    public function outstanding(PurchaseInvoice $purchase): float
    {
        $credited = (float) PurchaseInvoice::acrossTenants()
            ->where('org_id', $purchase->org_id)
            ->where('original_id', $purchase->id)
            ->where('document_type', PurchaseInvoice::CREDIT_NOTE)
            ->where('status', PurchaseInvoice::RECORDED)
            ->sum('gross');

        return round((float) $purchase->gross - $credited - $this->paid($purchase), 2);
    }

    /**
     * @throws DomainException
     */
    public function record(PurchaseInvoice $purchase, string $amount, CarbonImmutable $paidOn, ?string $reference = null): PurchasePayment
    {
        if ($purchase->isVoid() || $purchase->document_type !== PurchaseInvoice::INVOICE) {
            throw new DomainException(__('Only a recorded supplier invoice is paid.'));
        }

        if (! is_numeric($amount) || round((float) $amount, 2) <= 0) {
            throw new DomainException(__('The amount paid is more than zero.'));
        }

        return DB::transaction(function () use ($purchase, $amount, $paidOn, $reference): PurchasePayment {
            PurchaseInvoice::acrossTenants()->whereKey($purchase->id)->lockForUpdate()->first();
            $paying = round((float) $amount, 2);
            $owed = $this->outstanding($purchase);

            if ($paying > $owed + 0.005) {
                throw new DomainException(__('This purchase has :owed :currency left to pay; :amount is more than that.', [
                    'owed' => number_format($owed, 2), 'currency' => $purchase->currency, 'amount' => number_format($paying, 2),
                ]));
            }

            $payment = PurchasePayment::query()->create([
                'org_id' => (string) $purchase->org_id,
                'purchase_invoice_id' => (string) $purchase->id,
                'amount' => number_format($paying, 2, '.', ''),
                'paid_on' => $paidOn->toDateString(),
                'reference' => ($reference = trim((string) $reference)) === '' ? null : mb_substr($reference, 0, 100),
            ]);

            $this->audit->logChange('books.purchase.paid', 'PurchasePayment', (string) $payment->id, null,
                ['purchase_invoice_id' => (string) $purchase->id, 'amount' => (string) $payment->amount, 'paid_on' => $paidOn->toDateString()],
                [], null, (string) $purchase->org_id);

            return $payment;
        });
    }

    /**
     * @throws DomainException
     */
    public function reverse(PurchasePayment $payment): PurchasePayment
    {
        if ($payment->isReversal()) {
            throw new DomainException(__('A reversal cannot itself be reversed.'));
        }

        return DB::transaction(function () use ($payment): PurchasePayment {
            if (PurchasePayment::query()->where('reverses_id', $payment->id)->lockForUpdate()->exists()) {
                throw new DomainException(__('This payment is already reversed.'));
            }

            $reversal = PurchasePayment::query()->create([
                'org_id' => (string) $payment->org_id,
                'purchase_invoice_id' => (string) $payment->purchase_invoice_id,
                'amount' => number_format(-(float) $payment->amount, 2, '.', ''),
                'paid_on' => now()->toDateString(),
                'reference' => $payment->reference,
                'reverses_id' => (string) $payment->id,
            ]);

            $this->audit->logChange('books.purchase.payment_reversed', 'PurchasePayment', (string) $payment->id, null,
                ['reversal_id' => (string) $reversal->id], [], null, (string) $payment->org_id);

            return $reversal;
        });
    }
}
