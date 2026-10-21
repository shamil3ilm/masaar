<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Concerns;

use App\Domains\Organization\Models\Organization;
use App\Support\CountryClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period a Books page reads: from and to as asked, or this month on the
 * organization's own clock, so the first hours of a month are that month
 * wherever the server is. A year and a little at most.
 */
trait ReadsBooksPeriod
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function period(Request $request, Organization $organization): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $now = $this->today($organization);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : $now->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->startOfDay() : $now->endOfMonth()->startOfDay();

        if ($from->diffInDays($to, true) > 400) {
            $to = $from->addDays(400);
        }

        return [$from, $to];
    }

    /** One day, as asked, or today on the organization's clock. */
    protected function day(Request $request, Organization $organization, string $key): CarbonImmutable
    {
        $data = $request->validate([$key => ['nullable', 'date']]);

        return isset($data[$key]) ? CarbonImmutable::parse($data[$key])->startOfDay() : $this->today($organization);
    }

    protected function today(Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(CountryClock::timeZone($organization->country))->toDateString());
    }
}
