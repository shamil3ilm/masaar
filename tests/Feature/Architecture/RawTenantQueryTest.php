<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps raw access to tenant tables deliberate.
 *
 * BelongsToTenant's global scope is an Eloquent feature. DB::table() never
 * passes through it, so a raw query on a tenant table is outside the platform's
 * isolation guarantee and is safe only for as long as whoever wrote it
 * remembered a where('org_id', ...) — which is the arrangement C-4 existed to
 * end.
 *
 * Raw is still right in places: cross-tenant platform reporting has nothing to
 * scope to, and hydrating models to produce a COUNT is waste. The rule is not
 * "never raw", it is that every instance is a decision somebody made and wrote
 * down, so a new one cannot arrive unnoticed.
 */
class RawTenantQueryTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    /**
     * Tables carrying org_id, whose models apply the tenant scope.
     */
    private const TENANT_TABLES = [
        'invoices',
        'invoice_submissions',
        'submission_idempotency',
        'offline_queue',
        'hash_chain_state',
        'hash_chain_history',
        'fta_submissions',
        'webhooks',
        'branches',
        'compliance_profiles',
    ];

    /**
     * Files permitted to query a tenant table directly, and why.
     *
     * Adding a file here is the act of declaring the query cross-tenant or
     * console-only. If the reason is "it was easier", convert it instead.
     */
    private const ALLOWED = [
        // Platform operators look across every tenant by definition; that is
        // what the console is for.
        'Domains/Platform/Http/Controllers/AdminController.php' => 'platform admin, cross-tenant by design',
        'Domains/Platform/Http/Controllers/AdminDashboardController.php' => 'platform admin, cross-tenant by design',

        // Prometheus scrapes one set of figures for the whole deployment.
        'Domains/Platform/Http/Controllers/MetricsController.php' => 'platform-wide metrics, no tenant to scope to',

        // Billing totals a licence across the organizations it covers.
        'Domains/Licensing/Services/UsageReportingService.php' => 'licence-wide usage rollup, spans organizations',

        // Finding which organizations have queued work is the one genuinely
        // cross-tenant step; the draining that follows runs inside
        // TenantResolver::runAs(), so it is scoped per organization.
        'Console/Commands/ProcessOfflineQueue.php' => 'discovers tenants with pending work, then scopes per tenant',

    ];

    public function test_no_undeclared_raw_tenant_queries(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $path) {
            $relative = $this->relative($path);

            if (array_key_exists($relative, self::ALLOWED)) {
                continue;
            }

            $body = (string) file_get_contents($path);

            foreach (self::TENANT_TABLES as $table) {
                if (preg_match('/DB::table\([\'"]'.$table.'[\'"]/', $body)) {
                    $offenders[] = "{$relative} queries {$table} directly";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders)."\n\n".
            'Raw queries skip the tenant scope. Use the model, or declare the file '.
            'in '.self::class.'::ALLOWED with the reason it is cross-tenant.');
    }

    /**
     * An allowlist outlives the code it describes. Once a file stops querying
     * raw, its entry is a claim about the codebase that is no longer true.
     */
    public function test_allowlist_has_no_stale_entries(): void
    {
        $stale = [];

        foreach (self::ALLOWED as $relative => $reason) {
            $path = self::APP.'/'.$relative;

            if (! file_exists($path)) {
                $stale[] = "{$relative} no longer exists";

                continue;
            }

            $body = (string) file_get_contents($path);
            $found = false;

            foreach (self::TENANT_TABLES as $table) {
                if (preg_match('/DB::table\([\'"]'.$table.'[\'"]/', $body)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $stale[] = "{$relative} no longer queries a tenant table — drop it from ALLOWED";
            }
        }

        $this->assertSame([], $stale, implode("\n", $stale));
    }

    /**
     * Path below app/, in forward slashes, so entries in ALLOWED read the same
     * on any platform.
     */
    private function relative(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);
        $position = strrpos($normalised, '/app/');

        return $position === false
            ? $normalised
            : substr($normalised, $position + 5);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
