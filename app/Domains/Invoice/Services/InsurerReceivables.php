<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\InsuranceSplit;
use Illuminate\Support\Collection;

/**
 * What insurers owe the organization, by insurer, for the sales they pay
 * for (InsuranceSplits):
 *
 *   billed     its share of each invoice to a patient - what that invoice
 *              states for its account - and each invoice to it as the
 *              recipient;
 *   reduced    what its decisions took off: the part of a patient's invoice
 *              it did not pay, which the patient owes instead (ZATCA General
 *              VAT Guideline Healthcare, January 2023, §5.3.2 p25), and the
 *              credit notes on its own invoices;
 *   owed       the rest.
 *
 * Only sales whose invoice is issued and was not rejected by the authority.
 */
class InsurerReceivables
{
    /**
     * @return Collection<int, array{insurer: string, vat_number: ?string, claims: int, awaiting: int, billed: string, credited: string, owed: string, currency: string}>
     */
    public function of(string $organizationId): Collection
    {
        $splits = InsuranceSplit::withoutTenantScope(fn () => InsuranceSplit::query()
            ->with('invoices')
            ->where('org_id', $organizationId)
            ->get())
            ->filter(fn (InsuranceSplit $split): bool => ! in_array($split->sale()?->status, [null, InvoiceStatus::Draft, InvoiceStatus::Rejected], true));

        return $splits
            ->groupBy(fn (InsuranceSplit $split): string => ($split->insurer_customer_id ?? $split->insurer_vat_number ?? $split->insurer_name).'|'.$split->currency)
            ->map(function (Collection $group): array {
                $billed = $group->reduce(fn (string $sum, InsuranceSplit $split): string => bcadd($sum, $this->billed($split), 2), '0.00');
                $owed = $group->reduce(fn (string $sum, InsuranceSplit $split): string => bcadd($sum, $split->insurerOwes(), 2), '0.00');
                $first = $group->first();

                return [
                    'insurer' => (string) $first->insurer_name,
                    'vat_number' => $first->insurer_vat_number,
                    'claims' => $group->count(),
                    'awaiting' => $group->where('status', InsuranceSplit::BILLED)->count(),
                    'billed' => $billed,
                    'credited' => bcsub($billed, $owed, 2),
                    'owed' => $owed,
                    'currency' => (string) $first->currency,
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['owed'])
            ->values();
    }

    private function billed(InsuranceSplit $split): string
    {
        return $split->recipient === InsuranceSplit::PATIENT
            ? bcadd((string) $split->insurer_share, '0', 2)
            : bcadd((string) ($split->sale()?->total ?? '0'), '0', 2);
    }
}
