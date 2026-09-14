<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * The figures and lists each portal page hands its view.
 *
 * PortalDataScopeTest proves the pages stay inside one tenant; this pins what
 * they show inside it — state counts, filters, per-user activity and paging —
 * so moving the queries elsewhere cannot quietly change a number.
 */
class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $rival;

    private User $member;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $this->member = User::factory()->create(['name' => 'Member Person']);
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    /**
     * Pending counts every state short of a verdict. It once looked for a
     * "pending" state the column does not allow, and so missed every
     * submission waiting in pending_submission.
     */
    public function test_dashboard_counts_submissions_by_state(): void
    {
        foreach (['cleared', 'cleared', 'reported', 'rejected', 'pending_submission', 'queued', 'submitted', 'failed'] as $state) {
            $this->submission($this->acme, $state);
        }

        $this->submission($this->rival, 'cleared');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal')
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => $stats['cleared'] === 2
                && $stats['reported'] === 1
                && $stats['rejected'] === 1
                && $stats['pending'] === 3
                && $stats['invoices_today'] === 8
                && $stats['invoices_month'] === 8)
            ->assertViewHas('certificate', null));
    }

    public function test_dashboard_shows_activity_and_recent(): void
    {
        $this->submission($this->acme, 'cleared', $this->member);
        $this->submission($this->acme, 'rejected', $this->member);
        $this->submission($this->acme, 'cleared');
        $this->submission($this->acme, 'cleared', at: now()->subDays(8));
        $this->submission($this->rival, 'cleared', $this->member);

        for ($i = 0; $i < 9; $i++) {
            $this->submission($this->acme, 'reported');
        }

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal')
            ->assertOk()
            ->assertViewHas('userActivity', function ($activity) {
                $byName = $activity->pluck('submission_count', 'user_name')->map(fn ($n) => (int) $n)->all();

                return $activity->count() === 2
                    && $byName === ['System' => 10, 'Member Person' => 2]
                    && $activity->firstWhere('user_name', 'Member Person')->user_id === $this->member->id;
            })
            ->assertViewHas('recentSubmissions', fn ($recent) => $recent->count() === 10
                && $recent->every(fn ($s) => $s->org_id === $this->acme->id)));
    }

    public function test_submissions_filter_by_state_and_user(): void
    {
        $mine = $this->submission($this->acme, 'rejected', $this->member, 'ACME-MINE');
        $this->submission($this->acme, 'rejected');
        $this->submission($this->acme, 'cleared', $this->member);
        $this->submission($this->rival, 'rejected', $this->member);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/submissions?state=rejected&user_id='.$this->member->id)
            ->assertOk()
            ->assertViewHas('submissions', function (LengthAwarePaginator $page) use ($mine) {
                $row = $page->first();

                return $page->total() === 1
                    && $page->perPage() === 25
                    && $row->id === $mine->id
                    && $row->invoice_number === 'ACME-MINE'
                    && (float) $row->invoice_total === 115.0
                    && $row->user_name === 'Member Person'
                    && $row->user_email === $this->member->email;
            })
            ->assertViewHas('stateCounts', fn ($counts) => $counts->map(fn ($n) => (int) $n)->all() === ['cleared' => 1, 'rejected' => 2])
            ->assertViewHas('userId', $this->member->id)
            ->assertViewHas('state', 'rejected'));
    }

    public function test_submissions_filter_by_date(): void
    {
        $this->submission($this->acme, 'cleared', at: '2026-01-01 10:00:00');
        $inside = $this->submission($this->acme, 'cleared', at: '2026-02-10 23:30:00');
        $this->submission($this->acme, 'cleared', at: '2026-02-11 00:00:01');

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/submissions?date_from=2026-02-01&date_to=2026-02-10')
            ->assertOk()
            ->assertViewHas('submissions', fn (LengthAwarePaginator $page) => $page->total() === 1
                && $page->first()->id === $inside->id)
            ->assertViewHas('dateFrom', '2026-02-01')
            ->assertViewHas('dateTo', '2026-02-10'));
    }

    public function test_submissions_page_by_twenty_five(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->submission($this->acme, 'cleared');
        }

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get('/portal/submissions?page=2')
            ->assertOk()
            ->assertViewHas('submissions', fn (LengthAwarePaginator $page) => $page->total() === 27
                && $page->count() === 2
                && $page->currentPage() === 2));
    }

    public function test_user_activity_shows_member_stats(): void
    {
        $this->submission($this->acme, 'cleared', $this->member, 'ACME-A');
        $this->submission($this->acme, 'rejected', $this->member);
        $this->submission($this->acme, 'cleared', $this->member, at: now()->subDays(2));
        $this->submission($this->acme, 'cleared');
        $this->submission($this->rival, 'cleared', $this->member);

        $this->asRequest(fn () => $this->actingAs($this->member)
            ->get("/portal/users/{$this->member->id}/activity")
            ->assertOk()
            ->assertViewHas('user', fn ($user) => $user->id === $this->member->id
                && array_keys($user->getAttributes()) === ['id', 'name', 'email'])
            ->assertViewHas('userStats', ['total' => 3, 'cleared' => 2, 'rejected' => 1, 'today' => 2])
            ->assertViewHas('submissions', fn (LengthAwarePaginator $page) => $page->total() === 3
                && $page->perPage() === 25
                && $page->pluck('invoice_number')->contains('ACME-A')));
    }

    /**
     * Users are not tenant-scoped, so a stranger's id must answer 404 rather
     * than render their name and submission counts.
     */
    public function test_user_activity_hides_non_members(): void
    {
        $outsider = User::factory()->create();
        $outsider->organizations()->attach($this->rival->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($this->member)
            ->get("/portal/users/{$outsider->id}/activity")
            ->assertNotFound();
    }

    public function test_picker_shown_without_selection(): void
    {
        $this->member->organizations()->attach($this->rival->id, ['role' => 'member', 'status' => 'active']);

        foreach (['/portal', '/portal/submissions', '/portal/certificates'] as $uri) {
            $this->actingAs($this->member)
                ->get($uri)
                ->assertOk()
                ->assertViewIs('portal.select-org')
                ->assertViewHas('organizations', fn ($orgs) => $orgs->pluck('name')->all() === ['Acme', 'Rival'])
                ->assertViewHas('error', null);
        }
    }

    public function test_certificates_empty_before_onboarding(): void
    {
        $this->actingAs($this->member)
            ->get('/portal/certificates')
            ->assertOk()
            ->assertViewHas('activeCert', null)
            ->assertViewHas('organization', fn ($org) => $org->id === $this->acme->id);
    }

    /**
     * A submission as the tracker writes it: author and time are set on
     * creation, because the model refuses edits to one in a terminal state.
     */
    private function submission(
        Organization $organization,
        string $state,
        ?User $by = null,
        ?string $number = null,
        mixed $at = null,
    ): InvoiceSubmission {
        $this->sequence++;

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => $number ?? 'INV-'.$this->sequence,
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        return InvoiceSubmission::withoutTenantScope(function () use ($invoice, $organization, $state, $by, $at) {
            $submission = (new InvoiceSubmission)->forceFill(array_filter([
                'invoice_id' => $invoice->id,
                'org_id' => $organization->id,
                'state' => $state,
                'submission_type' => 'clearance',
                'created_by' => $by?->id,
                'created_at' => $at,
            ], fn ($value) => $value !== null));

            $submission->save();

            return $submission;
        });
    }
}
