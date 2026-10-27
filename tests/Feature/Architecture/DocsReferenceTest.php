<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * What the documentation names, the code has.
 *
 * A guide that says `php artisan` a command which was renamed, a setting the
 * configuration no longer reads, or a file that moved, reads exactly as well
 * as one that is right - until an operator follows it during an incident.
 * The four checks here are the parts of "the docs are honest" a machine can
 * hold: commands, settings, paths and dates.
 */
class DocsReferenceTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** Root documents scanned beside docs/. */
    private const ROOT_DOCS = ['README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'SUPPORT.md'];

    /** Excluded from the date check only: both are records of when things happened. */
    private const DATED_ON_PURPOSE = ['docs/STANDALONE-RELEASE-PLAN.md', 'CHANGELOG.md'];

    /** Where a backtick path is a repository path. */
    private const PATH_ROOTS = 'app|config|routes|database|docs|tests|scripts|resources';

    /**
     * Variables the documentation may name that the application does not
     * read: the operating system's, Docker Compose's, the CI runner's, and the
     * machine paths the conformance tests take from the developer's shell.
     */
    private const NOT_APPLICATION_SETTINGS = [
        'PATH', 'HOME', 'USER', 'COMPOSE_FILE', 'COMPOSE_PROJECT_NAME', 'DOCKER_BUILDKIT',
        'GITHUB_TOKEN', 'GITHUB_SHA', 'GITHUB_REF',
        'ZATCA_SDK_PATH', 'PDFA_VALIDATOR_PATH', 'PEPPOL_ARTEFACTS_PATH', 'PEPPOL_XSLT_JAR', 'GOLDEN_UPDATE',
    ];

    /**
     * TEMPORARY. Known failures in documents other work packages are
     * rewriting in parallel (guides, integration and API, architecture and
     * compliance). Each entry is "file: check: token", with the product's
     * name as <brand>. Empty this list once
     * those branches are merged; never add to it for a new document.
     */
    private const KNOWN_ELSEWHERE = [
        'docs/ae/COMPLIANCE-RULES.md: date: February 2026',
        'docs/ae/COMPLIANCE-RULES.md: date: January 2027',
        'docs/ae/COMPLIANCE-RULES.md: date: July 2026',
        'docs/ae/COMPLIANCE-RULES.md: date: June 2026',
        'docs/ae/COMPLIANCE-RULES.md: date: March 2027',
        'docs/ae/COMPLIANCE-RULES.md: date: October 2026',
        'docs/ae/INTEGRATION-GUIDE.md: date: 2026-10-10',
        'docs/ae/INTEGRATION-GUIDE.md: date: 2026-11-01',
        'docs/ae/INTEGRATION-GUIDE.md: date: 2026-11-09',
        'docs/COMPLIANCE-POLICIES.md: date: May 2023',
        'docs/NAMING.md: path: app/Domains/Auth/Http/Middleware/EnsureUserIsAdmin',
        'docs/PARTNER-ADVANCED-SCENARIOS.md: date: 2026-10-14',
        'docs/PARTNER-ADVANCED-SCENARIOS.md: date: 2026-10-15',
        'docs/PARTNER-INTEGRATION-GUIDE.md: date: 2026-10-15',
        'docs/PARTNER-INTEGRATION-GUIDE.md: date: 2026-10-20',
        'docs/PARTNER-INTEGRATION-GUIDE.md: date: 2026-11-01',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: app/Http/Controllers/<brand>WebhookController.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: app/Jobs/SubmitInvoiceTo<brand>.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: app/Providers/<brand>ServiceProvider.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: app/Services/<brand>/InvoiceService.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: app/Services/<brand>/<brand>Client.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: app/Services/<brand>/<brand>Exception.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: config/<brand>.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: path: tests/Feature/<brand>IntegrationTest.php',
        'docs/PARTNER-INTEGRATION-GUIDE.md: setting: <brand>_API_KEY',
        'docs/PARTNER-INTEGRATION-GUIDE.md: setting: <brand>_API_SECRET',
        'docs/PARTNER-INTEGRATION-GUIDE.md: setting: <brand>_BASE_URL',
        'docs/PARTNER-INTEGRATION-GUIDE.md: setting: <brand>_ORG_ID',
        'docs/PARTNER-INTEGRATION-GUIDE.md: setting: <brand>_TIMEOUT',
        'docs/PARTNER-INTEGRATION-GUIDE.md: setting: <brand>_WEBHOOK_SECRET',
        'docs/sa/COMPLIANCE-RULES.md: setting: FATOORA_ENV',
        'docs/sa/INTEGRATION-GUIDE.md: date: 2026-09-01',
        'docs/sa/INTEGRATION-GUIDE.md: date: 2026-10-20',
        'docs/sa/INTEGRATION-GUIDE.md: date: 2027-01-01',
        'docs/sa/INTEGRATION-GUIDE.md: date: 2027-01-03',
        'docs/sa/INTEGRATION-GUIDE.md: date: 2027-04-04',
        'docs/sa/INTEGRATION-GUIDE.md: date: January 2023',
        'docs/sa/STATUS.md: setting: ZATCA_SDK_TOKEN',
        'docs/sa/STATUS.md: setting: ZATCA_SDK_URL',
    ];

    public function test_documented_artisan_commands_exist(): void
    {
        $this->assertNone($this->commandFindings(), 'Documents run artisan commands the application does not have');
    }

    public function test_documented_settings_are_read(): void
    {
        $this->assertNone($this->settingFindings(), 'Documents name settings no example file holds and no config file reads');
    }

    public function test_documented_paths_exist(): void
    {
        $this->assertNone($this->pathFindings(), 'Documents point at files that do not exist');
    }

    /**
     * A date is stale the day after it is written; authority timelines belong
     * on the authorities' portals. Versions and legal citations ("No. 243 of
     * 2025") carry a year but no month, so they pass.
     */
    public function test_documents_carry_no_dates(): void
    {
        $this->assertNone($this->dateFindings(), 'Documents carry dates');
    }

    /**
     * The allow-list may only hold failures that still happen, so it empties
     * as the documents are fixed rather than outliving them.
     */
    public function test_allow_list_holds_only_live_failures(): void
    {
        $live = array_map(
            fn (string $finding): string => explode(chr(0), $finding)[0],
            [...$this->commandFindings(), ...$this->settingFindings(), ...$this->pathFindings(), ...$this->dateFindings()],
        );

        $this->assertSame([], array_values(array_diff(self::KNOWN_ELSEWHERE, $live)), 'Fixed, so remove from KNOWN_ELSEWHERE');
    }

    /**
     * @return list<string>
     */
    private function commandFindings(): array
    {
        $commands = array_keys(Artisan::all());
        $missing = [];

        foreach ($this->documents() as $doc) {
            foreach ($this->lines($doc) as [$number, $line]) {
                preg_match_all('/php artisan ([a-z][a-z0-9:-]*)/', $line, $matches);

                foreach ($matches[1] as $command) {
                    if (! in_array($command, $commands, true)) {
                        $missing[] = $this->finding($doc, 'command', $command, $number);
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function settingFindings(): array
    {
        $known = $this->knownSettings();
        $prefixes = array_unique(array_map(fn (string $name): string => strtok($name, '_'), $known));
        $codes = $this->codesInApplication();
        $missing = [];

        foreach ($this->documents() as $doc) {
            foreach ($this->lines($doc) as [$number, $line, $fenced]) {
                foreach ($this->settingsNamed($line, $fenced, $prefixes) as [$name, $assigned]) {
                    if (! in_array($name, $known, true)
                        && ! in_array($name, self::NOT_APPLICATION_SETTINGS, true)
                        && ($assigned || ! isset($codes[$name]))) {
                        $missing[] = $this->finding($doc, 'setting', $name, $number);
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function pathFindings(): array
    {
        $missing = [];

        foreach ($this->documents() as $doc) {
            foreach ($this->lines($doc) as [$number, $line, $fenced]) {
                if ($fenced) {
                    continue;
                }

                preg_match_all('/\]\(([^)\s#]+)(?:#[^)]*)?\)/', $line, $links);

                foreach ($links[1] as $link) {
                    if (! preg_match('/^(https?:|mailto:)/', $link) && ! file_exists(dirname($doc).'/'.$link)) {
                        $missing[] = $this->finding($doc, 'link', $link, $number);
                    }
                }

                foreach ($this->codeSpans($line) as $span) {
                    if (! preg_match('#^(?:'.self::PATH_ROOTS.')/[\w./-]*$#', $span) || str_contains($span, '...')) {
                        continue;
                    }

                    $path = rtrim($span, '.,:;');

                    if (! file_exists(self::ROOT.'/'.$path)) {
                        $missing[] = $this->finding($doc, 'path', $path, $number);
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function dateFindings(): array
    {
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';
        $dated = '/\b(19|20)\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b|\b('.$months.')\.?\s+(\d{1,2}(st|nd|rd|th)?,?\s+)?(19|20)\d{2}\b/';
        $found = [];

        foreach ($this->documents() as $doc) {
            if (in_array($this->relative($doc), self::DATED_ON_PURPOSE, true)) {
                continue;
            }

            foreach ($this->lines($doc) as [$number, $line]) {
                if (preg_match($dated, $line, $match)) {
                    $found[] = $this->finding($doc, 'date', $match[0], $number);
                }
            }
        }

        return $found;
    }

    /**
     * Settings named in a document, each with whether it is assigned:
     * `NAME=value` lines in a code block (not shell variables filled from a
     * command), and code spans holding `NAME=value`, or a bare `NAME` whose
     * first word is one the settings use (APP_, DB_, ZATCA_ ...). A bare name
     * the application spells as a code or constant is that, not a setting.
     *
     * @param  list<string>  $prefixes
     * @return list<array{string, bool}>
     */
    private function settingsNamed(string $line, bool $fenced, array $prefixes): array
    {
        $name = '[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+';
        $names = [];

        if ($fenced) {
            if (preg_match('/^\s*(?:#\s*)?(?:export\s+|-e\s+|-\s+)?('.$name.')=(?![$`]|"\$)/', $line, $match)) {
                $names[] = [$match[1], true];
            }

            return $names;
        }

        foreach ($this->codeSpans($line) as $span) {
            if (preg_match('/^('.$name.')(=.*)?$/', $span, $match)
                && (isset($match[2]) || in_array(strtok($match[1], '_'), $prefixes, true))) {
                $names[] = [$match[1], isset($match[2])];
            }
        }

        return $names;
    }

    /**
     * Every setting the application knows: the two example files, commented
     * lines included, and every env() a config file reads.
     *
     * @return list<string>
     */
    private function knownSettings(): array
    {
        $names = [];

        foreach (['.env.example', 'docker/.env.template'] as $file) {
            preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(self::ROOT.'/'.$file), $matches);
            $names = [...$names, ...$matches[1]];
        }

        foreach (glob(self::ROOT.'/config/*.php') ?: [] as $config) {
            preg_match_all('/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', (string) file_get_contents($config), $matches);
            $names = [...$names, ...$matches[1]];
        }

        return array_values(array_unique($names));
    }

    /**
     * Upper-case names the application spells as string literals or
     * constants: error codes, enum cases, permission names.
     *
     * @return array<string, true>
     */
    private function codesInApplication(): array
    {
        $codes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/app', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/[\'"]([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)[\'"]|(?:const|case)\s+([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)\b/', (string) file_get_contents($file->getPathname()), $matches);

            foreach ([...$matches[1], ...$matches[2]] as $code) {
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function codeSpans(string $line): array
    {
        preg_match_all('/`([^`]+)`/', $line, $matches);

        return array_map('trim', $matches[1]);
    }

    /**
     * Each line with its number and whether it sits inside a fenced block.
     *
     * @return list<array{int, string, bool}>
     */
    private function lines(string $doc): array
    {
        $lines = [];
        $fenced = false;

        foreach (file($doc) ?: [] as $index => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fenced = ! $fenced;

                continue;
            }

            $lines[] = [$index + 1, $line, $fenced];
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function documents(): array
    {
        $docs = array_map(fn (string $name): string => self::ROOT.'/'.$name, self::ROOT_DOCS);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/docs', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'md') {
                $docs[] = $file->getPathname();
            }
        }

        sort($docs);

        return $docs;
    }

    /**
     * Findings carry no line number, so the allow-list does not break when a
     * document above an entry changes; the message shows the line. The
     * product's name reads as <brand>, so this file does not spell it.
     */
    private function finding(string $doc, string $check, string $token, int $line): string
    {
        $token = str_ireplace((string) config('brand.name'), '<brand>', $token);

        return $this->relative($doc).": {$check}: {$token}".chr(0).$line;
    }

    /**
     * @param  list<string>  $findings
     */
    private function assertNone(array $findings, string $message): void
    {
        $left = [];

        foreach (array_unique($findings) as $finding) {
            [$entry, $line] = explode(chr(0), $finding);

            if (! in_array($entry, self::KNOWN_ELSEWHERE, true)) {
                $left[] = "{$entry} (line {$line})";
            }
        }

        $this->assertSame([], array_values($left), $message.":\n  ".implode("\n  ", $left));
    }

    private function relative(string $file): string
    {
        $root = str_replace('\\', '/', (string) realpath(self::ROOT));

        return ltrim(str_replace($root, '', str_replace('\\', '/', (string) realpath($file))), '/');
    }
}
