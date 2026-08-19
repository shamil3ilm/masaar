<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API secrets are hashed under a pepper held outside the database.
 *
 * A bare SHA-256 of an API secret is only as strong as the secret: whoever
 * reads the licences table can attack every hash offline, at whatever rate
 * their hardware allows, with no further access to the platform. A pepper is
 * not in that table, so the leak alone is not enough.
 *
 * config/security.php declared api_key_pepper and .env.example described it as
 * "mixed into API key hashes so a leaked table cannot be attacked offline" —
 * and nothing read it. Three separate call sites each computed
 * hash('sha256', $secret) of their own accord, which is why there were three
 * places for it to be forgotten. There is now one.
 */
class ApiKeyPepperTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pepper_changes_the_hash(): void
    {
        config(['security.api_key_pepper' => 'pepper-one']);
        $first = License::hashSecret('the-same-secret');

        config(['security.api_key_pepper' => 'pepper-two']);
        $second = License::hashSecret('the-same-secret');

        $this->assertNotSame(
            $first,
            $second,
            'The pepper does not reach the hash, so it protects nothing.'
        );
    }

    /**
     * The hash must not be reproducible from the secret alone — that is the
     * whole point. A reader of the table who guesses the right secret still
     * cannot confirm it without the pepper.
     */
    public function test_hash_is_not_a_plain_digest(): void
    {
        config(['security.api_key_pepper' => 'a-real-pepper']);

        $this->assertNotSame(
            hash('sha256', 'the-secret'),
            License::hashSecret('the-secret'),
            'The hash is a plain digest of the secret.'
        );
    }

    public function test_secret_verifies_under_its_pepper(): void
    {
        config(['security.api_key_pepper' => 'a-real-pepper']);

        $license = $this->licenceWith('correct-horse');

        $this->assertTrue($license->verifySecret('correct-horse'));
        $this->assertFalse($license->verifySecret('wrong-horse'));
    }

    /**
     * The claim .env.example makes about rotating the pepper, verified.
     */
    public function test_changing_the_pepper_invalidates_existing_keys(): void
    {
        config(['security.api_key_pepper' => 'the-old-pepper']);
        $license = $this->licenceWith('correct-horse');

        config(['security.api_key_pepper' => 'the-new-pepper']);

        $this->assertFalse(
            $license->verifySecret('correct-horse'),
            'A key issued under the old pepper still verifies under the new one.'
        );
    }

    private function licenceWith(string $secret): License
    {
        $org = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        return License::create([
            'org_id' => $org->id,
            'api_key' => 'cp_test_'.$org->id,
            'api_secret_hash' => License::hashSecret($secret),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.submit'],
        ]);
    }
}
