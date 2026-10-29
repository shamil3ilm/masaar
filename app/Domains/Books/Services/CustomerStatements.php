<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\BillingReferences;
use App\Domains\Organization\Services\BranchScope;
use Carbon\CarbonImmutable;

/**
 * A customer's statement for a period, in one currency: what was owed at
 * the start, each invoice and debit note (debits), each credit note and
 * payment (credits; a reversed payment is a debit again), with the running
 * balance, and what is owed at the end.
 *
 * The customer's documents are the issued ones made out to it - an invoice
 * names its saved customer (invoices.customer_id) - and the payments on
 * them. The closing balance is what Receivables gives as at the last day.
 *
 * Of the branches the person sees (BranchScope), as Receivables is: for
 * someone who does not see every branch it is not the customer's whole
 * account, and partial() says so.
 */
class CustomerStatements
{
    private const OWED = [DocumentType::Invoice, DocumentType::Prepayment, DocumentType::DebitNote];

    public function __construct(
        private readonly Receivables $receivables,
        private readonly BranchScope $branches,
    ) {}

    /** Whether the statement leaves out branches the person does not see. */
    public function partial(): bool
    {
        return $this->branches->visible() !== null;
    }

    public function customer(string $organizationId, string $customerId): ?Customer
    {
        return Customer::query()->withTrashed()->where('org_id', $organizationId)->find($customerId);
    }

    /**
     * @return array{currency: string, currencies: list<string>, opening: float, closing: float, debits: float, credits: float, lines: list<array{date: string, kind: string, reference: string, debit: float, credit: float, balance: float}>}
     */
    public function for(string $organizationId, Customer $customer, CarbonImmutable $from, CarbonImmutable $to, ?string $currency = null): array
    {
        $documents = $this->branches->applyVisible(Invoice::acrossTenants())
            ->where('org_id', $organizationId)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Rejected])
            ->whereIn('document_type', [...self::OWED, DocumentType::CreditNote])
            ->where('issue_date', '<', $to->addDay()->toDateString())
            ->get();

        $currencies = $documents->pluck('currency')->map(fn ($code): string => (string) $code)->unique()->sort()->values()->all();
        $currency ??= $documents->countBy('currency')->sortDesc()->keys()->first() ?? '';
        $documents = $documents->where('currency', $currency);

        $entries = [];

        // The customer's invoices by number and id, to spread a note on
        // several (Receivables::spread), and which of them were settled at
        // the till (Receivables::settledAtSale).
        $named = [];

        foreach ($documents as $document) {
            if ($document->document_type !== DocumentType::CreditNote) {
                $named[(string) $document->invoice_number] = $document;
                $named[(string) $document->id] = $document;
            }
        }

        foreach ($documents as $document) {
            $note = $document->document_type === DocumentType::CreditNote;
            $amount = $note ? (float) $document->total : (float) $document->payable();
            $date = CarbonImmutable::parse($document->issue_date)->toDateString();
            $entries[] = [
                'date' => $date,
                'kind' => $note ? __('Credit note') : ($document->document_type === DocumentType::DebitNote ? __('Debit note') : __('Invoice')),
                'reference' => (string) $document->invoice_number,
                'debit' => $note ? 0.0 : $amount,
                'credit' => $note ? $amount : 0.0,
            ];

            // Paid at the till when it was issued, and refunded there: a note
            // for the share of it that falls on a till's sale.
            $atTill = $note ? $this->refundedAtTill($document, $named) : ($this->receivables->settledAtSale($document) ? $amount : 0.0);

            if ($atTill > 0) {
                $entries[] = [
                    'date' => $date,
                    'kind' => $note ? __('Refunded at the till') : __('Paid at the till'),
                    'reference' => (string) $document->invoice_number,
                    'debit' => $note ? $atTill : 0.0,
                    'credit' => $note ? 0.0 : $atTill,
                ];
            }
        }

        $payments = InvoicePayment::acrossTenants()
            ->where('org_id', $organizationId)
            ->whereIn('invoice_id', $documents->pluck('id'))
            ->where('paid_on', '<', $to->addDay()->toDateString())
            ->get();
        $numbers = $documents->pluck('invoice_number', 'id');

        foreach ($payments as $payment) {
            $amount = (float) $payment->amount;
            $entries[] = [
                'date' => $payment->paid_on->toDateString(),
                'kind' => $amount < 0 ? __('Payment reversed') : __('Payment').' ('.$payment->method->label().')',
                'reference' => (string) ($numbers[$payment->invoice_id] ?? '').($payment->reference ? ' / '.$payment->reference : ''),
                'debit' => $amount < 0 ? -$amount : 0.0,
                'credit' => $amount > 0 ? $amount : 0.0,
            ];
        }

        // By day; on one day what was billed comes before what settled it.
        usort($entries, fn (array $a, array $b): int => [$a['date'], $a['credit'] > 0] <=> [$b['date'], $b['credit'] > 0]);

        $start = $from->toDateString();
        $opening = 0.0;
        $lines = [];
        $debits = 0.0;
        $credits = 0.0;

        foreach ($entries as $entry) {
            if ($entry['date'] < $start) {
                $opening += $entry['debit'] - $entry['credit'];

                continue;
            }

            $debits += $entry['debit'];
            $credits += $entry['credit'];
            $lines[] = $entry;
        }

        $balance = $opening;

        foreach ($lines as $index => $line) {
            $balance += $line['debit'] - $line['credit'];
            $lines[$index]['balance'] = round($balance, 2) + 0.0;
        }

        return [
            'currency' => (string) $currency,
            'currencies' => $currencies,
            'opening' => round($opening, 2) + 0.0,
            'closing' => round($balance, 2) + 0.0,
            'debits' => round($debits, 2) + 0.0,
            'credits' => round($credits, 2) + 0.0,
            'lines' => $lines,
        ];
    }

    /**
     * What of a credit note falls on the customer's sales settled at the
     * till, which it was refunded at.
     *
     * @param  array<string, Invoice>  $named  the customer's invoices by number and by id
     */
    private function refundedAtTill(Invoice $note, array $named): float
    {
        $payables = [];

        foreach (BillingReferences::of($note->billing_ref) as $reference) {
            if (isset($named[$reference])) {
                $payables[(string) $named[$reference]->id] ??= (float) $named[$reference]->payable();
            }
        }

        $refunded = 0.0;

        foreach (Receivables::spread((float) $note->total, $payables) as $id => $share) {
            $refunded += $this->receivables->settledAtSale($named[$id]) ? $share : 0.0;
        }

        return $refunded;
    }
}
