<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail, for reading.
 *
 * Every tenant's and every admin's entries in one place, filtered by what
 * happened, who did it and when. Cross-tenant by design.
 */
class AuditReport
{
    /**
     * The groups an operator filters by. Each is an action prefix.
     */
    public const CATEGORIES = [
        'admin.' => 'Admin actions',
        'security.' => 'Sign-ins and security',
        'auth.' => 'Registrations and API sign-outs',
    ];

    /**
     * @param  array{category?: string, actor?: string, from?: string, to?: string}  $filters
     */
    public function page(array $filters, int $perPage): LengthAwarePaginator
    {
        $category = $filters['category'] ?? null;
        $actor = $filters['actor'] ?? null;

        $page = DB::table('audit_logs')
            ->leftJoin('users as actors', 'actors.id', '=', 'audit_logs.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'audit_logs.org_id')
            ->select([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.old_values',
                'audit_logs.new_values',
                'audit_logs.metadata',
                'audit_logs.ip_address',
                'audit_logs.created_at',
                'actors.id as actor_id',
                'actors.email as actor_email',
                'organizations.name as organization',
            ])
            ->when(
                $category !== null && array_key_exists($category, self::CATEGORIES),
                fn (Builder $query) => $query->where('audit_logs.action', 'like', $category.'%')
            )
            ->when($actor !== null, fn (Builder $query) => $query->where('actors.email', 'like', '%'.$actor.'%'))
            ->when(isset($filters['from']), fn (Builder $query) => $query->where('audit_logs.created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn (Builder $query) => $query->where('audit_logs.created_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
            ->paginate($perPage);

        $page->setCollection($page->getCollection()->map(fn (object $row): object => (object) [
            ...(array) $row,
            'metadata' => $this->json($row->metadata),
            'old_values' => $this->json($row->old_values),
            'new_values' => $this->json($row->new_values),
        ]));

        return $page;
    }

    /**
     * @return array<mixed>
     */
    private function json(mixed $value): array
    {
        return is_string($value) ? (array) json_decode($value, true) : [];
    }
}
