<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Enums\ScheduleStatus;
use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * An organization's schedules as a list, with the toolkit every list has:
 * the next to run first, narrowed by status or a name, the filters kept in
 * the link. Every query names the organization.
 */
class ScheduleHistory
{
    public function spec(string $key): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: [
                'next' => new Sort(__('Next run'), 'next_run_on', 'asc'),
                'newest' => new Sort(__('Newest'), 'created_at'),
                'name' => new Sort(__('Name'), 'name', 'asc'),
            ],
            defaultSort: 'next',
            filters: [
                new Search('q', __('Search'), ['name'], __('Schedule name')),
                new Choice('status', __('Status'), [
                    ScheduleStatus::Active->value => __('Active'),
                    ScheduleStatus::Paused->value => __('Paused'),
                    ScheduleStatus::Completed->value => __('Completed'),
                    ScheduleStatus::Cancelled->value => __('Cancelled'),
                ], 'status', __('Any status')),
            ],
        );
    }

    /**
     * @return LengthAwarePaginator<int, InvoiceSchedule>
     */
    public function page(string $organizationId, ListState $list): LengthAwarePaginator
    {
        return $list->paginate(InvoiceSchedule::query()->where('org_id', $organizationId));
    }
}
