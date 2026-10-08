<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\CsrBuilder;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * fatoora:generate-csr puts the identity it was given into the request.
 *
 * The command has two generators - ZATCA's own SDK when ZATCA_SDK_PATH points
 * at one, and CsrBuilder otherwise - and the SDK path used to write a common
 * name of its own and ask for one fixed template, so the options were accepted
 * and discarded. A request carrying the wrong template or another taxpayer's
 * name is refused by ZATCA, and the OTP it was sent with is spent either way,
 * so what the request actually says is read back here rather than assumed.
 *
 * Both generators satisfy these assertions, so this runs with or without the
 * SDK on the machine.
 */
class CsrCommandTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = storage_path('framework/testing/csr-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        parent::tearDown();
    }

    public function test_the_request_carries_the_given_name(): void
    {
        $this->generate('EGS-UNIT-7421', CsrBuilder::TEMPLATE_SIMULATION);

        $this->assertStringContainsString('EGS-UNIT-7421', $this->der());
        // The SDK's own sample taxpayer, which the command used to insert.
        $this->assertStringNotContainsString('TST-886431145', $this->der());
    }

    #[DataProvider('templates')]
    public function test_the_request_carries_the_template(string $template): void
    {
        $this->generate('EGS-UNIT-7421', $template);

        $der = $this->der();

        // Matched as a whole UTF8String: "ZATCA-Code-Signing" is the tail of
        // "PREZATCA-Code-Signing", so a substring test cannot tell a
        // production request from a simulation one.
        $this->assertStringContainsString($this->utf8($template), $der, "{$template} is missing.");

        foreach (array_diff(array_column($this->templates(), 0), [$template]) as $other) {
            $this->assertStringNotContainsString($this->utf8($other), $der, "{$other} should not be here.");
        }
    }

    /** @return list<array{0: string}> */
    public static function templates(): array
    {
        return [
            [CsrBuilder::TEMPLATE_SANDBOX],
            [CsrBuilder::TEMPLATE_SIMULATION],
            [CsrBuilder::TEMPLATE_PRODUCTION],
        ];
    }

    private function generate(string $commonName, string $template): void
    {
        $this->artisan('fatoora:generate-csr', [
            '--vat' => '311111111111113',
            '--org' => 'Acme Trading Co',
            // ZATCA's production template requires the unit to be the group
            // member's ten-digit TIN rather than a department name.
            '--unit' => '3111111111',
            '--cn' => $commonName,
            '--template' => $template,
            '--standard' => true,
            '--output' => $this->output,
        ])->assertSuccessful();
    }

    private function der(): string
    {
        $pem = (string) file_get_contents($this->output.'/taxpayer.csr');

        return (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
    }

    /** A DER UTF8String: tag, length, bytes. */
    private function utf8(string $value): string
    {
        return "\x0c".chr(strlen($value)).$value;
    }
}
