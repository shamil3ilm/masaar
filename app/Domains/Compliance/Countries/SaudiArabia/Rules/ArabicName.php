<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A name a Saudi invoice prints, in Arabic letters (ArabicText). Implicit,
 * so an empty one is refused in the same words as a Latin one, under
 * whatever name the form gives the field.
 */
final class ArabicName implements ValidationRule
{
    /** Run on an empty value too: required is part of the rule. */
    public bool $implicit = true;

    /**
     * @param  string  $message  in English, as __() returns it; given in both languages
     * @param  bool  $required  refused when empty; otherwise checked only when given
     */
    public function __construct(
        private readonly string $message,
        private readonly bool $required = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->required && blank($value)) {
            return;
        }

        if (! ArabicText::isArabic($value)) {
            $fail(ArabicText::message($this->message, $attribute));
        }
    }
}
