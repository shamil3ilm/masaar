<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps each domain's models its own.
 *
 * A domain that imports another domain's Eloquent models couples itself to
 * that domain's tables and relations; a change there breaks code that never
 * asked for it. Another domain's data should arrive through its services.
 *
 * Organization and User are the shared kernel every domain is scoped by and
 * acts as, so importing them is not counted.
 *
 * The list below is a ratchet of file => domains whose models it imports: the
 * assertion is equality, so a new cross-domain import fails the build until it
 * is listed, and a removed one fails until it is delisted.
 */
class CrossDomainModelTest extends TestCase
{
    private const DOMAINS = __DIR__.'/../../../app/Domains';

    private const SHARED = [
        'App\\Domains\\Organization\\Models\\Organization',
        'App\\Domains\\Auth\\Models\\User',
    ];

    /**
     * Files importing another domain's models, and which domains.
     *
     * @var array<string, string>
     */
    private const DECLARED = [
        'Domains/Compliance/ComplianceRouter.php' => 'Invoice, Organization',
        'Domains/Compliance/Contracts/ComplianceEngine.php' => 'Invoice, Organization',
        'Domains/Compliance/FTA/FtaEngine.php' => 'Invoice, Organization',
        'Domains/Compliance/FTA/Models/FtaSubmission.php' => 'Invoice',
        'Domains/Compliance/FTA/Services/FtaService.php' => 'Invoice',
        'Domains/Compliance/Fatoora/FatooraEngine.php' => 'Invoice, Organization',
        'Domains/Compliance/Fatoora/Http/Controllers/BranchOnboardingController.php' => 'Organization',
        'Domains/Compliance/Fatoora/Models/ChainEntry.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Models/InvoiceSubmission.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Models/OfflineItem.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Models/SubmissionIdempotency.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/ChainRecorder.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/DocumentBuilder.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/DuplicateDetector.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/InvoiceValidator.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/InvoiceVerdict.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/OfflineFallback.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/OfflineQueue.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/SubmissionGuard.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/SubmissionTracker.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/Submitter.php' => 'Invoice, Organization',
        'Domains/Compliance/Fatoora/Services/VatPeriodTracker.php' => 'Invoice',
        'Domains/Invoice/Models/Invoice.php' => 'Organization',
        'Domains/Organization/Models/Branch.php' => 'Invoice',
        'Domains/Organization/Models/ComplianceProfile.php' => 'Invoice',
        'Domains/Organization/Models/Organization.php' => 'Invoice',
        'Domains/Pipeline/Services/PipelineNotifier.php' => 'Invoice',
        'Domains/Pipeline/Services/PipelineResult.php' => 'Invoice',
        'Domains/Pipeline/Services/PipelineService.php' => 'Invoice, Organization',
    ];

    public function test_domains_keep_their_models_to_themselves(): void
    {
        $found = [];

        foreach ($this->files() as $path) {
            $relative = $this->relative($path);
            $own = explode('/', $relative)[1];

            preg_match_all('/^use (App\\\\Domains\\\\(\w+)\\\\Models\\\\\w+)(?: as \w+)?;/m', (string) file_get_contents($path), $imports, PREG_SET_ORDER);

            $others = [];

            foreach ($imports as [, $class, $domain]) {
                if ($domain !== $own && ! in_array($class, self::SHARED, true)) {
                    $others[$domain] = true;
                }
            }

            if ($others !== []) {
                $domains = array_keys($others);
                sort($domains);
                $found[$relative] = implode(', ', $domains);
            }
        }

        ksort($found);

        $this->assertSame(self::DECLARED, $found, "Cross-domain model imports changed. Found:\n".var_export($found, true));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DOMAINS));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        $root = str_replace('\\', '/', (string) realpath(self::DOMAINS.'/..')).'/';

        return str_replace($root, '', str_replace('\\', '/', (string) realpath($path)));
    }
}
