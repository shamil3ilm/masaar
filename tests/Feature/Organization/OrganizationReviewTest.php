<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Submitter;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Mail\OrganizationReviewedMail;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Support\Brand;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A business that signed itself up, until a platform admin decides on it.
 *
 * Pending, it drafts and nothing more: no certificate, no counter, nothing
 * sent to ZATCA. Approval assigns its plan - an active licence - and opens
 * all three; without a plan they stay shut.
 */
class OrganizationReviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $founder;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'status' => Organization::STATUS_PENDING_REVIEW,
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $this->founder = User::factory()->create(['email' => 'owner@acme.sa']);
        $this->founder->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
    }

    public function test_a_pending_company_cannot_issue(): void
    {
        $this->assertRefused(ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW);

        // And issuing asks: the draft keeps no counter and no number.
        $draft = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->acme->id,
            'type' => 'simplified',
            'document_type' => 'invoice',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        try {
            app(Submitter::class)->generate($draft, $this->acme);
            $this->fail('A company awaiting review issued an invoice.');
        } catch (ComplianceException $e) {
            $this->assertSame(ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW, $e->getErrorCode());
        }

        $this->assertNull($draft->fresh()->icv);
    }

    /**
     * Refused before the OTP is spent on a certificate it could not use.
     */
    public function test_a_pending_company_cannot_onboard(): void
    {
        Http::fake();
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->founder);

        $this->withToken($token)->postJson('/api/compliance/onboarding/ccsid', [
            'otp' => '123456',
            'common_name' => 'EGS1-Acme',
            'serial_number' => '1-'.Brand::csrSolution().'|2-1.0|3-abc',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW->value);

        Http::assertNothingSent();
    }

    public function test_approval_assigns_a_plan(): void
    {
        $tier = LicenseTier::cases()[0]->value;

        $this->actingAs($this->platformAdmin())
            ->post("/admin/organizations/{$this->acme->id}/approve", ['tier' => $tier])
            ->assertRedirect()
            ->assertSessionHas('api_secret');

        $acme = $this->acme->fresh();
        $licence = License::where('org_id', $this->acme->id)->sole();

        $this->assertSame(Organization::STATUS_ACTIVE, $acme->status);
        $this->assertNotNull($acme->reviewed_at);
        $this->assertSame($tier, $licence->tier->value ?? $licence->tier);

        config(['licensing.filing_requires_licence' => true]);
        app(FilingClearance::class)->assertCleared($acme);

        Mail::assertSent(OrganizationReviewedMail::class, fn (OrganizationReviewedMail $mail) => $mail->approved && $mail->hasTo('owner@acme.sa'));
    }

    /**
     * Approved with no plan - or a plan that ran out - files nothing.
     */
    public function test_without_a_plan_nothing_is_filed(): void
    {
        config(['licensing.filing_requires_licence' => true]);
        $this->acme->forceFill(['status' => Organization::STATUS_ACTIVE])->save();

        $this->assertRefused(ErrorCode::AUTH_LICENSE_REQUIRED);
    }

    /**
     * An invoice already issued is filed whether or not the plan has lapsed:
     * reporting it is the taxpayer's obligation.
     */
    public function test_a_lapsed_plan_still_files(): void
    {
        config(['licensing.filing_requires_licence' => true]);
        $this->acme->forceFill(['status' => Organization::STATUS_ACTIVE])->save();

        app(FilingClearance::class)->assertMayFile($this->acme->fresh());

        $this->assertRefused(ErrorCode::AUTH_LICENSE_REQUIRED);
    }

    /**
     * Another organization's VAT number is refused on every way in.
     */
    public function test_a_held_vat_is_refused_everywhere(): void
    {
        $other = Organization::create(['name' => 'Other', 'country' => 'SA']);
        $token = JWTAuth::claims(['org_id' => $other->id, 'role' => 'admin'])->fromUser(tap(User::factory()->create(), fn (User $u) => $u->organizations()->attach($other->id, ['role' => 'admin', 'status' => 'active'])));

        $this->withToken($token)->putJson("/api/organizations/{$other->id}", ['vat_number' => '300000000000003'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vat_number'], 'error.details');
    }

    /**
     * No organization to clear is a refusal, not a pass.
     */
    public function test_onboarding_needs_an_organization(): void
    {
        Http::fake();

        $this->withToken(JWTAuth::fromUser($this->founder))->postJson('/api/compliance/onboarding/ccsid', [
            'otp' => '123456',
            'common_name' => 'EGS1-Acme',
            'serial_number' => '1-'.Brand::csrSolution().'|2-1.0|3-abc',
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_turning_down_records_why(): void
    {
        $this->actingAs($this->platformAdmin())
            ->post("/admin/organizations/{$this->acme->id}/reject", ['reason' => 'The CR number does not match the VAT registration.'])
            ->assertRedirect(route('admin.organization.detail', $this->acme->id));

        $acme = $this->acme->fresh();

        $this->assertSame(Organization::STATUS_REJECTED, $acme->status);
        $this->assertSame('The CR number does not match the VAT registration.', $acme->review_note);
        $this->assertRefused(ErrorCode::AUTH_ORGANIZATION_UNDER_REVIEW);

        Mail::assertSent(OrganizationReviewedMail::class, fn (OrganizationReviewedMail $mail) => ! $mail->approved);
    }

    /**
     * A second click does not issue a second licence.
     */
    public function test_a_decision_is_made_once(): void
    {
        $admin = $this->platformAdmin();
        $tier = LicenseTier::cases()[0]->value;

        $this->actingAs($admin)->post("/admin/organizations/{$this->acme->id}/approve", ['tier' => $tier]);
        $this->actingAs($admin)->post("/admin/organizations/{$this->acme->id}/approve", ['tier' => $tier])
            ->assertSessionHas('error');

        $this->assertSame(1, License::where('org_id', $this->acme->id)->count());
    }

    public function test_only_a_platform_admin_decides(): void
    {
        $this->actingAs($this->founder)
            ->post("/admin/organizations/{$this->acme->id}/approve", ['tier' => LicenseTier::cases()[0]->value])
            ->assertForbidden();

        $this->assertTrue($this->acme->fresh()->isPendingReview());
    }

    public function test_the_list_shows_who_is_waiting(): void
    {
        Organization::create(['name' => 'Already Approved Co', 'country' => 'SA']);

        $this->actingAs($this->platformAdmin())
            ->get('/admin/organizations?status=pending_review')
            ->assertOk()
            ->assertSee('Acme Trading')
            ->assertDontSee('Already Approved Co');
    }

    private function assertRefused(ErrorCode $code): void
    {
        try {
            app(FilingClearance::class)->assertCleared($this->acme->fresh());
            $this->fail('The organization was cleared to file.');
        } catch (ComplianceException $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }
}
