<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * Why a line is zero-rated, exempt or out of scope, from the country's
 * official code list: the code, the category it puts a line in, and its
 * official text in English and, where the authority publishes one, Arabic.
 *
 * $ownText marks a code with no official text, whose reason the seller
 * writes. $buyerScheme names the register a buyer has to be identified from
 * for the reason to apply - a citizen's national ID, where the zero rating
 * depends on who the buyer is - and is null where anyone may buy.
 */
final readonly class ExemptionReason
{
    public function __construct(
        public string $code,
        public string $category,
        public ?string $english,
        public ?string $arabic = null,
        public bool $ownText = false,
        public ?string $buyerScheme = null,
    ) {}

    /** The reason as a person picks it, in their language first where it has one. */
    public function label(): string
    {
        $texts = app()->getLocale() === 'ar' ? [$this->arabic, $this->english] : [$this->english, $this->arabic];
        $texts = array_values(array_filter($texts, fn (?string $text): bool => filled($text)));

        return $texts === [] ? $this->code : $this->code.' · '.implode(' · ', $texts);
    }
}
