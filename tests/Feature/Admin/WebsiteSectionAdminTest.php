<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Platform\Website\SectionCatalogue;
use App\Domains\Platform\Website\WebsiteSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin › Website › Home page: the sections list, each section's form, and
 * the audit record every change leaves.
 *
 * Held to website.manage: the owner and operations edit the public page;
 * support and auditors do not, and a customer never reaches the console.
 */
class WebsiteSectionAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_section_form_opens(): void
    {
        $admin = $this->admin('operations');

        $this->actingAs($admin)->get(route('admin.website.sections.index'))
            ->assertOk()
            ->assertSeeInOrder(['Hero', 'Where we work', 'Plans', 'Testimonials', 'Questions', 'Contact us'])
            ->assertSee('View the home page');

        foreach (array_keys(app(SectionCatalogue::class)->all()) as $key) {
            $this->actingAs($admin)->get(route('admin.website.sections.edit', $key))->assertOk()->assertSee('name="title', false);
        }

        $this->actingAs($admin)->get(route('admin.website.sections.edit', 'nowhere'))->assertNotFound();
    }

    public function test_the_form_saves_both_languages(): void
    {
        $admin = $this->admin('owner');
        $form = $this->form('contact');
        $form['title'] = ['en' => 'Say hello.', 'ar' => 'قل مرحباً.'];

        $this->actingAs($admin)->post(route('admin.website.sections.update', 'contact'), $form)
            ->assertRedirect(route('admin.website.sections.edit', 'contact'))
            ->assertSessionHasNoErrors();

        $this->withSession(['locale' => 'ar'])->get('/')->assertSee('قل مرحباً.')->assertSee('Say hello.');

        $log = AuditLog::query()->where('action', 'admin.website.sections.update')->sole();
        $this->assertSame(['WebsiteSection', 'contact'], [$log->entity_type, $log->entity_id]);
        $this->assertSame('Tell us about your business.', $log->old_values['title']['en']);
        $this->assertSame('Say hello.', $log->new_values['title']['en']);
        $this->assertSame($admin->id, $log->user_id);
    }

    /** A free list's spare rows add items; an emptied row removes one. */
    public function test_list_rows_add_and_remove(): void
    {
        $form = $this->form('faq');
        $form['items'][0] = ['question' => ['en' => '', 'ar' => ''], 'answer' => ['en' => '', 'ar' => '']];
        $form['items'][] = ['question' => ['en' => 'Is there an app?', 'ar' => 'هل يوجد تطبيق؟'], 'answer' => ['en' => 'The portal works on a phone.', 'ar' => 'تعمل البوابة على الهاتف.']];

        $this->actingAs($this->admin('owner'))->get(route('admin.website.sections.edit', 'faq'))->assertSee('name="items[6][question][en]"', false);
        $this->actingAs($this->admin('owner'))->post(route('admin.website.sections.update', 'faq'), $form)->assertSessionHasNoErrors();

        $this->withSession(['locale' => 'en'])->get('/')->assertDontSee('Do I need my own ERP?')->assertSee('Is there an app?');
    }

    public function test_a_price_is_sent_back(): void
    {
        $form = $this->form('plans');
        $form['body']['ar'] = 'من ٩٩ ريال شهرياً';

        $this->actingAs($this->admin('owner'))
            ->from(route('admin.website.sections.edit', 'plans'))
            ->post(route('admin.website.sections.update', 'plans'), $form)
            ->assertRedirect(route('admin.website.sections.edit', 'plans'))
            ->assertSessionHasErrors('body.ar');

        $this->actingAs($this->admin('owner'))->get(route('admin.website.sections.edit', 'plans'))
            ->assertSee('names a price')
            ->assertSee('من ٩٩ ريال شهرياً');
    }

    public function test_switch_move_and_reset_are_audited(): void
    {
        $admin = $this->admin('operations');
        $sections = app(WebsiteSections::class);

        $this->actingAs($admin)->post(route('admin.website.sections.visibility', 'business'), ['enabled' => '0'])->assertRedirect(route('admin.website.sections.index'));
        $this->assertFalse($sections->shows('business'));

        $this->actingAs($admin)->post(route('admin.website.sections.move', 'contact'), ['direction' => 'up']);
        $this->assertSame('contact', array_keys($sections->page())[count($sections->page()) - 2]);

        $this->actingAs($admin)->post(route('admin.website.sections.visibility', 'hero'), ['enabled' => '0'])->assertSessionHas('error');
        $this->assertTrue($sections->shows('hero'));

        $sections->save('steps', [...$sections->texts('steps'), 'title' => ['en' => 'Three steps.', 'ar' => 'ثلاث خطوات.']]);
        $this->actingAs($admin)->get(route('admin.website.sections.edit', 'steps'))->assertSee('Reset to default')->assertSee('data-confirm-tone="danger"', false);
        $this->actingAs($admin)->post(route('admin.website.sections.reset', 'steps'))->assertRedirect(route('admin.website.sections.edit', 'steps'));
        $this->assertSame('Four steps to your first invoice.', $sections->texts('steps')['title']['en']);

        foreach (['visibility', 'move', 'reset'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', 'admin.website.sections.'.$action)->where('user_id', $admin->id)->exists(), $action);
        }

        $reset = AuditLog::query()->where('action', 'admin.website.sections.reset')->sole();
        $this->assertSame('Three steps.', $reset->old_values['title']['en']);
    }

    public function test_only_website_managers_reach_it(): void
    {
        foreach (['support', 'auditor'] as $role) {
            $admin = $this->admin($role);
            $this->actingAs($admin)->get(route('admin.website.sections.index'))->assertForbidden();
            $this->actingAs($admin)->post(route('admin.website.sections.reset', 'faq'))->assertForbidden();
            $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.website.sections.index'));
        }

        $this->actingAs(User::factory()->create())->get(route('admin.website.sections.index'))->assertForbidden();
        $this->actingAs($this->admin('operations'))->get(route('admin.dashboard'))->assertSee(route('admin.website.sections.index'));
    }

    /**
     * A section's current words, as its form posts them.
     *
     * @return array<string, mixed>
     */
    private function form(string $key): array
    {
        return app(WebsiteSections::class)->texts($key);
    }

    private function admin(string $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }
}
