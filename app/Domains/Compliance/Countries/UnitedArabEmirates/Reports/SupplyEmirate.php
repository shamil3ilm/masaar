<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Reports;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Organization\Models\Organization;

/**
 * The emirate a sale is reported in, for boxes 1a-1g of the VAT return.
 *
 * Cabinet Decision No. 52 of 2017 Art 72(2): the emirate where the fixed
 * establishment the supply is made from is located - the branch it was
 * issued from, by its address, or the company's own emirate (its UAE
 * settings) where it has no branch or the branch's address names none.
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

    /** Towns an address names in place of its emirate. */
    private const TOWNS = [
        'abu dhabi' => 'AUH', 'al ain' => 'AUH', 'ruwais' => 'AUH', 'madinat zayed' => 'AUH',
        'dubai' => 'DXB', 'jebel ali' => 'DXB', 'hatta' => 'DXB',
        'sharjah' => 'SHJ', 'khor fakkan' => 'SHJ', 'kalba' => 'SHJ', 'dibba al hisn' => 'SHJ',
        'ajman' => 'AJM',
        'umm al quwain' => 'UAQ', 'umm al-quwain' => 'UAQ',
        'ras al khaimah' => 'RAK', 'ras al-khaimah' => 'RAK',
        'fujairah' => 'FUJ', 'dibba' => 'FUJ', 'dibba al fujairah' => 'FUJ',
    ];

    /** @var array<string, string|null> the company's emirate, by organization */
    private array $companies = [];

    public function __construct(
        private readonly UaeSellerDetails $seller,
    ) {}

    /** The box suffix (a-g) for an invoice, or null when no emirate is known. */
    public function boxFor(mixed $invoice): ?string
    {
        $branch = $invoice->branch_id === null ? null : $invoice->branch;
        $code = $this->fromAddress($branch?->district, $branch?->city)
            ?? $this->company($invoice->org)
            ?? self::code((string) (((array) ($invoice->jurisdiction_data ?? []))['buyer_subdivision'] ?? ''));

        return $code === null ? null : self::CODES[$code];
    }

    /** An emirate code from a district or a town, either of which may name it. */
    public function fromAddress(?string ...$parts): ?string
    {
        foreach ($parts as $part) {
            $code = self::code((string) $part) ?? (self::TOWNS[mb_strtolower(trim((string) $part))] ?? null);

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    private function company(?Organization $organization): ?string
    {
        if ($organization === null) {
            return null;
        }

        return $this->companies[(string) $organization->id] ??= self::code((string) ($this->seller->current($organization)['emirate'] ?? ''))
            ?? $this->fromAddress($organization->city);
    }

    private static function code(string $value): ?string
    {
        $value = strtoupper(trim($value));

        return isset(self::CODES[$value]) ? $value : null;
    }
}
