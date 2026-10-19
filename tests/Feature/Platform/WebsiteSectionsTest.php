<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Platform\Models\WebsiteSection;
use App\Domains\Platform\Website\PriceCheck;
use App\Domains\Platform\Website\SectionCatalogue;
use App\Domains\Platform\Website\WebsiteSections;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The home page's sections, editable from Admin › Website over the words
 * the page was written with.
 *
 * tests/Fixtures/website/home-{en,ar}.txt are the page's visible text as it
 * was before its words moved out of the Blade files, one text node a line.
 * The page out of the box must read exactly that, in both languages - with
 * the product's name, there as :app, being whatever is configured.
 */
class WebsiteSectionsTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/website/home-%s.txt';

    public function test_defaults_read_as_the_page_did(): void
    {
        foreach (SectionCatalogue::LOCALES as $locale) {
            $this->assertSame($this->fixture($locale), $this->visibleText($locale), "The {$locale} home page reads differently from before.");
        }
    }

    /** A rename reaches every section with no edit: the name is never stored. */
    public function test_the_name_follows_the_configuration(): void
    {
        $catalogue = app(SectionCatalogue::class);

        foreach (array_keys($catalogue->all()) as $key) {
            $defaults = (string) json_encode($catalogue->defaults($key), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Masaar', $defaults, $key);
            $this->assertDoesNotMatchRegularExpression('/(?<!\p{L})مسار(?!\p{L})/u', $defaults, $key);
        }

        config(['app.name' => 'Nibras']);

        $this->withSession(['locale' => 'en'])->get('/')
            ->assertSee('Nibras checks, sends and keeps each one')
            ->assertSee('Nibras is open to any country');
        $this->withSession(['locale' => 'ar'])->get('/')->assertSee('يتولى Nibras تدقيق كل فاتورة');
    }

    public function test_edited_text_shows_in_both_languages(): void
    {
        $this->sections()->save('faq', $this->edited('faq', fn (array $c): array => [
            ...$c,
            'title' => ['en' => 'Good questions.', 'ar' => 'أسئلة وجيهة.'],
        ]));

        $this->withSession(['locale' => 'en'])->get('/')->assertSee('Good questions.')->assertSee('أسئلة وجيهة.')->assertDontSee('Asked often.');
        $this->withSession(['locale' => 'ar'])->get('/')->assertSee('أسئلة وجيهة.')->assertSee('Good questions.');
    }

    public function test_admin_text_is_never_markup(): void
    {
        $this->sections()->save('plans', $this->edited('plans', fn (array $c): array => [
            ...$c,
            'title' => ['en' => '<script>alert(1)</script>', 'ar' => 'خطة <b>لك</b>'],
            'body' => ['en' => "Plans **made for you**.\nAsk us. <img src=x onerror=alert(1)>", 'ar' => 'خطط **لك**.'],
        ]));

        $html = (string) $this->withSession(['locale' => 'en'])->get('/')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('Plans <strong class="font-semibold">made for you</strong>.<br>', $html);
        $this->assertStringContainsString('خطة &lt;b&gt;لك&lt;/b&gt;', $html);
    }

    public function test_a_price_is_refused(): void
    {
        foreach (['From SAR 99 a month', '99 SAR', '$49', '49 dollars', 'من ٩٩ ريال', '150 ر.س شهرياً', 'AED 1,200'] as $price) {
            try {
                $this->sections()->save('plans', $this->edited('plans', fn (array $c): array => [...$c, 'body' => ['en' => $price, 'ar' => 'نص']]));
                $this->fail("A price was accepted: {$price}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('body.en', $e->errors(), $price);
            }
        }

        $this->assertNull(WebsiteSection::query()->value('content'));
    }

    public function test_a_number_alone_is_no_price(): void
    {
        foreach (['Four steps', '15% VAT on every invoice', '24 hours to report', 'Version 2.1', 'خلال ٢٤ ساعة'] as $text) {
            $this->assertFalse(PriceCheck::namesPrice($text), $text);
        }
    }

    public function test_every_field_needs_both_languages(): void
    {
        try {
            $this->sections()->save('contact', $this->edited('contact', fn (array $c): array => [...$c, 'title' => ['en' => 'Write to us', 'ar' => '  ']]));
            $this->fail('A heading without its Arabic was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['title.ar'], array_keys($e->errors()));
        }
    }

    public function test_a_fixed_list_keeps_its_count(): void
    {
        $this->expectException(ValidationException::class);

        $this->sections()->save('custom', $this->edited('custom', fn (array $c): array => [...$c, 'asks' => array_slice($c['asks'], 0, 2)]));
    }

    public function test_empty_rows_are_spare_slots(): void
    {
        $this->sections()->save('faq', $this->edited('faq', fn (array $c): array => [
            ...$c,
            'items' => [...$c['items'], ['question' => ['en' => '', 'ar' => ''], 'answer' => ['en' => '', 'ar' => '']]],
        ]));

        $this->assertCount(5, $this->sections()->texts('faq')['items']);
        // The same as the default, so nothing of its own is stored.
        $this->assertNull(WebsiteSection::query()->where('key', 'faq')->value('content'));
    }

    public function test_switched_off_section_leaves_the_page(): void
    {
        $this->sections()->setEnabled('plans', false);

        $this->get('/')->assertOk()
            ->assertDontSee('id="plans"', false)
            ->assertDontSee(url('/').'#plans', false)
            ->assertSee('id="faq"', false);

        $this->sections()->setEnabled('plans', true);

        $this->get('/')->assertSee('id="plans"', false);
    }

    /** One place at a time, and nowhere past either end. */
    public function test_sections_move_up_and_down(): void
    {
        // Past the testimonials, hidden until turned on but still in the list, then past the plans.
        $this->sections()->move('faq', 'up');
        $this->sections()->move('faq', 'up');

        $order = array_keys($this->sections()->page());
        $this->assertSame(['hero', 'countries', 'business', 'custom', 'features', 'steps', 'faq', 'plans', 'contact'], $order);

        $this->sections()->move('countries', 'up');
        $this->sections()->move('contact', 'down');
        $this->assertSame($order, array_keys($this->sections()->page()), 'Moving past either end changes nothing.');

        $this->get('/')->assertSeeInOrder(['id="faq"', 'id="plans"'], false);
    }

    public function test_the_hero_stays_first_and_shown(): void
    {
        $this->expectException(DomainException::class);

        $this->sections()->setEnabled('hero', false);
    }

    public function test_reset_returns_the_shipped_words(): void
    {
        $this->sections()->save('steps', $this->edited('steps', fn (array $c): array => [
            ...$c,
            'items' => [['text' => ['en' => 'Sign up.', 'ar' => 'سجّل.']], ['text' => ['en' => 'Invoice.', 'ar' => 'أصدر فاتورة.']]],
        ]));
        $this->sections()->setEnabled('business', false);

        $this->assertStringContainsString('Sign up.', $this->visibleText('en'));

        $this->sections()->reset('steps');
        $this->sections()->setEnabled('business', true);

        $this->assertSame($this->fixture('en'), $this->visibleText('en'));
    }

    /** The countries and their state are the registry's, whatever the section says. */
    public function test_country_truths_stay_the_registrys(): void
    {
        $this->sections()->save('countries', $this->edited('countries', fn (array $c): array => [
            ...$c,
            'title' => ['en' => 'Where we file.', 'ar' => 'حيث نقدّم الفواتير.'],
        ]));
        app(JurisdictionAvailability::class)->set('AE', false);

        $this->get('/')->assertOk()
            ->assertSee('Where we file.')
            ->assertSee('United Arab Emirates <span class="font-normal">(coming soon)</span>', false)
            ->assertSeeInOrder(['Standards we follow', 'ZATCA XML Implementation Standard', 'Peppol PINT AE']);

        $this->assertNotContains('United Arab Emirates', array_merge(...array_values(array_map(
            fn (array $both): array => array_values($both),
            array_filter($this->sections()->texts('countries'), fn (mixed $value): bool => is_array($value) && isset($value['en'])),
        ))));
    }

    private function sections(): WebsiteSections
    {
        return app(WebsiteSections::class);
    }

    /**
     * A section's current text, changed, as its edit form would post it.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function edited(string $key, callable $change): array
    {
        return $change($this->sections()->texts($key));
    }

    /** The fixture, with the product's configured name where it says :app. */
    private function fixture(string $locale): string
    {
        return str_replace(["\r\n", ':app'], ["\n", (string) config('app.name')], (string) file_get_contents(base_path(sprintf(self::FIXTURE, $locale))));
    }

    /**
     * The page's main content as a reader sees it: each text node on a line
     * of its own, whitespace collapsed, entities decoded, nothing drawn
     * (scripts, styles, inline SVG) and no markup.
     */
    private function visibleText(string $locale): string
    {
        $html = (string) $this->withSession(['locale' => $locale])->get('/')->assertOk()->getContent();

        preg_match('/<main id="content">(.*)<\/main>/s', $html, $main);
        $body = (string) preg_replace(['/<script\b.*?<\/script>/s', '/<style\b.*?<\/style>/s', '/<svg\b.*?<\/svg>/s'], '', $main[1] ?? '');
        $text = html_entity_decode(strip_tags((string) preg_replace('/<[^>]+>/', "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = array_filter(
            array_map(fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line)), explode("\n", $text)),
            fn (string $line): bool => $line !== '',
        );

        return implode("\n", $lines)."\n";
    }
}
