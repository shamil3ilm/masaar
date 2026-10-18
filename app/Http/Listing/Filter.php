<?php

declare(strict_types=1);

namespace App\Http\Listing;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * One way a history list can be narrowed: what it reads from the query
 * string, what it does to the query, and how it is said back on a chip.
 *
 * read() never throws. A malformed value - an array where a string was
 * meant, a date that is not one, a choice nobody offered - narrows nothing,
 * so a hand-edited link shows more rather than failing. Every value that
 * reaches apply() has been read here first, so nothing typed into a URL
 * reaches a query unchecked.
 */
interface Filter
{
    /** The filter's name: the first of keys(), and how a list asks for it. */
    public function name(): string;

    /**
     * The query-string keys it reads, so a chip can take exactly these away.
     *
     * @return list<string>
     */
    public function keys(): array;

    /** What the control and the chip are labelled. */
    public function label(): string;

    /**
     * The value the query string gives, or null when it narrows nothing.
     *
     * @param  array<string, mixed>  $query
     */
    public function read(array $query): mixed;

    /** Narrow the query by a value read() returned. */
    public function apply(Builder $query, mixed $value): void;

    /** The value as its chip says it. */
    public function describe(mixed $value): string;

    /**
     * The query-string values that ask for the value again, by key: what a
     * saved view and a shared link carry.
     *
     * @return array<string, string>
     */
    public function encode(mixed $value): array;

    /** The Blade view that draws its control, given $filter and $list. */
    public function view(): string;
}
