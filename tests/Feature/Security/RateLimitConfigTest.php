<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * The rate limits have to be coherent, and they have to be the ones in effect.
 *
 * Three separate things went wrong here, all of the same kind - a limit was
 * configured and something else was enforced:
 *
 *   RateLimitApi was aliased and attached to nothing, so every band was read
 *   by nobody and /api/auth/login had no limit at all.
 *
 *   Unauthenticated traffic drew from its own bucket but was measured against
 *   the band's limit, so the anonymous budget was never applied.
 *
 *   SubmissionGuard hardcoded sixty a minute and ten thousand a day while
 *   FatooraConfig had getters for both, so ZATCA_RATE_LIMIT_PER_MINUTE changed
 *   nothing and the figure in the error message was the only place the real
 *   limit was written down.
 *
 * None of those failed loudly. A limit nobody enforces looks exactly like a
 * limit nobody has reached, which is why these are asserted rather than left
 * to review.
 */
class RateLimitConfigTest extends TestCase
{
    /**
     * Two keys throttle submissions - the band at the edge and the guard
     * inside - and the tighter one silently decides.
     *
     * Asserted against the shipped template rather than the machine's own
     * .env, because that file is untracked and a developer's override is not a
     * defect. The deployment invariant lives in AppServiceProvider, which
     * refuses to boot production when they disagree; this only holds the
     * example honest, since every deployment starts as a copy of it and CI
     * runs from one.
     */
    public function test_the_example_env_keeps_them_equal(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        preg_match('/^RATE_LIMIT_SUBMISSION=(\d+)$/m', $example, $edge);
        preg_match('/^ZATCA_RATE_LIMIT_PER_MINUTE=(\d+)$/m', $example, $guard);

        $this->assertNotEmpty($edge, 'RATE_LIMIT_SUBMISSION is not in .env.example.');
        $this->assertNotEmpty($guard, 'ZATCA_RATE_LIMIT_PER_MINUTE is not in .env.example.');

        $this->assertSame(
            $edge[1],
            $guard[1],
            'RATE_LIMIT_SUBMISSION and ZATCA_RATE_LIMIT_PER_MINUTE throttle the '
                .'same traffic and the example sets them apart, so every deployment '
                .'copied from it starts with a limit nobody chose.'
        );
    }

    /**
     * And the two fallbacks agree, for a deployment that overrides neither.
     *
     * Read out of the config files as source. Loading them would evaluate
     * env() against this machine's .env, which is the thing this deliberately
     * is not about.
     */
    public function test_the_fallbacks_agree(): void
    {
        $edge = $this->fallback('config/security.php', 'RATE_LIMIT_SUBMISSION');
        $guard = $this->fallback('config/fatoora.php', 'ZATCA_RATE_LIMIT_PER_MINUTE');

        $this->assertSame(
            $guard,
            $edge,
            'The two config files fall back to different limits, so a deployment '
                .'that sets neither gets a throttle nobody chose.'
        );
    }

    /**
     * The literal an env() call falls back to, from the file as written.
     */
    private function fallback(string $path, string $key): string
    {
        $source = (string) file_get_contents(base_path($path));

        preg_match("/env\('{$key}',\s*(\d+)\)/", $source, $m);

        $this->assertNotEmpty($m, "{$key} has no numeric fallback in {$path}.");

        return $m[1];
    }

    /**
     * A band tighter than the fallback it would otherwise drop through to is a
     * band that makes its own traffic worse off, which is never the intent.
     * Ordered by what a request costs: an onboarding spends an OTP, a read
     * costs almost nothing.
     */
    public function test_the_bands_are_cost_ordered(): void
    {
        $limits = [
            'onboarding' => (int) config('security.rate_limits.onboarding'),
            'anonymous' => (int) config('security.rate_limits.anonymous'),
            'submission' => (int) config('security.rate_limits.submission'),
            'default' => (int) config('security.rate_limits.default'),
            'read' => (int) config('security.rate_limits.read'),
        ];

        $sorted = $limits;
        asort($sorted);

        $this->assertSame(
            array_keys($limits),
            array_keys($sorted),
            'The bands are not ordered by cost: '.json_encode($limits)
                .'. A band below the default penalises its own traffic.'
        );
    }

    /**
     * Every band a route can be put in has a limit, so a pattern added to
     * bands without a matching figure cannot silently fall back to default.
     */
    public function test_every_band_has_a_limit(): void
    {
        $missing = [];

        foreach (array_keys((array) config('security.rate_limits.bands', [])) as $band) {
            if (config("security.rate_limits.{$band}") === null) {
                $missing[] = $band;
            }
        }

        $this->assertSame([], $missing, 'Bands with no limit of their own: '.implode(', ', $missing));
    }

    /**
     * And the limiter is actually attached. An alias is not an attachment:
     * rate.api was registered in AppServiceProvider and reached no request.
     */
    public function test_the_limiter_runs_on_api_requests(): void
    {
        $response = $this->getJson('/api/health');

        $this->assertTrue(
            $response->headers->has('X-RateLimit-Limit'),
            'No X-RateLimit-Limit on an API response, so RateLimitApi is not in '
                .'the api middleware group.'
        );
    }
}
