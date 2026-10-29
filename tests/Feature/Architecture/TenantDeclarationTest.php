<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The commands and jobs that run across every organization without
 * declaring one, listed, so the list is seen to change.
 *
 * A queued job, or a command of the application's own, that declares no
 * tenant is refused its tenant-scoped queries (TenantScope). Work that spans
 * organizations by design - platform maintenance, a report across tenants -
 * carries #[RunsAcrossTenants] with the reason instead. The assertion is
 * equality: a new one fails until it is listed here, where a reviewer sees
 * it, and one removed fails until it is delisted.
 *
 * Before adding one, ask whether the work is really for every organization:
 * work for one belongs inside TenantResolver::runAs() (a job's ActsFor
 * middleware), and a single query that must read across tenants says
 * acrossTenants() itself (TenantBypassTest).
 */
class TenantDeclarationTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * Path below app/ => the reason the class gives.
     *
     * @var array<string, string>
     */
    private const DECLARED = [
        'Domains/Compliance/Countries/SaudiArabia/Console/CleanupOfflineQueue.php' => 'Prunes the offline items of every organization once they are filed or cancelled and past their retention.',
    ];

    public function test_cross_tenant_work_is_listed(): void
    {
        $found = [];

        foreach ($this->files() as $relative => $body) {
            if (preg_match('/#\[RunsAcrossTenants\(\s*\'(.*?)\'\s*\)\]/s', $body, $m)) {
                $found[$relative] = stripslashes($m[1]);
            }
        }

        $this->assertSame(self::DECLARED, $found, "Work declared across tenants changed. Found:\n".var_export($found, true));
    }

    public function test_every_declaration_gives_a_reason(): void
    {
        foreach (self::DECLARED as $relative => $reason) {
            $this->assertGreaterThanOrEqual(5, str_word_count($reason), "{$relative} says too little about why it reads every organization.");
        }
    }

    /**
     * @return array<string, string> path below app/ => contents
     */
    private function files(): array
    {
        $files = [];
        $root = str_replace('\\', '/', (string) realpath(self::APP)).'/';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = str_replace('\\', '/', (string) realpath($file->getPathname()));
                $files[str_replace($root, '', $path)] = (string) file_get_contents($path);
            }
        }

        ksort($files);

        return $files;
    }
}
