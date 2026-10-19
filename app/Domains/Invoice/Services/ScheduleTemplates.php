<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A schedule's invoice: kept as the request an invoice is entered by, and
 * checked by the organization's country's rules (InvoiceRules) - when the
 * schedule is made, and again for every run - so a schedule never makes an
 * invoice those rules would refuse from a person.
 *
 * What each run decides for itself is not kept: its number (numbered when
 * issued, InvoiceNumbering), its issue date, its supply period and its due
 * date.
 */
class ScheduleTemplates
{
    /** Request fields a run sets, or that name one request only. */
    private const PER_RUN = [
        'invoice_number', 'issue_date', 'supply_date', 'supply_end_date', 'due_date',
        'idempotency_key', 'erp_reference_id', 'sold_at', 'issued_at', 'prepayments',
        'till_id', 'till_number', 'org_id', 'auto_submit',
    ];

    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * The invoice as a schedule keeps it.
     *
     * @param  array<string, mixed>  $invoice  as a request to create an invoice gives it
     * @return array<string, mixed>
     */
    public function keep(array $invoice): array
    {
        return array_diff_key($invoice, array_flip(self::PER_RUN));
    }

    /**
     * The invoice for one period: the template, dated.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public function dated(array $template, string $issueDate, CarbonImmutable $start, CarbonImmutable $end, ?int $dueDays): array
    {
        return [
            ...$template,
            'issue_date' => $issueDate,
            // KSA-5 and KSA-24, BG-14 in PINT: the period the invoice bills.
            'supply_date' => $start->toDateString(),
            'supply_end_date' => $end->toDateString(),
            ...($dueDays === null ? [] : ['due_date' => CarbonImmutable::parse($issueDate, 'UTC')->addDays($dueDays)->toDateString()]),
        ];
    }

    /**
     * The invoice checked by the organization's country's rules, as
     * InvoiceDrafter takes it: the draft and the country's own columns.
     *
     * @param  array<string, mixed>  $payload
     * @return array{draft: array<string, mixed>, country: array<string, mixed>}
     *
     * @throws ValidationException naming each field as the request names it
     */
    public function check(Organization $organization, array $payload): array
    {
        return $this->tenant->runAs((string) $organization->id, function () use ($organization, $payload): array {
            $rules = $this->jurisdictions->forRules($organization)->invoiceRules();
            $request = Request::create('/', 'POST', $payload);
            $request->merge($rules->defaults($request));

            $validator = Validator::make($request->all(), $rules->rules($request), $rules->messages());
            $validator->after(fn ($validator) => $rules->after($validator, $request));
            $validated = $validator->validate();

            return ['draft' => $rules->draft($validated), 'country' => $rules->countryFields($validated)];
        });
    }
}
