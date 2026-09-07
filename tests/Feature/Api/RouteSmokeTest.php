<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * No endpoint may answer 500 to a request that reaches it.
 *
 * The compliance logic here is tested where it lives — Submitter, XadesSigner,
 * CredentialStore, the hash chain — and that is the right layer for it. The
 * controllers on top are thin, and thin is exactly where an undefined method
 * or a mistyped helper survives, because nothing calls them.
 *
 * This says nothing about whether an endpoint is correct. It says the code
 * behind it parses, its dependencies resolve and its query runs. 4xx is a
 * pass: not found, not licensed and not permitted are all answers. Only 5xx
 * means the request got in and the code fell over.
 */
class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** Endpoints that answer 5xx for a reason that is not a defect. */
    private const ACCEPTED = [];

    /** 25 endpoints answer 2xx today; well under that means auth broke. */
    private const MUST_REACH = 20;

    private string $token;

    private array $apiHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create([
            'name' => 'Smoke Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create(['email' => 'smoke@masaar.test']);
        $user->organizations()->attach($organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = (string) $this->postJson('/api/auth/login', [
            'email' => 'smoke@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');

        // Routes here answer to two different guards. Sending both credentials
        // means each endpoint is refused by its own rules rather than by a
        // missing header, which would leave the handler unreached and the
        // check vacuous.
        $issued = License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => 'Smoke Trading',
            'contact_email' => 'smoke@masaar.test',
            'tier' => 'starter',
        ]);

        $this->apiHeaders = [
            'Authorization' => 'Bearer '.$this->token,
            'X-API-Key' => $issued['api_key'],
            'X-API-Secret' => $issued['api_secret'],
            'Accept' => 'application/json',
        ];
    }

    public function test_no_endpoint_returns_a_server_error(): void
    {
        $broken = [];
        $reached = 0;

        foreach ($this->getRoutes() as $uri) {
            $path = preg_replace('/\{\w+\??\}/', '99999999', $uri);

            $status = $this->getJson('/'.$path, $this->apiHeaders)->status();

            if ($status < 300) {
                $reached++;
            }

            if ($status >= 500) {
                $broken[] = $status.' '.$uri;
            }
        }

        sort($broken);

        $this->assertSame(self::ACCEPTED, $broken, sprintf(
            "These endpoints answered 5xx. The request reached them and the code "
            ."behind them failed.\n%s",
            implode("\n", $broken)
        ));

        // Absence of 5xx only means something if the requests got in. If the
        // credentials stop being accepted every endpoint answers 401, nothing
        // fails, and this passes having checked nothing - the way the
        // conformance suite passed for months while skipping every test.
        $this->assertGreaterThanOrEqual(self::MUST_REACH, $reached, sprintf(
            'Only %d endpoints answered 2xx. The credentials are probably no '
            .'longer accepted, which makes this check vacuous.',
            $reached
        ));
    }

    /** @return list<string> */
    private function getRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $out[] = $uri;
        }

        sort($out);

        return array_values(array_unique($out));
    }
}
