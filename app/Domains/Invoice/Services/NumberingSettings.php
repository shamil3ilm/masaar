<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\InvoiceSequence;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reading and changing how an organization numbers its documents.
 *
 * The rules exist so a configuration cannot produce the same number twice -
 * which ZATCA refuses, since the number identifies the invoice for its
 * seller: a counter that resets must have the period in the number, and two
 * sequences cannot share a pattern.
 */
class NumberingSettings
{
    /** The document types that are issued, and so numbered. */
    public const TYPES = ['invoice', 'credit_note', 'debit_note', 'prepayment'];

    public function __construct(
        private readonly InvoiceNumbering $numbering,
    ) {}

    /**
     * Each document type's sequence as it stands - stored, or the default it
     * would be created with - with the number it would give next.
     *
     * @return list<array<string, mixed>>
     */
    public function sequences(string $organizationId, ?string $branchId = null): array
    {
        $shared = $branchId !== null && InvoiceNumbering::shared($organizationId);

        return array_map(function (string $type) use ($organizationId, $branchId, $shared): array {
            $own = $this->effective($organizationId, $type, $branchId);

            // A till numbers from its own series - the one it has, or the
            // one it will be given at its first issue - unless the
            // organization shares one series, which is then what it shows.
            $sequence = $shared ? $this->effective($organizationId, $type, null) : $own;

            return [
                'document_type' => $type,
                'branch_id' => $branchId,
                'pattern' => $sequence->pattern,
                'padding' => $sequence->padding,
                'reset' => $sequence->reset,
                'next' => $this->numbering->preview($sequence),
                'configured' => $own->exists,
                'inherited' => $shared,
            ];
        }, self::TYPES);
    }

    /**
     * Number every branch from the organization's one series, or each till
     * from its own. Taken by the next issue; numbers already given stay.
     */
    public function share(string $organizationId, bool $shared): void
    {
        DB::table('organizations')->where('id', $organizationId)->update(['shared_numbering' => $shared]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(string $organizationId, string $documentType, mixed $reset, ?string $branchId = null): array
    {
        return [
            'pattern' => [
                'required', 'string', 'max:60',
                // Literal text from what an invoice number can carry, and the
                // known tokens - nothing that would be printed verbatim by
                // mistake, like {YEAR}.
                'regex:/^(?:[A-Za-z0-9\-\/_.]|\{(?:YYYY|YY|MM|SEQ)\})+$/',
                $this->countsOnce(),
                $this->periodShown($reset),
                $this->notShared($organizationId, $documentType, $branchId),
            ],
            'padding' => ['required', 'integer', 'between:1,10'],
            'reset' => ['required', Rule::in(InvoiceSequence::RESETS)],
            'next_number' => ['sometimes', 'nullable', 'integer', 'between:1,999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'pattern.regex' => __('The pattern may use letters, digits, - / _ . and the tokens {YYYY}, {YY}, {MM} and {SEQ}.'),
        ];
    }

    /**
     * Store a sequence. A next_number given applies from now: the period is
     * set to the current one, so it is not reset at the next issue.
     *
     * @param  array{pattern: string, padding: int, reset: string, next_number?: int|null}  $settings
     */
    public function save(string $organizationId, string $documentType, array $settings, ?string $branchId = null): InvoiceSequence
    {
        return DB::transaction(function () use ($organizationId, $documentType, $settings, $branchId): InvoiceSequence {
            // The lock issuance takes to create a sequence. Without it a
            // first save and a first issue could both create the same
            // sequence and one would fail on the unique index - the issue,
            // possibly.
            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();

            $sequence = $this->effective($organizationId, $documentType, $branchId, lock: true);

            $sequence->fill([
                'pattern' => $settings['pattern'],
                'padding' => (int) $settings['padding'],
                'reset' => $settings['reset'],
            ]);

            if (! empty($settings['next_number'])) {
                $sequence->next_number = (int) $settings['next_number'];
                $sequence->period = $sequence->periodOf($this->numbering->now($organizationId));
            } elseif ($sequence->isDirty('reset')) {
                // A new reset period starts counting at the next issue.
                $sequence->period = null;
            }

            $sequence->save();

            return $sequence;
        });
    }

    private function effective(string $organizationId, string $documentType, ?string $branchId, bool $lock = false): InvoiceSequence
    {
        $query = InvoiceSequence::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('scope', $branchId ?? InvoiceSequence::ORGANIZATION)
            ->where('document_type', $documentType);

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? new InvoiceSequence($branchId === null
                ? InvoiceNumbering::defaults($organizationId, $documentType)
                : $this->numbering->tillDefaults($organizationId, $documentType, $branchId));
    }

    private function countsOnce(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (substr_count((string) $value, '{SEQ}') !== 1) {
                $fail(__('The pattern needs {SEQ}, the counter, exactly once.'));
            }
        };
    }

    /**
     * A counter that restarts repeats its numbers unless the period it
     * restarts for is part of them.
     */
    private function periodShown(mixed $reset): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($reset): void {
            $pattern = (string) $value;
            $year = str_contains($pattern, '{YYYY}') || str_contains($pattern, '{YY}');

            if ($reset === 'yearly' && ! $year) {
                $fail(__('A counter that restarts each year needs {YYYY} or {YY} in the pattern, or its numbers repeat.'));
            }

            if ($reset === 'monthly' && (! $year || ! str_contains($pattern, '{MM}'))) {
                $fail(__('A counter that restarts each month needs the year and {MM} in the pattern, or its numbers repeat.'));
            }
        };
    }

    /**
     * Two sequences with one pattern would count through the same numbers.
     */
    private function notShared(string $organizationId, string $documentType, ?string $branchId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($organizationId, $documentType, $branchId): void {
            $scopes = array_unique([InvoiceSequence::ORGANIZATION, $branchId ?? InvoiceSequence::ORGANIZATION]);

            $stored = InvoiceSequence::acrossTenants()
                ->where('org_id', $organizationId)
                ->get(['scope', 'document_type', 'pattern']);

            foreach (self::TYPES as $type) {
                foreach ($scopes as $scope) {
                    if ($type === $documentType && $scope === ($branchId ?? InvoiceSequence::ORGANIZATION)) {
                        continue;
                    }

                    $pattern = $stored->first(fn ($s) => $s->scope === $scope && $s->document_type === $type)?->pattern
                        ?? ($scope === InvoiceSequence::ORGANIZATION ? InvoiceSequence::DEFAULT_PATTERNS[$type] : null);

                    if ($pattern === $value) {
                        $fail('That pattern is already used for '.str_replace('_', ' ', $type)
                            .($scope === InvoiceSequence::ORGANIZATION ? '' : ' at this branch')
                            .'. Two sequences with one pattern would give the same numbers.');

                        return;
                    }
                }
            }

            // And no branch's own sequence elsewhere.
            if ($stored->contains(fn ($s) => ! in_array($s->scope, $scopes, true) && $s->pattern === $value)) {
                $fail(__('That pattern is already used by another branch. Two sequences with one pattern would give the same numbers.'));
            }
        };
    }
}
