<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A7: the sales VAT report's default period is this month on the
 * organization's own clock, as Books reads it, not the server's.
 */
class ReportPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** 22:30 UTC on 31 October is 01:30 on 1 November in Riyadh. */
    public function test_default_period_uses_country_clock(): void
    {
        $acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $user = User::factory()->create();
        $user->organizations()->attach($acme->id, ['role' => 'viewer', 'status' => 'active']);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'UTC'));

        $response = $this->actingAs($user)->withSession(['portal_org_id' => $acme->id])->get(route('portal.reports'))->assertOk();

        $this->assertSame('2026-11-01', $response->viewData('from')->toDateString());
        $this->assertSame('2026-11-30', $response->viewData('to')->toDateString());
        $this->assertSame('2026-11-01', $response->viewData('presets')[__('This month')]['from']);
        $this->assertSame('2026-10-01', $response->viewData('presets')[__('Last month')]['from']);
    }
}
