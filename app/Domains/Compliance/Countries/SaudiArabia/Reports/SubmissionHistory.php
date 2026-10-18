<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Organization\Services\Membership;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\NumberRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The current tenant's ZATCA submissions as a history list, with the
 * person who submitted and the invoice each was for.
 *
 * Confined to the tenant by InvoiceSubmission's global scope, as
 * SubmissionReport is, and by the organization named in the query besides;
 * the invoice joined in is the submission's own. The old links still work:
 * state, user_id, date_from and date_to mean what they did.
 */
class SubmissionHistory
{
    public function __construct(
        private readonly Membership $membership,
    ) {}

    public function spec(string $organizationId, string $key): ListSpec
    {
        $people = $this->membership->members($organizationId)
            ->mapWithKeys(fn ($user): array => [(string) $user->id => (string) ($user->name ?: $user->email)])
            ->all();

        return new ListSpec(
            key: $key,
            sorts: [
                'newest' => new Sort(__('Newest'), 'invoice_submissions.created_at'),
                'number' => new Sort(__('Invoice'), 'invoices.invoice_number', 'asc'),
                'amount' => new Sort(__('Amount'), 'invoices.total'),
                'state' => new Sort(__('State'), 'invoice_submissions.state', 'asc'),
            ],
            defaultSort: 'newest',
            filters: [
                new Search('q', __('Invoice'), ['invoices.invoice_number', 'invoices.buyer_name'], __('Invoice number or buyer')),
                // Days in UTC, as the page always read them.
                new DateRange('invoice_submissions.created_at', __('Submitted'), instant: true, fromKey: 'date_from', toKey: 'date_to'),
                new NumberRange('invoices.total', __('Amount')),
                new Choice('state', __('State'), [
                    'cleared' => __('Cleared'),
                    'reported' => __('Reported'),
                    'rejected' => __('Rejected'),
                    'failed' => __('Failed'),
                    'queued' => __('Queued'),
                    'submitted' => __('Submitted'),
                    'pending_submission' => __('Pending submission'),
                ], 'invoice_submissions.state'),
                new Choice('type', __('Type'), ['clearance' => __('Clearance'), 'reporting' => __('Reporting')], 'invoice_submissions.submission_type'),
                new Choice('user_id', __('User'), $people, 'invoice_submissions.created_by', __('All Users')),
            ],
            tiebreaker: 'invoice_submissions.id',
        );
    }

    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($organizationId));
    }

    public function csv(string $organizationId, ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            __('Invoice') => fn (InvoiceSubmission $row): string => (string) $row->getAttribute('invoice_number'),
            __('Submitted') => fn (InvoiceSubmission $row): string => (string) $row->created_at,
            __('Type') => fn (InvoiceSubmission $row): string => (string) $row->submission_type,
            __('State') => fn (InvoiceSubmission $row): string => (string) $row->state,
            __('Amount') => fn (InvoiceSubmission $row): string => number_format((float) $row->getAttribute('invoice_total'), 2, '.', ''),
            __('User') => fn (InvoiceSubmission $row): string => (string) ($row->getAttribute('user_name') ?? $row->getAttribute('user_email')),
            __('Error') => fn (InvoiceSubmission $row): string => (string) $row->last_error,
        ], $list->apply($this->query($organizationId))->lazy(500));
    }

    /**
     * @return Builder<InvoiceSubmission>
     */
    private function query(string $organizationId): Builder
    {
        return InvoiceSubmission::query()
            ->where('invoice_submissions.org_id', $organizationId)
            ->leftJoin('users', 'invoice_submissions.created_by', '=', 'users.id')
            ->leftJoin('invoices', 'invoice_submissions.invoice_id', '=', 'invoices.id')
            ->select([
                'invoice_submissions.*',
                'users.name as user_name',
                'users.email as user_email',
                'invoices.invoice_number',
                'invoices.total as invoice_total',
            ]);
    }
}
