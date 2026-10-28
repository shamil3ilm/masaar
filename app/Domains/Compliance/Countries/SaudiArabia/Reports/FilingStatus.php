<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\SubmissionState;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Engine\DTOs\VatReturnBox;
use App\Domains\Compliance\Engine\DTOs\VatReturnEntry;
use App\Domains\Compliance\Engine\Services\VatReturnTally;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\VatSummary;
use Carbon\CarbonImmutable;

/**
 * Where each document behind the VAT figures stands with ZATCA, so the
 * return can be tied to the filing record before it is filed.
 *
 * The figures count every issued document ZATCA has not rejected (VatSummary):
 * the tax is due on the supply, whatever has become of its filing. So they
 * hold documents ZATCA has accepted and documents it has not answered yet,
 * and an accountant needs to see which. Each is placed by the invoice's own
 * status and its latest submission (SubmissionState):
 *
 *   accepted  cleared or reported (InvoiceStatus::Accepted, or a submission
 *             cleared, reported or accepted with warnings)
 *   waiting   not sent yet, queued, or sent with no answer yet
 *   held      failed: the authority did not judge it, and it is sent again
 *
 * A rejected document is in no box; it is shown apart, left out.
 */
class FilingStatus
{
    /** The groups in the return's figures, in the order shown. */
    public const GROUPS = [
        'accepted' => 'Cleared or reported by ZATCA',
        'waiting' => 'Not answered yet: waiting to be sent, or sent',
        'held' => 'Not filed: did not get through, to be sent again',
    ];

    public const REJECTED = 'Rejected by ZATCA, left out of the return';

    public const HEADING = 'Filing status with ZATCA';

    public function __construct(
        private readonly VatSummary $sales,
    ) {}

    /**
     * The return's sales split by filing status, each box the sum of the
     * same rounded entries as boxes 1 to 5, so the total is box 6 exactly;
     * then the rejected documents, apart.
     *
     * @param  list<VatReturnBox>  $salesBoxes  boxes 1 to 5
     * @return array{boxes: list<VatReturnBox>, unsettled: array{waiting: int, held: int}}
     */
    public function forReturn(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, array $salesBoxes): array
    {
        $entries = array_merge([], ...array_map(fn (VatReturnBox $box): array => $box->entries, $salesBoxes));
        $groups = $this->groups(array_map(fn (VatReturnEntry $entry): string => $entry->id, $entries));
        $tally = new VatReturnTally;

        foreach ($entries as $entry) {
            $tally->add('filing-'.$groups[$entry->id], $entry->kind, $entry->id, $entry->number, $entry->date, $entry->party, $entry->amount, $entry->adjustment, $entry->vat);
        }

        $boxes = [];

        foreach (self::GROUPS as $group => $label) {
            $boxes[] = $tally->box('filing-'.$group, $label);
        }

        $boxes[] = VatReturnTally::total('filing-total', 'Total sales, as box 6', VatReturnTally::sum($boxes, 'amount'), VatReturnTally::sum($boxes, 'adjustment'), VatReturnTally::sum($boxes, 'vat'));

        $refused = new VatReturnTally;

        foreach ($this->sales->contributions($organizationId, $from, $to, refused: true) as $row) {
            if ($row['rate'] === null || $row['box'] === null) {
                continue;
            }

            $invoice = $row['invoice'];
            $refused->add('filing-rejected', VatReturnEntry::SALE, (string) $invoice->id, (string) $invoice->invoice_number, $invoice->issue_date?->toDateString() ?? '', (string) $invoice->buyer_name,
                $row['adjusts'] ? 0.0 : $row['base'], $row['adjusts'] ? $row['base'] : 0.0, $row['vat']);
        }

        $boxes[] = $refused->box('filing-rejected', self::REJECTED);
        $count = fn (string $group): int => count(array_unique(array_map(fn (VatReturnEntry $entry): string => $entry->id, $tally->entries('filing-'.$group))));

        return ['boxes' => $boxes, 'unsettled' => ['waiting' => $count('waiting'), 'held' => $count('held')]];
    }

    /**
     * The sales summary's documents by filing status, for the branches the
     * report shows: documents, net amount and net VAT per group, and the
     * rejected ones apart.
     *
     * @return array<string, array{label: string, documents: int, base: float, vat: float}>
     */
    public function forSummary(string $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = iterator_to_array($this->sales->contributions($organizationId, $from, $to), false);
        $groups = $this->groups(array_map(fn (array $row): string => (string) $row['invoice']->id, $rows));
        $split = [];

        foreach ([...self::GROUPS, 'rejected' => self::REJECTED] as $group => $label) {
            $split[$group] = ['label' => $label, 'documents' => 0, 'base' => 0.0, 'vat' => 0.0];
        }

        $add = function (string $group, array $row) use (&$split): void {
            if ($row['rate'] === null) {
                return;
            }

            $split[$group]['documents'] += $row['first'] ? 1 : 0;

            if ($row['box'] !== null) {
                $split[$group]['base'] += $row['base'];
                $split[$group]['vat'] += $row['vat'];
            }
        };

        foreach ($rows as $row) {
            $add($groups[(string) $row['invoice']->id], $row);
        }

        foreach ($this->sales->contributions($organizationId, $from, $to, refused: true) as $row) {
            $add('rejected', $row);
        }

        return array_map(fn (array $group): array => [...$group, 'base' => VatReturnTally::cents($group['base']), 'vat' => VatReturnTally::cents($group['vat'])], $split);
    }

    /**
     * Each document's group, by its status and its latest submission.
     *
     * @param  list<string>  $invoiceIds
     * @return array<string, string>
     */
    private function groups(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique($invoiceIds));
        $groups = [];

        foreach (array_chunk($invoiceIds, 500) as $chunk) {
            $accepted = Invoice::withoutTenantScope(fn () => Invoice::query()->whereIn('id', $chunk)
                ->where('status', InvoiceStatus::Accepted)->pluck('id')->flip()->all());
            $latest = InvoiceSubmission::withoutTenantScope(fn () => InvoiceSubmission::query()->whereIn('invoice_id', $chunk)
                ->orderBy('created_at')->orderBy('id')->get(['invoice_id', 'state'])
                ->mapWithKeys(fn (InvoiceSubmission $submission): array => [(string) $submission->invoice_id => (string) $submission->state])->all());

            foreach ($chunk as $id) {
                $groups[$id] = match (true) {
                    isset($accepted[$id]), in_array($latest[$id] ?? null, InvoiceSubmission::ACCEPTED_STATES, true) => 'accepted',
                    in_array($latest[$id] ?? null, [SubmissionState::Failed->value, SubmissionState::Rejected->value, SubmissionState::Cancelled->value], true) => 'held',
                    default => 'waiting',
                };
            }
        }

        return $groups;
    }
}
