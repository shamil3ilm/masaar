<?php

declare(strict_types=1);

namespace App\Console;

use RuntimeException;

/**
 * ZATCA's own validator, run over a document this platform produced.
 *
 * The Java SDK ZATCA publishes carries the UBL 2.1 schema, the CEN EN16931
 * rules and ZATCA's Schematron. It is a licensed download that cannot be
 * committed, so it is found through fatoora.validation.sdk_path
 * (ZATCA_SDK_PATH), and available() says whether it can run here.
 *
 * It starts a Java process, so it is operator tooling: console commands and
 * tests use it, and domain services must not (NoShellOutTest).
 */
final class SdkValidator
{
    /**
     * The stages that judge what a document says.
     *
     * The SDK also checks the certificate, the QR and signature over it, and
     * the PIH against its own configured file. Those need a ZATCA-issued
     * certificate: with the SDK's bundled test key even ZATCA's own simplified
     * samples fail SIGNATURE. Locally they say nothing about the document.
     */
    public const CONTENT_STAGES = ['XSD', 'EN', 'KSA'];

    private const STAGES = ['XSD', 'EN', 'KSA', 'QR', 'SIGNATURE', 'PIH'];

    private readonly ?string $path;

    public function __construct(?string $path = null)
    {
        $path ??= config('fatoora.validation.sdk_path');

        $this->path = $path === null || $path === ''
            ? null
            : rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function available(): bool
    {
        return $this->path !== null && $this->jar() !== null && $this->java() !== null;
    }

    /**
     * Validate a document and return each stage's verdict.
     *
     * Errors and warnings are kept apart. The SDK prints both under headings
     * that look alike, and a stage passes with warnings and fails only on
     * errors.
     *
     * @return array{stages: array<string, string>, global: string, errors: list<string>, warnings: list<string>}
     */
    public function validate(string $xml): array
    {
        if (! $this->available()) {
            throw new RuntimeException('The ZATCA SDK cannot run here: set ZATCA_SDK_PATH and install Java.');
        }

        $base = (string) tempnam(sys_get_temp_dir(), 'zatca');
        $file = $base.'.xml';
        file_put_contents($file, $xml);

        try {
            $output = $this->run($file);
        } finally {
            @unlink($file);
            @unlink($base);
        }

        $stages = [];

        foreach (self::STAGES as $stage) {
            if (preg_match("/\[{$stage}\] validation result : (\w+)/", $output, $found) === 1) {
                $stages[$stage] = $found[1];
            }
        }

        preg_match('/GLOBAL VALIDATION RESULT = (\w+)/', $output, $global);

        // The SDK tags each rule line with its severity, which is the only
        // thing separating a rule that failed the document from one that
        // merely commented on it.
        preg_match_all('/\[(ERROR|WARN)\].*?CODE : ([\w-]+), MESSAGE : (.+)/', $output, $found, PREG_SET_ORDER);

        $errors = [];
        $warnings = [];

        foreach ($found as [, $level, $code, $message]) {
            $line = trim($code.': '.$message);

            if ($level === 'WARN') {
                $warnings[] = $line;

                continue;
            }

            $errors[] = $line;
        }

        return [
            'stages' => $stages,
            'global' => $global[1] ?? 'UNKNOWN',
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Whether every stage about what the document says passed.
     *
     * @param  array{stages: array<string, string>}  $result
     */
    public function contentPassed(array $result): bool
    {
        foreach (self::CONTENT_STAGES as $stage) {
            if (($result['stages'][$stage] ?? null) !== 'PASSED') {
                return false;
            }
        }

        return true;
    }

    private function run(string $invoice): string
    {
        $process = proc_open(
            [
                $this->java(),
                '-Dfile.encoding=UTF-8',
                '-jar', $this->jar(),
                '--globalVersion', $this->version(),
                '-validate',
                '-invoice', str_replace('/', DIRECTORY_SEPARATOR, $invoice),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->path.'/Apps',
            [
                // Without SDK_CONFIG the SDK reads no paths at all and dies in
                // Config::readResourcesPaths with a NullPointerException that
                // looks like a malformed invoice. It is not.
                'SDK_CONFIG' => str_replace('/', DIRECTORY_SEPARATOR, $this->path.'/Configuration/config.json'),
                'FATOORA_HOME' => str_replace('/', DIRECTORY_SEPARATOR, $this->path.'/Apps'),
                'PATH' => getenv('PATH') ?: '',
                'SystemRoot' => getenv('SystemRoot') ?: '',
            ]
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the ZATCA SDK.');
        }

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($process);

        return (string) $output;
    }

    private function jar(): ?string
    {
        $found = glob($this->path.'/Apps/zatca-einvoicing-sdk-*.jar') ?: [];

        return $found[0] ?? null;
    }

    /**
     * The SDK refuses to start unless the version it is told matches the jar.
     */
    private function version(): string
    {
        $decoded = json_decode((string) @file_get_contents($this->path.'/Apps/global.json'), true);

        return (string) ($decoded['version'] ?? '');
    }

    private function java(): ?string
    {
        $home = getenv('JAVA_HOME');

        foreach (['/bin/java.exe', '/bin/java'] as $binary) {
            if ($home && is_file($home.$binary)) {
                return $home.$binary;
            }
        }

        $found = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where java' : 'which java'));

        return $found === '' ? null : (string) strtok($found, "\r\n");
    }
}
