<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Platform\Website\SectionContent;
use App\Support\Brand;
use Tests\TestCase;

/**
 * The product's name comes from config/brand.php alone, and :app in any
 * translated line is that name in the line's language.
 */
class BrandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['brand.name' => 'Nibras', 'brand.name_ar' => 'نبراس']);
    }

    public function test_the_name_follows_the_language(): void
    {
        $this->assertSame('Nibras', Brand::name('en'));
        $this->assertSame('نبراس', Brand::name('ar'));

        app()->setLocale('ar');
        $this->assertSame('نبراس', Brand::name());
    }

    public function test_app_placeholder_is_the_name(): void
    {
        $this->assertSame('Signed by Nibras.', __('Signed by :app.'));
        $this->assertSame('Signed by نبراس.', __('Signed by :app.', [], 'ar'));

        app()->setLocale('ar');
        $this->assertSame('Signed by نبراس.', __('Signed by :app.'));
    }

    public function test_a_given_app_value_is_kept(): void
    {
        $this->assertSame('Signed by :app.', __('Signed by :app.', ['app' => SectionContent::NAME]));
        $this->assertSame('Signed by Acme.', __('Signed by :app.', ['app' => 'Acme']));
    }

    public function test_website_text_names_it_per_language(): void
    {
        $this->assertSame('Nibras signs.', SectionContent::named(':app signs.', 'en'));
        $this->assertSame('يوقّع نبراس.', SectionContent::named('يوقّع :app.', 'ar'));
    }

    public function test_identifiers_come_from_the_configuration(): void
    {
        config([
            'brand.user_agent' => 'Nibras',
            'brand.metrics_prefix' => 'nibras',
            'brand.csr_solution' => 'Nibras',
        ]);

        $this->assertSame('Nibras-Webhook/1.0', Brand::userAgent('Webhook'));
        $this->assertSame('nibras', Brand::metricsPrefix());
        $this->assertSame('Nibras', Brand::csrSolution());
    }
}
