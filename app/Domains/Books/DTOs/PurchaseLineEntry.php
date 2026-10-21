<?php

declare(strict_types=1);

namespace App\Domains\Books\DTOs;

use App\Domains\Books\Enums\PurchaseTreatment;

/**
 * One purchase line as typed. VAT left blank is worked out from the rate;
 * the rate left blank is the country's standard rate where the treatment
 * carries VAT.
 */
final readonly class PurchaseLineEntry
{
    public function __construct(
        public string $description,
        public PurchaseTreatment $treatment,
        public string $net,
        public ?string $vat = null,
        public ?string $vatRate = null,
        public bool $capitalAsset = false,
    ) {}
}
