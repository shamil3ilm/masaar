<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Invoice\Enums\ScheduleFrequency;
use App\Domains\Invoice\Services\Schedules;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;

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

    private function frequency(): ScheduleFrequency
    {
        return app(Schedules::class)->defaultFrequency($this->acme->fresh(), null);
    }
}
