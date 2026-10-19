<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\AuditLog;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One organization's activity: who issued and corrected invoices, changed
 * its settings and branches, and invited, added or removed whom - read from
 * the audit log, newest first, said in words.
 *
 * The organization's own entries, and the ones about it that were written
 * without it as the tenant - someone joining from an invitation link, before
 * they belong to it - so the timeline holds the whole story of its people.
 * Nothing here writes: an activity record anyone could edit would not be one.
 */
class ActivityLog
{
    /**
     * The kinds of activity a timeline narrows to, each a set of the audit
     * log's actions.
     *
     * @var array<string, list<string>>
     */
    private const KINDS = [
        'invoices' => ['Invoice.%', 'invoice.%', '%.submission.%', 'schedule.%'],
        'settings' => ['Organization.%', 'Branch.%', '%.late_reporting.%'],
        'people' => ['security.member.%'],
        'security' => ['security.login.%', 'security.logout', 'security.mfa.%', 'security.password.%', 'security.account.%'],
    ];

    /**
     * @param  array<string, string>  $people  user id => name, for the "who" filter
     */
    public function spec(string $key, string $timezone, array $people): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: ['newest' => new Sort(__('Newest'), 'audit_logs.created_at')],
            defaultSort: 'newest',
            filters: [
                new DateRange('audit_logs.created_at', __('When'), $timezone, instant: true),
                new Choice('who', __('Who'), $people, 'audit_logs.user_id', __('Anyone')),
                new Choice('kind', __('What'), [
                    'invoices' => __('Invoices and filing'),
                    'settings' => __('Settings and branches'),
                    'people' => __('People and invitations'),
                    'security' => __('Sign-ins and security'),
                ], fn (QueryBuilder $query, string $kind) => $query->where(function (QueryBuilder $any) use ($kind): void {
                    foreach (self::KINDS[$kind] as $pattern) {
                        $any->orWhere('audit_logs.action', 'like', $pattern);
                    }
                }), __('Everything')),
                new Search('q', __('Search'), ['audit_logs.action', 'audit_logs.entity_id'], __('An action or a record\'s id')),
            ],
            defaultPerPage: 50,
            tiebreaker: 'audit_logs.id',
        );
    }

    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        $page = $list->paginate($this->query($organizationId));
        $page->setCollection($page->getCollection()->map(fn (AuditLog $entry): array => $this->describe($entry)));

        return $page;
    }

    public function csv(string $organizationId, ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            __('When') => fn (AuditLog $entry): string => (string) $entry->created_at?->toIso8601String(),
            __('Who') => fn (AuditLog $entry): string => (string) ($entry->getAttribute('actor_name') ?: $entry->getAttribute('actor_email')),
            __('What') => fn (AuditLog $entry): string => $this->describe($entry)['summary'],
            __('Action') => fn (AuditLog $entry): string => (string) $entry->action,
            __('Record') => fn (AuditLog $entry): string => trim($entry->entity_type.' '.$entry->entity_id),
        ], $list->apply($this->query($organizationId))->lazy(500));
    }

    /**
     * An entry as the timeline says it.
     *
     * @return array{id: string, when: mixed, who: string|null, email: string|null, action: string, summary: string, kind: string}
     */
    public function describe(AuditLog $entry): array
    {
        $action = (string) $entry->action;
        $values = $entry->new_values ?? [];
        $metadata = $entry->metadata ?? [];
        $number = (string) ($values['invoice_number'] ?? $metadata['invoice_number'] ?? '');

        return [
            'id' => (string) $entry->id,
            'when' => $entry->created_at,
            'who' => $entry->getAttribute('actor_name') ?: null,
            'email' => $entry->getAttribute('actor_email') ?: null,
            'action' => $action,
            'kind' => $this->kindOf($action),
            'summary' => match (true) {
                $action === 'invoice.issued' => __('Issued :document :number', ['document' => $this->document((string) ($metadata['document_type'] ?? 'invoice')), 'number' => $number]),
                $action === 'Invoice.created' => $number === '' ? __('Started a draft invoice') : __('Started draft :number', ['number' => $number]),
                $action === 'Invoice.updated' => __('Changed a draft invoice'),
                $action === 'Invoice.deleted' => __('Deleted a draft invoice'),
                $action === 'schedule.created' => __('Started the schedule :name', ['name' => (string) ($values['name'] ?? '')]),
                str_ends_with($action, '.submission.success') => __('An invoice was accepted by the authority'),
                str_ends_with($action, '.submission.failed') => __('An invoice was not accepted by the authority'),
                str_contains($action, '.late_reporting.') => __('Recorded a notice of late reporting'),
                $action === 'Organization.created' => __('Registered the company'),
                $action === 'Organization.updated' => __('Changed the company\'s details'),
                $action === 'Branch.created' => __('Added the branch :name', ['name' => (string) ($values['name'] ?? '')]),
                $action === 'Branch.updated' => __('Changed the branch :name', ['name' => (string) ($values['name'] ?? '')]),
                $action === 'security.member.invited' => __('Invited :email as :role', ['email' => (string) ($metadata['email'] ?? ''), 'role' => $this->role((string) ($metadata['role'] ?? ''))]),
                $action === 'security.member.joined' => __('Joined as :role', ['role' => $this->role((string) ($metadata['role'] ?? ''))]),
                $action === 'security.member.removed' => __('Removed someone from the organization'),
                $action === 'security.member.branch_assigned' => __('Changed who works at a branch'),
                str_starts_with($action, 'security.login.succeeded') => __('Signed in'),
                str_starts_with($action, 'security.login.failed') => __('Failed to sign in'),
                default => $action,
            },
        ];
    }

    private function kindOf(string $action): string
    {
        foreach (self::KINDS as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/^'.str_replace('%', '.*', preg_quote($pattern, '/')).'$/', $action) === 1) {
                    return $kind;
                }
            }
        }

        return 'other';
    }

    private function document(string $type): string
    {
        return match ($type) {
            'credit_note' => __('credit note'),
            'debit_note' => __('debit note'),
            'prepayment' => __('prepayment invoice'),
            default => __('invoice'),
        };
    }

    private function role(string $role): string
    {
        return match ($role) {
            'admin' => __('an administrator'),
            'viewer' => __('a viewer'),
            default => __('a member'),
        };
    }

    /**
     * The organization's entries and those about it, each with who did it.
     *
     * Not the platform's own staff: what an operator did from the console
     * (admin.*) is the platform's record, with the operator's address in it,
     * and stays in the console's audit log.
     *
     * @return Builder<AuditLog>
     */
    private function query(string $organizationId): Builder
    {
        return AuditLog::query()
            ->leftJoin('users as actors', 'actors.id', '=', 'audit_logs.user_id')
            ->where(fn (QueryBuilder $about) => $about
                ->where('audit_logs.org_id', $organizationId)
                ->orWhere(fn (QueryBuilder $it) => $it
                    ->where('audit_logs.entity_type', 'Organization')
                    ->where('audit_logs.entity_id', $organizationId)))
            ->where('audit_logs.action', 'not like', 'admin.%')
            ->select(['audit_logs.*', 'actors.name as actor_name', 'actors.email as actor_email']);
    }
}
