<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Schedules through the API.
 */
class ScheduleApiTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
    }

    public function test_a_schedule_is_made_and_read(): void
    {
        $id = $this->withToken($this->token())->postJson('/api/schedules', [
            'name' => 'Beta retainer',
            'frequency' => 'monthly',
            'start_date' => '2026-11-01',
            'occurrences' => 12,
            'due_days' => 30,
            'invoice' => $this->scheduledInvoice(),
        ])->assertCreated()
            ->assertJsonPath('data.schedule.status', 'active')
            ->assertJsonPath('data.schedule.next_run_on', '2026-11-01')
            ->assertJsonPath('data.upcoming.1.start_date', '2026-12-01')
            ->json('data.schedule.id');

        $this->withToken($this->token())->getJson('/api/schedules/'.$id)
            ->assertOk()
            ->assertJsonPath('data.schedule.name', 'Beta retainer')
            ->assertJsonPath('data.schedule.template.buyer_name', 'Beta Industries');

        $this->withToken($this->token())->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_its_invoice_meets_country_rules(): void
    {
        $this->withToken($this->token())->postJson('/api/schedules', [
            'name' => 'Broken',
            'frequency' => 'monthly',
            'start_date' => '2026-11-01',
            'invoice' => [...$this->scheduledInvoice(), 'lines' => []],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice.lines'], 'error.details');
    }

    public function test_a_termly_schedule_gives_its_terms(): void
    {
        $this->withToken($this->token())->postJson('/api/schedules', [
            'name' => 'Grade 5',
            'frequency' => 'termly',
            'invoice' => $this->scheduledInvoice(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['terms'], 'error.details');

        $this->withToken($this->token())->postJson('/api/schedules', [
            'name' => 'Grade 5',
            'frequency' => 'termly',
            'terms' => [['start_date' => '2026-11-01', 'end_date' => '2027-01-15'], ['start_date' => '2027-01-10', 'end_date' => '2027-03-30']],
            'invoice' => $this->scheduledInvoice(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['terms.1.start_date'], 'error.details');
    }

    public function test_a_viewer_makes_none(): void
    {
        $viewer = User::factory()->create();
        $viewer->organizations()->attach($this->acme->id, ['role' => 'viewer', 'status' => 'active']);

        $this->withToken(JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'viewer'])->fromUser($viewer))
            ->postJson('/api/schedules', ['name' => 'X', 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice()])
            ->assertForbidden();
    }

    public function test_another_organizations_is_not_found(): void
    {
        $schedule = $this->makeSchedule();
        $other = Organization::create(['name' => 'Other', 'country' => 'SA', 'vat_number' => '399999999700003']);
        $user = User::factory()->create();
        $user->organizations()->attach($other->id, ['role' => 'admin', 'status' => 'active']);

        $this->withToken(JWTAuth::claims(['org_id' => $other->id, 'role' => 'admin'])->fromUser($user))
            ->getJson('/api/schedules/'.$schedule->id)
            ->assertNotFound();
    }

    private function token(): string
    {
        return JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);
    }
}
