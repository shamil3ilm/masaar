<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin console shows what an action did.
 *
 * processQueue() has flashed 'success' since it was written and no view has
 * ever rendered it, so every action in this console has been silent.
 */
class FlashMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_flash_message_is_shown(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)
            ->withSession(['success' => 'Queue processed'])
            ->get('/admin')
            ->assertSee('Queue processed');
    }
}
