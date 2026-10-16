<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The portal in Arabic: chosen before sign-in or after, kept on the account,
 * and laid out right to left.
 */
class ArabicInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_reads_in_arabic(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'ar'])->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ar"', false)
            ->assertSee('تسجيل الدخول');
    }

    public function test_english_is_the_default(): void
    {
        $this->get('/login')->assertOk()->assertSee('dir="ltr"', false)->assertSee('العربية');
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    /**
     * Kept on the account, so it follows the person to another browser.
     */
    public function test_the_choice_follows_the_account(): void
    {
        $acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $user = User::factory()->create();
        $user->organizations()->attach($acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)->post('/locale', ['locale' => 'ar']);
        $this->assertSame('ar', $user->fresh()->locale);

        // A fresh session: only the account remembers.
        $this->flushSession();
        $this->actingAs($user->fresh())->withSession(['portal_org_id' => $acme->id])
            ->get('/portal')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('لوحة التحكم');
    }
}
