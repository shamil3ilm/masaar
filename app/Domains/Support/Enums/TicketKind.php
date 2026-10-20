<?php

declare(strict_types=1);

namespace App\Domains\Support\Enums;

/**
 * What a ticket asks for. All three are one conversation; a customization
 * and a feature request also move through stages (RequestStage).
 */
enum TicketKind: string
{
    /** Help with something that does not work as it should. */
    case Support = 'support';

    /** Paid work for this one customer, at a price support agrees. */
    case Customization = 'customization';

    /** Something new the customer would like everyone to have. */
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::Support => __('Support'),
            self::Customization => __('Customization'),
            self::Feature => __('Feature request'),
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Support => __('Something is not working, or you have a question.'),
            self::Customization => __('Work done for your company alone, such as a report or an import. We agree a price with you first.'),
            self::Feature => __('Something new you would like the product to do, such as an integration or a report.'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Support => 'neutral',
            self::Customization => 'info',
            self::Feature => 'accent',
        };
    }

    /** Whether it moves through stages beside the conversation. */
    public function staged(): bool
    {
        return $this !== self::Support;
    }
}
