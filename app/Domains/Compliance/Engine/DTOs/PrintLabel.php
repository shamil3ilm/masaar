<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A caption printed in both of the invoice's languages: English, and the
 * Arabic beside it.
 */
final readonly class PrintLabel
{
    public function __construct(
        public string $en,
        public string $ar = '',
    ) {}
}
