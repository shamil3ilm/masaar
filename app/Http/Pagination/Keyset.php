<?php

declare(strict_types=1);

namespace App\Http\Pagination;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A page of a list walked by an opaque cursor, for an ERP keeping a copy in
 * step: what changed since it last looked, oldest change first.
 *
 * Page numbers do not hold still under a list that is being written to - a
 * row added at the top moves every later row down a page, so a client paging
 * through sees one twice or never. The cursor names the last row returned by
 * its change time and its id, and the next page starts strictly after it, so
 * a walk sees every row once whatever is written meanwhile; a row changed
 * during the walk comes again later, as it now is. The id breaks ties
 * between rows changed in the same second.
 *
 * The cursor is the API's to read: base64url of the last row's updated_at
 * and id. A client stores it and sends it back, and nothing else.
 */
final class Keyset
{
    /** The query parameter that asks for this paging and carries the cursor. */
    public const CURSOR = 'cursor';

    /** The query parameter that limits a list to rows changed since then. */
    public const SINCE = 'updated_since';

    /**
     * @param  list<Model>  $items
     */
    private function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly int $perPage,
    ) {}

    /** Whether the request pages by cursor: it sends one, even empty for the first page. */
    public static function requested(Request $request): bool
    {
        return $request->query->has(self::CURSOR);
    }

    /**
     * updated_since as a time, or null when absent.
     *
     * @throws ValidationException When it is not a date and time
     */
    public static function since(Request $request): ?CarbonImmutable
    {
        $since = $request->query(self::SINCE);

        if ($since === null || $since === '') {
            return null;
        }

        try {
            // In the zone the timestamps are stored in, which is the
            // application's; a bound date is compared as it is written.
            return CarbonImmutable::parse(is_string($since) ? $since : '')->setTimezone(date_default_timezone_get());
        } catch (Throwable) {
            throw ValidationException::withMessages([
                self::SINCE => __('updated_since must be a date and time, such as 2026-10-27T09:00:00Z.'),
            ]);
        }
    }

    /**
     * The page after the request's cursor, the query's rows by updated_at and
     * then id. The query is the list's own, with its filters, unordered.
     *
     * @throws ValidationException When the cursor is not one this API gave
     */
    public static function page(Builder $query, Request $request, int $perPage): self
    {
        $cursor = $request->query(self::CURSOR);

        if ($cursor !== null && $cursor !== '') {
            [$at, $id] = self::decode($cursor);

            $query->where(fn (Builder $q) => $q
                ->where('updated_at', '>', $at)
                ->orWhere(fn (Builder $tie) => $tie->where('updated_at', $at)->where('id', '>', $id)));
        }

        $rows = $query->orderBy('updated_at')->orderBy('id')->limit($perPage + 1)->get();
        $items = $rows->take($perPage)->values()->all();
        $last = end($items);

        return new self(
            $items,
            $rows->count() > $perPage && $last instanceof Model ? self::encode($last) : null,
            $perPage,
        );
    }

    private static function encode(Model $last): string
    {
        $json = (string) json_encode(['u' => (string) $last->getRawOriginal('updated_at'), 'i' => (string) $last->getKey()]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws ValidationException
     */
    private static function decode(mixed $cursor): array
    {
        $decoded = is_string($cursor) ? json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true) : null;

        if (! is_array($decoded)
            || ! is_string($decoded['u'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $decoded['u']) !== 1
            || ! is_string($decoded['i'] ?? null) || $decoded['i'] === '') {
            throw ValidationException::withMessages([
                self::CURSOR => __('The cursor is not one this API gave. Start again without one, or with the next_cursor of the last page.'),
            ]);
        }

        return [$decoded['u'], $decoded['i']];
    }
}
