<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Invoice\Enums\BillingTiming;
use App\Domains\Invoice\Enums\InstalmentModel;
use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Support\SchedulePeriods;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Making a schedule: its periods, and its invoice checked by the
 * organization's country's rules as the first run would draft it, so a
 * schedule that could never make a valid invoice is refused when it is
 * made rather than failing at its first run.
 */
class Schedules
{
    public function __construct(
        private readonly ScheduleTemplates $templates,
        private readonly AuditService $audit,
        private readonly Catalogue $catalogue,
        private readonly BranchService $branches,
        private readonly Instalments $instalments,
        private readonly ScheduleSources $sources,
        private readonly Customizations $customizations,
    ) {}

    /**
     * The organization's schedules, newest first, of one status or all.
     *
     * @return LengthAwarePaginator<int, InvoiceSchedule>
     */
    public function paginate(string $organizationId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->ofOrganization($organizationId)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * One of the organization's schedules, with its runs. Another
     * organization's is not found, as one that does not exist.
     *
     * @throws ModelNotFoundException
     */
    public function find(string $organizationId, string $scheduleId): InvoiceSchedule
    {
        return $this->ofOrganization($organizationId)->with('runs')->findOrFail($scheduleId);
    }

    /**
     * As find(), each run with its invoice's number, status and total, for
     * the schedule's page.
     *
     * @throws ModelNotFoundException
     */
    public function findWithInvoices(string $organizationId, string $scheduleId): InvoiceSchedule
    {
        return $this->ofOrganization($organizationId)
            ->with(['runs.invoice' => fn ($query) => $query->select(['id', 'org_id', 'invoice_number', 'status', 'document_type', 'total', 'currency'])])
            ->findOrFail($scheduleId);
    }

    /**
     * How often a new schedule bills unless the form says otherwise: the
     * person's own choice, the organization's, what its business type
     * suggests (a school by term, a service by month), or the platform's.
     */
    public function defaultFrequency(Organization $organization, ?string $userId): ScheduleFrequency
    {
        $scope = $userId === null ? Scope::organization((string) $organization->id) : Scope::member((string) $organization->id, $userId);

        return ScheduleFrequency::tryFrom((string) $this->customizations->get('schedules.frequency', $scope)) ?? ScheduleFrequency::Monthly;
    }

    /** Whether an instalment plan can be paid ahead in prepayment invoices here. */
    public function offersPrepayments(Organization $organization): bool
    {
        return $this->instalments->prepaymentsIssued($organization);
    }

    /**
     * Why a branch or customer named for a schedule is not the
     * organization's own, by field; empty when both are.
     *
     * @return array<string, string>
     */
    public function owned(string $organizationId, mixed $branchId, mixed $customerId): array
    {
        $problems = [];

        if (is_string($branchId) && $branchId !== '' && ! $this->branches->belongsTo($branchId, $organizationId)) {
            $problems['branch_id'] = __('There is no branch with this id.');
        }

        if (is_string($customerId) && $customerId !== '' && $this->catalogue->customer($organizationId, $customerId) === null) {
            $problems['customer_id'] = __('There is no saved customer with this id.');
        }

        return $problems;
    }

    /**
     * @param  array{name: string, frequency: string, start_date?: string|null, end_date?: string|null, occurrences?: int|null, terms?: list<array{start_date: string, end_date: string}>|null, auto_issue?: bool, due_days?: int|null, branch_id?: string|null, customer_id?: string|null, source_invoice_id?: string|null}  $attributes
     * @param  array<string, mixed>  $invoice  the invoice, as a request to create one gives it
     *
     * @throws ValidationException the schedule's own fields by name, the invoice's under invoice.
     */
    public function create(Organization $organization, array $attributes, array $invoice): InvoiceSchedule
    {
        $frequency = ScheduleFrequency::from($attributes['frequency']);
        $terms = $frequency->hasTerms() ? array_values((array) ($attributes['terms'] ?? [])) : null;
        // A termly schedule starts with its first term.
        $start = $terms === null ? (string) ($attributes['start_date'] ?? '') : (string) ($terms[0]['start_date'] ?? '');
        $template = $this->templates->keep($invoice);
        $timing = filled($attributes['bills_in'] ?? null) ? BillingTiming::from((string) $attributes['bills_in']) : BillingTiming::Advance;

        $periods = new SchedulePeriods(
            $frequency,
            SchedulePeriods::date($start),
            filled($attributes['end_date'] ?? null) ? SchedulePeriods::date((string) $attributes['end_date']) : null,
            isset($attributes['occurrences']) ? (int) $attributes['occurrences'] : null,
            $terms ?? [],
            $timing === BillingTiming::Arrears,
        );
        $first = $periods->at(0) ?? throw ValidationException::withMessages(['end_date' => __('The schedule ends before its first period starts.')]);
        $model = filled($attributes['instalment_model'] ?? null) ? InstalmentModel::from((string) $attributes['instalment_model']) : null;

        if ($model !== null) {
            $this->instalments->assertSplittable($organization, $template, $model, $periods->count(), $timing);
        }

        // Checked with its buyer as each run will take it: the saved
        // customer's details where it names one.
        $this->checked($organization, $this->templates->withBuyer($organization, $attributes['customer_id'] ?? null, $template),
            $frequency, $first['start'], $first['end'], $attributes['due_days'] ?? null, $model !== InstalmentModel::Delivered);

        return DB::transaction(function () use ($organization, $attributes, $frequency, $terms, $start, $template, $first, $model, $timing): InvoiceSchedule {
            $schedule = InvoiceSchedule::acrossTenants()->create([
                'org_id' => $organization->id,
                'branch_id' => $attributes['branch_id'] ?? null,
                'customer_id' => $attributes['customer_id'] ?? null,
                'source_invoice_id' => $attributes['source_invoice_id'] ?? null,
                'name' => $attributes['name'],
                'frequency' => $frequency,
                'terms' => $terms,
                'start_date' => $start,
                'end_date' => $terms === null ? ($attributes['end_date'] ?? null) : null,
                'occurrences' => $attributes['occurrences'] ?? null,
                'auto_issue' => (bool) ($attributes['auto_issue'] ?? false),
                'due_days' => $attributes['due_days'] ?? null,
                'instalment_model' => $model,
                'bills_in' => $timing,
                'template' => $template,
                'status' => ScheduleStatus::Active,
                'next_period' => 0,
                'next_run_on' => $first['runs_on']->toDateString(),
            ]);

            $this->audit->logChange('schedule.created', 'InvoiceSchedule', (string) $schedule->id, null, [
                'name' => $schedule->name,
                'frequency' => $frequency->value,
                'start_date' => $start,
                'auto_issue' => $schedule->auto_issue,
                'instalment_model' => $model?->value,
                'bills_in' => $timing->value,
            ], organizationId: (string) $organization->id);

            return $schedule;
        });
    }

    /**
     * A schedule repeating an invoice the organization wrote: its buyer,
     * lines and terms, its branch, customer and days to pay, under the
     * schedule's own frequency and dates. What the attributes give wins.
     *
     * @param  array<string, mixed>  $attributes  as create() takes them
     *
     * @throws ValidationException
     * @throws DomainException when the invoice is a note or an advance
     */
    public function createFromInvoice(Organization $organization, array $attributes, string $invoiceId): InvoiceSchedule
    {
        $source = $this->sources->fromInvoice($organization, $invoiceId);

        return $this->create($organization, [
            ...$source['attributes'],
            ...array_filter($attributes, fn (mixed $value): bool => $value !== null && $value !== ''),
            'source_invoice_id' => (string) $source['invoice']->id,
        ], $source['template']);
    }

    /**
     * The template, as the first run would draft it, through the country's
     * rules. Its errors are the invoice's, so they are named under invoice.
     *
     * @param  array<string, mixed>  $template
     *
     * @throws ValidationException
     */
    private function checked(Organization $organization, array $template, ScheduleFrequency $frequency, CarbonImmutable $start, CarbonImmutable $end, mixed $dueDays, bool $periodic): void
    {
        $today = CountryClock::now($organization->country)->toDateString();

        try {
            $this->templates->check($organization, $this->templates->dated($organization, $template, $frequency, $today, $start, $end, $dueDays === null ? null : (int) $dueDays, $periodic));
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $field => $messages) {
                $errors['invoice.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return Builder<InvoiceSchedule>
     */
    private function ofOrganization(string $organizationId): Builder
    {
        return InvoiceSchedule::query()->where('org_id', $organizationId);
    }
}
