<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Console\Commands\FatooraGenerateCsr;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The curve the EGS key is generated on, and what happens when it cannot be.
 *
 * ZATCA mandates secp256k1. When the local OpenSSL could not provide it, the
 * command used to generate a prime256v1 key instead and carry on. That is the
 * dangerous shape of this bug: it does not fail. The CSR is well formed, the
 * authority issues a CSID against it, and the mistake only surfaces when a
 * signature is checked against a public key on a curve the verifier does not
 * expect — by which point certificates have been issued against the wrong key.
 *
 * CsrGenerationTest asserts the curve of a key that was generated. This asserts
 * that no key is generated at all when the right curve is unavailable.
 */
class EcKeyCurveTest extends TestCase
{
    /**
     * The guard. A build that cannot do secp256k1 must stop here, before a key
     * exists and long before onboarding is attempted.
     */
    public function test_missing_curve_is_refused(): void
    {
        $keyPath = $this->keyPath();

        try {
            $this->generateWith('openssl-that-does-not-exist', $keyPath);
            $this->fail('An OpenSSL that cannot generate the key was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('secp256k1', $e->getMessage());
        }

        $this->assertFileDoesNotExist($keyPath, 'A key was written despite the failure.');
    }

    /**
     * The positive control, without which the test above passes for a method
     * that only ever throws.
     */
    public function test_key_uses_the_mandated_curve(): void
    {
        $openssl = $this->openssl();

        if ($openssl === null) {
            $this->markTestSkipped('No OpenSSL binary on this machine.');
        }

        $keyPath = $this->keyPath();

        $this->generateWith($openssl, $keyPath);

        $this->assertFileExists($keyPath);

        $details = openssl_pkey_get_details(
            openssl_pkey_get_private(file_get_contents($keyPath))
        );

        $this->assertSame(OPENSSL_KEYTYPE_EC, $details['type']);
        $this->assertSame('secp256k1', $details['ec']['curve_name']);
    }

    private function generateWith(string $openssl, string $keyPath): void
    {
        $command = app(FatooraGenerateCsr::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        $method = new ReflectionMethod($command, 'generateEcKey');
        $method->setAccessible(true);
        $method->invoke($command, $openssl, $keyPath);
    }

    private function openssl(): ?string
    {
        $command = app(FatooraGenerateCsr::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        $method = new ReflectionMethod($command, 'findOpenSsl');
        $method->setAccessible(true);

        return $method->invoke($command);
    }

    private function keyPath(): string
    {
        $path = sys_get_temp_dir().'/eckey-'.bin2hex(random_bytes(8)).'.pem';

        if (file_exists($path)) {
            unlink($path);
        }

        return $path;
    }
}
