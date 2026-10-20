<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\BillingTiming;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Enums\InstalmentModel;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Models\InvoiceScheduleRun;
use App\Domains\Invoice\Support\SchedulePeriods;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * An instalment plan: one agreed total - the template's lines - in N parts,
 * one per period (InstalmentModel says which document each part is).
 *
 * Each line is split by its amount, quantity times price: every part takes
 * the amount divided by N, rounded to the halala or fils, and the last takes
 * what is left, so the parts add up to the total exactly. A part is one of
 * the line, its VAT treatment as the line's; VAT is worked on each part as
 * on any invoice.
 *
 * Under the prepayment model the last part is the final invoice: the whole
 * supply, its period from the first part's first day to the last part's
 * last, settling every earlier part by its number (KSA-26 to KSA-34,
 * BT-113). The buyer pays what is left. Those parts have to be issued by
 * then - a prepayment that was never issued cannot be settled.
 */
class Instalments
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * Refused before anything is made: a plan of fewer than two parts, one
     * whose invoice has charges or a discount to split - put them in the
     * lines' prices - and the prepayment model where the country issues no
     * prepayment invoices.
     *
     * @param  array<string, mixed>  $template
     *
     * @throws ValidationException
     */
    public function assertSplittable(Organization $organization, array $template, InstalmentModel $model, ?int $count, BillingTiming $timing = BillingTiming::Advance): void
    {
        $errors = [];

        // An advance is paid ahead of its period, so it is not billed after it.
        if ($model === InstalmentModel::Prepayment && $timing === BillingTiming::Arrears) {
            $errors['bills_in'] = __('Instalments paid ahead are billed in advance.');
        }

        if ($count === null || $count < 2) {
            $errors['occurrences'] = __('An instalment plan has two instalments or more.');
        }

        $charged = ! empty($template['charges']) || bccomp((string) ($template['discount_amount'] ?? '0'), '0', 2) !== 0
            || array_filter((array) ($template['lines'] ?? []), fn (mixed $line): bool => is_array($line) && (! empty($line['charges']) || bccomp((string) ($line['discount_amount'] ?? '0'), '0', 2) !== 0)) !== [];

        if ($charged) {
            $errors['invoice.lines'] = __('An instalment plan splits its lines\' prices. Put charges and discounts in the prices.');
        }

        if ($model === InstalmentModel::Prepayment && ! $this->prepaymentsIssued($organization)) {
            $errors['instalment_model'] = __('Prepayment invoices are not issued in your country. Invoice each instalment instead.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Whether the organization's country issues prepayment invoices. */
    public function prepaymentsIssued(Organization $organization): bool
    {
        return in_array(DocumentType::Prepayment->value, $this->jurisdictions->forRules($organization)->invoiceRules()->documentTypes(), true);
    }

    /**
     * One period's invoice of the plan, from its dated invoice.
     *
     * @param  array<string, mixed>  $dated  the template, dated for the period
     * @return array<string, mixed>
     *
     * @throws DomainException when the final invoice's prepayments are not all issued
     */
    public function payload(InvoiceSchedule $schedule, int $period, array $dated): array
    {
        $model = $schedule->instalment_model ?? InstalmentModel::Invoice;
        $periods = SchedulePeriods::of($schedule);
        $count = (int) $periods->count();

        if ($model === InstalmentModel::Prepayment && $period === $count - 1) {
            $first = $periods->at(0);

            return [
                ...$dated,
                'document_type' => DocumentType::Invoice->value,
                'supply_date' => $first === null ? $dated['supply_date'] : $first['start']->toDateString(),
                'prepayments' => array_map(fn (string $number): array => ['invoice_number' => $number], $this->advances($schedule, $count - 1)),
            ];
        }

        return [
            ...$dated,
            'document_type' => $model === InstalmentModel::Prepayment ? DocumentType::Prepayment->value : ($dated['document_type'] ?? DocumentType::Invoice->value),
            'lines' => array_map(fn (array $line): array => $this->part($line, $period, $count), array_values((array) $dated['lines'])),
        ];
    }

    /**
     * A line's part: one of it, at its share of the line's amount.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function part(array $line, int $period, int $count): array
    {
        $amount = bcmul((string) ($line['quantity'] ?? '1'), (string) ($line['unit_price'] ?? '0'), 6);
        $share = self::round(bcdiv($amount, (string) $count, 6));
        $price = $period === $count - 1 ? self::round(bcsub($amount, bcmul($share, (string) ($count - 1), 6), 6)) : $share;
        $number = ['number' => $period + 1, 'count' => $count];

        return [
            ...$line,
            'quantity' => '1',
            'unit_price' => $price,
            ...(filled($line['description'] ?? null) ? ['description' => $line['description'].' - '.__('instalment :number of :count', $number, 'en')] : []),
            ...(filled($line['description_ar'] ?? null) ? ['description_ar' => $line['description_ar'].' - '.__('instalment :number of :count', $number, 'ar')] : []),
            ...(filled($line['item_name'] ?? null) ? ['item_name' => $line['item_name'].' - '.__('instalment :number of :count', $number, 'en')] : []),
        ];
    }

    /**
     * The numbers of the plan's prepayment invoices, the first first.
     *
     * @return list<string>
     *
     * @throws DomainException naming the first that has not been issued
     */
    private function advances(InvoiceSchedule $schedule, int $count): array
    {
        $runs = InvoiceScheduleRun::query()->where('schedule_id', $schedule->id)->where('period', '<', $count)->orderBy('period')->get();
        $numbers = [];

        for ($period = 0; $period < $count; $period++) {
            $run = $runs->firstWhere('period', $period);
            $invoice = $run?->invoice_id === null ? null : Invoice::withoutTenantScope(fn (): ?Invoice => Invoice::query()->find($run->invoice_id));

            if ($invoice === null || $invoice->hash === null || in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Rejected], true)) {
                throw new DomainException(__('Instalment :number has not been issued, so the final invoice cannot settle it yet.', ['number' => $period + 1]));
            }

            $numbers[] = (string) $invoice->invoice_number;
        }

        return $numbers;
    }

    private static function round(string $value): string
    {
        $offset = bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $offset, 6), '0', 2);
    }
}
