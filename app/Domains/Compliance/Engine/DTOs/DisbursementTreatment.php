<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * How a country treats a disbursement - a cost paid in the customer's name
 * and for its account - on an invoice: the tax category and reason code its
 * line carries, the reason in words, and the conditions the seller confirms
 * before a line may be one, each citing the country's text.
 */
final readonly class DisbursementTreatment
{
    /**
     * @param  list<string>  $conditions  in English, translated where shown
     */
    public function __construct(
        public string $category,
        public string $exemptCode,
        public string $reason,
        public array $conditions,
        public string $source,
    ) {}
}
