<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The seller identification number in the shape of the scheme sent beside
 * it (seller_id_scheme), else the one the organization already uses, else
 * CRN (SellerIdentification, BR-KSA-08).
 */
final class SellerIdNumber implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $currentScheme = SellerIdentification::DEFAULT) {}

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $scheme = (string) ($this->data['seller_id_scheme'] ?? $this->currentScheme);

        if (($problem = SellerIdentification::problem($scheme, (string) $value)) !== null) {
            $fail($problem);
        }
    }
}
