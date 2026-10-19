<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Platform\Models\WebsiteTestimonial;
use App\Domains\Platform\Website\WebsiteImages;
use App\Domains\Platform\Website\WebsiteSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Website › Testimonials, and the home page section that shows them.
 *
 * The section is off by default, and shows nothing until it is on and at
 * least one testimonial is published. Testimonials are written by an admin
 * holding website.manage - the owner and operations - in both languages,
 * shown as plain text, and a logo is an image or nothing.
 */
class WebsiteTestimonialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(WebsiteImages::DISK);
    }

    public function test_the_section_starts_switched_off(): void
    {
        $this->testimonial(['published' => true]);

        $this->get('/')->assertOk()->assertDontSee('id="testimonials"', false)->assertDontSee('Clearance just works.');
        $this->assertFalse(app(WebsiteSections::class)->switchedOn('testimonials'));
    }

    public function test_shown_when_on_and_published(): void
    {
        $this->testimonial(['published' => true]);
        $this->testimonial(['quote_en' => 'Not yet approved.', 'published' => false]);
        app(WebsiteSections::class)->setEnabled('testimonials', true);

        $this->withSession(['locale' => 'en'])->get('/')
            ->assertSeeInOrder(['id="plans"', 'id="testimonials"', 'id="faq"'], false)
            ->assertSee('Clearance just works.')
            ->assertSee('Head of finance, Gulf Supplies')
            ->assertDontSee('Not yet approved.');

        $this->withSession(['locale' => 'ar'])->get('/')
            ->assertSee('الاعتماد يعمل ببساطة.')
            ->assertSee('هدى الحربي');
    }

    public function test_on_with_none_published_shows_nothing(): void
    {
        $this->testimonial(['published' => false]);
        app(WebsiteSections::class)->setEnabled('testimonials', true);

        $this->get('/')->assertOk()->assertDontSee('id="testimonials"', false);
    }

    public function test_a_quote_is_shown_as_text(): void
    {
        $this->testimonial(['quote_en' => '<script>alert(1)</script> **Fast**', 'published' => true]);
        app(WebsiteSections::class)->setEnabled('testimonials', true);

        $html = (string) $this->withSession(['locale' => 'en'])->get('/')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; <strong class="font-semibold">Fast</strong>', $html);
    }

    /** An admin adds one with a logo, served only while it is published. */
    public function test_a_logo_is_served_sandboxed(): void
    {
        $this->actingAs($this->admin('operations'))
            ->post(route('admin.website.testimonials.store'), [
                ...$this->form(),
                'published' => '1',
                'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
            ])
            ->assertRedirect(route('admin.website.testimonials.index'))
            ->assertSessionHasNoErrors();

        $testimonial = WebsiteTestimonial::query()->sole();
        $this->assertSame('image/png', $testimonial->logo_type);
        Storage::disk(WebsiteImages::DISK)->assertExists((string) $testimonial->logo_path);

        $logo = $this->get(route('website.testimonials.logo', $testimonial->id))->assertOk();
        $logo->assertHeader('Content-Type', 'image/png');
        $logo->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $logo->headers->get('Content-Security-Policy'));

        $testimonial->forceFill(['published' => false])->save();
        $this->get(route('website.testimonials.logo', $testimonial->id))->assertNotFound();
    }

    public function test_a_logo_must_be_an_image(): void
    {
        $admin = $this->admin('owner');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $text = UploadedFile::fake()->createWithContent('logo.png', 'not an image at all');
        $large = UploadedFile::fake()->image('logo.png', 100, 100)->size(600);

        foreach ([$svg, $text, $large] as $file) {
            $this->actingAs($admin)
                ->post(route('admin.website.testimonials.store'), [...$this->form(), 'logo' => $file])
                ->assertSessionHasErrors('logo');
        }

        $this->assertSame(0, WebsiteTestimonial::query()->count());
        $this->assertSame([], Storage::disk(WebsiteImages::DISK)->allFiles());
    }

    public function test_both_languages_are_required(): void
    {
        $this->actingAs($this->admin('owner'))
            ->post(route('admin.website.testimonials.store'), [...$this->form(), 'quote_ar' => '', 'name_ar' => ' '])
            ->assertSessionHasErrors(['quote_ar', 'name_ar']);
    }

    public function test_only_website_managers_reach_it(): void
    {
        $testimonial = $this->testimonial();

        foreach (['support', 'auditor'] as $role) {
            $admin = $this->admin($role);
            $this->actingAs($admin)->get(route('admin.website.testimonials.index'))->assertForbidden();
            $this->actingAs($admin)->post(route('admin.website.testimonials.destroy', $testimonial->id))->assertForbidden();
        }

        foreach (['owner', 'operations'] as $role) {
            $this->actingAs($this->admin($role))->get(route('admin.website.testimonials.index'))->assertOk()->assertSee('Huda Al-Harbi');
        }

        $this->actingAs(User::factory()->create())->get(route('admin.website.testimonials.index'))->assertForbidden();
    }

    public function test_every_change_is_audited(): void
    {
        $testimonial = $this->testimonial();
        $admin = $this->admin('operations');

        $this->actingAs($admin)->post(route('admin.website.testimonials.update', $testimonial->id), [...$this->form(), 'quote_en' => 'Even better now.']);
        $this->actingAs($admin)->post(route('admin.website.testimonials.visibility', $testimonial->id), ['published' => '1']);
        $this->actingAs($admin)->post(route('admin.website.testimonials.destroy', $testimonial->id));

        $log = AuditLog::query()->where('action', 'admin.website.testimonials.update')->sole();
        $this->assertSame('WebsiteTestimonial', $log->entity_type);
        $this->assertSame($testimonial->id, $log->entity_id);
        $this->assertSame('Clearance just works.', $log->old_values['quote_en']);
        $this->assertSame('Even better now.', $log->new_values['quote_en']);
        $this->assertSame($admin->id, $log->user_id);

        $this->assertTrue(AuditLog::query()->where('action', 'admin.website.testimonials.visibility')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.website.testimonials.destroy')->exists());

        $removed = WebsiteTestimonial::withTrashed()->find($testimonial->id);
        $this->assertNotNull($removed->deleted_at);
        $this->assertSame($admin->id, $removed->deleted_by);
    }

    public function test_testimonials_move_up_and_down(): void
    {
        $first = $this->testimonial(['name_en' => 'First', 'published' => true]);
        $second = $this->testimonial(['name_en' => 'Second', 'published' => true]);
        $first->forceFill(['position' => 10])->save();
        $second->forceFill(['position' => 20])->save();

        $this->actingAs($this->admin('owner'))
            ->post(route('admin.website.testimonials.move', $second->id), ['direction' => 'up'])
            ->assertRedirect(route('admin.website.testimonials.index'));

        app(WebsiteSections::class)->setEnabled('testimonials', true);
        $this->withSession(['locale' => 'en'])->get('/')->assertSeeInOrder(['Second', 'First']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function testimonial(array $overrides = []): WebsiteTestimonial
    {
        return WebsiteTestimonial::query()->create([
            'quote_en' => 'Clearance just works.',
            'quote_ar' => 'الاعتماد يعمل ببساطة.',
            'name_en' => 'Huda Al-Harbi',
            'name_ar' => 'هدى الحربي',
            'role_en' => 'Head of finance',
            'role_ar' => 'رئيسة المالية',
            'company_en' => 'Gulf Supplies',
            'company_ar' => 'إمدادات الخليج',
            ...$overrides,
        ]);
    }

    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'quote_en' => 'Clearance just works.',
            'quote_ar' => 'الاعتماد يعمل ببساطة.',
            'name_en' => 'Huda Al-Harbi',
            'name_ar' => 'هدى الحربي',
            'role_en' => 'Head of finance',
            'role_ar' => '',
            'company_en' => 'Gulf Supplies',
            'company_ar' => '',
            'published' => '0',
        ];
    }

    private function admin(string $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }
}
