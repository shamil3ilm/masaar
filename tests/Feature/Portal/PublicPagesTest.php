<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pages anyone can open: the landing page, the privacy notice and the
 * terms, in either language.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_invites_sign_up(): void
    {
        config(['signup.enabled' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee(route('register'), false)
            ->assertSee(route('privacy'), false)
            ->assertSee('Plans');
    }

    public function test_no_invitation_without_sign_up(): void
    {
        config(['signup.enabled' => false]);

        $this->get('/')->assertOk()->assertDontSee(route('register'), false);
    }

    /**
     * The notice names who is responsible, from the deployment's settings.
     */
    public function test_the_privacy_notice_names_the_operator(): void
    {
        config(['company.legal_name' => 'Masaar Information Technology Co.', 'company.email' => 'privacy@masaar.test']);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Masaar Information Technology Co.')
            ->assertSee('privacy@masaar.test')
            ->assertSee('Personal Data Protection Law');
    }

    public function test_the_terms_read_in_arabic(): void
    {
        $this->post('/locale', ['locale' => 'ar']);

        $this->get('/terms')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('شروط الخدمة')
            ->assertSee('تخضع هذه الشروط لأنظمة المملكة العربية السعودية');
    }

    /**
     * Sign-up asks people to accept the terms; with none configured, it
     * links to the ones published here instead of to nothing.
     */
    public function test_sign_up_links_the_published_terms(): void
    {
        $this->assertSame('/terms', config('signup.terms_url'));
    }
}
