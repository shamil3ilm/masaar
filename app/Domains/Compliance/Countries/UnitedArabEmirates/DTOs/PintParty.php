<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs;

/**
 * A seller or buyer.
 *
 * vatId is the 15-digit TRN (IBT-031, IBT-048). taxRegistrationId is the
 * seller's 10-digit TIN, carried instead when it has no TRN (IBT-032).
 * legalAuthority is the trade licence's issuing authority, or the issuing
 * country of a passport (BTAE-11/12, BTAE-18/19): both travel in the same
 * attribute.
 */
final readonly class PintParty
{
    public function __construct(
        public string $name,
        public string $endpointScheme,
        public string $endpointId,
        public PintAddress $address,
        public ?string $vatId = null,
        public ?string $taxRegistrationId = null,
        public ?string $legalId = null,
        public ?string $legalType = null,
        public ?string $legalAuthority = null,
    ) {}

    /** The participant identifier as the network routes on it, 0235:1234567890. */
    public function endpoint(): string
    {
        return $this->endpointScheme.':'.$this->endpointId;
    }
}
