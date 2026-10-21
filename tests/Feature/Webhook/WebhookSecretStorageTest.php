<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A webhook's signing secret is what a receiver trusts a delivery by, so a
 * copy of the database or a backup must not be enough to forge one. It is
 * kept encrypted, and read back in the clear only to sign.
 */
class WebhookSecretStorageTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/1730_encrypt_webhook_secrets.php';

    public function test_secret_is_encrypted_at_rest(): void
    {
        $webhook = $this->webhook();

        $stored = (string) DB::table('webhooks')->where('id', $webhook->id)->value('secret');

        $this->assertNotSame('shhh', $stored);
        $this->assertSame('shhh', Crypt::decryptString($stored));
        $this->assertSame('shhh', $webhook->fresh()->secret);
    }

    public function test_secret_is_hidden_from_serialisation(): void
    {
        $this->assertArrayNotHasKey('secret', $this->webhook()->toArray());
    }

    public function test_migration_encrypts_existing_secrets(): void
    {
        $webhook = $this->webhook();
        DB::table('webhooks')->where('id', $webhook->id)->update(['secret' => 'plain-old']);

        (require base_path(self::MIGRATION))->up();
        // Run twice: a secret already encrypted is left as it is.
        (require base_path(self::MIGRATION))->up();

        $this->assertSame('plain-old', $webhook->fresh()->secret);
    }

    private function webhook(): Webhook
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        return Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $organization->id,
            'url' => 'https://203.0.113.20/hooks',
            'secret' => 'shhh',
            'events' => ['invoice.cleared'],
            'is_active' => true,
        ]));
    }
}
