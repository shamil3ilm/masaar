<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\UnfiledDocuments;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Models\Organization;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retiring a till: it issues nothing more, and once nothing it signed is
 * left to file, it is retired for good and never issues again.
 *
 * Its documents are filed with the certificate that signed them, through
 * the till that signed them (CredentialStore::forInvoice), so a till with
 * documents queued offline, waiting for ZATCA or overdue cannot simply go:
 * it is retiring until they are filed - the same documents a renewal waits
 * for (RenewalGuard, UnfiledDocuments). Asking again once they are filed
 * retires it. Its chain is closed where it stands; its credentials and
 * certificate versions are kept, as everything it signed is.
 *
 * Head office's unit is the organization's own and is never retired.
 */
class UnitRetirement
{
    public function __construct(
        private readonly UnfiledDocuments $unfiled,
        private readonly AuditService $audit,
    ) {}

    /**
     * What the till signed that ZATCA does not hold yet.
     *
     * @return Collection<int, array{id: string, invoice_number: string, reason: string}>
     */
    public function waiting(EgsUnit $unit): Collection
    {
        return $this->unfiled->signedBy((string) $unit->org_id, (string) $unit->id);
    }

    /**
     * Retire the till, or - with documents still to file - stop it issuing
     * until they are.
     *
     * @return EgsUnit the till, retired or retiring
     *
     * @throws DomainException when it is head office's, or already gone
     */
    public function retire(Organization $organization, EgsUnit $unit, ?string $actorId = null): EgsUnit
    {
        if ($unit->isHeadOffice()) {
            throw new DomainException(__('Head office\'s own unit is the organization\'s and is not retired.'));
        }

        if (! $unit->files()) {
            throw new DomainException(__('Till :till no longer issues invoices (:status). Issue from another till.', [
                'till' => $unit->label(),
                'status' => $unit->statusLabel(),
            ]));
        }

        return DB::transaction(function () use ($organization, $unit, $actorId): EgsUnit {
            // One decision at a time for this till: an issuance holds its
            // issuing lock while it counts, and this waits for it.
            DB::table('issuing_locks')->upsert(
                [['org_id' => $unit->org_id, 'scope' => $unit->id, 'held_at' => now()]],
                ['org_id', 'scope'],
                ['held_at'],
            );

            $waiting = $this->waiting($unit);
            $retired = $waiting->isEmpty();

            $unit->forceFill($retired
                ? ['status' => EgsUnit::RETIRED, 'retired_at' => now(), 'retired_by' => $actorId]
                : ['status' => EgsUnit::RETIRING])->save();

            $this->audit->log($retired ? 'zatca.unit.retired' : 'zatca.unit.retiring', $organization, null, null, [
                'org_id' => (string) $organization->id,
                'device_id' => (string) $unit->id,
                'branch_id' => $unit->branch_id,
                'unfiled' => $waiting->pluck('invoice_number')->all(),
            ]);

            return $unit;
        });
    }

    /**
     * Retire a retiring till once nothing it signed is left to file, with
     * nobody asking again (FinishRetirements, daily).
     *
     * Recorded in its organization's audit log as the retirement the person
     * asked for: retired_by is whoever set it retiring, the scheduler being
     * only the one who finished it.
     *
     * @return bool whether it was retired now
     */
    public function finish(EgsUnit $unit): bool
    {
        if ($unit->status !== EgsUnit::RETIRING) {
            return false;
        }

        return DB::transaction(function () use ($unit): bool {
            DB::table('issuing_locks')->upsert(
                [['org_id' => $unit->org_id, 'scope' => $unit->id, 'held_at' => now()]],
                ['org_id', 'scope'],
                ['held_at'],
            );

            if ($this->waiting($unit)->isNotEmpty()) {
                return false;
            }

            // By the till's own id: retire() logs under the tenant of the
            // moment, which a console caller does not have.
            $askedBy = DB::table('audit_logs')
                ->where('action', 'zatca.unit.retiring')
                ->where('metadata->device_id', (string) $unit->id)
                ->orderByDesc('created_at')
                ->value('user_id');

            $unit->forceFill(['status' => EgsUnit::RETIRED, 'retired_at' => now(), 'retired_by' => $askedBy])->save();

            $this->audit->logFor((string) $unit->org_id, 'zatca.unit.retired', $unit, [
                'org_id' => (string) $unit->org_id,
                'device_id' => (string) $unit->id,
                'branch_id' => $unit->branch_id,
                'unfiled' => [],
                'finished_by' => 'system',
                'asked_by' => $askedBy,
            ]);

            return true;
        });
    }
}
