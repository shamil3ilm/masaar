<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Invoice\Services\InvoicePdf;
use App\Domains\Invoice\Services\ReceiptImage;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * A Saudi invoice prints Arabic first, right to left, with the English
 * beside it: the PDF, the browser's print view and the till's receipt.
 * An organization may choose English first, never English alone, and the
 * platform's default is a customization that ships as Arabic first, and
 * there is no person's own: the order belongs to the organization's document.
 *
 * ZATCA's E-Invoicing Detailed Guidelines (v2, May 2023) p63: "Any
 * information contained in the human readable form of the Tax Invoice must
 * be in Arabic including notes. Any additional translation is also allowed."
 */
class ArabicFirstPrintTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private const TOKEN = 'arabic-first-token';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
    }

    public function test_the_pdf_prints_arabic_first(): void
    {
        $html = $this->pdf('standard');

        $this->assertStringContainsString('<html dir="rtl" lang="ar">', $html);
        $this->assertStringContainsString('رقم الفاتورة / Invoice number', $html);
        $this->assertStringContainsString('تفاصيل السلع أو الخدمات<br><span class="en">Description</span>', $html);
    }

    public function test_english_first_keeps_both_languages(): void
    {
        app(PrintPreferences::class)->setLanguageOrder($this->organization, PrintPreferences::ENGLISH_FIRST);

        $html = $this->pdf('standard');

        $this->assertStringContainsString('<html dir="ltr" lang="en">', $html);
        $this->assertStringContainsString('Invoice number / رقم الفاتورة', $html);
        $this->assertStringContainsString('Description<br><span class="ar">تفاصيل السلع أو الخدمات</span>', $html);
    }

    public function test_the_platform_default_is_a_customization(): void
    {
        $preferences = app(PrintPreferences::class);
        $this->assertTrue($preferences->arabicFirst($this->organization));

        app(Customizations::class)->set('printing.language_order', Level::Platform, Scope::platform(), PrintPreferences::ENGLISH_FIRST, null);
        $this->assertFalse($preferences->arabicFirst($this->organization));
        $this->assertNull($preferences->languageOrder($this->organization));

        $preferences->setLanguageOrder($this->organization, PrintPreferences::ARABIC_FIRST);
        $this->assertTrue($preferences->arabicFirst($this->organization->fresh()));
    }

    public function test_no_person_has_their_own_order(): void
    {
        $definition = app(Customizations::class)->definition('printing.language_order');

        $this->assertSame([Level::Platform, Level::Organization], $definition->levels);
        $this->assertSame(PrintPreferences::ARABIC_FIRST, $definition->default);
    }

    public function test_the_receipt_puts_arabic_first(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 3);
        $receipt = app(JurisdictionResolver::class)->forRules($this->organization)->presentation()->receipt($invoice, $this->organization);

        $this->assertTrue($receipt->arabicFirst);
        $this->assertStringContainsString('data-arabic-first', app(InvoicePdf::class)->html($invoice, $this->organization, PaperSize::Roll80));

        // The printer's image draws it too.
        $this->assertGreaterThan(0, imagesy(app(ReceiptImage::class)->render($receipt, PaperSize::Roll80, 576, 203)));
    }

    public function test_the_browser_print_follows_it(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'standard', 2);
        $admin = $this->admin();

        $this->asRequest(fn (): TestResponse => $this->actingAs($admin)->get('/portal/invoices/'.$invoice->id.'/print?paper=a4'))
            ->assertOk()
            ->assertSee('dir="rtl" lang="ar"', false);
    }

    public function test_an_administrator_chooses_english_first(): void
    {
        $admin = $this->admin();

        $this->asRequest(fn (): TestResponse => $this->actingAs($admin)->get('/portal/printing'))
            ->assertOk()
            ->assertSee('name="language_order"', false);

        $this->asRequest(fn (): TestResponse => $this->actingAs($admin)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/printing', ['_token' => self::TOKEN, 'paper' => 'a4', 'language_order' => PrintPreferences::ENGLISH_FIRST]))
            ->assertSessionHasNoErrors();

        $this->assertSame(PrintPreferences::ENGLISH_FIRST, app(PrintPreferences::class)->languageOrder($this->organization));

        $this->asRequest(fn (): TestResponse => $this->actingAs($admin)
            ->withSession(['_token' => self::TOKEN])
            ->post('/portal/printing', ['_token' => self::TOKEN, 'paper' => 'a4', 'language_order' => 'english_only']))
            ->assertSessionHasErrors('language_order');
    }

    private function pdf(string $type): string
    {
        return app(InvoicePdf::class)->html($this->sampleInvoice($this->organization, $type, 2), $this->organization->fresh(), PaperSize::A4);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->organizations()->attach($this->organization->id, ['role' => 'admin', 'status' => 'active']);

        return $admin;
    }
}
