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
 * Compliance holds one module per country and one per network, each with
 * models of its own, and each counts as a domain here:
 * Compliance\Countries\SaudiArabia's models are no more
 * Compliance\Countries\UnitedArabEmirates' to import than Invoice's are, and
 * a country importing Compliance\Networks\Peppol's is listed like any other
 * domain's. Matching only the segment after Domains\ missed
 * every such import, since
 * App\Domains\Compliance\Countries\SaudiArabia\Models\X has no Models segment
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
    private const NESTED = ['Compliance/Countries/SaudiArabia', 'Compliance/Countries/UnitedArabEmirates', 'Compliance/FTA', 'Compliance/Networks/Peppol'];

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
        // A person's memberships are the Organization's Membership pivot, so
        // one attached through either side records who added them.
        'Domains/Auth/Models/User.php' => 'Organization',
        'Domains/Billing/Models/Payment.php' => 'Invoice',
        'Domains/Billing/Services/Billing.php' => 'Invoice, Licensing',
        'Domains/Billing/Services/RenewalReminders.php' => 'Licensing',
        // Checks each till's certificate, an EGS unit being the Organization's.
        'Domains/Compliance/Countries/SaudiArabia/Console/CheckCertificateExpiry.php' => 'Organization',
        // Moved from app/Console/Commands, where the import was not counted.
        'Domains/Compliance/Countries/SaudiArabia/Console/FatooraSandboxFiling.php' => 'Invoice',
        // Finds the retiring tills, an EGS unit being the Organization's.
        'Domains/Compliance/Countries/SaudiArabia/Console/FinishRetirements.php' => 'Organization',
        'Domains/Compliance/Countries/SaudiArabia/Console/ProcessOfflineQueue.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Console/VerifyHashChain.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Document/DocumentBuilder.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/DuplicateDetector.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/InvoiceVerdict.php' => 'Invoice',
        // Which simplified invoices are past ZATCA's 24 hours unreported.
        'Domains/Compliance/Countries/SaudiArabia/Filing/LateReporting.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/OfflineFallback.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/OfflineQueue.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/SubmissionGuard.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/SubmissionRate.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/SubmissionTracker.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Filing/Submitter.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Http/Controllers/BranchOnboardingController.php' => 'Organization',
        // A till's certificate from the portal: connecting it, renewing it.
        'Domains/Compliance/Countries/SaudiArabia/Http/Controllers/PortalCertificateRenewalController.php' => 'Organization',
        // A branch's tills from the portal: adding one, retiring one.
        'Domains/Compliance/Countries/SaudiArabia/Http/Controllers/PortalTillController.php' => 'Organization',
        // A till is the organization's EGS unit: listed, added and onboarded
        // over the API.
        'Domains/Compliance/Countries/SaudiArabia/Http/Controllers/UnitController.php' => 'Organization',
        'Domains/Compliance/Countries/SaudiArabia/Issuing/ChainRecorder.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Issuing/InvoiceIssuer.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Issuing/IssuingStep.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Models/ChainEntry.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Models/InvoiceSubmission.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Models/OfflineItem.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Models/SubmissionIdempotency.php' => 'Invoice',
        // Saudi Arabia's engine module: issues and files an invoice.
        'Domains/Compliance/Countries/SaudiArabia/Module.php' => 'Invoice',
        // Onboarding writes where the till it onboards stands.
        'Domains/Compliance/Countries/SaudiArabia/Onboarding/CertificateRenewal.php' => 'Organization',
        'Domains/Compliance/Countries/SaudiArabia/Onboarding/OrganizationOnboarding.php' => 'Organization',
        'Domains/Compliance/Countries/SaudiArabia/Onboarding/UnitOnboarding.php' => 'Organization',
        'Domains/Compliance/Countries/SaudiArabia/Onboarding/UnitRetirement.php' => 'Organization',
        // An invoice's page and the copies of it a buyer is given; whether a
        // first invoice was issued, for the setup steps.
        'Domains/Compliance/Countries/SaudiArabia/Presentation/Presentation.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Presentation/PrintedTerms.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Presentation/ReceiptContent.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Presentation/SetupSteps.php' => 'Invoice',
        // Each till, with the certificates it has held.
        'Domains/Compliance/Countries/SaudiArabia/Reports/CertificateVersions.php' => 'Organization',
        'Domains/Compliance/Countries/SaudiArabia/Reports/DailyClose.php' => 'Invoice, Organization',
        'Domains/Compliance/Countries/SaudiArabia/Reports/DeadlineReport.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Rules/InvoiceValidator.php' => 'Invoice',
        // What a saved invoice lacks of the Arabic it must carry.
        'Domains/Compliance/Countries/SaudiArabia/Rules/LanguageRules.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Rules/VatPeriodTracker.php' => 'Invoice',
        'Domains/Compliance/Countries/SaudiArabia/Signing/SigningCredentials.php' => 'Invoice, Organization',
        // A UAE document is built from an invoice and its organization's UAE
        // settings, and stored as the network's PeppolDocument.
        'Domains/Compliance/Countries/UnitedArabEmirates/Document/PeppolPreparer.php' => 'Compliance\Networks\Peppol, Invoice, Organization',
        'Domains/Compliance/Countries/UnitedArabEmirates/Document/PintMapper.php' => 'Invoice, Organization',
        // The API's document listing and download: the network's PeppolDocument.
        'Domains/Compliance/Countries/UnitedArabEmirates/Http/Controllers/UaeDocumentController.php' => 'Compliance\Networks\Peppol',
        // Issues a UAE invoice through the lifecycle every country shares, and
        // queues its PeppolDocument.
        'Domains/Compliance/Countries/UnitedArabEmirates/Issuing/InvoiceIssuer.php' => 'Compliance\Networks\Peppol, Invoice',
        // The UAE's step in that lifecycle: guards, checks, the stored document.
        'Domains/Compliance/Countries/UnitedArabEmirates/Issuing/IssuingStep.php' => 'Compliance\Networks\Peppol, Invoice',
        // The UAE's engine module: issues and sends an invoice.
        'Domains/Compliance/Countries/UnitedArabEmirates/Module.php' => 'Invoice',
        // An invoice's page; whether a first invoice was issued, for the setup steps.
        'Domains/Compliance/Countries/UnitedArabEmirates/Presentation/Presentation.php' => 'Invoice',
        'Domains/Compliance/Countries/UnitedArabEmirates/Presentation/SetupSteps.php' => 'Invoice',
        // An organization's Peppol documents, for its screens and dashboards.
        'Domains/Compliance/Countries/UnitedArabEmirates/Reports/UaeDocuments.php' => 'Compliance\Networks\Peppol',
        // The 14 days are counted from an invoice's supply and issue dates,
        // and a late PeppolDocument says so.
        'Domains/Compliance/Countries/UnitedArabEmirates/Rules/IssueDeadline.php' => 'Compliance\Networks\Peppol, Invoice',
        // Answers that a UAE invoice lacks no Arabic.
        'Domains/Compliance/Countries/UnitedArabEmirates/Rules/LanguageRules.php' => 'Invoice',
        // Drafts a UAE invoice through the shared InvoiceDrafter, for the API and the portal.
        'Domains/Compliance/Countries/UnitedArabEmirates/Support/UaeDrafts.php' => 'Invoice',
        // Keeps a UAE seller's tax identity on its UAE ComplianceProfile.
        'Domains/Compliance/Countries/UnitedArabEmirates/Support/UaeSellerDetails.php' => 'Organization',
        // The compliance engine issues an invoice in its organization's jurisdiction.
        'Domains/Compliance/Engine/Contracts/IssueNotices.php' => 'Invoice',
        'Domains/Compliance/Engine/Contracts/LanguageRules.php' => 'Invoice',
        'Domains/Compliance/Engine/Contracts/Presentation.php' => 'Invoice',
        'Domains/Compliance/Engine/Contracts/Transmission.php' => 'Invoice',
        'Domains/Compliance/Engine/Services/ComplianceEngine.php' => 'Invoice',
        'Domains/Compliance/Engine/Services/JurisdictionResolver.php' => 'Invoice',
        // The receiver of an inbound document is the endpoint on a compliance profile.
        'Domains/Compliance/Networks/Peppol/Receiving/InboundDocuments.php' => 'Organization',
        'Domains/Invoice/Models/Invoice.php' => 'Compliance\Countries\SaudiArabia, Organization',
        'Domains/Licensing/Http/Middleware/RequireLicenseFeature.php' => 'Invoice',
        'Domains/Organization/Models/Branch.php' => 'Invoice',
        'Domains/Organization/Models/ComplianceProfile.php' => 'Invoice',
        'Domains/Organization/Models/Organization.php' => 'Invoice',
        'Domains/Organization/Services/SetupChecklist.php' => 'Invoice',
        'Domains/Pipeline/Services/PipelineResult.php' => 'Invoice',
        'Domains/Pipeline/Services/PipelineService.php' => 'Invoice, Organization',
        // The plan level reads the organization's licences, found through
        // LicenseValidationService; the partner API hands it the request's.
        'Domains/Platform/Access/Access.php' => 'Licensing',
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
