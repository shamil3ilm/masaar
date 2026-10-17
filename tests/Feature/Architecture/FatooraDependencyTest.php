<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Who outside the Saudi module reaches into it, so the list can only get
 * shorter.
 *
 * Issuing is being taken apart: the lifecycle every jurisdiction shares -
 * lock, number, moment of issue, status, the invoice.issued webhook - moves
 * out of Submitter into an issuer that knows no country, and ZATCA keeps only
 * its seal. Every file outside app/Domains/Compliance/Countries/SaudiArabia
 * that names one of its classes is a place the rest of the platform still
 * assumes it is issuing for Saudi Arabia.
 *
 * The list below is a ratchet of file => the Saudi classes it references,
 * relative to App\Domains\Compliance\Countries\SaudiArabia. The assertion is
 * equality: a new reference fails until it is listed, and one removed fails
 * until it is delisted, so the dependency is seen to shrink rather than
 * assumed to.
 *
 * Any reference counts - a use statement, a fully-qualified name, a Blade
 * view's - because each is a dependency whatever its spelling. Tests are not
 * scanned: they are allowed to reach into what they test.
 */
class FatooraDependencyTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** Where the platform's own PHP lives, Blade views included. */
    private const SCANNED = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes'];

    private const FATOORA = 'app/Domains/Compliance/Countries/SaudiArabia/';

    /**
     * Files outside the Saudi module referencing it, and what they reference.
     *
     * @var array<string, string>
     */
    private const DECLARED = [
        // The exception the shared clearance and the kill switch still throw.
        'app/Domains/Compliance/Peppol/Services/UaeJurisdiction.php' => 'Exceptions\\FatooraException',
        'app/Domains/Invoice/Http/Requests/CreateInvoiceRequest.php' => 'Rules\\FatooraConfig',
        'app/Domains/Invoice/Models/Invoice.php' => 'Models\\InvoiceSubmission',
        'app/Domains/Invoice/Services/CadesSignature.php' => 'Exceptions\\SigningException, Signing\\EcdsaSigner',
        'app/Domains/Invoice/Services/InvoicePdf.php' => 'Exceptions\\SigningException, Signing\\CertificatePem, Signing\\CredentialStore',
        'app/Domains/Invoice/Services/PdfStamp.php' => 'Exceptions\\SigningException, Signing\\CertificateChain',
        'app/Domains/Organization/Http/Controllers/CustomerPortalController.php' => 'DTOs\\SubmissionFilterData, Reports\\SubmissionReport, Signing\\CertificateService, Signing\\CredentialStore',
        'app/Domains/Organization/Models/Branch.php' => 'DTOs\\AddressData, DTOs\\CsrData',
        'app/Domains/Organization/Models/Organization.php' => 'DTOs\\AddressData',
        'app/Domains/Organization/Services/BranchService.php' => 'Signing\\CredentialStore',
        // Moved out of the Saudi module; still throws FatooraException, which its callers catch.
        'app/Domains/Organization/Services/FilingClearance.php' => 'Exceptions\\FatooraException',
        'app/Domains/Organization/Services/SetupChecklist.php' => 'Exceptions\\FatooraException',
        'app/Domains/Pipeline/Services/PipelineService.php' => 'Exceptions\\FatooraException',
        'app/Domains/Platform/Http/Controllers/AdminController.php' => 'Enums\\RequeueOutcome, Filing\\Requeuer',
        'app/Domains/Platform/Http/Controllers/AdminDashboardController.php' => 'Enums\\RequeueOutcome, Filing\\CircuitBreaker, Filing\\Connectivity, Filing\\Requeuer',
        'app/Domains/Platform/Http/Controllers/DashboardController.php' => 'Reports\\SubmissionReport',
        'app/Domains/Platform/Services/IssueDetector.php' => 'Filing\\CircuitBreaker, Filing\\Connectivity, Filing\\OfflineQueue',
        // Moved out of the Saudi module; still throws FatooraException, which its callers catch.
        'app/Domains/Platform/Services/KillSwitch.php' => 'Exceptions\\FatooraException',
        'app/Domains/Platform/Services/MetricsCollector.php' => 'Filing\\CircuitBreaker',
        'app/Domains/Platform/Services/OrganizationReport.php' => 'Signing\\CertificateService, Signing\\CredentialStore',
        'app/Domains/Platform/Services/PlatformStatus.php' => 'Filing\\CircuitBreaker, Signing\\CertificateService, Signing\\CredentialStore',
        'app/Domains/Platform/Services/QueueReport.php' => 'Filing\\OfflineQueue',
        'app/Domains/Platform/Services/TenantDashboard.php' => 'Filing\\CircuitBreaker, Reports\\ChainReport, Reports\\QueueBacklog, Reports\\SubmissionReport, Signing\\CertificateService, Signing\\CredentialStore',
        'app/Http/Responses/ApiResponse.php' => 'Exceptions\\CertificateException, Exceptions\\FatooraException',
        'app/Providers/AppServiceProvider.php' => 'Filing\\CircuitBreaker, Filing\\TimestampValidator, Http\\Middleware\\FilingCleared, Rules\\FatooraConfig',
        'app/Providers/EventServiceProvider.php' => 'Events\\InvoiceCleared, Events\\InvoiceFailed, Events\\InvoiceRejected, Events\\InvoiceReported, Events\\InvoiceSubmitted, Events\\InvoiceWarning, Listeners\\DispatchInvoiceWebhook',
        'bootstrap/app.php' => 'Exceptions\\CertificateException, Exceptions\\FatooraException, Exceptions\\SigningException',
        // The Saudi module's own provider, registering it with the compliance engine.
        'bootstrap/providers.php' => 'ModuleServiceProvider',
        'resources/views/pdf/invoice.blade.php' => 'Rules\\FatooraConfig, Support\\FatooraTime',
        'resources/views/portal/invoices/create.blade.php' => 'Rules\\FatooraConfig',
        'resources/views/portal/invoices/print.blade.php' => 'Rules\\FatooraConfig, Support\\FatooraTime',
        'routes/api/partner.php' => 'Http\\Controllers\\ComplianceController',
        'routes/api/tenant.php' => 'Http\\Controllers\\BranchOnboardingController, Http\\Controllers\\ComplianceController, Http\\Controllers\\OnboardingController',
        'routes/web.php' => 'Http\\Controllers\\AdminAlertController, Http\\Controllers\\AdminKillSwitchController, Http\\Controllers\\PortalZatcaController',
    ];

    public function test_fatoora_dependents_only_shrink(): void
    {
        $found = [];

        foreach ($this->files() as $relative => $path) {
            if (str_starts_with($relative, self::FATOORA)) {
                continue;
            }

            preg_match_all('/App\\\\Domains\\\\Compliance\\\\Countries\\\\SaudiArabia\\\\(\w+(?:\\\\\w+)*)/', (string) file_get_contents($path), $matches);

            $classes = array_values(array_unique($matches[1]));

            if ($classes !== []) {
                sort($classes);
                $found[$relative] = implode(', ', $classes);
            }
        }

        ksort($found);

        $this->assertSame(self::DECLARED, $found, "References to Fatoora from outside it changed. Found:\n".var_export($found, true));
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function files(): array
    {
        $root = str_replace('\\', '/', (string) realpath(self::ROOT)).'/';
        $files = [];

        foreach (self::SCANNED as $directory) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$directory));

            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $path = str_replace('\\', '/', (string) $file->getRealPath());
                    $files[str_replace($root, '', $path)] = $path;
                }
            }
        }

        return $files;
    }
}
