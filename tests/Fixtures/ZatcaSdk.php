<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * ZATCA's own validator, run against a document we produced.
 *
 * The platform's 700-odd tests assert that the code does what the code
 * intends. None of them could say whether that matches ZATCA, because there
 * was no external oracle anywhere in the project. This is one: the Java SDK
 * ZATCA publishes, carrying the UBL 2.1 schema, the CEN EN16931 rules and
 * ZATCA's own Schematron, run over a document this platform generated.
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
     * The four validators the SDK runs, and what each covers.
     */
    private const STAGES = ['XSD', 'EN', 'KSA', 'PIH'];

    /**
     * Skip unless the SDK and a JRE are both available.
     */
    private function requireSdk(): string
    {
        $path = getenv('ZATCA_SDK_PATH') ?: null;

        if ($path === null) {
            $this->markTestSkipped('ZATCA_SDK_PATH is not set; conformance not checked.');
        }

        $path = rtrim(str_replace('\\', '/', $path), '/');

        if (! is_file($this->jar($path))) {
            $this->markTestSkipped("No SDK jar under {$path}.");
        }

        if ($this->java() === null) {
            $this->markTestSkipped('No Java runtime; the SDK cannot run.');
        }

        return $path;
    }

    /**
     * Validate a document and return each stage's verdict.
     *
     * Errors and warnings are kept apart. The SDK prints both under headings
     * that look alike, and reading them as one list buries the rule that
     * actually failed under a dozen advisory ones — a stage passes with
     * warnings and fails only on errors.
     *
     * @return array{
     *     stages: array<string, string>,
     *     global: string,
     *     errors: list<string>,
     *     warnings: list<string>
     * }
     */
    private function validate(string $xml): array
    {
        $sdk = $this->requireSdk();

        $file = tempnam(sys_get_temp_dir(), 'zatca').'.xml';
        file_put_contents($file, $xml);

        try {
            $output = $this->runSdk($sdk, $file);
        } finally {
            @unlink($file);
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

    private function runSdk(string $sdk, string $invoice): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            [
                $this->java(),
                '-Dfile.encoding=UTF-8',
                '-jar', $this->jar($sdk),
                '--globalVersion', $this->version($sdk),
                '-validate',
                '-invoice', str_replace('/', DIRECTORY_SEPARATOR, $invoice),
            ],
            $descriptors,
            $pipes,
            $sdk.'/Apps',
            [
                // Without SDK_CONFIG the SDK reads no paths at all and dies in
                // Config::readResourcesPaths with a NullPointerException that
                // looks like a malformed invoice. It is not.
                'SDK_CONFIG' => str_replace('/', DIRECTORY_SEPARATOR, $sdk.'/Configuration/config.json'),
                'FATOORA_HOME' => str_replace('/', DIRECTORY_SEPARATOR, $sdk.'/Apps'),
                'PATH' => getenv('PATH') ?: '',
                'SystemRoot' => getenv('SystemRoot') ?: '',
            ]
        );

        $this->assertIsResource($process, 'could not start the ZATCA SDK');

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($process);

        return (string) $output;
    }

    private function jar(string $sdk): string
    {
        $found = glob($sdk.'/Apps/zatca-einvoicing-sdk-*.jar') ?: [];

        return $found[0] ?? $sdk.'/Apps/none.jar';
    }

    /**
     * The SDK refuses to start unless the version it is told matches the jar.
     */
    private function version(string $sdk): string
    {
        $global = @file_get_contents($sdk.'/Apps/global.json');
        $decoded = json_decode((string) $global, true);

        return $decoded['version'] ?? '';
    }

    private function java(): ?string
    {
        $home = getenv('JAVA_HOME');

        if ($home && is_file($home.'/bin/java.exe')) {
            return $home.'/bin/java.exe';
        }

        if ($home && is_file($home.'/bin/java')) {
            return $home.'/bin/java';
        }

        $which = PHP_OS_FAMILY === 'Windows' ? 'where java' : 'which java';
        $found = trim((string) @shell_exec($which));

        return $found === '' ? null : strtok($found, "\r\n");
    }
}
