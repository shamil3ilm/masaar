<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Users as the platform console sees them: who they are, where they belong,
 * and what the record says they did.
 *
 * Cross-tenant by design, so it reads through the query builder. Every query
 * names its columns. The users table holds a password hash and a remember
 * token, and a screen for debugging has no use for either - selecting them
 * would leave keeping them off the page to the view.
 */
class UserReport
{
    private const COLUMNS = [
        'users.id',
        'users.name',
        'users.email',
        'users.status',
        'users.is_platform_admin',
        // When two-factor was confirmed - never the secret or the codes.
        'users.two_factor_confirmed_at',
        'users.email_verified_at',
        'users.default_org_id',
        'users.created_at',
        'users.updated_at',
    ];

    /** What counts as signing in or out, on either surface. */
    private const SIGN_INS = ['security.login.succeeded', 'security.login.failed', 'security.logout'];

    /**
     * Newest first, each with how many organizations it belongs to and when
     * it last signed in.
     */
    public function page(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        return DB::table('users')
            ->select(self::COLUMNS)
            ->selectSub(
                DB::table('organization_user')->selectRaw('COUNT(*)')->whereColumn('organization_user.user_id', 'users.id'),
                'organizations'
            )
            ->selectSub($this->lastSignIn(), 'last_sign_in_at')
            ->when($search !== null, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->where('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%')
            ))
            ->when($status !== null, fn (Builder $query) => $query->where('users.status', $status))
            ->orderByDesc('users.created_at')
            ->orderByDesc('users.id')
            ->paginate($perPage);
    }

    /**
     * One user's row, or null when there is none.
     */
    public function find(string $id): ?object
    {
        return DB::table('users')
            ->select(self::COLUMNS)
            ->selectSub($this->lastSignIn(), 'last_sign_in_at')
            ->where('users.id', $id)
            ->first();
    }

    /**
     * The organizations a user belongs to, with the role and membership
     * status the pivot holds.
     */
    public function memberships(string $id): Collection
    {
        return DB::table('organization_user')
            ->join('organizations', 'organizations.id', '=', 'organization_user.org_id')
            ->where('organization_user.user_id', $id)
            ->orderBy('organizations.name')
            ->get([
                'organizations.id',
                'organizations.name',
                'organizations.status as organization_status',
                'organization_user.role',
                'organization_user.status',
                'organization_user.created_at as joined_at',
            ]);
    }

    /**
     * Sign-ins, failures and sign-outs filed under the account, newest first.
     */
    public function signIns(string $id, int $limit): Collection
    {
        return $this->decoded(
            DB::table('audit_logs')
                ->where('entity_type', 'User')
                ->where('entity_id', $id)
                ->whereIn('action', self::SIGN_INS)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(['action', 'ip_address', 'user_agent', 'metadata', 'created_at'])
        );
    }

    /**
     * What admins have done to the account - suspended it, reactivated it -
     * with who did it and the reason they gave.
     */
    public function accountHistory(string $id, int $limit): Collection
    {
        return $this->decoded(
            DB::table('audit_logs')
                ->leftJoin('users as actors', 'actors.id', '=', 'audit_logs.user_id')
                ->where('audit_logs.entity_type', 'User')
                ->where('audit_logs.entity_id', $id)
                ->where('audit_logs.action', 'like', 'admin.%')
                ->orderByDesc('audit_logs.created_at')
                ->limit($limit)
                ->get([
                    'audit_logs.action',
                    'audit_logs.metadata',
                    'audit_logs.ip_address',
                    'audit_logs.created_at',
                    'actors.email as actor_email',
                ])
        );
    }

    /**
     * Everything the user did, newest first: invoices, settings, and for an
     * admin, their console actions.
     */
    public function activity(string $id, int $limit): Collection
    {
        return $this->decoded(
            DB::table('audit_logs')
                ->leftJoin('organizations', 'organizations.id', '=', 'audit_logs.org_id')
                ->where('audit_logs.user_id', $id)
                ->whereNotIn('audit_logs.action', self::SIGN_INS)
                ->orderByDesc('audit_logs.created_at')
                ->limit($limit)
                ->get([
                    'audit_logs.action',
                    'audit_logs.entity_type',
                    'audit_logs.entity_id',
                    'audit_logs.metadata',
                    'audit_logs.ip_address',
                    'audit_logs.created_at',
                    'organizations.name as organization',
                ])
        );
    }

    /**
     * The submissions this user started, with the invoice each one filed.
     */
    public function submissions(string $id, int $limit): Collection
    {
        return DB::table('invoice_submissions')
            ->leftJoin('invoices', 'invoices.id', '=', 'invoice_submissions.invoice_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'invoice_submissions.org_id')
            ->where('invoice_submissions.created_by', $id)
            ->orderByDesc('invoice_submissions.created_at')
            ->limit($limit)
            ->get([
                'invoice_submissions.id',
                'invoice_submissions.state',
                'invoice_submissions.submission_type',
                'invoice_submissions.created_at',
                'invoices.invoice_number',
                'organizations.id as org_id',
                'organizations.name as organization',
            ]);
    }

    /**
     * When the account last signed in successfully, as a correlated subquery.
     */
    private function lastSignIn(): Builder
    {
        return DB::table('audit_logs')
            ->selectRaw('MAX(audit_logs.created_at)')
            ->where('audit_logs.entity_type', 'User')
            ->where('audit_logs.action', 'security.login.succeeded')
            ->whereColumn('audit_logs.entity_id', 'users.id');
    }

    /**
     * The query builder hands JSON columns back as strings.
     */
    private function decoded(Collection $rows): Collection
    {
        return $rows->map(fn (object $row): object => (object) [
            ...(array) $row,
            'metadata' => is_string($row->metadata ?? null) ? (array) json_decode($row->metadata, true) : [],
        ]);
    }
}
