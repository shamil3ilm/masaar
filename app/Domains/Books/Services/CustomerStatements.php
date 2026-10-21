<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Books\Models\InvoicePayment;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
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
 */
class CustomerStatements
{
    private const OWED = [DocumentType::Invoice, DocumentType::Prepayment, DocumentType::DebitNote];

    public function customer(string $organizationId, string $customerId): ?Customer
    {
        return Customer::query()->withTrashed()->where('org_id', $organizationId)->find($customerId);
    }

    /**
     * @return array{currency: string, currencies: list<string>, opening: float, closing: float, debits: float, credits: float, lines: list<array{date: string, kind: string, reference: string, debit: float, credit: float, balance: float}>}
     */
    public function for(string $organizationId, Customer $customer, CarbonImmutable $from, CarbonImmutable $to, ?string $currency = null): array
    {
        $documents = Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $organizationId)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Rejected])
            ->whereIn('document_type', [...self::OWED, DocumentType::CreditNote])
            ->where('issue_date', '<', $to->addDay()->toDateString())
            ->get());

        $currencies = $documents->pluck('currency')->map(fn ($code): string => (string) $code)->unique()->sort()->values()->all();
        $currency ??= $documents->countBy('currency')->sortDesc()->keys()->first() ?? '';
        $documents = $documents->where('currency', $currency);

        $entries = [];

        foreach ($documents as $document) {
            $note = $document->document_type === DocumentType::CreditNote;
            $amount = $note ? (float) $document->total : (float) $document->payable();
            $entries[] = [
                'date' => CarbonImmutable::parse($document->issue_date)->toDateString(),
                'kind' => $note ? __('Credit note') : ($document->document_type === DocumentType::DebitNote ? __('Debit note') : __('Invoice')),
                'reference' => (string) $document->invoice_number,
                'debit' => $note ? 0.0 : $amount,
                'credit' => $note ? $amount : 0.0,
            ];
        }

        $payments = InvoicePayment::withoutTenantScope(fn () => InvoicePayment::query()
            ->where('org_id', $organizationId)
            ->whereIn('invoice_id', $documents->pluck('id'))
            ->where('paid_on', '<', $to->addDay()->toDateString())
            ->get());
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
}
