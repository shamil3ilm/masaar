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
 * Compliance holds one engine per jurisdiction, each with models of its own,
 * and each engine counts as a domain here: Compliance\Fatoora's models are no
 * more Compliance\FTA's to import (Compliance\Peppol's, once renamed) than
 * Invoice's are. Matching only the segment after Domains\ missed every such
 * import, since App\Domains\Compliance\Fatoora\Models\X has no Models segment
 * directly under Compliance.
 *
 * The list below is a ratchet of file => domains whose models it imports: the
 * assertion is equality, so a new cross-domain import fails the build until it
 * is listed, and a removed one fails until it is delisted.
 */
class CrossDomainModelTest extends TestCase
{
    private const DOMAINS = __DIR__.'/../../../app/Domains';

    /**
     * Engines inside a domain that are domains in their own right.
     */
    private const NESTED = ['Compliance/Fatoora', 'Compliance/FTA', 'Compliance/Peppol'];

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
        'Domains/Billing/Models/Payment.php' => 'Invoice',
        'Domains/Billing/Services/Billing.php' => 'Invoice, Licensing',
        'Domains/Billing/Services/RenewalReminders.php' => 'Licensing',
        'Domains/Compliance/ComplianceRouter.php' => 'Invoice, Organization',
        'Domains/Compliance/Contracts/ComplianceEngine.php' => 'Invoice, Organization',
        // Moved from app/Console/Commands, where the import was not counted.
        'Domains/Compliance/Fatoora/Console/FatooraSandboxFiling.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Console/ProcessOfflineQueue.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Console/VerifyHashChain.php' => 'Invoice',
        'Domains/Compliance/Fatoora/FatooraEngine.php' => 'Invoice, Organization',
        'Domains/Compliance/Fatoora/Http/Controllers/BranchOnboardingController.php' => 'Organization',
        'Domains/Compliance/Fatoora/Models/ChainEntry.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Models/InvoiceSubmission.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Models/OfflineItem.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Models/SubmissionIdempotency.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/ChainRecorder.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/DeadlineReport.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/DocumentBuilder.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/DuplicateDetector.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/InvoiceIssuer.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/InvoiceValidator.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/InvoiceVerdict.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/IssuanceNotifier.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/OfflineFallback.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/OfflineQueue.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/SigningCredentials.php' => 'Invoice, Organization',
        'Domains/Compliance/Fatoora/Services/SubmissionGuard.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/SubmissionTracker.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/Submitter.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/VatPeriodTracker.php' => 'Invoice',
        'Domains/Compliance/Fatoora/Services/ZatcaJurisdiction.php' => 'Invoice',
        'Domains/Compliance/Peppol/PeppolEngine.php' => 'Invoice, Organization',
        // The receiver of an inbound document is the endpoint on a compliance profile.
        'Domains/Compliance/Peppol/Services/InboundDocuments.php' => 'Organization',
        // A UAE document is built from an invoice and its organization's UAE settings.
        'Domains/Compliance/Peppol/Services/PeppolPreparer.php' => 'Invoice, Organization',
        'Domains/Compliance/Peppol/Services/PintMapper.php' => 'Invoice, Organization',
        // Drafts a UAE invoice through the shared InvoiceDrafter, for the API and the portal.
        'Domains/Compliance/Peppol/Services/UaeDrafts.php' => 'Invoice',
        // Issues a UAE invoice through the lifecycle every country shares.
        'Domains/Compliance/Peppol/Services/UaeInvoiceIssuer.php' => 'Invoice',
        // The UAE's step in that lifecycle: guards, checks, the stored document.
        'Domains/Compliance/Peppol/Services/UaeJurisdiction.php' => 'Invoice',
        'Domains/Invoice/Models/Invoice.php' => 'Compliance\Fatoora, Organization',
        'Domains/Licensing/Http/Middleware/RequireLicenseFeature.php' => 'Invoice',
        'Domains/Organization/Models/Branch.php' => 'Invoice',
        'Domains/Organization/Models/ComplianceProfile.php' => 'Invoice',
        'Domains/Organization/Models/Organization.php' => 'Invoice',
        'Domains/Organization/Services/SetupChecklist.php' => 'Invoice',
        'Domains/Pipeline/Services/PipelineResult.php' => 'Invoice',
        'Domains/Pipeline/Services/PipelineService.php' => 'Invoice, Organization',
    ];

    public function test_domains_keep_their_models_to_themselves(): void
    {
        $found = [];

        foreach ($this->files() as $path) {
            $relative = $this->relative($path);
            $own = $this->domainOf(substr($relative, strlen('Domains/')));

            preg_match_all('/^use (App\\\\Domains\\\\(\w+(?:\\\\\w+)*?)\\\\Models\\\\\w+)(?: as \w+)?;/m', (string) file_get_contents($path), $imports, PREG_SET_ORDER);

            $others = [];

            foreach ($imports as [, $class, $namespace]) {
                $domain = $this->domainOf(str_replace('\\', '/', $namespace));

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
     * The domain a path under app/Domains belongs to, written as a namespace:
     * its first segment, or the engine in NESTED that holds it.
     */
    private function domainOf(string $path): string
    {
        foreach (self::NESTED as $nested) {
            if ($path === $nested || str_starts_with($path, $nested.'/')) {
                return str_replace('/', '\\', $nested);
            }
        }

        return explode('/', $path)[0];
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
