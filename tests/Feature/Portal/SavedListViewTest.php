<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\SavedListView;
use App\Http\Listing\SavedViewStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * A person names a set of filters and comes back to it.
 *
 * A view is theirs alone, kept for the organization they saved it in, and
 * it carries nothing the list would not have understood.
 */
class SavedListViewTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    private Organization $acme;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->member = $this->memberOf($this->acme, 'member');
    }

    public function test_a_saved_view_is_offered_again(): void
    {
        $this->makeInvoice($this->acme, 'ACME-1', ['status' => 'issued']);

        $this->posting($this->member, fn () => $this->post('/portal/views', [
            'list' => 'portal.invoices.index', 'name' => 'Issued', 'query' => 'status=issued&sort=amount',
        ]))->assertRedirect(url('/portal/invoices').'?status=issued&sort=amount');

        $this->as($this->member, fn () => $this->get('/portal/invoices'))
            ->assertOk()
            ->assertSee('Saved views')
            ->assertSee('href="'.e(url('/portal/invoices').'?status=issued&sort=amount').'"', false);
    }

    public function test_a_view_is_its_owners_alone(): void
    {
        $colleague = $this->memberOf($this->acme, 'member');
        $this->save($this->member, 'Mine');

        $this->as($colleague, fn () => $this->get('/portal/invoices'))
            ->assertOk()
            ->assertDontSee('Mine');

        $view = SavedListView::query()->firstOrFail();

        $this->posting($colleague, fn () => $this->post('/portal/views/'.$view->id.'/delete'))->assertRedirect();
        $this->assertDatabaseHas('saved_list_views', ['id' => $view->id]);
    }

    public function test_a_view_stays_in_its_organization(): void
    {
        $other = Organization::create(['name' => 'Other', 'country' => 'SA']);
        $this->member->organizations()->attach($other->id, ['role' => 'member', 'status' => 'active']);
        $this->save($this->member, 'Acme only');

        $this->as($this->member, fn () => $this->withSession(['portal_org_id' => $other->id])->get('/portal/invoices'))
            ->assertOk()
            ->assertDontSee('Acme only');
    }

    public function test_the_owner_deletes_a_view(): void
    {
        $this->save($this->member, 'Old');
        $view = SavedListView::query()->firstOrFail();

        $this->posting($this->member, fn () => $this->post('/portal/views/'.$view->id.'/delete'))->assertRedirect();

        $this->assertDatabaseMissing('saved_list_views', ['id' => $view->id]);
    }

    public function test_a_viewer_keeps_views_too(): void
    {
        $viewer = $this->memberOf($this->acme, 'viewer');

        $this->save($viewer, 'Auditor')->assertRedirect();

        $this->assertDatabaseHas('saved_list_views', ['user_id' => $viewer->id, 'name' => 'Auditor']);
    }

    public function test_only_a_listed_list_is_saved(): void
    {
        $this->posting($this->member, fn () => $this->post('/portal/views', ['list' => 'portal.team.index', 'name' => 'Nope']))
            ->assertSessionHasErrors('list');

        $this->assertDatabaseCount('saved_list_views', 0);
    }

    public function test_the_query_keeps_plain_values_only(): void
    {
        $this->posting($this->member, fn () => $this->post('/portal/views', [
            'list' => 'portal.invoices.index', 'name' => 'Odd', 'query' => 'status[]=issued&page=3&q=acme',
        ]));

        $this->assertSame('q=acme', SavedListView::query()->value('query'));
    }

    public function test_the_store_keeps_a_limit(): void
    {
        foreach (range(1, SavedViewStore::LIMIT) as $n) {
            $this->save($this->member, 'View '.$n);
        }

        $this->save($this->member, 'One too many')->assertSessionHas('error');

        $this->assertDatabaseCount('saved_list_views', SavedViewStore::LIMIT);
    }

    public function test_console_views_are_operators_only(): void
    {
        $operator = User::factory()->create(['is_platform_admin' => true]);

        $this->posting($operator, fn () => $this->post('/account/views', ['list' => 'admin.audit', 'name' => 'Sign-ins', 'query' => 'category=security.']))
            ->assertRedirect();
        $this->assertDatabaseHas('saved_list_views', ['user_id' => $operator->id, 'scope' => 'admin', 'org_id' => null]);

        $this->posting($this->member, fn () => $this->post('/account/views', ['list' => 'admin.audit', 'name' => 'Mine']))
            ->assertForbidden();
    }

    private function save(User $user, string $name): TestResponse
    {
        return $this->posting($user, fn () => $this->post('/portal/views', ['list' => 'portal.invoices.index', 'name' => $name, 'query' => 'status=draft']));
    }

    /**
     * As the user. A page is read inside a request, as a browser gets it; a
     * form is posted outside one, where the test suite stands CSRF down.
     *
     * @param  callable(): TestResponse  $request
     */
    private function as(User $user, callable $request): TestResponse
    {
        $this->actingAs($user);

        return $this->asRequest(fn (): TestResponse => $request());
    }

    /**
     * @param  callable(): TestResponse  $request
     */
    private function posting(User $user, callable $request): TestResponse
    {
        $this->actingAs($user);

        return $request();
    }

    private function memberOf(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
