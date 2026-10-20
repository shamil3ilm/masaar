<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

use App\Domains\Billing\Enums\OutgoingChannel;
use Carbon\CarbonImmutable;

/**
 * Money paid to a supplier against one expense, as it is recorded: how
 * much, in the expense's currency, on which day, how it went out, the
 * reference its statement shows, the account or card it came from, and -
 * in a currency other than the tax currency - that day's rate when it is
 * typed rather than taken from the rate table.
 */
final readonly class OutgoingPayment
{
    public function __construct(
        public string $amount,
        public CarbonImmutable $paidOn,
        public OutgoingChannel $channel,
        public ?string $reference = null,
        public ?string $paidFrom = null,
        public ?string $notes = null,
        public ?string $exchangeRate = null,
    ) {}

    /**
     * As an approval request carries it.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'paid_on' => $this->paidOn->toDateString(),
            'channel' => $this->channel->value,
            'reference' => $this->reference,
            'paid_from' => $this->paidFrom,
            'notes' => $this->notes,
            'exchange_rate' => $this->exchangeRate,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: (string) $data['amount'],
            paidOn: CarbonImmutable::parse((string) $data['paid_on']),
            channel: OutgoingChannel::from((string) $data['channel']),
            reference: isset($data['reference']) && $data['reference'] !== '' ? (string) $data['reference'] : null,
            paidFrom: isset($data['paid_from']) && $data['paid_from'] !== '' ? (string) $data['paid_from'] : null,
            notes: isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
            exchangeRate: isset($data['exchange_rate']) && $data['exchange_rate'] !== '' ? (string) $data['exchange_rate'] : null,
        );
    }
}
