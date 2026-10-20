<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Platform\Website\WebsiteImages;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * How the public site appears in search and when shared, in both languages.
 *
 * The language used to live only in the session, so a search engine saw
 * one language at one address. Each public page now also has an address per
 * language (?lang=), which its head links as hreflang alternates and
 * sitemap.xml lists; the language switch drops it again so the visitor's
 * choice wins.
 */
class WebsiteSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_head_keeps_its_old_words(): void
    {
        $html = (string) $this->withSession(['locale' => 'en'])->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>E-invoicing - '.$this->site().'</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="E-invoicing for each country you sell in: issue, sign, file and keep every invoice, in Arabic and English.">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="E-invoicing - '.$this->site().'">', $html);
        // No picture set: the shipped one, the mark on the route line.
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.asset('icons/og-image.png').'">', $html);
    }

    public function test_each_language_has_its_address(): void
    {
        $home = url('/').'/';
        $html = (string) $this->get('/?lang=ar')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="ar" dir="rtl"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$home.'?lang=ar">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.$home.'?lang=en">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ar" href="'.$home.'?lang=ar">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.$home.'">', $html);
        $this->assertStringContainsString('<meta property="og:locale" content="ar_SA">', $html);

        // Kept for the next page, as the switch would keep it.
        $this->get('/privacy')->assertSee('<html lang="ar"', false);

        $this->get('/privacy?lang=en')->assertSee('<html lang="en"', false)
            ->assertSee('<link rel="canonical" href="'.route('privacy').'?lang=en">', false);
    }

    public function test_the_parameter_means_nothing_elsewhere(): void
    {
        $this->get('/login?lang=ar')->assertSee('<html lang="en"', false);
        $this->get('/?lang=fr')->assertSee('<html lang="en"', false);
    }

    /** The switch drops the address's language, or the address would choose again. */
    public function test_the_switch_beats_the_address(): void
    {
        $this->get('/?lang=ar');

        $this->from(url('/').'/?lang=ar&topic=pricing')
            ->post('/locale', ['locale' => 'en'])
            ->assertRedirect(url('/').'/?topic=pricing');
    }

    public function test_the_sitemap_lists_both_languages(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));

        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);
        $this->assertCount(8, $xml->url);

        $locations = array_map(fn ($url): string => (string) $url->loc, iterator_to_array($xml->url, false));
        $this->assertContains(url('/').'/?lang=ar', $locations);
        $this->assertContains(route('terms').'?lang=en', $locations);

        $links = $xml->url[0]->children('http://www.w3.org/1999/xhtml')->link;
        $this->assertSame(['en', 'ar', 'x-default'], array_map(fn ($link): string => (string) $link->attributes()->hreflang, iterator_to_array($links, false)));
    }

    public function test_robots_points_at_the_sitemap(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'A file there is served before the route, and would hide the sitemap.');

        $robots = (string) $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $robots);
        foreach (['/admin/', '/portal/', '/api/', '/i/'] as $private) {
            $this->assertStringContainsString('Disallow: '.$private, $robots);
        }
        $this->assertStringNotContainsString("Disallow: /\n", $robots);
    }

    public function test_an_admin_words_both_languages(): void
    {
        Storage::fake(WebsiteImages::DISK);
        $admin = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'operations']);

        $this->actingAs($admin)->get(route('admin.website.seo.edit'))->assertOk()->assertSee('name="title[ar]"', false);

        $this->actingAs($admin)->post(route('admin.website.seo.update'), [
            'title' => ['en' => 'E-invoicing in the Gulf', 'ar' => 'الفوترة الإلكترونية في الخليج'],
            'description' => ['en' => 'Issue and file **every** invoice.', 'ar' => 'أصدر كل فاتورة وقدّمها.'],
            'share_title' => ['en' => ':app e-invoicing', 'ar' => ':app للفوترة الإلكترونية'],
            'share_text' => ['en' => 'From the first invoice.', 'ar' => 'من الفاتورة الأولى.'],
            'image' => UploadedFile::fake()->image('share.png', 1200, 630),
        ])->assertRedirect(route('admin.website.seo.edit'))->assertSessionHasNoErrors();

        $en = (string) $this->withSession(['locale' => 'en'])->get('/')->getContent();
        $this->assertStringContainsString('<title>E-invoicing in the Gulf - '.$this->site().'</title>', $en);
        $this->assertStringContainsString('content="Issue and file every invoice."', $en);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $en);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="'.preg_quote(route('website.share-image'), '#').'\?v=\d+">#', $en);

        $this->get('/?lang=ar')->assertSee('<title>الفوترة الإلكترونية في الخليج - '.$this->site().'</title>', false)
            ->assertSee('<meta property="og:title" content="'.Brand::name('ar').' للفوترة الإلكترونية - '.$this->site().'">', false);

        $this->get(route('website.share-image'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        $log = AuditLog::query()->where('action', 'admin.website.seo.update')->sole();
        $this->assertSame('E-invoicing in the Gulf', $log->new_values['title']['en']);
        $this->assertSame($admin->id, $log->user_id);
    }

    /** The site's name as the page's frame shows it: the platform theme's. */
    private function site(): string
    {
        return app(ThemeService::class)->forScope('platform')->name();
    }

    public function test_bad_input_is_sent_back(): void
    {
        Storage::fake(WebsiteImages::DISK);
        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);

        $this->actingAs($owner)->post(route('admin.website.seo.update'), [
            'title' => ['en' => str_repeat('a', 71), 'ar' => 'عنوان'],
            'description' => ['en' => 'Plans from SAR 49.', 'ar' => 'وصف'],
            'share_title' => ['en' => 'x', 'ar' => ''],
            'share_text' => ['en' => 'x', 'ar' => 'y'],
            'image' => UploadedFile::fake()->createWithContent('share.png', '<svg/>'),
        ])->assertSessionHasErrors(['title.en', 'description.en', 'share_title.ar']);

        $this->assertSame([], Storage::disk(WebsiteImages::DISK)->allFiles());
        $this->get(route('website.share-image'))->assertNotFound();

        $support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $this->actingAs($support)->get(route('admin.website.seo.edit'))->assertForbidden();
    }
}
