<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps persistence out of controllers.
 *
 * A controller that queries models or the database directly holds rules no job,
 * command or other endpoint can reuse, and InvoiceController and the Pipeline
 * API already compute the same totals two ways. Queries belong in a service or
 * a query class the controller calls.
 *
 * The list below is a ratchet: the assertion is equality, so a controller that
 * starts querying fails the build until it is listed, and one that stops fails
 * until it is delisted.
 */
class ControllerQueryTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    private const QUERY_METHODS = 'query|where|whereIn|whereNull|whereNotNull|whereHas|find|findOrFail|first|firstOrFail|firstOrCreate|updateOrCreate|create|count|sum|avg|max|min|pluck|exists|with|withCount|withoutGlobalScope|withoutGlobalScopes|withTrashed|latest|oldest|orderBy|select|selectRaw|join|groupBy|paginate|get|all|destroy|insert|upsert|chunk|cursor';

    /**
     * Controllers that still query persistence directly.
     */
    private const DECLARED = [
        'Domains/Auth/Http/Controllers/AuthController.php',
        'Domains/Compliance/FTA/Http/Controllers/FtaController.php',
        'Domains/Compliance/Fatoora/Http/Controllers/ComplianceController.php',
        'Domains/Invoice/Http/Controllers/InvoiceController.php',
        'Domains/Licensing/Http/Controllers/LicenseController.php',
        'Domains/Organization/Http/Controllers/CustomerPortalController.php',
        'Domains/Organization/Http/Controllers/OrganizationController.php',
        'Domains/Pipeline/Http/Controllers/PipelineController.php',
        'Domains/Platform/Http/Controllers/AdminController.php',
        'Domains/Platform/Http/Controllers/AdminDashboardController.php',
        'Domains/Platform/Http/Controllers/DashboardController.php',
        'Domains/Platform/Http/Controllers/MetricsController.php',
        'Domains/Webhook/Http/Controllers/WebhookController.php',
    ];

    public function test_controllers_do_not_query_directly(): void
    {
        $found = [];

        foreach ($this->controllers() as $path) {
            if ($this->queries((string) file_get_contents($path))) {
                $found[] = $this->relative($path);
            }
        }

        sort($found);

        $this->assertSame(self::DECLARED, $found, $this->explain($found));
    }

    private function queries(string $source): bool
    {
        if (preg_match('/\bDB::/', $source) === 1) {
            return true;
        }

        preg_match_all('/^use App\\\\Domains\\\\\w+\\\\Models\\\\(\w+)(?: as (\w+))?;/m', $source, $imports, PREG_SET_ORDER);

        foreach ($imports as $import) {
            $name = ($import[2] ?? '') !== '' ? $import[2] : $import[1];

            if (preg_match('/\b'.preg_quote($name, '/').'::(?:'.self::QUERY_METHODS.')\(/', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $found
     */
    private function explain(array $found): string
    {
        $added = array_values(array_diff($found, self::DECLARED));
        $removed = array_values(array_diff(self::DECLARED, $found));

        return "Controllers querying directly changed.\n"
            .($added === [] ? '' : "Now querying (move it into a service): \n  ".implode("\n  ", $added)."\n")
            .($removed === [] ? '' : "No longer querying (delist): \n  ".implode("\n  ", $removed)."\n");
    }

    /**
     * @return list<string>
     */
    private function controllers(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::APP));

        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if ($file->isFile() && str_ends_with($path, 'Controller.php') && str_contains($path, '/Controllers/')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        $root = str_replace('\\', '/', (string) realpath(self::APP)).'/';

        return str_replace($root, '', str_replace('\\', '/', (string) realpath($path)));
    }
}
