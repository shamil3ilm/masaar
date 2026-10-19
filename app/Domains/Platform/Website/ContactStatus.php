<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

/**
 * Where the inbox has got with a message.
 */
enum ContactStatus: string
{
    /** Nobody has answered it yet. */
    case New = 'new';

    /** Someone wrote back to the sender. */
    case Replied = 'replied';

    /** Put away: dealt with, or not worth answering. Restoring brings it back. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Replied => __('Replied'),
            self::Archived => __('Archived'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Replied => 'success',
            self::Archived => 'neutral',
        };
    }

    /**
     * Every status, value => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
