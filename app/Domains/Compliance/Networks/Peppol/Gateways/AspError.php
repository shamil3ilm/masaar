<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Gateways;

use Illuminate\Support\Str;

/**
 * One thing a provider, the buyer's provider or the authority found wrong
 * with a document: the rule it broke, how badly, where, and in words.
 *
 * The taxpayer resolves errors with its provider (Electronic Invoicing
 * Guidelines v1.1, p41: a governance model for resolving errors), and an
 * error that names its rule and field can be acted on without reading the
 * XML. Providers answer in strings or in objects of their own naming; both
 * come in through from(), and what cannot be read as either is dropped.
 */
final readonly class AspError
{
    public const FATAL = 'fatal';

    public const ERROR = 'error';

    public const WARNING = 'warning';

    private const SEVERITIES = [self::FATAL, self::ERROR, self::WARNING];

    private const MAX_MESSAGE = 1000;

    private const MAX_FIELD = 500;

    /**
     * @param  string|null  $code  the rule or error code ("ibr-003", "BV")
     * @param  string|null  $field  where in the document: an XPath, an element or a business term
     */
    public function __construct(
        public string $message,
        public ?string $code = null,
        public string $severity = self::ERROR,
        public ?string $field = null,
    ) {}

    /**
     * An error as a provider sends it: an object, or a string that may lead
     * with its code ("[ibr-003]-text", "BV: text"). Null when it says nothing.
     */
    public static function from(mixed $error): ?self
    {
        if ($error instanceof self) {
            return $error;
        }

        if (is_string($error)) {
            return self::fromString($error);
        }

        if (! is_array($error)) {
            return null;
        }

        $message = self::text($error['message'] ?? $error['text'] ?? $error['description'] ?? null, self::MAX_MESSAGE);
        $code = self::text($error['code'] ?? $error['rule_id'] ?? $error['rule'] ?? $error['id'] ?? null, 100);

        if ($message === null && $code === null) {
            return null;
        }

        $severity = strtolower((string) ($error['severity'] ?? $error['flag'] ?? self::ERROR));

        return new self(
            message: $message ?? $code,
            code: $code,
            severity: in_array($severity, self::SEVERITIES, true) ? $severity : self::ERROR,
            field: self::text($error['field'] ?? $error['location'] ?? $error['path'] ?? null, self::MAX_FIELD),
        );
    }

    /**
     * @param  iterable<mixed>  $errors
     * @return list<self>
     */
    public static function listFrom(iterable $errors, int $limit = 200): array
    {
        $list = [];

        foreach ($errors as $error) {
            $parsed = self::from($error);

            if ($parsed !== null) {
                $list[] = $parsed;
            }

            if (count($list) >= $limit) {
                break;
            }
        }

        return $list;
    }

    /**
     * As stored and as the API answers it.
     *
     * @param  list<self>  $errors
     * @return list<array{code: string|null, severity: string, field: string|null, message: string}>
     */
    public static function toArrays(array $errors): array
    {
        return array_map(fn (self $error): array => $error->toArray(), $errors);
    }

    /**
     * @return array{code: string|null, severity: string, field: string|null, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity, 'field' => $this->field, 'message' => $this->message];
    }

    /** One line, for a log or an exception message. */
    public function line(): string
    {
        return ($this->code !== null ? "[{$this->code}] " : '').$this->message.($this->field !== null ? " (at {$this->field})" : '');
    }

    private static function fromString(string $error): ?self
    {
        $error = trim((string) preg_replace('/\s+/', ' ', $error));

        if ($error === '') {
            return null;
        }

        // "[ibr-003]-An Invoice MUST ..." - the published rules' own form.
        if (preg_match('/^\[([^\]]{1,100})\]\s*-?\s*(.+)$/s', $error, $match) === 1) {
            return new self(Str::limit($match[2], self::MAX_MESSAGE), $match[1]);
        }

        // "BV: The buyer's access point ..." - a short code, then the words.
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._-]{0,30}):\s+(.+)$/s', $error, $match) === 1) {
            return new self(Str::limit($match[2], self::MAX_MESSAGE), $match[1]);
        }

        return new self(Str::limit($error, self::MAX_MESSAGE));
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max);
    }
}
