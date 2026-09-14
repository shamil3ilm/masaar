<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Console\SdkValidator;

/**
 * ZATCA's own validator, run against a document we produced.
 *
 * The platform's 700-odd tests assert that the code does what the code
 * intends. None of them could say whether that matches ZATCA, because there
 * was no external oracle anywhere in the project. This is one: the Java SDK
 * ZATCA publishes, run through SdkValidator over a document this platform
 * generated.
 *
 * It is optional by necessity — the SDK is a licensed download that cannot be
 * committed, and CI has no Java. Set ZATCA_SDK_PATH to the directory holding
 * Apps/ and Data/ and these tests run; leave it unset and they skip. A skipped
 * conformance test is honest. A missing one is what let BT-3's business
 * process stay wrong.
 */
trait ZatcaSdk
{
    /**
     * Skip unless the SDK and a JRE are both available.
     */
    private function requireSdk(): string
    {
        $sdk = $this->sdk();

        if ($sdk->path() === null) {
            $this->markTestSkipped('ZATCA_SDK_PATH is not set; conformance not checked.');
        }

        if (! $sdk->available()) {
            $this->markTestSkipped("The SDK under {$sdk->path()} cannot run: no jar, or no Java.");
        }

        return (string) $sdk->path();
    }

    /**
     * @return array{stages: array<string, string>, global: string, errors: list<string>, warnings: list<string>}
     */
    private function validate(string $xml): array
    {
        $this->requireSdk();

        return $this->sdk()->validate($xml);
    }

    private function sdk(): SdkValidator
    {
        return new SdkValidator(getenv('ZATCA_SDK_PATH') ?: null);
    }
}
