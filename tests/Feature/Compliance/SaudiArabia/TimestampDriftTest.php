<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\TimestampValidator;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * ZATCA allows ±30 seconds between a document's claimed issue time and the
 * clock that stamps it, and this runs on every submission as step seven of the
 * pre-submission checks. It had no tests.
 *
 * The distinctions it draws are the point: a future timestamp beyond tolerance
 * is refused, a past one never is, and an ERP clock disagreeing is only a
 * warning because the ERP is not authoritative.
 */
class TimestampDriftTest extends TestCase
{
    private TimestampValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = app(TimestampValidator::class);
    }

    public function test_current_time_is_accepted(): void
    {
        $result = $this->validator->validateTimestamps($this->at(0));

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Inside the tolerance a clock running fast is noted, not refused —
     * refusing would reject documents over a second or two of ordinary drift.
     */
    public function test_small_future_drift_is_only_warned(): void
    {
        $result = $this->validator->validateTimestamps($this->at(20));

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_large_future_drift_is_refused(): void
    {
        $result = $this->validator->validateTimestamps($this->at(120));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('in the future', $result['errors'][0]);
    }

    /**
     * A document issued days ago is legitimate — a credit note against an old
     * invoice, or a queue drained after an outage. It is worth noticing and
     * never worth refusing.
     */
    public function test_an_old_document_is_accepted(): void
    {
        $result = $this->validator->validateTimestamps($this->at(-3 * 86400));

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertNotSame([], $result['warnings']);
    }

    /**
     * The invoice date is stored as a date, so every document submitted after
     * midnight is hours behind the clock. That must not be refused, or the
     * platform would stop working each afternoon.
     */
    public function test_midnight_today_is_accepted(): void
    {
        $midnight = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTime(0, 0, 0);

        $result = $this->validator->validateTimestamps($midnight);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_a_missing_timestamp_is_refused(): void
    {
        foreach ([null, ''] as $absent) {
            $result = $this->validator->validateTimestamps($absent);

            $this->assertFalse($result['valid']);
            $this->assertSame('missing_timestamp', $result['result_code']);
        }
    }

    public function test_an_unreadable_timestamp_is_refused(): void
    {
        $result = $this->validator->validateTimestamps('not a date');

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_timestamp_format', $result['result_code']);
    }

    /**
     * The calling ERP's clock is not, so its disagreement is recorded and the
     * document still goes. Turning this into an error would let any customer's
     * unsynchronised server stop their invoicing.
     */
    public function test_erp_disagreement_is_only_a_warning(): void
    {
        $result = $this->validator->validateTimestamps(
            $this->at(0),
            $this->at(-300),
        );

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString('informational', $result['warnings'][0]);
    }

    private function at(int $secondsFromNow): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('%+d seconds', $secondsFromNow));
    }
}
