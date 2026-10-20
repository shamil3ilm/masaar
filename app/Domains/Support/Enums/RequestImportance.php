<?php

declare(strict_types=1);

namespace App\Domains\Support\Enums;

/**
 * How much a feature request matters to the company asking.
 */
enum RequestImportance: string
{
    case NiceToHave = 'nice_to_have';
    case Important = 'important';

    /** The company cannot do part of its work without it. */
    case Blocking = 'blocking';

    public function label(): string
    {
        return match ($this) {
            self::NiceToHave => __('Nice to have'),
            self::Important => __('Important'),
            self::Blocking => __('We cannot work without it'),
        };
    }
}
