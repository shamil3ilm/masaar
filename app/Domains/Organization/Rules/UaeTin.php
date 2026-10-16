<?php

declare(strict_types=1);

namespace App\Domains\Organization\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A UAE Tax Identification Number: ten digits starting with 1 (IBR-148-AE).
 *
 * A VAT-registered seller's TIN is the first ten digits of its TRN, so one
 * given beside a TRN has to be those ten; anything else would put two
 * identities for one taxpayer on its documents.
 */
final class UaeTin implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^1\d{9}$/', $value) !== 1) {
            $fail(__('A TIN is 10 digits that start with 1.'));

            return;
        }

        $trn = $this->data['trn'] ?? null;

        if (is_string($trn) && $trn !== '' && ! str_starts_with($trn, $value)) {
            $fail(__('The TIN is the first 10 digits of the TRN.'));
        }
    }
}
