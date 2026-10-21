<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Organization\Models\Organization;

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

    /** The box suffix (a-g) for an invoice, or null when no emirate is known. */
    public function boxFor(object $invoice): ?string
    {
        $code = self::code((string) ($invoice->branch_id === null ? '' : $invoice->branch?->emirate))
            ?? $this->company($invoice->org)
            ?? self::code((string) (((array) ($invoice->jurisdiction_data ?? []))['buyer_subdivision'] ?? ''));

        return $code === null ? null : self::CODES[$code];
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
