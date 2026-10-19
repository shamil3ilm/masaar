<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\InsuranceSplit;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * What insurers owe the organization, by insurer: the insurer's shares of
 * its split sales that were issued, less the credit notes against them -
 * a declined claim, or one corrected by hand - and how many claims still
 * wait for the insurer's decision.
 *
 * Read from the documents themselves, so it agrees with what was issued.
 * Drafts are not owed and a share the authority rejected was never valid,
 * so neither counts.
 */
class InsurerReceivables
{
    /**
     * @return Collection<int, array{insurer: string, vat_number: ?string, claims: int, awaiting: int, billed: string, credited: string, owed: string, currency: string}>
     */
    public function of(string $organizationId): Collection
    {
        $shares = Invoice::withoutTenantScope(fn () => Invoice::query()
            ->with(['insuranceSplit' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('org_id', $organizationId)
            ->where('split_role', InsuranceSplit::INSURER)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Rejected->value])
            ->get());

        $credited = $this->credited($organizationId, $shares->pluck('invoice_number')->filter()->values()->all());

        return $shares
            ->groupBy(fn (Invoice $share): string => (string) ($share->insuranceSplit?->insurer_customer_id ?? $share->buyer_vat_number ?? $share->buyer_name).'|'.$share->currency)
            ->map(function (Collection $group) use ($credited): array {
                $billed = $group->reduce(fn (string $sum, Invoice $share): string => bcadd($sum, (string) $share->total, 2), '0.00');
                $credit = $group->reduce(fn (string $sum, Invoice $share): string => bcadd($sum, $credited[(string) $share->invoice_number] ?? '0.00', 2), '0.00');
                $first = $group->first();

                return [
                    'insurer' => (string) ($first->buyer_name ?: $first->buyer_name_ar),
                    'vat_number' => $first->buyer_vat_number,
                    'claims' => $group->count(),
                    'awaiting' => $group->filter(fn (Invoice $share): bool => $share->insuranceSplit?->status === InsuranceSplit::BILLED)->count(),
                    'billed' => $billed,
                    'credited' => $credit,
                    'owed' => bcsub($billed, $credit, 2),
                    'currency' => (string) $first->currency,
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['owed'])
            ->values();
    }

    /**
     * The issued credit notes against these invoices, together by the
     * invoice each names.
     *
     * @param  list<string>  $numbers
     * @return array<string, string>
     */
    private function credited(string $organizationId, array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        $totals = [];

        Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $organizationId)
            ->where('document_type', DocumentType::CreditNote->value)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Rejected->value])
            ->whereIn('billing_ref', $numbers)
            ->get(['billing_ref', 'total']))
            ->each(function (Invoice $note) use (&$totals): void {
                $totals[(string) $note->billing_ref] = bcadd($totals[(string) $note->billing_ref] ?? '0.00', (string) $note->total, 2);
            });

        return $totals;
    }
}
