<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sign-up in numbered steps, each saying what it needs and why.
 *
 * The form was one long page of fieldsets with a grey legend each, which
 * the owner called too vague: nothing said why a commercial registration was
 * wanted, where to find it, or how far through the form someone was. It is
 * now four steps under a stepper - your account, your company, the details
 * the country's authority needs, and a review - and without script it is
 * still the same one form that sends as it always did.
 */
class RegisterStepsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response('')]);
        config(['signup.enabled' => true, 'peppol.countries.AE.enabled' => true]);
    }

    public function test_the_form_has_four_steps(): void
    {
        $page = $this->get('/register')->assertOk()->getContent();

        $this->assertSame(4, preg_match_all('/data-step="\d"/', $page));
        $this->assertSame(4, preg_match_all('/data-step-indicator="\d"/', $page));
        $this->assertStringContainsString('aria-current="step"', $page);
        $this->assertStringContainsString('data-step-review', $page);
    }

    public function test_each_step_says_why(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('You sign in with this email, and we send a link to it to confirm it is yours.')
            ->assertSee('Printed on every invoice you issue, exactly as on your commercial registration.')
            ->assertSee('This decides which authority your invoices go to, and what the next step asks for.')
            ->assertSee('Check what you entered. You can go back to any step before you create the account.');
    }

    /**
     * Continue and Back are hidden until the script that drives them runs,
     * so a page without it shows every step and its one submit button.
     */
    public function test_without_script_it_is_one_form(): void
    {
        $page = $this->get('/register')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-step-next[^>]*\bhidden\b|\bhidden\b[^>]*data-step-next/', $page);
        $this->assertDoesNotMatchRegularExpression('/data-step="[1-3]"[^>]*\bhidden\b/', $page);
        $this->assertSame(1, substr_count($page, 'type="submit"') - substr_count($page, 'action="'.route('locale').'"'));
    }

    /**
     * A form sent back says which fields were wrong, so the step that has
     * them can be opened on its own.
     */
    public function test_errors_name_their_fields(): void
    {
        $this->from('/register')->post('/register', ['name' => 'Sara', 'country' => 'SA'])->assertRedirect('/register');

        $page = $this->get('/register')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-step-errors="[^"]*email/', $page);
        $this->assertStringContainsString('data-field-error', $page);
    }

    /**
     * The password never comes back, so after any refusal the first step
     * is marked to be filled again and says why, rather than shown as done.
     */
    public function test_the_password_is_asked_again(): void
    {
        $this->from('/register')->post('/register', ['name' => 'Sara', 'email' => 'sara@example.test', 'password' => 'Secret!2026a', 'password_confirmation' => 'Secret!2026a', 'country' => 'SA', 'vat_number' => '310123456700000'])
            ->assertRedirect('/register');

        $page = $this->get('/register')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-step-errors="[^"]*password/', $page);
        $this->assertStringContainsString(__('For your safety the password is not kept. Enter it again.'), $page);
    }

    /**
     * The summary of errors names the field each is for, so the page can
     * take a message away once its field is corrected: the "email has
     * already been taken" alert stayed on top after the email was changed.
     */
    public function test_summary_names_each_errors_field(): void
    {
        $this->from('/register')->post('/register', ['name' => 'Sara', 'country' => 'SA'])->assertRedirect('/register');

        $page = $this->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString('data-error-summary', $page);
        $this->assertMatchesRegularExpression('/<li data-error-for="email">/', $page);
    }

    /**
     * An optional field's error is drawn and named like a required one's,
     * so the page clears it the same way once it is corrected.
     */
    public function test_optional_field_error_is_named(): void
    {
        $this->from('/register')->post('/register', ['name' => 'Sara', 'country' => 'SA', 'cr_number' => '12'])->assertRedirect('/register');

        $page = $this->get('/register')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<li data-error-for="cr_number">/', $page);
        $this->assertMatchesRegularExpression('/name="cr_number".*?data-field-error/s', $page);
    }

    /**
     * A searchable list counts its results in each language's own forms:
     * it said "1 results", and Arabic has forms for one and two.
     */
    public function test_result_count_has_arabic_forms(): void
    {
        $english = (string) $this->get('/register')->assertOk()->getContent();
        $arabic = (string) $this->withSession(['locale' => 'ar'])->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString('"{1} :count result|[0,*] :count results":"{1} :count result|[0,*] :count results"', $this->stringsOf($english));
        $this->assertStringContainsString('نتيجتان', $this->stringsOf($arabic));
    }

    /**
     * With a country to choose, each country draws its own business-type
     * list in a fieldset the script disables and hides for the other
     * countries. A control in a disabled fieldset is :disabled while its own
     * disabled property stays false, so the review listed both (H-64). The
     * review reads only what would be sent: :disabled, hidden or a name
     * already listed is skipped, in every variant of the form.
     */
    public function test_review_lists_each_field_once(): void
    {
        $page = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertSame(2, substr_count($page, 'name="business_type"'));
        $this->assertSame(2, preg_match_all('/<fieldset[^>]*:disabled="country !== \'(SA|AE)\'"(?:(?!<\/fieldset>).)*name="business_type"/s', $page));

        $script = (string) file_get_contents(resource_path('js/ui.js'));
        $review = substr($script, (int) strpos($script, 'function review(form)'));

        $this->assertStringContainsString("control.matches(':disabled')", $review);
        $this->assertStringContainsString('hiddenWithin(part, control)', $review);
        $this->assertStringContainsString('seen.has(control.name)', $review);
        $this->assertStringNotContainsString('control.disabled ||', $review);
    }

    /**
     * The TIN is the TRN's first ten digits (UaeTin), so the form fills it
     * as the TRN is typed, as its hint says (H-69).
     */
    public function test_tin_fills_from_the_trn(): void
    {
        $page = (string) $this->get('/register?country=AE')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="tin"[^>]*data-fill-from="trn"[^>]*data-fill-when="\^1\[0-9\]\{12\}03\$"[^>]*data-fill-length="10"/s', $page);
        $this->assertStringContainsString('registerFills();', (string) file_get_contents(resource_path('js/ui.js')));
    }

    /** The scripts' strings the page carries (x-ui.strings). */
    private function stringsOf(string $page): string
    {
        preg_match('/data-ui-strings="([^"]*)"/', $page, $match);

        return html_entity_decode($match[1] ?? '');
    }

    /** Checked on its own step, as its hint describes it. */
    public function test_formats_are_checked_early(): void
    {
        $page = $this->get('/register?country=SA')->assertOk()->getContent();

        $this->assertStringContainsString('pattern="3[0-9]{13}3"', $page);
        $this->assertStringContainsString('pattern="[0-9]{4}"', $page);
        $this->assertStringContainsString('data-pattern-message="'.e(__('15 digits, starting and ending with 3, as on your VAT registration certificate.')).'"', $page);
    }
}
