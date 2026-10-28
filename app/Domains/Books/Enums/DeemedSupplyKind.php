<?php

declare(strict_types=1);

namespace App\Domains\Books\Enums;

/** What a deemed supply is: why something left the business without a sale. */
enum DeemedSupplyKind: string
{
    // Goods or services used for something other than the business.
    case OwnUse = 'own_use';
    // A gift to promote the business.
    case Gift = 'gift';
    // A sample to promote the business.
    case Sample = 'sample';
    // Goods or services given to employees.
    case Employee = 'employee';
    // What the business keeps when it deregisters.
    case Deregistration = 'deregistration';

    public function label(): string
    {
        return match ($this) {
            self::OwnUse => __('Own or non-business use'),
            self::Gift => __('Gift'),
            self::Sample => __('Sample'),
            self::Employee => __('Given to an employee'),
            self::Deregistration => __('Kept on deregistration'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
