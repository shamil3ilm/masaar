<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSequence;
use DateTimeInterface;
use RuntimeException;

/**
 * Gives an invoice its number when it is issued.
 *
 * Called by IssuingLifecycle::issue inside the transaction that holds the
 * organization row and allocates the ZATCA counter, which is what makes this
 * safe: every issuance for the organization waits on that lock, so two cannot
 * read the same next number, and a failure after this rolls the counter back
 * with everything else. Issued invoices therefore run without gaps.
 *
 * A number already taken - typed into an earlier draft by hand, say - is
 * skipped rather than repeated: ZATCA requires the number to identify the
 * invoice uniquely for its seller.
 */
class InvoiceNumbering
{
    /** More numbers than this already taken in a row is a misconfiguration. */
    private const MAX_SKIPPED = 1000;

    /**
     * The next number for this invoice. The caller holds the organization
     * lock and saves the invoice.
     */
    public function allocate(Invoice $invoice): string
    {
        $now = FatooraTime::saudiNow();
        $sequence = $this->sequenceFor($invoice);
        $period = $sequence->periodOf($now);

        if ($period !== $sequence->period) {
            $sequence->next_number = 1;
            $sequence->period = $period;
        }

        // Already taken - after the reset period changed, say, or a number
        // typed by hand - jumps past the highest number the pattern has given
        // in this period, rather than stepping one at a time under the lock.
        if ($this->taken((string) $invoice->org_id, $sequence->format($sequence->next_number, $now))) {
            $sequence->next_number = $this->highestGiven($sequence, (string) $invoice->org_id, $now) + 1;
        }

        for ($skipped = 0; $skipped <= self::MAX_SKIPPED; $skipped++) {
            $number = $sequence->format($sequence->next_number, $now);
            $sequence->next_number++;

            if (! $this->taken((string) $invoice->org_id, $number)) {
                $sequence->save();

                return $number;
            }
        }

        throw new RuntimeException(
            'The '.$sequence->document_type.' numbering pattern '.$sequence->pattern.' produced '
            .self::MAX_SKIPPED.' numbers in a row that are already in use. Change the pattern or the next number.'
        );
    }

    /**
     * What the next number would be, without taking it.
     */
    public function preview(InvoiceSequence $sequence, ?DateTimeInterface $on = null): string
    {
        $on ??= FatooraTime::saudiNow();
        $counter = $sequence->periodOf($on) === $sequence->period ? $sequence->next_number : 1;

        return $sequence->format($counter, $on);
    }

    /**
     * The branch's own sequence for this kind of document, else the
     * organization's, created with the defaults the first time.
     */
    private function sequenceFor(Invoice $invoice): InvoiceSequence
    {
        $type = $invoice->document_type->value;

        return InvoiceSequence::withoutTenantScope(function () use ($invoice, $type): InvoiceSequence {
            $query = fn (string $scope) => InvoiceSequence::query()
                ->where('org_id', $invoice->org_id)
                ->where('scope', $scope)
                ->where('document_type', $type)
                ->lockForUpdate()
                ->first();

            return ($invoice->branch_id ? $query((string) $invoice->branch_id) : null)
                ?? $query(InvoiceSequence::ORGANIZATION)
                ?? InvoiceSequence::create(self::defaults((string) $invoice->org_id, $type));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(string $organizationId, string $documentType, ?string $branchId = null): array
    {
        return [
            'org_id' => $organizationId,
            'branch_id' => $branchId,
            'scope' => $branchId ?? InvoiceSequence::ORGANIZATION,
            'document_type' => $documentType,
            'pattern' => InvoiceSequence::DEFAULT_PATTERNS[$documentType] ?? 'DOC-{YYYY}-{SEQ}',
            'padding' => InvoiceSequence::DEFAULT_PADDING,
            'reset' => InvoiceSequence::DEFAULT_RESET,
            'next_number' => 1,
            'period' => null,
        ];
    }

    /**
     * The highest counter this pattern shows among the organization's
     * numbers for the period this day falls in.
     */
    private function highestGiven(InvoiceSequence $sequence, string $organizationId, DateTimeInterface $on): int
    {
        // The pattern with this period's date filled in, either side of the
        // counter. The validated pattern holds {SEQ} exactly once.
        [$before, $after] = explode('{SEQ}', strtr($sequence->pattern, [
            '{YYYY}' => $on->format('Y'),
            '{YY}' => $on->format('y'),
            '{MM}' => $on->format('m'),
        ]), 2) + [1 => ''];

        // '!' as the LIKE escape: a backslash means something else to MySQL
        // inside a string literal, and the pattern cannot contain '!'.
        $escape = fn (string $text): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);

        $numbers = Invoice::withoutTenantScope(fn () => Invoice::query()
            ->where('org_id', $organizationId)
            ->whereRaw("invoice_number LIKE ? ESCAPE '!'", [$escape($before).'%'.$escape($after)])
            ->pluck('invoice_number'));

        $highest = 0;
        $shape = '/^'.preg_quote($before, '/').'(\d+)'.preg_quote($after, '/').'$/';

        foreach ($numbers as $number) {
            if (preg_match($shape, (string) $number, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $highest;
    }

    private function taken(string $organizationId, string $number): bool
    {
        return Invoice::withoutTenantScope(fn (): bool => Invoice::query()
            ->where('org_id', $organizationId)
            ->where('invoice_number', $number)
            ->exists());
    }
}
