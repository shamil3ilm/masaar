<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Invoice\Models\Invoice;
use App\Support\Errors\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many submissions a device, and its organization, may make in a minute
 * and in a day.
 *
 * Per device first: ZATCA keeps one chain per EGS unit, and a chain of shops
 * is many units. Capped per organization alone, every till drew on one
 * budget, so one busy branch used up its neighbours' minute and the day ran
 * out for all of them together. Then the organization's ceiling, which
 * bounds the devices together and is what the edge limiter enforces too
 * (security.rate_limits.submission).
 */
class SubmissionRate
{
    /**
     * @throws FatooraException
     */
    public function assertWithin(Invoice $invoice): void
    {
        $organizationId = (string) $invoice->org_id;
        $device = (string) ($invoice->device_id ?? $invoice->org_id);

        // The day is the Kingdom's. created_at is stored in UTC, so the
        // Kingdom's midnight is handed over as the instant it is - utc() -
        // and not as a Riyadh-zoned Carbon, which the query grammar formats
        // in its own timezone and so would move the window three hours.
        $minute = now()->subMinute();
        $day = FatooraTime::saudiNow()->startOfDay()->utc();

        $this->refuseAbove(
            $this->forDevice($organizationId, $device, $minute),
            FatooraConfig::getDeviceRateLimitPerMinute(),
            'Rate limit exceeded for this device (%d/minute). Other devices of the organization are not affected.',
            ErrorCode::RATE_LIMIT_EXCEEDED,
        );

        $this->refuseAbove(
            $this->forOrganization($organizationId, $minute),
            FatooraConfig::getRateLimitPerMinute(),
            'Rate limit exceeded for this organization across its devices (%d/minute).',
            ErrorCode::RATE_LIMIT_EXCEEDED,
        );

        $this->refuseAbove(
            $this->forDevice($organizationId, $device, $day),
            FatooraConfig::getDeviceRateLimitPerDay(),
            'Daily submission limit exceeded for this device (%d/day). Other devices of the organization are not affected.',
            ErrorCode::RATE_DAILY_LIMIT,
        );

        $this->refuseAbove(
            $this->forOrganization($organizationId, $day),
            FatooraConfig::getRateLimitPerDay(),
            'Daily submission limit exceeded for this organization across its devices (%d/day).',
            ErrorCode::RATE_DAILY_LIMIT,
        );
    }

    /**
     * @throws FatooraException
     */
    private function refuseAbove(Builder $submissions, int $limit, string $message, ErrorCode $code): void
    {
        if ($submissions->count() >= $limit) {
            throw new FatooraException(sprintf($message, $limit), $code, ['limit' => $limit]);
        }
    }

    private function forOrganization(string $organizationId, CarbonInterface $since): Builder
    {
        return InvoiceSubmission::query()
            ->where('org_id', $organizationId)
            ->where('created_at', '>=', $since);
    }

    /**
     * The submissions of documents this device signed: the device is on the
     * invoice, and a submission names its invoice.
     */
    private function forDevice(string $organizationId, string $device, CarbonInterface $since): Builder
    {
        return $this->forOrganization($organizationId, $since)
            ->whereIn('invoice_id', Invoice::acrossTenants()
                ->select('id')
                ->where('org_id', $organizationId)
                ->where('device_id', $device)
                ->toBase());
    }
}
