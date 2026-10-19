<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Networks\Peppol\Sending\ReportOnlyEndpoints;
use Tests\TestCase;

/**
 * The network's report-only endpoints for the UAE are the three the
 * Guidelines reserve (UAE Electronic Invoicing Guidelines v1.1, p23, p26,
 * p28), as PintAe names them: one list, configured once, kept equal here.
 */
class ReportOnlyEndpointsTest extends TestCase
{
    public function test_reserved_endpoints_are_report_only(): void
    {
        $reserved = array_map(fn (string $id): string => PintAe::SCHEME.':'.$id, [
            PintAe::DEEMED_SUPPLY_BUYER, PintAe::BUYER_NOT_YET_ON_EINVOICING, PintAe::EXPORT_BUYER,
        ]);
        $configured = app(ReportOnlyEndpoints::class)->all();
        sort($reserved);
        sort($configured);

        $this->assertSame($reserved, $configured);
        $this->assertTrue(app(ReportOnlyEndpoints::class)->contains('iso6523-actorid-upis::0235:9900000097'));
        $this->assertFalse(app(ReportOnlyEndpoints::class)->contains('0235:1007654321'));
    }
}
