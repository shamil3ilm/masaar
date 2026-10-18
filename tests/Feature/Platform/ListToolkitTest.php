<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\NumberRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The list toolkit reads a query string the same way for every list: what
 * it understands survives into every link it draws, and what it does not
 * is dropped rather than passed on.
 */
class ListToolkitTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_presets_read_the_owners_clock(): void
    {
        // 22:30 UTC on Saturday the 17th is already Sunday the 18th in Riyadh.
        Carbon::setTestNow('2026-10-17 22:30:00');

        $riyadh = new DateRange('issue_date', 'Date', 'Asia/Riyadh');

        $this->assertSame('2026-10-18', $riyadh->read(['period' => 'today'])['from']->toDateString());
        $this->assertSame('2026-10-01', $riyadh->read(['period' => 'this_month'])['from']->toDateString());
        $this->assertSame('2026-09-30', $riyadh->read(['period' => 'last_month'])['to']->toDateString());
        $this->assertSame('2026-12-31', $riyadh->read(['period' => 'this_quarter'])['to']->toDateString());
    }

    public function test_a_moment_column_compares_utc_instants(): void
    {
        $range = new DateRange('created_at', 'When', 'Asia/Riyadh', instant: true);
        $query = DB::table('audit_logs');

        $range->apply($query, $range->read(['from' => '2026-10-01', 'to' => '2026-10-01']));

        $this->assertSame(['2026-09-30 21:00:00', '2026-10-01 21:00:00'], $query->getBindings());
    }

    public function test_typed_dates_win_over_a_preset(): void
    {
        $range = new DateRange('issue_date', 'Date');

        $value = $range->read(['period' => 'this_month', 'from' => '2026-03-09', 'to' => '2026-03-01']);

        $this->assertSame('custom', $value['preset']);
        $this->assertSame(['2026-03-01', '2026-03-09'], [$value['from']->toDateString(), $value['to']->toDateString()]);
    }

    public function test_malformed_values_narrow_nothing(): void
    {
        $this->assertNull((new DateRange('d', 'D'))->read(['from' => '2026-02-30']));
        $this->assertNull((new NumberRange('total', 'Total'))->read(['amount_min' => '1e9', 'amount_max' => '12,5']));
        $this->assertNull((new Choice('status', 'S', ['issued' => 'Issued'], 'status'))->read(['status' => ['issued']]));
        $this->assertNull((new Choice('status', 'S', ['issued' => 'Issued'], 'status'))->read(['status' => 'void']));
        $this->assertNull((new Search('q', 'Q', ['name']))->read(['q' => '   ']));
    }

    public function test_links_carry_only_what_was_understood(): void
    {
        $list = ListState::fromQuery($this->spec(), [
            'status' => 'issued', 'amount_min' => '100', 'junk' => 'x', 'sort' => 'newest', 'per_page' => '25', 'page' => '3',
        ], 'https://masaar.test/list');

        // In the spec's order, whatever order the link had.
        $this->assertSame(['amount_min' => '100', 'status' => 'issued'], $list->query());
        $this->assertSame('https://masaar.test/list', $list->clearUrl());
        $this->assertSame('https://masaar.test/list?amount_min=100&status=issued&sort=amount&dir=desc', $list->sortUrl('amount'));
    }

    public function test_a_second_click_turns_the_order(): void
    {
        $list = ListState::fromQuery($this->spec(), ['sort' => 'amount'], '/list');

        $this->assertSame('desc', $list->sortedBy('amount'));
        $this->assertSame('/list?sort=amount&dir=asc', $list->sortUrl('amount'));
        $this->assertNull($list->sortedBy('newest'));
    }

    public function test_each_chip_takes_one_filter_away(): void
    {
        $list = ListState::fromQuery($this->spec(), ['status' => 'issued', 'amount_min' => '100', 'amount_max' => '500'], '/list');
        $chips = $list->chips();

        $this->assertCount(2, $chips);
        $this->assertSame('100 to 500', $chips[0]->value);
        $this->assertSame('/list?status=issued', $chips[0]->removeUrl);
        $this->assertSame('/list?amount_min=100&amount_max=500', $chips[1]->removeUrl);
    }

    private function spec(): ListSpec
    {
        return new ListSpec(
            key: 'test.list',
            sorts: ['newest' => new Sort('Newest', 'created_at'), 'amount' => new Sort('Amount', 'total')],
            defaultSort: 'newest',
            filters: [
                new NumberRange('total', 'Total'),
                new Choice('status', 'Status', ['issued' => 'Issued'], 'status'),
            ],
        );
    }
}
