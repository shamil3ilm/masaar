<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's shortcuts are only those the person may follow: an
 * accountant was offered "New invoice" and got a 403 for clicking it.
 */
class DashboardShortcutsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Organization::create(['name' => 'Corner Shop', 'country' => 'SA', 'status' => 'active', 'business_type' => 'retail']);
    }

    public function test_accountant_sees_no_new_invoice(): void
    {
        $page = $this->as('accountant')->get('/portal')->assertOk();

        $page->assertDontSee(route('portal.invoices.create'), false)
            ->assertSee(route('portal.products.index'), false);
    }

    public function test_every_shortcut_shown_is_allowed(): void
    {
        foreach (['accountant', 'viewer', 'member', 'admin'] as $role) {
            $content = (string) $this->as($role)->get('/portal')->assertOk()->getContent();
            preg_match('#<nav[^>]*aria-label="'.preg_quote(__('Shortcuts'), '#').'"[^>]*>(.*?)</nav>#s', $content, $nav);
            preg_match_all('#href="([^"]+)"#', $nav[1] ?? '', $links);

            foreach (array_map('html_entity_decode', $links[1]) as $link) {
                $this->assertNotSame(403, $this->as($role)->get($link)->status(), "{$role} is offered {$link}");
            }
        }
    }

    public function test_admin_keeps_the_new_invoice(): void
    {
        $this->as('admin')->get('/portal')->assertOk()->assertSee(route('portal.invoices.create'), false);
    }

    private function as(string $role): static
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->shop->id, ['role' => $role, 'status' => 'active']);

        return $this->actingAs($user)->withSession(['portal_org_id' => $this->shop->id]);
    }
}
