<?php

declare(strict_types=1);

namespace App\Domains\Books\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Books\Models\VatPeriod;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * The VAT periods an organization has filed, and what filing closes.
 *
 * Filing snapshots the return's figures. From then on the period's own
 * figures do not move:
 *
 *   - a purchase, supplier credit note, payment to a supplier or customer
 *     dated in it counts from the next open day (openDay): input tax may be
 *     deducted in a later period than the supply's (KSA VAT Implementing
 *     Regulations Art 49(8); UAE Federal Decree-Law No. 8 of 2017 Art
 *     55(2)), and what a payment changes is the period's in which it is
 *     taken account of;
 *   - voiding a purchase, a supplier's write-off or a bad-debt claim dated
 *     in it is refused (assertOpen): a correction is a credit note, or a
 *     correction in the next return (KSA Art 63; the UAE's voluntary
 *     disclosure);
 *   - a sale whose tax point falls in it after filing - which issuing does
 *     not stop - shows as the redraft's difference from the snapshot (drift),
 *     for the next return's corrections.
 *
 * Reopening a filed period is an owner's, with a reason, on the audit log.
 */
class VatPeriods
{
    /** @var array<string, Collection<int, VatPeriod>> filed periods, by organization */
    private array $filed = [];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /** @return Collection<int, VatPeriod> every period ever filed, latest first */
    public function all(string $organizationId): Collection
    {
        return VatPeriod::acrossTenants()->where('org_id', $organizationId)->orderByDesc('starts_on')->get();
    }

    public function find(string $organizationId, string $id): ?VatPeriod
    {
        return VatPeriod::query()->where('org_id', $organizationId)->find($id);
    }

    /** The filed period a day falls in, or null where it is open. */
    public function filedOn(string $organizationId, CarbonImmutable $day): ?VatPeriod
    {
        $date = $day->toDateString();

        return $this->filed($organizationId)->first(fn (VatPeriod $period): bool => $period->starts_on->toDateString() <= $date && $period->ends_on->toDateString() >= $date);
    }

    /** The day itself where its period is open, otherwise the first open day after the filed periods it falls in. */
    public function openDay(string $organizationId, CarbonImmutable $day): CarbonImmutable
    {
        while (($period = $this->filedOn($organizationId, $day)) !== null) {
            $day = CarbonImmutable::parse($period->ends_on->toDateString())->addDay();
        }

        return $day;
    }

    /**
     * @throws DomainException when the day is in a filed period
     */
    public function assertOpen(string $organizationId, CarbonImmutable $day, string $what): void
    {
        $period = $this->filedOn($organizationId, $day);

        if ($period !== null) {
            throw new DomainException(__(':what falls in the VAT period from :from to :to, which was filed on :filed. Correct it in an open period instead: with a credit note, or as a correction in the next return.', [
                'what' => $what, 'from' => $period->starts_on->toDateString(), 'to' => $period->ends_on->toDateString(), 'filed' => $period->filed_on->toDateString(),
            ]));
        }
    }

    /**
     * Mark the period filed, with the return as it stands.
     *
     * @throws DomainException
     */
    public function file(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $filedOn, VatReturnDraft $draft, ?string $actorId): VatPeriod
    {
        if ($to->lt($from) || $filedOn->lt($to)) {
            throw new DomainException(__('A period is filed after it ends: the filing date is on or after :date.', ['date' => $to->toDateString()]));
        }

        $overlap = $this->filed($organizationId)->first(fn (VatPeriod $period): bool => $period->starts_on->toDateString() <= $to->toDateString() && $period->ends_on->toDateString() >= $from->toDateString());

        if ($overlap !== null) {
            throw new DomainException(__('The period from :from to :to is already filed.', ['from' => $overlap->starts_on->toDateString(), 'to' => $overlap->ends_on->toDateString()]));
        }

        // A tax period is a calendar month or three (KSA VAT Implementing
        // Regulations Art 58; UAE Federal Decree-Law No. 8 of 2017 Art 74):
        // it ends on a month's last day and is one or three months long. The
        // first filed may start later in its month, from registration.
        $before = $this->filed($organizationId)->filter(fn (VatPeriod $period): bool => $period->ends_on->lt($from))->sortBy('ends_on')->last();
        $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;

        if ($to->toDateString() !== $to->endOfMonth()->toDateString() || ! in_array($months, [1, 3], true)
            || ($before !== null && $from->day !== 1)) {
            throw new DomainException(__('A VAT period is a calendar month or a quarter: from the first of a month to the last day of the same month, or of the second month after it.'));
        }

        // Filed in order: no period left unfiled between the last one and this.
        if ($before !== null && $before->ends_on->addDay()->toDateString() !== $from->toDateString()) {
            throw new DomainException(__('The period from :from to :to is not filed yet: file it first, so no period is left out.', [
                'from' => $before->ends_on->addDay()->toDateString(), 'to' => $from->subDay()->toDateString(),
            ]));
        }

        $period = VatPeriod::query()->create([
            'org_id' => $organizationId,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'filed_on' => $filedOn->toDateString(),
            'figures' => self::figures($draft),
        ]);
        $period->forceFill(['status' => VatPeriod::FILED])->save();
        unset($this->filed[$organizationId]);

        $this->audit->logChange('books.vat_period.filed', 'VatPeriod', (string) $period->id, null,
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'filed_on' => $filedOn->toDateString()], [], $actorId, $organizationId);

        return $period;
    }

    /**
     * Open a filed period again: an owner's decision, with a reason.
     *
     * @throws DomainException
     */
    public function reopen(VatPeriod $period, string $reason, ?string $actorId): VatPeriod
    {
        if (! $period->isFiled()) {
            throw new DomainException(__('This period is not filed.'));
        }

        $period->forceFill([
            'status' => VatPeriod::REOPENED,
            'reopen_reason' => trim($reason),
            'reopened_by' => $actorId,
            'reopened_at' => now(),
        ])->save();
        unset($this->filed[(string) $period->org_id]);

        $this->audit->logChange('books.vat_period.reopened', 'VatPeriod', (string) $period->id, ['status' => VatPeriod::FILED],
            ['status' => VatPeriod::REOPENED, 'reason' => trim($reason)], [], $actorId, (string) $period->org_id);

        return $period;
    }

    /**
     * What has moved since filing: each box whose figures differ from the
     * snapshot, as now minus then.
     *
     * @return list<array{box: string, label: string, vat: float, amount: float}>
     */
    public function drift(VatPeriod $period, VatReturnDraft $now): array
    {
        $then = $period->figures['boxes'] ?? [];
        $current = self::figures($now)['boxes'];
        $drift = [];

        foreach ($current as $key => $box) {
            $before = $then[$key] ?? ['amount' => 0, 'adjustment' => 0, 'vat' => 0];
            $vat = round((float) ($box['vat'] ?? 0) - (float) ($before['vat'] ?? 0), 2);
            $amount = round((float) ($box['amount'] ?? 0) + (float) ($box['adjustment'] ?? 0) - (float) ($before['amount'] ?? 0) - (float) ($before['adjustment'] ?? 0), 2);

            if (abs($vat) >= 0.005 || abs($amount) >= 0.005) {
                $drift[] = ['box' => (string) $key, 'label' => $box['label'], 'vat' => $vat + 0.0, 'amount' => $amount + 0.0];
            }
        }

        return $drift;
    }

    /**
     * @return array{currency: string, boxes: array<string, array{label: string, amount: float|null, adjustment: float|null, vat: float|null}>, reconciliation: array<string, array<string, array<string, mixed>>>}
     */
    public static function figures(VatReturnDraft $draft): array
    {
        $boxes = [];
        $reconciliation = [];

        foreach ($draft->sections as $section) {
            foreach ($section as $box) {
                $boxes[$box->key] = ['label' => $box->label, 'amount' => $box->amount, 'adjustment' => $box->adjustment, 'vat' => $box->vat];
            }
        }

        // What the return reconciles to as it stood - filing status with the
        // authority - kept with the boxes, apart, so drift reads the boxes.
        foreach ($draft->reconciliation as $heading => $section) {
            foreach ($section as $box) {
                $reconciliation[$heading][$box->key] = ['label' => $box->label, 'amount' => $box->amount, 'adjustment' => $box->adjustment, 'vat' => $box->vat];
            }
        }

        return ['currency' => $draft->currency, 'boxes' => $boxes, 'reconciliation' => $reconciliation];
    }

    /** @return Collection<int, VatPeriod> */
    private function filed(string $organizationId): Collection
    {
        return $this->filed[$organizationId] ??= VatPeriod::acrossTenants()
            ->where('org_id', $organizationId)->where('status', VatPeriod::FILED)->orderBy('starts_on')->get();
    }
}
