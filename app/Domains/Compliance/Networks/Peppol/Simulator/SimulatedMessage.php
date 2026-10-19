<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Simulator;

use App\Domains\Compliance\Networks\Peppol\Gateways\AspStatus;

/**
 * One document as the simulated provider holds it: what it was given, what
 * it will say about it, and how much of that it has said.
 *
 * @phpstan-type Event array{type: string, status?: string, mls_code?: string|null, errors?: list<string>, authority_reference?: string|null}
 */
final readonly class SimulatedMessage
{
    /**
     * @param  list<Event>  $events  the callbacks, in order
     * @param  int  $played  how many of them have been delivered
     */
    public function __construct(
        public string $messageId,
        public string $idempotencyKey,
        public string $outcome,
        public string $sender,
        public string $receiver,
        public string $xml,
        public int $acceptedAt,
        public array $events,
        public int $played = 0,
    ) {}

    /**
     * @return Event|null
     */
    public function nextEvent(): ?array
    {
        return $this->events[$this->played] ?? null;
    }

    public function played(): self
    {
        return new self(
            $this->messageId,
            $this->idempotencyKey,
            $this->outcome,
            $this->sender,
            $this->receiver,
            $this->xml,
            $this->acceptedAt,
            $this->events,
            $this->played + 1,
        );
    }

    /**
     * The same message with more callbacks to come, after those it has.
     *
     * @param  list<Event>  $events
     */
    public function withMoreEvents(array $events): self
    {
        return new self(
            $this->messageId,
            $this->idempotencyKey,
            $this->outcome,
            $this->sender,
            $this->receiver,
            $this->xml,
            $this->acceptedAt,
            [...$this->events, ...$events],
            $this->played,
        );
    }

    /**
     * What a status lookup answers at $now, one status per track: the
     * latest of each due by then, one step apart from acceptance, whether
     * or not its callback got through - as a provider's own record would.
     * The buyer's response code stays on the record once given.
     *
     * @return list<AspStatus>
     */
    public function statusAt(int $now, int $stepSeconds): array
    {
        $due = $stepSeconds === 0
            ? count($this->events)
            : intdiv(max(0, $now - $this->acceptedAt), $stepSeconds);

        $delivery = null;
        $reporting = null;
        $mls = null;

        foreach (array_slice($this->events, 0, $due) as $event) {
            if ($event['type'] !== 'status') {
                continue;
            }

            if (in_array($event['status'] ?? null, AspStatus::REPORTING, true)) {
                $reporting = $event;
            } else {
                $delivery = $event;
                $mls = $event['mls_code'] ?? $mls;
            }
        }

        $delivery ??= ['status' => AspStatus::SENT];

        return array_values(array_filter([
            new AspStatus($this->messageId, $delivery['status'] ?? null, $mls, $delivery['errors'] ?? []),
            $reporting === null ? null : new AspStatus(
                $this->messageId,
                $reporting['status'] ?? null,
                null,
                $reporting['errors'] ?? [],
                $reporting['authority_reference'] ?? null,
            ),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messageId: (string) $data['messageId'],
            idempotencyKey: (string) $data['idempotencyKey'],
            outcome: (string) $data['outcome'],
            sender: (string) $data['sender'],
            receiver: (string) $data['receiver'],
            xml: (string) $data['xml'],
            acceptedAt: (int) $data['acceptedAt'],
            events: array_values((array) $data['events']),
            played: (int) $data['played'],
        );
    }
}
