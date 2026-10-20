<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Licensing\Models\License;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * How often a new schedule bills, as its organization's business type
 * suggests: a school by term, a service by month. A setting like any other:
 * the organization's own choice, and a person's, come first.
 */
class SchedulePresetTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
    }

    public function test_a_school_bills_by_term(): void
    {
        $this->acme->forceFill(['business_type' => 'school'])->save();

        $this->assertSame(ScheduleFrequency::Termly, $this->frequency());
        $this->asRequest(fn () => $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.schedules.create'))
            ->assertOk()
            ->assertSee('<option value="termly" selected', false));
    }

    public function test_a_service_bills_by_month(): void
    {
        $this->acme->forceFill(['business_type' => 'services'])->save();

        $this->assertSame(ScheduleFrequency::Monthly, $this->frequency());
    }

    public function test_without_a_type_it_is_monthly(): void
    {
        $this->assertSame(ScheduleFrequency::Monthly, $this->frequency());
    }

    public function test_own_choices_come_first(): void
    {
        $this->acme->forceFill(['business_type' => 'school'])->save();
        app(Customizations::class)->set('schedules.frequency', Level::Organization, Scope::organization($this->acme->id), 'quarterly', $this->admin->id);

        $this->assertSame(ScheduleFrequency::Quarterly, $this->frequency());

        app(Customizations::class)->set('schedules.frequency', Level::User, Scope::member($this->acme->id, $this->admin->id), 'yearly', $this->admin->id);

        $this->assertSame(ScheduleFrequency::Yearly, app(Schedules::class)->defaultFrequency($this->acme->fresh(), $this->admin->id));
    }

    /** Over the API a schedule may leave its frequency out: the preset decides, as on the form. */
    public function test_the_api_falls_back_to_the_preset(): void
    {
        $this->acme->forceFill(['business_type' => 'school'])->save();
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);

        // Termly: it needs its terms.
        $this->withToken($token)->postJson('/api/schedules', ['name' => 'Grade 5', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice()])
            ->assertUnprocessable()->assertJsonValidationErrors(['terms'], 'error.details');

        $this->withToken($token)->postJson('/api/schedules', [
            'name' => 'Grade 5', 'terms' => [['start_date' => '2026-11-01', 'end_date' => '2027-01-31']], 'invoice' => $this->scheduledInvoice(),
        ])->assertCreated()->assertJsonPath('data.schedule.frequency', 'termly');

        $this->acme->forceFill(['business_type' => 'services'])->save();
        $this->withToken($token)->postJson('/api/schedules', ['name' => 'Retainer', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice()])
            ->assertCreated()->assertJsonPath('data.schedule.frequency', 'monthly');
    }

    public function test_a_partner_falls_back_too(): void
    {
        $this->acme->forceFill(['business_type' => 'school'])->save();
        $issued = License::createWithCredentials(['org_id' => $this->acme->id, 'organization_name' => 'Acme', 'contact_email' => 'erp@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode(['schedule.manage'])]);

        $this->withHeaders(['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']])->postJson('/api/v1/schedules', [
            'name' => 'Grade 5', 'terms' => [['start_date' => '2026-11-01', 'end_date' => '2027-01-31']], 'invoice' => $this->scheduledInvoice(),
        ])->assertCreated()->assertJsonPath('data.schedule.frequency', 'termly');
    }

    private function frequency(): ScheduleFrequency
    {
        return app(Schedules::class)->defaultFrequency($this->acme->fresh(), null);
    }
}
