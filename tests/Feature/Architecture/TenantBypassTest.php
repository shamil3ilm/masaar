<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every query that reads across tenants, counted, so the number can only be
 * seen to change.
 *
 * The tenant scope is stood down one query at a time - acrossTenants() on the
 * model or any builder of it, or withoutGlobalScope(TenantScope::class) - and
 * each is a decision to read another organization's rows. The uses grew from
 * 304 to 352 while nothing counted them. The total here is a ratchet: the
 * assertion is equality, so a new bypass fails until the count is raised in
 * the same change, where a reviewer sees it, and one removed fails until it is
 * lowered.
 *
 * Before raising it, ask whether the query needs it: one already filtered by
 * org_id under the request's own tenant does not, and work for one
 * organization in a command or job belongs inside TenantResolver::runAs().
 *
 * The callback form - a static switch that unscoped every query in a callback,
 * relations and listeners included - is gone, and must stay gone.
 */
class TenantBypassTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * The bypasses in app/, by the domain they are in: 341 in all.
     *
     * Billing and Books read the platform's own receivables and a customer's
     * documents across organizations; Compliance's commands and reports span
     * every organization by design; Invoice and Organization read numbering,
     * chains and tills an issuance or a till change must see whoever asks.
     */
    private const DECLARED = [
        'Billing' => 72,
        'Books' => 25,
        'Compliance' => 79,
        'Invoice' => 95,
        'Licensing' => 8,
        'Organization' => 48,
        'Support' => 10,
        'Webhook' => 4,
    ];

    public function test_tenant_bypasses_are_counted(): void
    {
        $found = [];

        foreach ($this->files() as $relative => $body) {
            $count = preg_match_all('/->acrossTenants\(|::acrossTenants\(|withoutGlobalScope\(\s*TenantScope::class/', $body);

            if ($count > 0) {
                $domain = preg_match('#^Domains/(\w+)/#', $relative, $m) ? $m[1] : explode('/', $relative)[0];
                $found[$domain] = ($found[$domain] ?? 0) + $count;
            }
        }

        ksort($found);

        $this->assertSame(self::DECLARED, $found, "Tenant bypasses changed. Found:\n".var_export($found, true)."\nTotal: ".array_sum($found));
    }

    public function test_no_callback_bypass_remains(): void
    {
        $found = [];

        foreach ($this->files() as $relative => $body) {
            if (preg_match('/withoutTenantScope\(|TenantScope::withoutScope\(/', $body)) {
                $found[] = $relative;
            }
        }

        $this->assertSame([], $found);
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
