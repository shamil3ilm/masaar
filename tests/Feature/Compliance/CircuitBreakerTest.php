<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\CircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\Connectivity;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The breaker that keeps a ZATCA outage from becoming a stampede.
 *
 * Connectivity consults it before every availability check, and OfflineFallback
 * consults Connectivity before every submission, so this decides whether an
 * outage is absorbed or hammered at. Nothing tested it.
 *
 * The two failure modes are opposite and both bad. A breaker that never opens
 * leaves every request queuing against a dead endpoint until it times out. A
 * breaker that never closes turns a two-minute outage into a permanent one,
 * with every invoice diverted offline long after the authority came back.
 *
 * Thresholds are set explicitly rather than taken from config: this is about
 * whether the mechanism works, and reading the deployed numbers would make the
 * test restate configuration instead of behaviour.
 */
class CircuitBreakerTest extends TestCase
{
    private const SERVICE = 'zatca-test';

    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'fatoora.circuit_breaker.failure_threshold' => 3,
            'fatoora.circuit_breaker.success_threshold' => 2,
            'fatoora.circuit_breaker.timeout_seconds' => 60,
            'fatoora.circuit_breaker.half_open_max_requests' => 3,
        ]);

        $this->breaker = new CircuitBreaker;
    }

    public function test_requests_pass_while_closed(): void
    {
        $this->assertTrue($this->breaker->allowRequest(self::SERVICE));
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $this->breaker->getState(self::SERVICE));
    }

    public function test_breaker_opens_on_repeated_failure(): void
    {
        $this->failTimes(3);

        $this->assertSame(CircuitBreaker::STATE_OPEN, $this->breaker->getState(self::SERVICE));
        $this->assertFalse($this->breaker->allowRequest(self::SERVICE), 'Requests continued after the breaker opened.');
    }

    /**
     * One failure short of the threshold is not an outage. Opening too eagerly
     * diverts traffic offline over a single blip.
     */
    public function test_breaker_holds_below_the_threshold(): void
    {
        $this->failTimes(2);

        $this->assertTrue($this->breaker->allowRequest(self::SERVICE));
    }

    /**
     * A success before the threshold clears the count, or unrelated failures
     * spread over hours would eventually add up to a false outage.
     */
    public function test_success_clears_the_failure_count(): void
    {
        $this->failTimes(2);
        $this->breaker->recordSuccess(self::SERVICE);
        $this->failTimes(2);

        $this->assertTrue($this->breaker->allowRequest(self::SERVICE));
    }

    /**
     * After the timeout it must try again. Without this the breaker never
     * reconsiders, and a brief outage becomes permanent.
     */
    public function test_breaker_retries_after_the_timeout(): void
    {
        $this->failTimes(3);
        $this->assertFalse($this->breaker->allowRequest(self::SERVICE));

        $this->travel(61)->seconds();

        $this->assertTrue($this->breaker->allowRequest(self::SERVICE), 'The breaker never reconsidered.');
    }

    /**
     * Recovery has to be earned: enough consecutive successes to close, not one
     * lucky request.
     */
    public function test_recovery_closes_the_breaker(): void
    {
        $this->failTimes(3);
        $this->travel(61)->seconds();

        $this->breaker->allowRequest(self::SERVICE);
        $this->breaker->recordSuccess(self::SERVICE);
        $this->breaker->recordSuccess(self::SERVICE);

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $this->breaker->getState(self::SERVICE));
    }

    /**
     * A failure while testing recovery reopens it rather than counting toward
     * the successes needed to close.
     */
    public function test_failure_while_recovering_reopens(): void
    {
        $this->failTimes(3);
        $this->travel(61)->seconds();

        $this->breaker->allowRequest(self::SERVICE);
        $this->breaker->recordFailure(self::SERVICE);

        $this->assertSame(CircuitBreaker::STATE_OPEN, $this->breaker->getState(self::SERVICE));
        $this->assertFalse($this->breaker->allowRequest(self::SERVICE));
    }

    /**
     * One service failing must not stop another. The breaker is keyed by
     * service, and losing that would make a ZATCA outage stop UAE submissions.
     */
    public function test_services_break_independently(): void
    {
        $this->failTimes(3);

        $this->assertFalse($this->breaker->allowRequest(self::SERVICE));
        $this->assertTrue($this->breaker->allowRequest('another-service'));
    }

    /**
     * The breaker can be turned off.
     *
     * fatoora.features.circuit_breaker was read nowhere, so setting
     * ZATCA_FEATURE_CIRCUIT_BREAKER=false turned nothing off. A breaker that
     * cannot be disabled is a problem during the one incident where the
     * breaker itself is what is misbehaving.
     */
    public function test_the_breaker_can_be_disabled(): void
    {
        config(['fatoora.features.circuit_breaker' => false]);

        $this->failTimes(3);

        $connectivity = new \ReflectionMethod(Connectivity::class, 'isCircuitOpen');

        $this->assertFalse(
            $connectivity->invoke(app(Connectivity::class)),
            'The breaker still reported open with the feature disabled.'
        );
    }

    private function failTimes(int $times): void
    {
        for ($attempt = 0; $attempt < $times; $attempt++) {
            $this->breaker->recordFailure(self::SERVICE);
        }
    }
}
