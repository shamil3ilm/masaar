<?php

declare(strict_types=1);

namespace Tests\Feature\Interface;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A form sent with its upload's progress (resources/js/file-field.js) goes
 * by script, and a script following the redirect itself would open the next
 * page out of sight and use up its flash message. Asked to, the server names
 * the redirect instead, and the page goes there as a submission would.
 */
class UploadRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_redirect_is_named_when_asked(): void
    {
        $response = $this->portal()->post(route('portal.support.store'), [
            'subject' => 'Upload check', 'body' => 'The progress bar.', 'category' => 'other', 'priority' => 'normal',
        ], ['X-Redirect-Manually' => '1']);

        // Named, and its message still waiting for the page it names.
        $response->assertOk()->assertJsonStructure(['redirect'])->assertSessionHas('success');
        $this->assertStringStartsWith(url('/portal/support'), (string) $response->json('redirect'));
    }

    public function test_an_ordinary_post_still_redirects(): void
    {
        $this->portal()->post(route('portal.support.store'), [
            'subject' => 'Upload check', 'body' => 'The progress bar.', 'category' => 'other', 'priority' => 'normal',
        ])->assertRedirect();
    }

    private function portal(): static
    {
        $organization = Organization::firstOrCreate(['name' => 'Acme Trading'], ['country' => 'SA', 'vat_number' => '300000000000003']);
        $member = User::query()->first() ?? User::factory()->create();
        $member->organizations()->syncWithoutDetaching([$organization->id => ['role' => 'owner', 'status' => 'active']]);

        return $this->actingAs($member)->withSession(['portal_org_id' => $organization->id]);
    }
}
