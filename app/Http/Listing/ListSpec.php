<?php

declare(strict_types=1);

namespace App\Http\Listing;

/**
 * What one history list can be sorted and narrowed by.
 *
 * Every list in the portal and the console declares one of these, and the
 * toolkit does the rest the same way for each: reading the query string,
 * narrowing and ordering the query, the chips, the saved views, the CSV and
 * the page size. A list never reads a filter off the request itself, so
 * nothing typed into a URL reaches its query without being checked.
 *
 * key        the list's route name: what a saved view is saved against.
 * sorts      the orders offered, by the name the query string uses.
 * filters    the ways it narrows, in the order the drawer shows them.
 * tiebreaker the column that settles a tie in the sort, so paging over
 *            rows that share a date shows each once (InvoicePagingTest).
 */
final readonly class ListSpec
{
    /** The page sizes offered. */
    public const PAGE_SIZES = [10, 25, 50, 100];

    /**
     * @param  array<string, Sort>  $sorts
     * @param  list<Filter>  $filters
     */
    public function __construct(
        public string $key,
        public array $sorts,
        public string $defaultSort,
        public array $filters,
        public int $defaultPerPage = 25,
        public string $tiebreaker = 'id',
    ) {}
}
