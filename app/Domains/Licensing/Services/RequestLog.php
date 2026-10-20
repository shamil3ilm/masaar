<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use App\Domains\Licensing\DTOs\Credential;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\ApiRequestLog;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Scope;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * An organization's recent partner API requests: what its administrators
 * read to find out why their ERP's call was refused.
 *
 * Only what identifies a request and its answer is kept - method, path (no
 * query string), status, the error code, which key, when, how long and the
 * request id. Never a body, a header, a secret or an address. Kept for the
 * days the customization integrations.request_log_days gives the
 * organization (prune()).
 */
class RequestLog
{
    public const RETENTION = 'integrations.request_log_days';

    /** The licence's own key, in the key filter. */
    public const LICENCE_KEY = 'licence';

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    /**
     * Note one request. Never at the request's expense.
     */
    public function record(Credential $credential, Request $request, int $status, ?string $errorCode, ?string $requestId, int $durationMs): void
    {
        $organizationId = $credential->licence->org_id;

        if ($organizationId === null) {
            return;
        }

        try {
            $entry = new ApiRequestLog([
                'org_id' => (string) $organizationId,
                'license_id' => (string) $credential->licence->id,
                'api_key_id' => $credential->key?->id,
                'method' => mb_substr($request->method(), 0, 10),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
                'status' => $status,
                'error_code' => $errorCode === null ? null : mb_substr($errorCode, 0, 64),
                'duration_ms' => max(0, $durationMs),
                'occurred_at' => now(),
            ]);

            $entry->forceFill(['request_id' => $requestId === null ? null : mb_substr($requestId, 0, 100)]);

            ApiRequestLog::withoutTenantScope(fn () => $entry->save());
        } catch (\Throwable $e) {
            Log::warning('Failed to record API request', ['org_id' => $organizationId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The error code an answer carries in its envelope, read from the body
     * that is otherwise never kept.
     */
    public static function errorCodeOf(mixed $response): ?string
    {
        if (! is_object($response) || ! method_exists($response, 'getStatusCode') || $response->getStatusCode() < 400 || ! method_exists($response, 'getContent')) {
            return null;
        }

        $body = json_decode((string) $response->getContent(), true);
        $code = is_array($body) ? ($body['error']['code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    /**
     * @param  array<string, string>  $keys  key id => name, and LICENCE_KEY
     */
    public function spec(string $timezone, array $keys): ListSpec
    {
        return new ListSpec(
            key: 'portal.integrations.requests',
            sorts: ['newest' => new Sort(__('Newest'), 'api_request_logs.occurred_at')],
            defaultSort: 'newest',
            filters: [
                new DateRange('api_request_logs.occurred_at', __('When'), $timezone, instant: true),
                new Choice('key', __('Key'), $keys, function (QueryBuilder $query, string $key): void {
                    $key === self::LICENCE_KEY
                        ? $query->whereNull('api_request_logs.api_key_id')
                        : $query->where('api_request_logs.api_key_id', $key);
                }, __('Any key')),
                new Choice('outcome', __('Answer'), [
                    'ok' => __('Succeeded (2xx)'),
                    'refused' => __('Refused (4xx)'),
                    'failed' => __('Failed (5xx)'),
                ], function (QueryBuilder $query, string $outcome): void {
                    [$from, $to] = match ($outcome) {
                        'ok' => [200, 399],
                        'refused' => [400, 499],
                        default => [500, 599],
                    };
                    $query->whereBetween('api_request_logs.status', [$from, $to]);
                }, __('Any answer')),
                new Choice('method', __('Method'), array_combine(['GET', 'POST', 'PUT', 'DELETE'], ['GET', 'POST', 'PUT', 'DELETE']), 'api_request_logs.method'),
                new Search('q', __('Search'), ['api_request_logs.path', 'api_request_logs.request_id', 'api_request_logs.error_code'], __('A path, request id or error code')),
            ],
            defaultPerPage: 50,
            tiebreaker: 'api_request_logs.id',
        );
    }

    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query($organizationId));
    }

    /**
     * The organization's keys by id, revoked ones too - a log names the key
     * that was used then.
     *
     * @return array<string, string> key id => name, and LICENCE_KEY
     */
    public function keys(string $organizationId): array
    {
        $keys = ApiKey::withoutTenantScope(fn () => ApiKey::withTrashed()
            ->where('org_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'deleted_at']))
            ->mapWithKeys(fn (ApiKey $key): array => [(string) $key->id => $key->trashed() ? __(':name (revoked)', ['name' => $key->name]) : $key->name])
            ->all();

        return [self::LICENCE_KEY => __('The licence key'), ...$keys];
    }

    /** How many days the organization's requests are kept. */
    public function retentionDays(string $organizationId): int
    {
        return max(1, (int) $this->customizations->get(self::RETENTION, Scope::organization($organizationId)));
    }

    /**
     * Remove what each organization keeps no longer.
     *
     * @return int the requests removed
     */
    public function prune(): int
    {
        $removed = 0;

        $organizations = ApiRequestLog::withoutTenantScope(fn () => ApiRequestLog::query()->distinct()->pluck('org_id'));

        foreach ($organizations as $organizationId) {
            $before = now()->subDays($this->retentionDays((string) $organizationId));

            $removed += (int) ApiRequestLog::withoutTenantScope(fn () => ApiRequestLog::query()
                ->where('org_id', $organizationId)
                ->where('occurred_at', '<', $before)
                ->delete());
        }

        return $removed;
    }

    /**
     * @return Builder<ApiRequestLog>
     */
    private function query(string $organizationId): Builder
    {
        return ApiRequestLog::query()
            ->where('api_request_logs.org_id', $organizationId)
            ->where('api_request_logs.occurred_at', '>=', now()->subDays($this->retentionDays($organizationId)));
    }
}
