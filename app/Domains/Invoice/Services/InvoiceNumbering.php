<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSequence;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Tills;
use App\Support\CountryClock;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gives an invoice its number when it is issued.
 *
 * Called by IssuingLifecycle::issue inside the transaction that holds the
 * chain's issuing lock and allocates the counter. The sequence row is locked
 * until that transaction ends, so two issuances drawing on one sequence -
 * two devices sharing the organization's, say - cannot read the same next
 * number, and a failure after this rolls the number back with everything
 * else. Issued invoices therefore run without gaps.
 *
 * Each till numbers from a series of its own by default - the
 * organization's pattern with the till's number in it, INV-T01-{YYYY}-{SEQ}
 * - so two tills never wait for each other here. The till is the unit the
 * invoice is issued on (its device), or, for one issued on head office's,
 * its branch, as before tills were kept apart from branches; a branch's
 * first till has the branch's id, so the two are one series. An organization that turns
 * on shared_numbering numbers every document from its one series instead,
 * and then its tills do wait for each other, for the rest of the issuance.
 *
 * A number already taken - typed into an earlier draft by hand, say - is
 * skipped rather than repeated: ZATCA requires the number to identify the
 * invoice uniquely for its seller.
 */
class InvoiceNumbering
{
    /** More numbers than this already taken in a row is a misconfiguration. */
    private const MAX_SKIPPED = 1000;

    public function __construct(
        private readonly Tills $tills,
    ) {}

    /**
     * The next number for this invoice. The caller holds the issuing lock
     * and saves the invoice.
     */
    public function allocate(Invoice $invoice): string
    {
        $now = $this->now((string) $invoice->org_id);
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
        $on ??= $this->now((string) $sequence->org_id);
        $counter = $sequence->periodOf($on) === $sequence->period ? $sequence->next_number : 1;

        return $sequence->format($counter, $on);
    }

    /**
     * Now, on the organization's own clock: the date in a number and the
     * period a counter restarts at are what the day is where it trades.
     */
    public function now(string $organizationId): Carbon
    {
        return CountryClock::now(Organization::query()->whereKey($organizationId)->value('country'));
    }

    /**
     * The sequence this invoice is numbered from, locked: the till's own
     * unless the organization shares one series, else the organization's.
     *
     * Found first without a lock and then locked by key, so a sequence that
     * is not there takes no lock on the gap where it would be: two issuances
     * of different devices both holding that gap and both inserting into it
     * deadlock. A till's series is created with an insert that does nothing
     * if another issuance created it first; the organization's under the
     * organization row, the lock NumberingSettings::save takes, looking again
     * under it - a locking read sees what another issuance created meanwhile.
     */
    private function sequenceFor(Invoice $invoice): InvoiceSequence
    {
        $type = $invoice->document_type->value;
        $organizationId = (string) $invoice->org_id;
        $till = self::shared($organizationId) ? null : self::tillOf($invoice);

        return InvoiceSequence::withoutTenantScope(function () use ($organizationId, $till, $type): InvoiceSequence {
            $query = fn (string $scope, bool $lock) => InvoiceSequence::query()
                ->where('org_id', $organizationId)
                ->where('scope', $scope)
                ->where('document_type', $type)
                ->when($lock, fn ($query) => $query->lockForUpdate())
                ->first();

            $scope = $till ?? InvoiceSequence::ORGANIZATION;
            $found = $query($scope, false);

            if ($found !== null) {
                return InvoiceSequence::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();
            }

            if ($till !== null) {
                $now = now();
                InvoiceSequence::query()->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    ...$this->tillDefaults($organizationId, $type, $till),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $query($till, true) ?? throw new RuntimeException('The numbering series of till '.$till.' could not be created.');
            }

            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();

            return $query(InvoiceSequence::ORGANIZATION, true) ?? InvoiceSequence::create(self::defaults($organizationId, $type));
        });
    }

    /**
     * The series an invoice numbers from when each till has its own: the
     * till it is issued on, else its branch's, else none - the
     * organization's.
     */
    public static function tillOf(Invoice $invoice): ?string
    {
        if ($invoice->device_id !== null && $invoice->device_id !== $invoice->org_id) {
            return (string) $invoice->device_id;
        }

        return $invoice->branch_id === null ? null : (string) $invoice->branch_id;
    }

    /** Whether the organization numbers every branch from one series. */
    public static function shared(string $organizationId): bool
    {
        return (bool) Organization::query()->whereKey($organizationId)->value('shared_numbering');
    }

    /**
     * A till's own series before anyone configures it: the organization's,
     * with the till's number after its first part - INV-{YYYY}-{SEQ} becomes
     * INV-T01-{YYYY}-{SEQ} - and never a pattern another series of the
     * organization already has, which would count through the same numbers.
     *
     * $tillId is a till's id, or a branch's - its first till's.
     *
     * @return array<string, mixed>
     */
    public function tillDefaults(string $organizationId, string $documentType, string $tillId): array
    {
        $organization = InvoiceSequence::withoutTenantScope(fn () => InvoiceSequence::query()
            ->where('org_id', $organizationId)
            ->where('scope', InvoiceSequence::ORGANIZATION)
            ->where('document_type', $documentType)
            ->first()) ?? new InvoiceSequence(self::defaults($organizationId, $documentType));

        $used = InvoiceSequence::withoutTenantScope(fn () => InvoiceSequence::query()
            ->where('org_id', $organizationId)
            ->where('scope', '!=', $tillId)
            ->pluck('pattern')
            ->all());
        $used = [...$used, ...array_values(InvoiceSequence::DEFAULT_PATTERNS)];

        $till = $this->tills->tillNumber($tillId) ?? 1;

        do {
            $pattern = self::withTill((string) $organization->pattern, $till++);
        } while (in_array($pattern, $used, true));

        return [
            ...self::defaults($organizationId, $documentType, $this->tills->branchOf($tillId), $tillId),
            'pattern' => $pattern,
            'padding' => $organization->padding,
            'reset' => $organization->reset,
        ];
    }

    /**
     * The till's mark before the pattern's first token: after its fixed
     * prefix, so the numbers of every till still start alike.
     */
    private static function withTill(string $pattern, int $till): string
    {
        $mark = 'T'.str_pad((string) $till, 2, '0', STR_PAD_LEFT).'-';
        $token = (int) strpos($pattern, '{');

        return substr($pattern, 0, $token).$mark.substr($pattern, $token);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(string $organizationId, string $documentType, ?string $branchId = null, ?string $scope = null): array
    {
        return [
            'org_id' => $organizationId,
            'branch_id' => $branchId,
            'scope' => $scope ?? $branchId ?? InvoiceSequence::ORGANIZATION,
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
