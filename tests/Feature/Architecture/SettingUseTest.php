<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Platform\Services\Settings;
use PHPUnit\Framework\TestCase;

/**
 * An operational number is a setting, and a setting is read by something.
 *
 * The thresholds that decide when the admin dashboard calls something a
 * problem were private consts in IssueDetector - 100 queued invoices is a
 * warning, 500 is critical, 50 failed items is critical, 10 rejections in a
 * day is a warning. The right value for any of them is only learned by
 * watching the platform run, and changing one meant editing PHP, raising a
 * pull request and shipping a release.
 *
 * The second assertion is the one that matters more. A settings screen that
 * stores a number nothing reads is worse than a constant, because it looks
 * like it worked: the operator changes the backlog threshold, the page says
 * Settings saved, and the dashboard carries on using the old value with
 * nothing anywhere to say so.
 */
class SettingUseTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * Where an operational threshold must not be written down.
     *
     * Not every constant in the codebase - a protocol constant, a byte
     * length, a ZATCA-defined limit all belong in code, because they are not
     * ours to change. These are the files whose numbers are judgements about
     * our own platform.
     */
    private const NO_THRESHOLDS = [
        'Domains/Platform/Services/IssueDetector.php',
    ];

    public function test_no_threshold_is_a_constant(): void
    {
        $offenders = [];

        foreach (self::NO_THRESHOLDS as $relative) {
            $source = (string) file_get_contents(self::APP.'/'.$relative);

            preg_match_all('/(?:private|protected|public)?\s*const\s+(\w+)\s*=\s*\d+\s*;/', $source, $matches);

            foreach ($matches[1] as $name) {
                $offenders[] = $relative.': '.$name;
            }
        }

        $this->assertSame([], $offenders,
            'These are numbers an operator should be able to change from the admin console. '
            .'Add them to Settings::DEFINITIONS instead.');
    }

    /**
     * Every setting on the screen is read by something.
     */
    public function test_every_setting_is_read(): void
    {
        $sources = $this->sources();
        $unread = [];

        foreach (array_keys(Settings::DEFINITIONS) as $key) {
            $needle = "'".$key."'";
            $found = false;

            foreach ($sources as $relative => $source) {
                // The definitions themselves name every key, so the file that
                // declares them cannot be the thing that reads one.
                if ($relative === 'Domains/Platform/Services/Settings.php') {
                    continue;
                }

                if (str_contains($source, $needle)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $unread[] = $key;
            }
        }

        $this->assertSame([], $unread,
            'These settings are editable and nothing reads them. A screen that stores a number '
            .'nothing uses is worse than a constant: it looks like it worked.');
    }

    /** @return array<string, string> relative path => source */
    private function sources(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::APP, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::APP) + 1));
            $found[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($found);

        return $found;
    }
}
