<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Support\Enums\RequestStage;
use App\Domains\Support\Enums\TicketKind;
use App\Domains\Support\Mail\BoardUpdateMail;
use App\Domains\Support\Mail\TicketMail;
use App\Domains\Support\Models\FeatureBoardBacker;
use App\Domains\Support\Models\FeatureBoardItem;
use App\Domains\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The curated board: feature requests triaged into items an admin words and
 * publishes, which every company reads, backs and follows - and which never
 * tell one company who else asked, or what they wrote.
 */
class FeatureBoardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $gulf;

    private User $sara;

    private User $omar;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->gulf = Organization::create(['name' => 'Gulf Supplies', 'country' => 'SA']);
        $this->sara = $this->memberOf($this->acme, 'sara@acme.test');
        $this->omar = $this->memberOf($this->gulf, 'omar@gulf.test');

        $this->support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $this->support->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_a_draft_stays_off_the_board(): void
    {
        $item = $this->publishFrom($this->ask($this->sara, $this->acme), publish: false);

        $this->as($this->omar, $this->gulf)->get(route('portal.board.index'))->assertOk()->assertDontSee('Invoices from the ERP');
        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $item->id))->assertNotFound();

        $this->actingAs($this->support)->post(route('admin.board.publish', $item->id), ['published' => '1']);

        $this->as($this->omar, $this->gulf)->get(route('portal.board.index'))->assertOk()->assertSee('Invoices from the ERP');
    }

    /**
     * Another company reads the admin's words and the count - never the
     * company that asked, its subject, its message or its system.
     */
    public function test_another_company_learns_nothing_private(): void
    {
        $ticket = $this->ask($this->sara, $this->acme, 'Odoo sync for Acme branches', 'Our Jeddah branch loses two hours a day.');
        $item = $this->publishFrom($ticket);
        $this->as($this->omar, $this->gulf)->post(route('portal.board.back', $item->id), ['back' => '1']);

        foreach ([route('portal.board.index'), route('portal.board.show', $item->id)] as $page) {
            $this->as($this->omar, $this->gulf)->get($page)->assertOk()
                ->assertSee('Invoices from the ERP')
                ->assertDontSee('Acme')
                ->assertDontSee('Odoo sync for Acme branches')
                ->assertDontSee('Jeddah')
                ->assertDontSee('Odoo')
                ->assertDontSee('sara@acme.test');
        }

        $this->as($this->sara, $this->acme)->get(route('portal.board.show', $item->id))->assertOk()
            ->assertDontSee('Gulf')->assertDontSee('omar@gulf.test');
        $this->as($this->omar, $this->gulf)->get(route('portal.support.show', $ticket->id))->assertNotFound();
    }

    public function test_each_company_sees_its_own_part(): void
    {
        $item = $this->publishFrom($this->ask($this->sara, $this->acme));

        $this->as($this->sara, $this->acme)->get(route('portal.board.show', $item->id))->assertOk()
            ->assertSee('Your company asked for this.')->assertDontSee('We need this too');
        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $item->id))->assertOk()
            ->assertSee('We need this too')->assertSee('Companies asking: 1');
    }

    /**
     * Backing counts the company once, follows it for the person, and can
     * be withdrawn and given again without a second row.
     */
    public function test_backing_counts_each_company_once(): void
    {
        $item = $this->publishFrom($this->ask($this->sara, $this->acme));

        $this->as($this->omar, $this->gulf)->post(route('portal.board.back', $item->id), ['back' => '1'])->assertRedirect();
        $this->as($this->omar, $this->gulf)->post(route('portal.board.back', $item->id), ['back' => '1']);
        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $item->id))->assertSee('Companies asking: 2')->assertSee('Stop following');

        $this->as($this->omar, $this->gulf)->post(route('portal.board.back', $item->id), ['back' => '0']);
        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $item->id))->assertSee('Companies asking: 1');

        $this->as($this->omar, $this->gulf)->post(route('portal.board.back', $item->id), ['back' => '1']);
        $this->assertSame(1, FeatureBoardBacker::withTrashed()->where('board_item_id', $item->id)->count());
    }

    /**
     * A duplicate joins the item: both companies keep their request, the
     * demand is two, and both requests move with it.
     */
    public function test_a_duplicate_keeps_every_requester(): void
    {
        $item = $this->publishFrom($this->ask($this->sara, $this->acme));
        $duplicate = $this->ask($this->omar, $this->gulf, 'ERP integration please');

        $this->actingAs($this->support)->post(route('admin.board.link', $duplicate->id), ['item_id' => $item->id])->assertRedirect();

        $this->actingAs($this->support)->get(route('admin.board.show', $item->id))->assertOk()
            ->assertSee('Acme Trading')->assertSee('Gulf Supplies')->assertSee('2 companies want it');
        $this->as($this->omar, $this->gulf)->get(route('portal.support.show', $duplicate->id))->assertOk()->assertSee('Invoices from the ERP');
        $this->assertSame(RequestStage::UnderReview, $duplicate->fresh()->stage);
    }

    /**
     * An update reaches the board, each company's own request and its
     * opener, and every follower not already told.
     */
    public function test_an_update_tells_everyone_concerned(): void
    {
        $ticket = $this->ask($this->sara, $this->acme);
        $item = $this->publishFrom($ticket);
        $this->as($this->omar, $this->gulf)->post(route('portal.board.back', $item->id), ['back' => '1']);
        $this->as($this->sara, $this->acme)->post(route('portal.board.follow', $item->id), ['follow' => '1']);

        $this->actingAs($this->support)->post(route('admin.board.stage', $item->id), [
            'stage' => 'planned', 'note' => 'Planned for the next release.', 'note_ar' => 'مخطط له في الإصدار القادم.',
        ])->assertRedirect();

        $this->assertSame(RequestStage::Planned, $item->fresh()->stage);
        $this->assertSame(RequestStage::Planned, $ticket->fresh()->stage);
        Mail::assertSent(TicketMail::class, fn (TicketMail $mail): bool => $mail->hasTo('sara@acme.test'));
        Mail::assertSent(BoardUpdateMail::class, fn (BoardUpdateMail $mail): bool => $mail->hasTo('omar@gulf.test'));
        Mail::assertNotSent(BoardUpdateMail::class, fn (BoardUpdateMail $mail): bool => $mail->hasTo('sara@acme.test'));

        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $item->id))->assertSee('Planned for the next release.');
    }

    public function test_a_release_is_linked(): void
    {
        $item = $this->publishFrom($this->ask($this->sara, $this->acme));

        $this->actingAs($this->support)->post(route('admin.board.update', $item->id), [
            'title' => 'Invoices from the ERP', 'summary' => 'Send invoices straight from an ERP.', 'category' => 'integration',
            'released_in' => '2026.11', 'release_url' => 'https://example.test/releases/2026-11',
        ])->assertSessionHas('success');

        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $item->id))->assertSee('Released in 2026.11.')
            ->assertSee('https://example.test/releases/2026-11', false);
    }

    /**
     * Merged, a duplicate item leaves the board and everything on it moves
     * across; a company on both counts once.
     */
    public function test_merging_keeps_every_company_once(): void
    {
        $into = $this->publishFrom($this->ask($this->sara, $this->acme));
        $from = $this->publishFrom($this->ask($this->omar, $this->gulf, 'ERP connector'), title: 'ERP connector');
        $this->as($this->sara, $this->acme)->post(route('portal.board.back', $from->id), ['back' => '1']);

        $this->actingAs($this->support)->post(route('admin.board.merge', $from->id), ['into_id' => $into->id])->assertRedirect();

        $this->assertSame($into->id, $from->fresh()->merged_into_id);
        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $from->id))->assertNotFound();
        $this->as($this->omar, $this->gulf)->get(route('portal.board.show', $into->id))->assertSee('Companies asking: 2')->assertSee('Your company asked for this.');
        $this->assertSame(0, FeatureBoardBacker::query()->where('board_item_id', $from->id)->count());
    }

    public function test_the_board_sorts_by_demand(): void
    {
        $quiet = $this->publishFrom($this->ask($this->sara, $this->acme), title: 'Quiet idea');
        $wanted = $this->publishFrom($this->ask($this->omar, $this->gulf), title: 'Wanted idea');
        $this->as($this->sara, $this->acme)->post(route('portal.board.back', $wanted->id), ['back' => '1']);

        $this->actingAs($this->support)->get(route('admin.board.index'))->assertOk()->assertSeeInOrder(['Wanted idea', 'Quiet idea']);
        $this->as($this->omar, $this->gulf)->get(route('portal.board.index'))->assertOk()->assertSeeInOrder(['Wanted idea', 'Quiet idea']);
        $this->assertNotSame($quiet->id, $wanted->id);
    }

    public function test_untriaged_requests_wait_on_the_board(): void
    {
        $ticket = $this->ask($this->sara, $this->acme, 'Scan supplier bills');

        $this->actingAs($this->support)->get(route('admin.board.index'))->assertOk()->assertSee('Scan supplier bills')->assertSee('Acme Trading');
        $this->actingAs($this->support)->get(route('admin.support.show', $ticket->id))->assertOk()->assertSee('Put it on the board as a new item');
    }

    public function test_a_request_becomes_a_customization(): void
    {
        $ticket = $this->ask($this->sara, $this->acme, 'Scan supplier bills');

        $this->actingAs($this->support)->post(route('admin.board.convert', $ticket->id), ['note' => 'We will build this for you.'])->assertRedirect();

        $fresh = SupportTicket::acrossTenants()->with('customization')->find($ticket->id);
        $this->assertSame(TicketKind::Customization, $fresh->kind);
        $this->assertNotNull($fresh->customization);
        $this->actingAs($this->support)->get(route('admin.board.index'))->assertDontSee('Scan supplier bills');
    }

    public function test_an_auditor_cannot_triage(): void
    {
        $ticket = $this->ask($this->sara, $this->acme);
        $this->support->forceFill(['platform_role' => 'auditor'])->save();

        $this->actingAs($this->support)->get(route('admin.board.index'))->assertOk();
        $this->triage($ticket)->assertForbidden();
    }

    private function memberOf(Organization $organization, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        return $user;
    }

    private function as(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $organization->id]);
    }

    private function ask(User $user, Organization $organization, string $subject = 'Connect our ERP', string $body = 'We type every invoice twice.'): SupportTicket
    {
        $this->as($user, $organization)->post(route('portal.support.store'), [
            'kind' => 'feature', 'subject' => $subject, 'body' => $body,
            'feature_category' => 'integration', 'system_name' => 'Odoo', 'importance' => 'important',
        ])->assertRedirect();

        return SupportTicket::acrossTenants()->with('featureRequest')->latest('number')->firstOrFail();
    }

    private function triage(SupportTicket $ticket, bool $publish = true, string $title = 'Invoices from the ERP'): TestResponse
    {
        return $this->actingAs($this->support)->post(route('admin.board.store', $ticket->id), [
            'title' => $title, 'title_ar' => 'الفواتير من نظام تخطيط الموارد',
            'summary' => 'Send invoices straight from an ERP.', 'category' => 'integration', 'publish' => $publish ? '1' : '0',
        ]);
    }

    private function publishFrom(SupportTicket $ticket, bool $publish = true, string $title = 'Invoices from the ERP'): FeatureBoardItem
    {
        $this->triage($ticket, $publish, $title)->assertRedirect();

        return FeatureBoardItem::query()->latest('number')->firstOrFail();
    }
}
