<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use App\Domains\Platform\Models\ContactMessage;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The console's Website inbox, as a list: by topic, status and the day it
 * arrived, newest first.
 */
class ContactHistory
{
    public function spec(string $key): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: [
                'newest' => new Sort(__('Newest'), 'created_at'),
                'name' => new Sort(__('Name'), 'name', 'asc'),
            ],
            defaultSort: 'newest',
            filters: [
                new Search('q', __('Search'), ['name', 'email', 'company'], __('Name, email or company')),
                new Choice('topic', __('Topic'), ContactTopic::options(), 'topic', __('Any topic')),
                new Choice('status', __('Status'), ContactStatus::options(), 'status', __('Any status')),
                new DateRange('created_at', __('Received'), instant: true),
            ],
        );
    }

    public function page(ListState $list): LengthAwarePaginator
    {
        return $list->paginate(ContactMessage::query());
    }
}
