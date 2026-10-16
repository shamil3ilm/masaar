<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\DeviceCredentials;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Clearance asks the authority to stamp the document; reporting does not.
 *
 * ZATCA's clearance endpoint clears only with Clearance-Status: 1. The
 * sandbox onboarding command always sent it, but FatooraClient - the path
 * every real standard invoice takes - did not, so a test against the
 * sandbox could pass while production filing did not clear.
 */
class ClearanceHeaderTest extends TestCase
{
    public function test_clearance_asks_for_a_stamp(): void
    {
        Http::fake(['*' => Http::response(['clearanceStatus' => 'CLEARED'])]);

        app(FatooraClient::class)->clearInvoice('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), FatooraClient::CLEARANCE)
            && $request->hasHeader('Clearance-Status', '1'));
    }

    public function test_reporting_carries_no_clearance_status(): void
    {
        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED'])]);

        app(FatooraClient::class)->reportInvoice('<Invoice/>', 'hash', 'uuid', new DeviceCredentials('token', 'secret'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), FatooraClient::REPORTING)
            && ! $request->hasHeader('Clearance-Status'));
    }
}
