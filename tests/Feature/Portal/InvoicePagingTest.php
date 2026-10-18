<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Invoice\Services\InvoiceFinder;
use App\Domains\Invoice\Services\InvoiceHistory;
use App\Domains\Organization\Models\Organization;
use App\Http\Listing\ListState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\MakesInvoices;
use Tests\TestCase;

/**
 * Paging through a list shows every row once.
 *
 * Every paginated query in this application ordered by a timestamp alone,
 * which is not a total order. `created_at` is a MySQL `timestamp` - one
 * second of precision - so any batch of invoices issued through the API in
 * the same second ties, and a tie leaves the order to the planner, which is
 * free to answer differently for page one and page two. The result is a row
 * that appears on both pages and another that appears on neither, which is
 * the worse half: an invoice can simply be unreachable through the list.
 *
 * Not hypothetical. On the development database at the time this was written
 * all seven invoices shared one created_at, and two of the three
 * organizations did too.
 *
 * The fix is a tiebreaker on the primary key. Laravel's HasUuids generates
 * ordered UUIDs - time-prefixed - so ordering by id after created_at is both
 * a total order and still chronological, rather than an arbitrary one chosen
 * to settle ties.
 *
 * The assertions here are on the ORDER BY the finder issues, not on the rows
 * that come back, and that is deliberate. The suite runs on SQLite, which
 * answers a simple scan in rowid order and therefore resolves tied
 * timestamps consistently by accident - so a test written against the rows
 * passes with the bug present and proves nothing on this driver. The rows
 * are still walked below, because under the MySQL job in CI that is a real
 * check, but the assertion that can actually fail is the one on the query.
 */
class InvoicePagingTest extends TestCase
{
    use MakesInvoices;
    use RefreshDatabase;

    /**
     * Five invoices, one timestamp, two per page.
     */
    public function test_a_tied_timestamp_pages_cleanly(): void
    {
        $org = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $finder = app(InvoiceFinder::class);

        // The shape the API produces: a batch issued inside one second.
        $moment = now()->subHour();

        $this->tie($org, $moment);

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            Paginator::currentPageResolver(fn (): int => $page);

            foreach ($finder->paginate($org->id, null, 2) as $invoice) {
                $seen[] = $invoice->invoice_number;
            }
        }

        sort($seen);

        $this->assertSame(['INV-1', 'INV-2', 'INV-3', 'INV-4', 'INV-5'], $seen,
            'Paging over rows with one timestamp did not return each row exactly once. '
            .'A timestamp is not a total order, so the pages overlap and something is '
            .'unreachable.');

        $this->assertTotalOrder($this->orderByOf(
            fn () => $finder->paginate($org->id, null, 2)
        ));
    }

    /**
     * And the same for the portal's own list, which is a different query:
     * the history list orders by whatever it is asked and settles a tie on
     * the id (ListSpec's tiebreaker).
     */
    public function test_the_portal_list_pages_cleanly(): void
    {
        $org = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $history = app(InvoiceHistory::class);
        $list = ListState::fromQuery($history->spec($org, 'portal.invoices.index'), ['per_page' => '10'], '/portal/invoices');

        // The smallest page the list offers is ten, so twelve rows make two.
        foreach (range(6, 12) as $n) {
            $this->makeInvoice($org, 'INV-'.$n);
        }

        $this->tie($org, now()->subHour());

        $seen = [];

        foreach ([1, 2] as $page) {
            Paginator::currentPageResolver(fn (): int => $page);

            foreach ($history->page($org->id, $list) as $invoice) {
                $seen[] = $invoice->invoice_number;
            }
        }

        sort($seen, SORT_NATURAL);

        $this->assertSame(array_map(fn (int $n): string => 'INV-'.$n, range(1, 12)), $seen);

        $this->assertTotalOrder($this->orderByOf(
            fn () => $history->page($org->id, $list)
        ));
    }

    /**
     * The ORDER BY of the select the callback runs.
     *
     * Read from the connection rather than from a builder, because these
     * methods return a paginator and the query that produced it is not
     * reachable from the outside.
     */
    private function orderByOf(callable $run): string
    {
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
                && str_contains(strtolower($query->sql), 'order by')) {
                $statements[] = strtolower($query->sql);
            }
        });

        $run();

        $this->assertNotSame([], $statements, 'The query ran no ordered select.');

        $sql = end($statements);

        return substr($sql, strpos($sql, 'order by') ?: 0);
    }

    private function assertTotalOrder(string $orderBy): void
    {
        $this->assertStringContainsString('created_at', $orderBy,
            'The list is no longer ordered newest first.');

        $this->assertMatchesRegularExpression('/"?id"?/', $orderBy,
            'ORDER BY is '.$orderBy.' - a timestamp with no tiebreaker. Rows sharing '
            .'a created_at are ordered however the planner likes, so paging over them '
            .'repeats some and never reaches others. Add the primary key.');
    }

    /**
     * Five invoices sharing one created_at.
     *
     * Written after the insert and through the query builder: created_at is
     * not fillable, and it should not become fillable to let a test set it.
     */
    private function tie(Organization $org, Carbon $moment): void
    {
        foreach (range(1, 5) as $n) {
            $this->makeInvoice($org, 'INV-'.$n);
        }

        DB::table('invoices')
            ->where('org_id', $org->id)
            ->update(['created_at' => $moment, 'updated_at' => $moment]);
    }

    protected function tearDown(): void
    {
        Paginator::currentPageResolver(fn (): int => 1);

        parent::tearDown();
    }
}
