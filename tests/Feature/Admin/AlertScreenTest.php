<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A missed reporting deadline is visible without reading a log file.
 *
 * `fatoora:overdue-submissions` already finds these rows hourly and raises a
 * critical event, which reaches the alerts channel and Slack and nowhere a
 * person can go and look. When the page arrives, the two have to agree: a
 * screen that shows fewer rows than the alert counted is worse than no screen,
 * because it is the one someone will trust at three in the morning.
 *
 * So these hold the screen to the same four conditions the command uses -
 * reporting only, not yet accepted, past the deadline - and to keeping the two
 * populations apart, since only the at-risk one can still be saved.
 */
class AlertScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
    }

    public function test_overdue_submissions_are_listed(): void
    {
        $this->submission('INV-OVERDUE', ['state' => 'failed'], hoursAgo: 30);

        $response = $this->actingAs($this->admin())->get('/admin/alerts');

        $response->assertOk()->assertSee('INV-OVERDUE');
        $this->assertSame(['INV-OVERDUE'], $response->viewData('overdue')->pluck('invoice_number')->all());
    }

    /**
     * A document the authority has acknowledged is finished, however old it
     * is. Listing it would put a permanent row on the page and teach people
     * that the page is wrong.
     */
    public function test_a_settled_document_is_absent(): void
    {
        $this->submission('INV-REPORTED', ['state' => 'reported'], hoursAgo: 72);

        $response = $this->actingAs($this->admin())->get('/admin/alerts');

        $response->assertOk()
            ->assertDontSee('INV-REPORTED')
            ->assertSee('Nothing is overdue');
    }

    /**
     * A standard invoice is cleared before it is issued, so an unsubmitted one
     * has reached nobody and no clock is running on it.
     */
    public function test_clearance_has_no_deadline(): void
    {
        $this->submission('INV-CLEARANCE', ['state' => 'failed', 'submission_type' => 'clearance'], hoursAgo: 72);

        $response = $this->actingAs($this->admin())->get('/admin/alerts');

        $response->assertOk()
            ->assertDontSee('INV-CLEARANCE')
            ->assertSee('Nothing is overdue');
    }

    /**
     * An empty page has to say it is empty. Rendering two blank tables looks
     * the same as a page that failed to load its data.
     */
    public function test_a_quiet_platform_says_so(): void
    {
        $this->submission('INV-FRESH', ['state' => 'failed'], hoursAgo: 1);

        $response = $this->actingAs($this->admin())->get('/admin/alerts');

        $response->assertOk()
            ->assertSee('Nothing is overdue')
            ->assertDontSee('INV-FRESH');
        $this->assertSame([], $response->viewData('overdue')->all());
        $this->assertSame([], $response->viewData('atRisk')->all());
    }

    /**
     * Past three quarters of the deadline but not past it: the obligation can
     * still be met, which is the only case where seeing it early changes the
     * outcome. Mixed into the overdue table it would read as a breach that has
     * already happened.
     */
    public function test_at_risk_is_separate(): void
    {
        $this->submission('INV-AT-RISK', ['state' => 'queued'], hoursAgo: 20);

        $response = $this->actingAs($this->admin())->get('/admin/alerts');

        $response->assertOk()->assertSee('INV-AT-RISK');
        $this->assertSame([], $response->viewData('overdue')->all());
        $this->assertSame(['INV-AT-RISK'], $response->viewData('atRisk')->pluck('invoice_number')->all());
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function submission(string $number, array $attributes, int $hoursAgo): InvoiceSubmission
    {
        $invoice = Invoice::create([
            'org_id' => $this->org->id,
            'invoice_number' => $number,
            'type' => 'simplified',
            'status' => 'issued',
            'issue_date' => Carbon::now()->subHours($hoursAgo)->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Walk-in',
        ]);

        $submission = InvoiceSubmission::create(array_merge([
            'invoice_id' => $invoice->id,
            'org_id' => $this->org->id,
            'submission_type' => 'reporting',
            'retry_count' => 0,
        ], $attributes));

        // created_at is what the age is measured from, and the model sets it.
        $submission->forceFill(['created_at' => Carbon::now()->subHours($hoursAgo)])->saveQuietly();

        return $submission->refresh();
    }
}
