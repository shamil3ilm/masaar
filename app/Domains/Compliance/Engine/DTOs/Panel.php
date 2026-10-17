<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A Blade view a country renders, and the data it reads: a part of a shared
 * screen, or a screen of its own, that only the country knows how to draw.
 *
 * The view lives in the country's own folder of views,
 * resources/views/countries/<country>/, so a shared screen includes it by
 * the name it is given here and never by a country's name.
 */
final readonly class Panel
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $view,
        public array $data = [],
    ) {}
}
