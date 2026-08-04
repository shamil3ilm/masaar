<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Fatoora\Helpers\LogSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Compliance logs are retained for years and carry invoice context, so a
 * credential written into one is a long-lived exposure. Sanitisation happens
 * at ComplianceLogger's single write path, not per call site.
 */
class LogSanitizerTest extends TestCase
{
    public function test_secrets_are_redacted(): void
    {
        $clean = LogSanitizer::sanitize([
            'api_secret' => 'cpay_live_supersecret_value',
            'password' => 'hunter2',
        ]);

        $this->assertStringNotContainsString('supersecret_value', json_encode($clean));
        $this->assertSame('[REDACTED]', $clean['password']);
    }

    public function test_nested_secrets_are_redacted(): void
    {
        $clean = LogSanitizer::sanitize([
            'request' => ['headers' => ['password' => 'hunter2']],
        ]);

        $this->assertSame('[REDACTED]', $clean['request']['headers']['password']);
    }

    /**
     * Redaction has to leave the log useful, or people stop logging context.
     */
    public function test_business_fields_survive(): void
    {
        $clean = LogSanitizer::sanitize([
            'invoice_number' => 'INV-1001',
            'buyer_name' => 'Acme Ltd',
            'icv' => 42,
        ]);

        $this->assertSame('INV-1001', $clean['invoice_number']);
        $this->assertSame('Acme Ltd', $clean['buyer_name']);
        $this->assertSame(42, $clean['icv']);
    }
}
