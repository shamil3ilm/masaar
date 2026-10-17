<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

/**
 * What the provider says became of one sent document.
 *
 * Every provider names these differently; a gateway translates into the four
 * the platform tracks. status null means nothing new: still in transit, or a
 * value the gateway does not recognise, which changes nothing rather than
 * guessing.
 */
final readonly class AspStatus
{
    /** The provider accepted it for transport. */
    public const SENT = 'sent';

    /** The buyer's provider validated it (message level response accepted). */
    public const DELIVERED = 'delivered';

    /** The buyer's provider refused it (message level response rejected). */
    public const REJECTED = 'rejected';

    /** The authority confirmed the tax data, relayed by the provider. */
    public const REPORTED = 'reported';

    public const ALL = [self::SENT, self::DELIVERED, self::REJECTED, self::REPORTED];

    /**
     * Peppol message level response codes: AB acknowledged, AP accepted,
     * RE rejected.
     */
    private const MLS = ['AB' => self::DELIVERED, 'AP' => self::DELIVERED, 'RE' => self::REJECTED];

    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $messageId,
        public ?string $status,
        public ?string $mlsCode = null,
        public array $errors = [],
    ) {}

    /**
     * The status a message level response code stands for, or null.
     */
    public static function fromMlsCode(?string $code): ?string
    {
        return self::MLS[strtoupper((string) $code)] ?? null;
    }
}
