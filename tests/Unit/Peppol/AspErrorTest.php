<?php

declare(strict_types=1);

namespace Tests\Unit\Peppol;

use App\Domains\Compliance\Networks\Peppol\Gateways\AspError;
use PHPUnit\Framework\TestCase;

/**
 * A provider's errors, in whatever form it sends them, read as rule,
 * severity, field and message.
 */
class AspErrorTest extends TestCase
{
    public function test_published_rule_text_gives_its_code(): void
    {
        $error = AspError::from('[ibr-003]-An Invoice MUST have an Invoice issue date (ibt-002).');

        $this->assertSame(['code' => 'ibr-003', 'severity' => 'error', 'field' => null, 'message' => 'An Invoice MUST have an Invoice issue date (ibt-002).'], $error?->toArray());
    }

    public function test_object_fields_by_other_names(): void
    {
        $error = AspError::from(['rule' => 'BR-AE-10', 'flag' => 'FATAL', 'path' => 'cbc:CompanyID', 'description' => 'invalid TRN']);

        $this->assertSame(['code' => 'BR-AE-10', 'severity' => 'fatal', 'field' => 'cbc:CompanyID', 'message' => 'invalid TRN'], $error?->toArray());
    }

    public function test_unreadable_errors_are_dropped(): void
    {
        $this->assertSame([], AspError::listFrom(['', ['unrelated' => 1], 42, null]));
        $this->assertSame('warning', AspError::from(['code' => 'W1', 'severity' => 'warning', 'message' => 'x'])?->severity);
        $this->assertSame('error', AspError::from(['code' => 'X', 'severity' => 'catastrophic'])?->severity);
    }

    public function test_one_line_for_logs(): void
    {
        $this->assertSame('[BV] Refused (at cbc:ID)', (new AspError('Refused', 'BV', AspError::FATAL, 'cbc:ID'))->line());
    }
}
