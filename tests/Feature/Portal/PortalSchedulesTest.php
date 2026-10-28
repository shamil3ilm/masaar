<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The portal's Schedules: repeating an invoice from its page, the list, a
 * schedule's page with its upcoming runs, and its lifecycle buttons.
 */
class PortalSchedulesTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    private const TOKEN = 'schedules-form-token';

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->makeOrganization();
        $this->invoice = app(InvoiceDrafter::class)->draft([
            ...$this->scheduledInvoice(), 'document_type' => 'invoice', 'issue_date' => '2026-10-01',
            'payment_terms' => 'صافي 30 يوماً / Net 30', 'invoice_number' => 'INV-7',
        ], (string) $this->acme->id, countryFields: ['due_date' => '2026-10-31']);
    }

    public function test_an_invoice_repeats_on_schedule(): void
    {
        $this->open(route('portal.invoices.show', $this->invoice->id))->assertOk()
            ->assertSee(route('portal.schedules.create', ['invoice' => $this->invoice->id]), false);
        $this->open(route('portal.schedules.create', ['invoice' => $this->invoice->id]))->assertOk()->assertSee('INV-7');

        $this->submit('/portal/schedules', [
            'source_invoice_id' => $this->invoice->id, 'name' => 'Beta retainer', 'frequency' => 'monthly', 'start_date' => '2026-11-01',
            'occurrences' => '', 'end_date' => '', 'auto_issue' => '0', 'instalment_model' => '',
            'terms' => [['start_date' => '', 'end_date' => '']],
        ])->assertRedirect();

        $schedule = InvoiceSchedule::acrossTenants()->sole();
        $this->assertSame([30, $this->invoice->id, 'صافي 30 يوماً / Net 30', 'Monthly retainer'], [
            $schedule->due_days, $schedule->source_invoice_id, $schedule->template['payment_terms'], $schedule->template['lines'][0]['description'],
        ]);

        $this->open(route('portal.schedules.index'))->assertOk()->assertSee('Beta retainer');
        $this->open(route('portal.schedules.show', $schedule->id))->assertOk()->assertSee('2026-12-01')->assertSee('1,150.00');
    }

    public function test_the_buttons_pause_and_cancel(): void
    {
        $schedule = $this->makeSchedule();

        $this->submit(route('portal.schedules.pause', $schedule->id))->assertRedirect(route('portal.schedules.show', $schedule->id));
        $this->assertSame(ScheduleStatus::Paused, $schedule->fresh()->status);

        $this->submit(route('portal.schedules.cancel', $schedule->id))->assertRedirect();
        $this->assertSame(ScheduleStatus::Cancelled, $schedule->fresh()->status);

        $this->submit(route('portal.schedules.resume', $schedule->id))->assertSessionHas('error');
    }

    public function test_the_api_repeats_an_invoice(): void
    {
        $token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->admin);

        $this->withToken($token)->postJson('/api/schedules', [
            'name' => 'Beta retainer', 'frequency' => 'quarterly', 'start_date' => '2026-11-01', 'source_invoice_id' => $this->invoice->id,
        ])->assertCreated()
            ->assertJsonPath('data.schedule.source_invoice_id', $this->invoice->id)
            ->assertJsonPath('data.upcoming.0.total', '1150.00')
            ->assertJsonPath('data.upcoming.1.start_date', '2027-02-01');
    }

    public function test_another_organizations_is_not_found(): void
    {
        $schedule = $this->makeSchedule();
        $stranger = User::factory()->create();
        $other = Organization::create(['name' => 'Other', 'country' => 'SA', 'vat_number' => '399999999700003']);
        $stranger->organizations()->attach($other->id, ['role' => 'admin', 'status' => 'active']);

        $this->asRequest(fn () => $this->actingAs($stranger)->withSession(['portal_org_id' => $other->id])
            ->get(route('portal.schedules.show', $schedule->id))->assertNotFound());
    }

    private function portal(): static
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id, '_token' => self::TOKEN]);
    }

    private function open(string $uri): TestResponse
    {
        return $this->asRequest(fn () => $this->portal()->get($uri));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submit(string $uri, array $data = []): TestResponse
    {
        return $this->asRequest(fn () => $this->portal()->post($uri, [...$data, '_token' => self::TOKEN]));
    }
}
