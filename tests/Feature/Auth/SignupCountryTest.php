<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Sign-up asks where the company is registered, when there is a choice.
 *
 * A UAE business could not sign itself up at all: the form asked for a Saudi
 * VAT number and national address, and the company it made was Saudi.
 */
class SignupCountryTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();

        // The breached-password check asks a range API; nothing here is in it.
        Http::fake(['*' => Http::response('')]);
        Notification::fake();
        config(['peppol.countries.AE.enabled' => true]);
    }

    /**
     * Its tax identity and legal registration land where the company page
     * keeps them. No Saudi building number or postal code is asked for, and
     * no Peppol endpoint: issuing still waits for that one.
     */
    public function test_uae_signup_saves_seller_details(): void
    {
        $this->post('/register', $this->uaeForm())->assertRedirect(route('verification.notice'));

        $organization = User::where('email', 'owner@gulf.ae')->sole()->organizations()->sole();
        $seller = app(UaeSellerDetails::class);
        $details = $seller->current($organization);

        $this->assertSame('AE', $organization->country);
        $this->assertSame('Gulf Trading', $organization->name);
        $this->assertSame(Organization::STATUS_PENDING_REVIEW, $organization->status);
        $this->assertSame('Sheikh Zayed Road', $organization->street);
        $this->assertNull($organization->building_number);
        $this->assertNull($organization->postal_code);
        $this->assertSame('100123456700003', $organization->compliance_profile['vat_number']);
        $this->assertSame('100123456700003', $details['trn']);
        $this->assertSame('1001234567', $details['tin']);
        $this->assertSame('DXB', $details['emirate']);
        $this->assertSame('TL', $details['legal_registration_type']);
        $this->assertSame('CN-1234567', $details['legal_registration_id']);
        $this->assertSame('Dubai Economy', $details['legal_registration_authority']);
        $this->assertNull($details['endpoint_id']);
        $this->assertFalse($seller->complete($organization));
    }

    /** A seller not registered for VAT gives its TIN instead. */
    public function test_uae_seller_without_trn_signs_up(): void
    {
        $this->post('/register', $this->uaeForm(['trn' => null, 'tin' => '1001234567']))
            ->assertRedirect(route('verification.notice'));

        $organization = Organization::where('country', 'AE')->sole();

        $this->assertNull(app(UaeSellerDetails::class)->current($organization)['trn']);
        $this->assertSame('1001234567', app(UaeSellerDetails::class)->current($organization)['tin']);
    }

    public function test_bad_trn_or_tin_is_refused(): void
    {
        $this->post('/register', $this->uaeForm(['trn' => '300000000000003']))->assertSessionHasErrors('trn');
        $this->post('/register', $this->uaeForm(['trn' => null, 'tin' => '2001234567']))->assertSessionHasErrors('tin');
        $this->post('/register', $this->uaeForm(['trn' => null]))->assertSessionHasErrors('tin');
        $this->post('/register', $this->uaeForm(['tin' => '1009999999']))->assertSessionHasErrors('tin');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /** A deployment that does not register UAE companies refuses one. */
    public function test_uae_refused_when_not_offered(): void
    {
        config(['peppol.countries.AE.enabled' => false]);

        $this->post('/register', $this->uaeForm())->assertSessionHasErrors('country');

        $this->assertGuest();
        $this->assertSame(0, Organization::count());
    }

    public function test_saudi_chosen_signs_up_saudi(): void
    {
        $this->post('/register', $this->saudiForm(['country' => 'SA']))->assertRedirect(route('verification.notice'));

        $organization = Organization::sole();

        $this->assertSame('SA', $organization->country);
        $this->assertTrue($organization->hasCompleteZatcaProfile());
        $this->assertSame(0, $organization->complianceProfiles()->count());
    }

    /**
     * The console's switches decide what sign-up offers: Saudi Arabia off
     * leaves the UAE alone in the chooser, and a Saudi company is refused.
     */
    public function test_signup_follows_the_admin_switches(): void
    {
        app(JurisdictionAvailability::class)->set('SA', false);

        $this->get('/register')
            ->assertOk()
            ->assertSee('name="country" value="AE"', false)
            ->assertDontSee('name="country" value="SA"', false);

        $this->post('/register', $this->saudiForm(['country' => 'SA']))->assertSessionHasErrors('country');
        $this->assertSame(0, Organization::count());
    }

    /** With only Saudi Arabia there is nothing to choose, and the page is as it was. */
    public function test_no_choice_with_one_country(): void
    {
        config(['peppol.countries.AE.enabled' => false]);

        $this->get('/register')
            ->assertOk()
            ->assertDontSee('name="country"', false)
            ->assertDontSee('signupCountry', false)
            ->assertDontSee('name="trn"', false)
            ->assertSee('Your company, as registered with ZATCA')
            ->assertSee('name="vat_number"', false)
            ->assertSee('name="postal_code"', false);
    }

    /**
     * Both sections are on the page; the one not chosen is hidden and
     * disabled, so its fields are not sent. No Peppol endpoint is asked.
     */
    public function test_choice_offered_with_two_countries(): void
    {
        $page = $this->get('/register')
            ->assertOk()
            ->assertSee('x-data="signupCountry"', false)
            ->assertSee('Saudi Arabia')
            ->assertSee('United Arab Emirates')
            ->assertSee('name="vat_number"', false)
            ->assertSee('name="trn"', false)
            ->assertSee('name="emirate"', false)
            ->assertSee('name="legal_registration_type"', false)
            ->assertDontSee('name="endpoint_id"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/data-country-section="AE"[^>]*\bhidden disabled\b/', $page);
        $this->assertDoesNotMatchRegularExpression('/data-country-section="SA"[^>]*\bhidden disabled\b/', $page);
    }

    /**
     * The business type is asked with the company, from the types each
     * country serves: a Saudi pharmacy keeps it, and the UAE, whose
     * e-invoicing leaves consumer sales out, does not offer pure retail
     * (UAE Electronic Invoicing Guidelines v1.1, section 6.2).
     */
    public function test_signup_keeps_the_business_type(): void
    {
        $page = $this->get('/register')->assertOk()->getContent();
        $this->assertSame(2, substr_count($page, 'name="business_type"'));
        $this->assertSame(1, substr_count($page, '<option value="retail"'));

        $this->post('/register', $this->saudiForm(['country' => 'SA', 'business_type' => 'pharmacy']))->assertRedirect(route('verification.notice'));
        $this->assertSame('pharmacy', Organization::sole()->business_type);

        auth()->logout();
        $this->post('/register', $this->uaeForm(['business_type' => 'retail']))->assertSessionHasErrors('business_type');
    }

    /** A form sent back with errors opens on the country that was chosen. */
    public function test_errors_reopen_the_chosen_country(): void
    {
        $page = $this->withSession(['_old_input' => ['country' => 'AE']])->get('/register')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-country-section="SA"[^>]*\bhidden disabled\b/', $page);
        $this->assertDoesNotMatchRegularExpression('/data-country-section="AE"[^>]*\bhidden disabled\b/', $page);
        $this->assertMatchesRegularExpression('/value="AE"[^>]*checked/', $page);
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    private function uaeForm(array $overrides = []): array
    {
        return $this->form(array_merge([
            'email' => 'owner@gulf.ae',
            'country' => 'AE',
            'company_name' => 'Gulf Trading',
            'trn' => '100123456700003',
            'emirate' => 'DXB',
            'street' => 'Sheikh Zayed Road',
            'city' => 'Dubai',
            'legal_registration_type' => 'TL',
            'legal_registration_id' => 'CN-1234567',
            'legal_registration_authority' => 'Dubai Economy',
        ], $overrides));
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    private function saudiForm(array $overrides = []): array
    {
        return $this->form(array_merge([
            'email' => 'owner@acme.sa',
            'company_name' => 'Acme Trading',
            'company_name_ar' => 'شركة أكمي للتجارة',
            'vat_number' => '300000000000003',
            'cr_number' => '1010101010',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ], $overrides));
    }

    /**
     * @param  array<string, string|null>  $company
     * @return array<string, string>
     */
    private function form(array $company): array
    {
        return array_filter(array_merge([
            'name' => 'Sara Al-Qahtani',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'terms' => '1',
        ], $company), fn (?string $value): bool => $value !== null);
    }
}
