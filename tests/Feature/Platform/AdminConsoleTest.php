<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What each page of the /admin console hands its view.
 *
 * AdminConsoleAccessTest covers who may open these pages; this pins the rows,
 * joins, filters and counts they show across every tenant.
 */
class AdminConsoleTest extends TestCase
{
    use PlatformRows;
    use RefreshDatabase;

    public function test_organizations_list_success_counts(): void
    {
        $acme = $this->organization('Acme');
        $acme->forceFill(['created_at' => '2026-01-01 10:00:00'])->save();
        $globex = $this->organization('Globex');
        $globex->forceFill(['created_at' => '2026-01-02 10:00:00'])->save();

        $this->submission($acme, 'cleared');
        $this->submission($acme, 'reported');
        $this->submission($acme, 'rejected');

        $this->admin()
            ->get('/admin/organizations')
            ->assertOk()
            ->assertViewHas('organizations', function (LengthAwarePaginator $page) use ($globex) {
                $first = $page->first();

                return $page->perPage() === 20
                    && $page->total() === 2
                    && $first->id === $globex->id
                    && array_keys((array) $first) === ['id', 'name', 'vat_number', 'status', 'created_at', 'cert_expires_at']
                    && $first->cert_expires_at === null;
            })
            ->assertViewHas('submissionStats', fn ($stats) => $stats->map(fn ($n) => (int) $n)->all() === [$acme->id => 2]);
    }

    public function test_organization_detail_shows_stats(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $invoice = $this->invoice($acme);
        $this->invoice($acme);
        $this->submission($acme, 'cleared', ['invoice_id' => $invoice->id]);
        $this->submission($acme, 'rejected', ['invoice_id' => $invoice->id]);
        $this->submission($acme, 'rejected', ['invoice_id' => $invoice->id]);
        $this->submission($globex, 'cleared');

        $this->admin()
            ->get("/admin/organizations/{$acme->id}")
            ->assertOk()
            ->assertViewHas('organization', fn ($org) => $org->id === $acme->id && $org->name === 'Acme')
            ->assertViewHas('stats', ['invoices' => 2, 'submissions' => 3, 'cleared' => 1, 'rejected' => 2])
            ->assertViewHas('recentSubmissions', fn ($rows) => $rows->count() === 3
                && $rows->every(fn ($row) => $row->org_id === $acme->id))
            ->assertViewHas('certificate', null);
    }

    public function test_unknown_organization_not_found(): void
    {
        $this->admin()
            ->get('/admin/organizations/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_queue_filters_and_counts(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $mine = $this->queueItem($acme, 'failed');
        $this->queueItem($acme, 'pending');
        $this->queueItem($globex, 'failed');

        $this->admin()
            ->get("/admin/queue?state=failed&org_id={$acme->id}")
            ->assertOk()
            ->assertViewHas('items', fn (LengthAwarePaginator $page) => $page->perPage() === 50
                && $page->total() === 1
                && $page->first()->id === $mine->id
                && $page->first()->organization_name === 'Acme')
            ->assertViewHas('stats', fn ($stats) => $stats->map(fn ($n) => (int) $n)->all() === ['failed' => 2, 'pending' => 1])
            ->assertViewHas('state', 'failed')
            ->assertViewHas('orgId', $acme->id);

        $this->admin()
            ->get('/admin/queue')
            ->assertOk()
            ->assertViewHas('items', fn (LengthAwarePaginator $page) => $page->total() === 3);
    }

    public function test_logs_filters_and_counts(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $mine = $this->submission($acme, 'rejected');
        $this->submission($acme, 'cleared');
        $this->submission($globex, 'rejected');

        $this->admin()
            ->get("/admin/logs?state=rejected&org_id={$acme->id}")
            ->assertOk()
            ->assertViewHas('logs', fn (LengthAwarePaginator $page) => $page->perPage() === 50
                && $page->total() === 1
                && $page->first()->id === $mine->id
                && $page->first()->organization_name === 'Acme')
            ->assertViewHas('stateCounts', fn ($counts) => $counts->map(fn ($n) => (int) $n)->all() === ['cleared' => 1, 'rejected' => 2])
            ->assertViewHas('state', 'rejected')
            ->assertViewHas('orgId', $acme->id);
    }

    public function test_retry_requeues_the_item(): void
    {
        $item = $this->queueItem($this->organization('Acme'), 'failed', ['attempts' => 3, 'last_error' => 'boom']);

        $this->admin()
            ->from('/admin/queue')
            ->post("/admin/queue/{$item->id}/retry")
            ->assertRedirect('/admin/queue')
            ->assertSessionHas('success', 'Item queued for retry');

        $row = DB::table('offline_queue')->where('id', $item->id)->first();

        $this->assertSame('pending', $row->state);
        $this->assertEquals(0, $row->attempts);
        $this->assertNull($row->last_error);
    }

    public function test_retry_unknown_item_errors(): void
    {
        $this->admin()
            ->from('/admin/queue')
            ->post('/admin/queue/missing/retry')
            ->assertRedirect('/admin/queue')
            ->assertSessionHas('error', 'Queue item not found');
    }

    /**
     * Only a failed item goes round again. Resetting one that completed would
     * resend an invoice the authority has already accepted.
     */
    public function test_retry_refuses_unfailed_item(): void
    {
        $item = $this->queueItem($this->organization('Acme'), 'completed', ['attempts' => 1]);

        $this->admin()
            ->from('/admin/queue')
            ->post("/admin/queue/{$item->id}/retry")
            ->assertRedirect('/admin/queue')
            ->assertSessionHas('error', 'Only failed items can be retried');

        $this->assertSame('completed', DB::table('offline_queue')->where('id', $item->id)->value('state'));
    }

    private function admin(): self
    {
        return $this->actingAs(User::factory()->platformAdmin()->create());
    }
}
