<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Compliance\Countries\SaudiArabia\Models\OfflineItem;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Documents a certificate signed that ZATCA does not hold yet.
 *
 * Issued or submitted with no answer: queued offline, a submission pending or
 * being retried, a report overdue. Each is filed with the certificate that
 * signed it - the one its chain entry names - so they are what a renewal or
 * a revocation of that certificate puts at risk.
 *
 * Read through the chain, which records the device and the certificate of
 * every document it holds.
 */
class UnfiledDocuments
{
    /** How many a refusal lists by number. */
    public const LISTED = 10;

    /** Issued and not yet answered by the authority. */
    private const UNANSWERED = ['issued', 'submitted'];

    /**
     * What one certificate of a device signed - or, with no certificate
     * named, anything the device signed, under any certificate it has held:
     * what retiring the device would leave behind.
     *
     * @return Collection<int, array{id: string, invoice_number: string, reason: string}>
     */
    public function signedBy(string $organizationId, string $deviceId, ?string $certificateId = null): Collection
    {
        return $this->describe($this->unfiled($organizationId)
            ->where('device_id', $deviceId)
            ->when($certificateId !== null, fn (Builder $query) => $query->where('certificate_id', $certificateId))
            ->with(['invoice' => self::invoiceColumns(...)])
            ->orderBy('icv')
            ->get());
    }

    /**
     * What any of the organization's devices signed since a moment - its
     * switch into the current ZATCA environment, or ever - that the
     * authority does not hold yet: pending, queued, held or offline.
     *
     * @return Collection<int, array{id: string, invoice_number: string, reason: string}>
     */
    public function inOrganization(string $organizationId, ?CarbonInterface $since = null): Collection
    {
        return $this->describe($this->unfiled($organizationId)
            ->when($since !== null, fn (Builder $query) => $query->whereHas('invoice', fn (Builder $invoice) => $invoice->acrossTenants()->where('issued_at', '>=', $since)))
            ->with(['invoice' => self::invoiceColumns(...)])
            ->orderBy('created_at')
            ->get());
    }

    /**
     * @param  Collection<int, ChainEntry>  $entries
     * @return Collection<int, array{id: string, invoice_number: string, reason: string}>
     */
    private function describe(Collection $entries): Collection
    {
        $offline = OfflineItem::acrossTenants()
            ->whereIn('invoice_id', $entries->pluck('invoice_id'))
            ->whereIn('state', [OfflineQueue::STATE_PENDING, OfflineQueue::STATE_PROCESSING, OfflineQueue::STATE_FAILED])
            ->pluck('invoice_id')
            ->all();

        return $entries->map(fn (ChainEntry $entry): array => [
            'id' => (string) $entry->invoice_id,
            'invoice_number' => (string) $entry->invoice?->invoice_number,
            'reason' => match (true) {
                in_array($entry->invoice_id, $offline, true) => 'offline_queue',
                $this->overdue($entry) => 'overdue',
                default => 'awaiting_filing',
            },
        ]);
    }

    /**
     * How many wait under each certificate the organization's devices have
     * held.
     *
     * @return array<string, array<string, int>> device id => certificate id => count
     */
    public function countsFor(string $organizationId): array
    {
        $counts = [];

        foreach ($this->unfiled($organizationId)
            ->selectRaw('device_id, certificate_id, count(*) as waiting')
            ->groupBy('device_id', 'certificate_id')
            ->get() as $row) {
            $counts[(string) $row->device_id][(string) $row->certificate_id] = (int) $row->getAttribute('waiting');
        }

        return $counts;
    }

    /**
     * @return Builder<ChainEntry>
     */
    private function unfiled(string $organizationId): Builder
    {
        return ChainEntry::acrossTenants()
            ->where('org_id', $organizationId)
            ->whereHas('invoice', fn (Builder $invoice) => $invoice->acrossTenants()
                ->whereNotNull('hash')
                ->whereIn('status', self::UNANSWERED));
    }

    /**
     * The invoice beside each entry, across tenants as the entries are read:
     * only the columns the description needs.
     */
    private static function invoiceColumns(Builder|Relation $invoice): void
    {
        $invoice->acrossTenants()->select(['id', 'invoice_number', 'type', 'issued_at']);
    }

    /**
     * A simplified invoice past ZATCA's twenty-four hours to be reported
     * (E-Invoicing Detailed Guidelines, section 4.2.2(c)).
     */
    private function overdue(ChainEntry $entry): bool
    {
        $invoice = $entry->invoice;
        $type = $invoice?->type instanceof BackedEnum ? $invoice->type->value : (string) $invoice?->type;

        return $type === 'simplified'
            && $invoice->issued_at !== null
            && $invoice->issued_at->lessThan(now()->subMinutes((int) round((float) config('fatoora.reporting.deadline_hours', 24) * 60)));
    }
}
