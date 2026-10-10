<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Helpers;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ZATCA Time Helper.
 *
 * Two clocks, and which one a value belongs on depends on what the value is
 * for. An instant - when a document was signed, when a retry is due, when a
 * token expires - is UTC, and comparing two of those is comparing instants.
 * A civil statement - the date and time a document says it was issued, the
 * day a filing deadline falls on, what "today" means for a daily count - is
 * the Kingdom's, because that is the clock the taxpayer, the consumer reading
 * a QR and the authority all keep.
 *
 * Writing a civil statement in UTC does not make it three hours early. It
 * makes it wrong, and for the three hours after midnight in Riyadh it names
 * the previous day: an invoice issued at 01:30 said 22:30 of the day before.
 * Use now()/format() for an instant and saudiNow()/toSaudiTime() for a civil
 * statement; the names are the only thing that distinguishes them, so say
 * which you mean.
 */
final class FatooraTime
{
    /**
     * ZATCA timezone (always UTC).
     */
    private const ZATCA_TIMEZONE = 'UTC';

    /**
     * Saudi Arabia timezone (for display purposes).
     */
    private const SAUDI_TIMEZONE = 'Asia/Riyadh';

    /**
     * ISO 8601 format with timezone.
     */
    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Date only format.
     */
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * Time only format.
     */
    private const TIME_FORMAT = 'H:i:s';

    /**
     * Get current UTC timestamp.
     */
    public static function now(): DateTimeImmutable
    {
        // Honour a frozen clock when one is set.
        //
        // This read the system clock directly, so travel() and freezeTime()
        // did not reach it and nothing that depends on a ZATCA timestamp could
        // be asserted on. The signature carries a SigningTime to the second,
        // which meant a test could not tell a document signed once from one
        // signed twice inside the same second — and the difference between
        // those two is the whole of whether the archive matches what the
        // authority received.
        //
        // Nothing changes outside tests: hasTestNow() is false in production.
        if (Carbon::hasTestNow()) {
            return Carbon::getTestNow()
                ->copy()
                ->setTimezone(self::ZATCA_TIMEZONE)
                ->toDateTimeImmutable();
        }

        return new DateTimeImmutable('now', new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Get current timestamp formatted for ZATCA XML.
     */
    public static function nowFormatted(): string
    {
        return self::now()->format(self::ISO_FORMAT);
    }

    /**
     * Get current date for ZATCA XML (UTC).
     */
    public static function today(): string
    {
        return self::now()->format(self::DATE_FORMAT);
    }

    /**
     * Get current time for ZATCA XML (UTC).
     */
    public static function currentTime(): string
    {
        return self::now()->format(self::TIME_FORMAT);
    }

    /**
     * Convert any DateTime to UTC.
     */
    public static function toUtc(\DateTimeInterface $dateTime): DateTimeImmutable
    {
        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime->setTimezone(new DateTimeZone(self::ZATCA_TIMEZONE));
        }

        return DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Format DateTime for ZATCA XML (always UTC).
     */
    public static function format(\DateTimeInterface $dateTime): string
    {
        return self::toUtc($dateTime)->format(self::ISO_FORMAT);
    }

    /**
     * Format date only (for issue_date, supply_date).
     */
    public static function formatDate(\DateTimeInterface $dateTime): string
    {
        return self::toUtc($dateTime)->format(self::DATE_FORMAT);
    }

    /**
     * Format time only.
     */
    public static function formatTime(\DateTimeInterface $dateTime): string
    {
        return self::toUtc($dateTime)->format(self::TIME_FORMAT);
    }

    /**
     * Parse ZATCA timestamp (assumes UTC).
     */
    public static function parse(string $timestamp): DateTimeImmutable
    {
        // Try ISO 8601 format first
        $dt = DateTimeImmutable::createFromFormat(
            self::ISO_FORMAT,
            $timestamp,
            new DateTimeZone(self::ZATCA_TIMEZONE)
        );

        if ($dt !== false) {
            return $dt;
        }

        // Try with timezone offset
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:sP',
            $timestamp
        );

        if ($dt !== false) {
            return self::toUtc($dt);
        }

        // Fallback to standard parsing
        return new DateTimeImmutable($timestamp, new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Convert to the Kingdom's clock.
     *
     * Not "for display". A document's IssueTime and the timestamp in its QR
     * are civil statements about when it was issued, so they are read off
     * this and not off the application's clock.
     */
    public static function toSaudiTime(\DateTimeInterface $dateTime): DateTimeImmutable
    {
        return self::toUtc($dateTime)->setTimezone(new DateTimeZone(self::SAUDI_TIMEZONE));
    }

    /**
     * The signing instant, in the form ZATCA signs with.
     *
     * Local time, no timezone designator - which is what the authority's own
     * signer writes. Its sample reads 2026-10-09T17:14:20 where this platform
     * wrote 2026-10-09T11:45:56Z, and that Z was the last difference between
     * the two signed-properties blocks.
     *
     * It mattered. The SDK accepted the Z, because it recomputes the digest
     * from the bytes it is given, so both forms hash consistently to it. The
     * live API does not: it refused every simplified document with "Invalid
     * signed properties hashing", which means it normalises the timestamp
     * before hashing and a marker it does not expect changes the result.
     *
     * The Kingdom's clock rather than the machine's, so an unmarked stamp is
     * read correctly by a reader who assumes local time - and so this agrees
     * with IssueTime and the QR, which are on the same clock for the same
     * reason. The authority signs on whatever clock its own machine keeps;
     * for a Saudi taxpayer that is this one.
     */
    public static function signingTime(): string
    {
        return self::saudiNow()->format('Y-m-d\TH:i:s');
    }

    /**
     * Now, on the Kingdom's clock.
     *
     * For the civil date or wall-clock time a document or a deadline states.
     * Carbon, not DateTimeImmutable, because the callers want startOfDay()
     * and lte() - and Carbon so a frozen test clock reaches it, which a bare
     * new DateTime would not.
     */
    public static function saudiNow(): Carbon
    {
        return Carbon::now(self::SAUDI_TIMEZONE);
    }

    /**
     * Get Unix timestamp in milliseconds.
     */
    public static function timestampMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Check if timestamp is within allowed window.
     */
    public static function isWithinWindow(
        \DateTimeInterface $timestamp,
        int $windowMinutes = 5
    ): bool {
        $now = self::now();
        $diff = abs($now->getTimestamp() - $timestamp->getTimestamp());

        return $diff <= ($windowMinutes * 60);
    }

    /**
     * Get certificate expiry date in UTC.
     */
    public static function fromUnixTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Calculate days until date.
     */
    public static function daysUntil(\DateTimeInterface $date): int
    {
        $now = self::now();
        $target = self::toUtc($date);

        $diff = $now->diff($target);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Check if date is in the past.
     */
    public static function isPast(\DateTimeInterface $date): bool
    {
        return self::toUtc($date) < self::now();
    }

    /**
     * Check if date is in the future.
     */
    public static function isFuture(\DateTimeInterface $date): bool
    {
        return self::toUtc($date) > self::now();
    }

    /**
     * Get start of day in UTC.
     */
    public static function startOfDay(?\DateTimeInterface $date = null): DateTimeImmutable
    {
        $date = $date ? self::toUtc($date) : self::now();

        return $date->setTime(0, 0, 0, 0);
    }

    /**
     * Get end of day in UTC.
     */
    public static function endOfDay(?\DateTimeInterface $date = null): DateTimeImmutable
    {
        $date = $date ? self::toUtc($date) : self::now();

        return $date->setTime(23, 59, 59, 999999);
    }

    /**
     * Add seconds to timestamp.
     */
    public static function addSeconds(int $seconds, ?\DateTimeInterface $from = null): DateTimeImmutable
    {
        $from = $from ? self::toUtc($from) : self::now();

        return $from->modify("+{$seconds} seconds");
    }

    /**
     * Subtract seconds from timestamp.
     */
    public static function subSeconds(int $seconds, ?\DateTimeInterface $from = null): DateTimeImmutable
    {
        $from = $from ? self::toUtc($from) : self::now();

        return $from->modify("-{$seconds} seconds");
    }
}
