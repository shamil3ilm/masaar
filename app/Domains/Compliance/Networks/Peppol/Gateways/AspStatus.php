<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

/**
 * What the provider says became of one sent document.
 *
 * Every provider names these differently; a gateway translates into the
 * ones the platform tracks. They belong to two tracks, because the provider
 * delivers the document and reports its tax data to the authority in
 * parallel, and confirms each on its own (Electronic Invoicing
 * Guidelines v1.1, p10 steps 4, 5 and 8; p11 step 10):
 *
 *   delivery   sent, delivered, rejected
 *   reporting  reported, report_failed
 *
 * status null means nothing new: still in transit, or a value the gateway
 * does not recognise, which changes nothing rather than guessing.
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

    /** The tax data did not reach the authority, as the provider relays it. */
    public const REPORT_FAILED = 'report_failed';

    public const ALL = [self::SENT, self::DELIVERED, self::REJECTED, self::REPORTED, self::REPORT_FAILED];

    /** The statuses about the report to the authority; the rest are about delivery. */
    public const REPORTING = [self::REPORTED, self::REPORT_FAILED];

    /**
     * Peppol message level response codes: AB acknowledged, AP accepted,
     * RE rejected.
     */
    private const MLS = ['AB' => self::DELIVERED, 'AP' => self::DELIVERED, 'RE' => self::REJECTED];

    /** @var list<AspError> */
    public array $errors;

    /**
     * @param  iterable<AspError|string|array<string, mixed>>  $errors  as the provider gave them
     * @param  string|null  $authorityReference  the authority's reference for the report, where the provider relays one
     */
    public function __construct(
        public string $messageId,
        public ?string $status,
        public ?string $mlsCode = null,
        iterable $errors = [],
        public ?string $authorityReference = null,
    ) {
        $this->errors = AspError::listFrom($errors);
    }

    /**
     * The status a message level response code stands for, or null.
     */
    public static function fromMlsCode(?string $code): ?string
    {
        return self::MLS[strtoupper((string) $code)] ?? null;
    }

    /** Whether this is about the report to the authority rather than delivery. */
    public function aboutReporting(): bool
    {
        return in_array($this->status, self::REPORTING, true);
    }
}
