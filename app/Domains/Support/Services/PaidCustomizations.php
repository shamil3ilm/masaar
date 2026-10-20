<?php

declare(strict_types=1);

namespace App\Domains\Support\Services;

use App\Domains\Billing\Contracts\ChargeSource;
use App\Domains\Billing\DTOs\PendingCharge;
use App\Domains\Platform\Access\Feature;
use App\Domains\Platform\Access\FeatureOverrides;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Models\PaidCustomization;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Customizations: what support agreed for one customer's paid work, and the
 * customer's next invoice asking what it owes for it.
 *
 * There is no price list: an admin enters the price agreed, in the
 * customer's currency, or marks the work not billable. A customization is
 * owed once it is released and billable with a price; billing (ChargeSource)
 * puts it on the organization's next invoice in that currency and says
 * which invoice billed it, after which its price is fixed.
 *
 * The features it was agreed with are switched on for the organization
 * while it is released - the same per-organization switches an admin sets
 * on the organization's page (FeatureOverrides), each giving this
 * customization as its reason, so it is clear where a switch came from.
 */
class PaidCustomizations implements ChargeSource
{
    public const SOURCE = 'customization';

    /** A switch on beyond the plan (FeatureOverride::ON). */
    private const ON = 'on';

    public function __construct(
        private readonly FeatureOverrides $overrides,
    ) {}

    public function source(): string
    {
        return self::SOURCE;
    }

    /**
     * Support records what was agreed.
     *
     * @param  list<string>  $features  Feature values
     *
     * @throws DomainException once it is billed: an invoice carries its price
     */
    public function agree(PaidCustomization $customization, ?string $description, ?string $price, ?string $currency, bool $billable, array $features, string $actorId): void
    {
        $changesBilling = $customization->price !== ($price === null ? null : number_format((float) $price, 2, '.', ''))
            || $customization->currency !== $currency || $customization->billable !== $billable;

        if ($customization->invoice_id !== null && $changesBilling) {
            throw new DomainException('This customization is billed already. Its price, currency and billing stay as invoiced.');
        }

        $before = (array) ($customization->features ?? []);
        $features = array_values(array_unique(array_filter($features, fn (string $value): bool => Feature::tryFrom($value) !== null)));

        DB::transaction(function () use ($customization, $description, $price, $currency, $billable, $features, $actorId, $before): void {
            $customization->fill([
                'description' => filled($description) ? trim((string) $description) : null,
                'price' => $billable && $price !== null ? $price : null,
                'currency' => $billable && $price !== null ? $currency : null,
                'billable' => $billable,
                'features' => $features,
            ])->save();

            if ($customization->ticket?->stage === RequestStage::Released) {
                $this->grant($customization, array_diff($features, $before), $actorId);
                $this->withdraw($customization, array_diff($before, $features), $actorId);
            }
        });
    }

    /**
     * Released, its features are switched on; moved off released, the
     * switches it set are cleared.
     */
    public function released(PaidCustomization $customization, bool $released, string $actorId): void
    {
        $features = (array) ($customization->features ?? []);

        $released ? $this->grant($customization, $features, $actorId) : $this->withdraw($customization, $features, $actorId);
    }

    /**
     * An organization's customizations released and billable with a price
     * in this currency, not yet on an invoice.
     *
     * @return list<PendingCharge>
     */
    public function unbilled(string $organizationId, string $currency): array
    {
        return PaidCustomization::query()
            ->with('ticket')
            ->where('org_id', $organizationId)
            ->where('billable', true)
            ->whereNotNull('price')
            ->where('currency', $currency)
            ->whereNull('invoice_id')
            ->whereHas('ticket', fn ($query) => $query->withoutGlobalScopes()->where('stage', RequestStage::Released))
            ->orderBy('created_at')
            ->get()
            ->map(fn (PaidCustomization $customization): PendingCharge => new PendingCharge(
                source: self::SOURCE,
                sourceId: (string) $customization->id,
                organizationId: $organizationId,
                description: (string) ($customization->description ?? $customization->ticket?->subject),
                amount: (string) $customization->price,
                currency: $currency,
                reference: (string) $customization->ticket?->reference(),
            ))
            ->all();
    }

    public function markBilled(string $sourceId, string $invoiceId): void
    {
        DB::transaction(function () use ($sourceId, $invoiceId): void {
            $customization = PaidCustomization::query()->whereKey($sourceId)->lockForUpdate()->first()
                ?? throw new DomainException('There is no customization '.$sourceId.'.');

            if ($customization->invoice_id !== null) {
                throw new DomainException('This customization is billed already, on invoice '.$customization->invoice_id.'.');
            }

            $customization->forceFill(['invoice_id' => $invoiceId, 'billed_at' => now()])->save();
        });
    }

    /** The reason its switches give: which customization set them. */
    public function reason(PaidCustomization $customization): string
    {
        return 'Customization '.$customization->ticket?->reference();
    }

    /**
     * @param  array<int, string>  $features
     */
    private function grant(PaidCustomization $customization, array $features, string $actorId): void
    {
        foreach ($features as $value) {
            $feature = Feature::tryFrom($value);

            if ($feature !== null) {
                $this->overrides->set((string) $customization->org_id, $feature, self::ON, $this->reason($customization), $actorId);
            }
        }
    }

    /**
     * Clears only the switches this customization set: one an admin has
     * since set for another reason is theirs.
     *
     * @param  array<int, string>  $features
     */
    private function withdraw(PaidCustomization $customization, array $features, string $actorId): void
    {
        $switches = $this->overrides->for((string) $customization->org_id);

        foreach ($features as $value) {
            $feature = Feature::tryFrom($value);

            if ($feature !== null && ($switches[$value]['reason'] ?? null) === $this->reason($customization)) {
                $this->overrides->set((string) $customization->org_id, $feature, null, $this->reason($customization), $actorId);
            }
        }
    }
}
