<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InstalmentModel;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Support\SchedulePeriods;
use App\Domains\Organization\Models\Organization;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * A schedule's next runs as they would be made today: each period's dates,
 * the day it runs, the document and its total, worked out by the same
 * rules and totals as the run itself - or why that run would fail.
 *
 * The final invoice of a prepayment plan shows the whole supply's total;
 * what is left to pay is that less the advances, which are only known
 * once they are issued.
 */
class SchedulePreview
{
    public function __construct(
        private readonly ScheduleTemplates $templates,
    ) {}

    /**
     * @return list<array{period: int, start_date: string, end_date: string, runs_on: string, document_type: string|null, total: string|null, currency: string|null, settles_advances: bool, problem: string|null}>
     */
    public function upcoming(InvoiceSchedule $schedule, Organization $organization, int $limit = 6): array
    {
        if ($schedule->status->isFinished() || $schedule->next_period === null) {
            return [];
        }

        $periods = SchedulePeriods::of($schedule);
        $last = $periods->count();
        $today = CountryClock::now($organization->country)->toDateString();
        $upcoming = [];

        foreach ($periods->from($schedule->next_period, $limit) as $period) {
            $final = $schedule->instalment_model === InstalmentModel::Prepayment && $last !== null && $period['period'] === $last - 1;

            $upcoming[] = [
                'period' => $period['period'],
                'start_date' => $period['start']->toDateString(),
                'end_date' => $period['end']->toDateString(),
                'runs_on' => $period['runs_on']->toDateString(),
                'settles_advances' => $final,
                ...$this->priced($schedule, $organization, $period['period'], $period['start'], $period['end'], $today, $final),
            ];
        }

        return $upcoming;
    }

    /**
     * @return array{document_type: string|null, total: string|null, currency: string|null, problem: string|null}
     */
    private function priced(InvoiceSchedule $schedule, Organization $organization, int $index, CarbonImmutable $start, CarbonImmutable $end, string $today, bool $final): array
    {
        try {
            try {
                $payload = $this->templates->forPeriod($organization, $schedule, $index, $start, $end, $today);
            } catch (DomainException) {
                // The final invoice, before its advances are issued: its
                // total is the whole supply's all the same.
                $payload = $this->templates->dated($organization, (array) $schedule->template, $schedule->frequency, $today, $start, $end, $schedule->due_days);
            }

            if ($final) {
                unset($payload['prepayments']);
            }

            $draft = $this->templates->check($organization, $payload)['draft'];
            $totals = InvoiceTotals::of(
                $draft['lines'],
                (string) ($draft['discount_amount'] ?? '0'),
                (bool) ($draft['prices_include_vat'] ?? $organization->prices_include_vat),
                array_values((array) ($draft['charges'] ?? [])),
            );

            return [
                'document_type' => (string) ($draft['document_type'] ?? DocumentType::Invoice->value),
                'total' => $totals->total,
                'currency' => (string) ($draft['currency'] ?? ''),
                'problem' => null,
            ];
        } catch (ValidationException $e) {
            return ['document_type' => null, 'total' => null, 'currency' => null, 'problem' => implode(' ', array_merge(...array_values($e->errors())))];
        }
    }
}
