<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Models\IssuedEmirate;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The emirate a sale is reported in, for boxes 1a-1g of the VAT return.
 *
 * Cabinet Decision No. 52 of 2017 Art 72(2): the emirate where the fixed
 * establishment the supply is made from is located - the emirate of the
 * branch it was issued from (branches.emirate), or the company's own (its
 * UAE settings) for a sale from head office or a branch with none yet.
 * Only a seller with no establishment in the State reports by where the
 * supply is received (Art 72(3)(b)): the buyer's emirate is the last
 * resort here. Null when none of them names one; the draft then lists the
 * sale for the organization to place.
 */
class SupplyEmirate
{
    /** Box suffix => emirate, in the order the form lists them. */
    public const BOXES = [
        'a' => 'Abu Dhabi',
        'b' => 'Dubai',
        'c' => 'Sharjah',
        'd' => 'Ajman',
        'e' => 'Umm Al Quwain',
        'f' => 'Ras Al Khaimah',
        'g' => 'Fujairah',
    ];

    /** The emirate codes the e-invoice uses (PintAe::EMIRATES), by box suffix. */
    private const CODES = ['AUH' => 'a', 'DXB' => 'b', 'SHJ' => 'c', 'AJM' => 'd', 'UAQ' => 'e', 'RAK' => 'f', 'FUJ' => 'g'];

    /** @var array<string, string|null> the company's emirate, by organization */
    private array $companies = [];

    public function __construct(
        private readonly UaeSellerDetails $seller,
    ) {}

    /** @var array<string, string|null> the emirate recorded at issue, by invoice */
    private array $issued = [];

    /**
     * The box suffix (a-g) for an invoice, or null when no emirate is known:
     * the emirate as it was when the invoice was issued (record()), so a
     * branch or the company moving later does not move sales already made;
     * today's for an invoice issued before that was recorded, or with none
     * known then.
     */
    public function boxFor(object $invoice): ?string
    {
        $code = $this->issuedEmirate((string) $invoice->id) ?? $this->current($invoice);

        return $code === null ? null : self::CODES[$code];
    }

    /** Keep the emirate a sale is made from as it stands at its issue. */
    public function record(object $invoice): void
    {
        IssuedEmirate::query()->firstOrCreate(['invoice_id' => (string) $invoice->id], [
            'org_id' => (string) $invoice->org_id,
            'emirate' => $this->current($invoice),
            'issued_on' => CarbonImmutable::parse($invoice->issue_date ?? now())->toDateString(),
        ]);
    }

    /**
     * Read at once the emirates recorded for an organization's sales issued
     * from a day on: every document of a period starting then is.
     */
    public function prime(string $organizationId, CarbonImmutable $from): void
    {
        foreach (IssuedEmirate::query()->where('org_id', $organizationId)->where('issued_on', '>=', $from->toDateString())->get(['invoice_id', 'emirate']) as $row) {
            $this->issued[(string) $row->invoice_id] = $row->emirate;
        }
    }

    private function issuedEmirate(string $invoiceId): ?string
    {
        if (! array_key_exists($invoiceId, $this->issued)) {
            $this->issued[$invoiceId] = IssuedEmirate::query()->whereKey($invoiceId)->value('emirate');
        }

        return $this->issued[$invoiceId] === null ? null : self::code($this->issued[$invoiceId]);
    }

    private function current(object $invoice): ?string
    {
        return self::code((string) ($invoice->branch_id === null ? '' : $invoice->branch?->emirate))
            ?? $this->company($invoice->org)
            ?? self::code((string) (((array) ($invoice->jurisdiction_data ?? []))['buyer_subdivision'] ?? ''));
    }

    private function company(?Organization $organization): ?string
    {
        if ($organization === null) {
            return null;
        }

        $id = (string) $organization->id;

        if (! array_key_exists($id, $this->companies)) {
            $this->companies[$id] = self::code((string) ($this->seller->current($organization)['emirate'] ?? ''));
        }

        return $this->companies[$id];
    }

    private static function code(string $value): ?string
    {
        $value = strtoupper(trim($value));

        return isset(self::CODES[$value]) ? $value : null;
    }
}
