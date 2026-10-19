<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

/**
 * What a message from the home page's contact form is about.
 *
 * The values are what the form posts, what the "Ask us" links put in
 * ?topic=, and what is stored, so they do not change when a label does:
 * engineer is "Set it up with us".
 */
enum ContactTopic: string
{
    case General = 'general';
    case Country = 'country';
    case Feature = 'feature';
    case Customization = 'customization';
    case Pricing = 'pricing';
    case Engineer = 'engineer';

    public function label(): string
    {
        return match ($this) {
            self::General => __('General'),
            self::Country => __('New country'),
            self::Feature => __('Feature request'),
            self::Customization => __('Customization'),
            self::Pricing => __('Pricing'),
            self::Engineer => __('Set it up with us'),
        };
    }

    /**
     * Every topic, value => label, in the form's order.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $topic) {
            $options[$topic->value] = $topic->label();
        }

        return $options;
    }
}
