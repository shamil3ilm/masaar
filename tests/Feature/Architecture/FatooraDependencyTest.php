<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Who outside Fatoora reaches into it, so the list can only get shorter.
 *
 * Issuing is being taken apart: the lifecycle every jurisdiction shares -
 * lock, number, moment of issue, status, the invoice.issued webhook - moves
 * out of Submitter into an issuer that knows no country, and ZATCA keeps only
 * its seal. Every file outside app/Domains/Compliance/Fatoora that names a
 * Fatoora class is a place the rest of the platform still assumes it is
 * issuing for Saudi Arabia.
 *
 * The list below is a ratchet of file => the Fatoora classes it references,
 * relative to App\Domains\Compliance\Fatoora. The assertion is equality: a new
 * reference fails until it is listed, and one removed fails until it is
 * delisted, so the dependency is seen to shrink rather than assumed to.
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

    private const FATOORA = 'app/Domains/Compliance/Fatoora/';

    /**
     * Files outside Fatoora referencing it, and what they reference.
     *
     * @var array<string, string>
     */
    private const DECLARED = [
        'app/Console/Commands/CheckCertificateExpiry.php' => 'Helpers\\CertificatePem, Mail\\CertificateExpiringMail, Services\\CertificateService, Services\\CredentialStore',
        'app/Console/Commands/CleanupOfflineQueue.php' => 'Services\\OfflineQueue',
        'app/Console/Commands/FatooraGenerateCsr.php' => 'DTOs\\CsrData, Services\\CsrBuilder',
        'app/Console/Commands/FatooraOnboarding.php' => 'DTOs\\AddressData, DTOs\\InvoiceXmlData, DTOs\\QrCodeData, Services\\CertificateService, Services\\ComplianceSampleSet, Services\\EcdsaSigner, Services\\InvoiceHasher, Services\\QrCodeGenerator, Services\\QrCodeInjector, Services\\TlvEncoder, Services\\XadesSigner',
        'app/Console/Commands/FatooraSandboxFiling.php' => 'Services\\CredentialStore, Services\\SubmissionTracker, Services\\Submitter',
        'app/Console/Commands/FatooraSandboxTest.php' => 'DTOs\\CsrData, Services\\CsrBuilder',
        'app/Console/Commands/FatooraValidate.php' => 'Config\\FatooraConfig, DTOs\\AddressData, DTOs\\InvoiceXmlData, Services\\XmlBuilder',
        'app/Console/Commands/ProcessOfflineQueue.php' => 'Client\\FatooraClient, DTOs\\FatooraResponse, Services\\Connectivity, Services\\CredentialStore, Services\\OfflineQueue',
        'app/Console/Commands/RecoverSubmissions.php' => 'Jobs\\ProcessFatooraSubmission, Models\\InvoiceSubmission, Services\\SubmissionLedger',
        'app/Console/Commands/ReportOverdueSubmissions.php' => 'Models\\InvoiceSubmission, Services\\DeadlineReport',
        'app/Console/Commands/RotateCredentialKey.php' => 'Services\\CredentialStore',
        'app/Console/Commands/VerifyHashChain.php' => 'Config\\FatooraConfig, Models\\ChainEntry',
        'app/Domains/Auth/Http/Controllers/AccountController.php' => 'Services\\QrCodeRenderer',
        'app/Domains/Auth/Http/Controllers/AuthController.php' => 'Enums\\ErrorCode',
        'app/Domains/Auth/Http/Controllers/TwoFactorController.php' => 'Services\\QrCodeRenderer',
        'app/Domains/Billing/Services/Billing.php' => 'Services\\InvoiceIssuer',
        // The issuance guards every country passes. They are generic and live
        // in Fatoora only for now; moving them out removes this line.
        'app/Domains/Compliance/Peppol/Services/UaeInvoiceIssuer.php' => 'Exceptions\\FatooraException, Services\\FilingClearance, Services\\IssuanceNotifier, Services\\KillSwitch',
        'app/Domains/Invoice/Http/Controllers/PortalInvoiceFilingController.php' => 'Services\\InvoiceIssuer, Services\\QrCodeRenderer',
        'app/Domains/Invoice/Http/Requests/CreateInvoiceRequest.php' => 'Config\\FatooraConfig',
        'app/Domains/Invoice/Models/Invoice.php' => 'Models\\InvoiceSubmission',
        'app/Domains/Invoice/Services/CadesSignature.php' => 'Exceptions\\SigningException, Services\\EcdsaSigner',
        'app/Domains/Invoice/Services/InvoiceNumbering.php' => 'Helpers\\FatooraTime',
        'app/Domains/Invoice/Services/InvoicePdf.php' => 'Exceptions\\SigningException, Helpers\\CertificatePem, Services\\CredentialStore, Services\\QrCodeRenderer',
        'app/Domains/Invoice/Services/InvoiceTotals.php' => 'Config\\FatooraConfig',
        'app/Domains/Invoice/Services/NumberingSettings.php' => 'Helpers\\FatooraTime',
        'app/Domains/Invoice/Services/PdfStamp.php' => 'Exceptions\\SigningException, Services\\CertificateChain',
        'app/Domains/Licensing/Services/LicenseValidationService.php' => 'Helpers\\LogSanitizer',
        'app/Domains/Organization/Http/Controllers/CustomerPortalController.php' => 'DTOs\\SubmissionFilterData, Services\\CertificateService, Services\\CredentialStore, Services\\SubmissionReport',
        'app/Domains/Organization/Models/Branch.php' => 'DTOs\\AddressData, DTOs\\CsrData',
        'app/Domains/Organization/Models/Organization.php' => 'DTOs\\AddressData',
        'app/Domains/Organization/Services/BranchService.php' => 'Services\\CredentialStore',
        'app/Domains/Organization/Services/SetupChecklist.php' => 'Exceptions\\FatooraException, Services\\FilingClearance',
        'app/Domains/Pipeline/Services/PipelineService.php' => 'DTOs\\IssueOutcome, Exceptions\\FatooraException, Services\\InvoiceIssuer, Services\\OfflineQueue',
        'app/Domains/Platform/Http/Controllers/AdminController.php' => 'Enums\\RequeueOutcome, Services\\Requeuer',
        'app/Domains/Platform/Http/Controllers/AdminDashboardController.php' => 'Enums\\RequeueOutcome, Services\\CircuitBreaker, Services\\Connectivity, Services\\Requeuer',
        'app/Domains/Platform/Http/Controllers/DashboardController.php' => 'Services\\SubmissionReport',
        'app/Domains/Platform/Services/IssueDetector.php' => 'Services\\CircuitBreaker, Services\\Connectivity, Services\\OfflineQueue',
        'app/Domains/Platform/Services/MetricsCollector.php' => 'Services\\CircuitBreaker',
        'app/Domains/Platform/Services/OrganizationReport.php' => 'Services\\CertificateService, Services\\CredentialStore',
        'app/Domains/Platform/Services/PlatformStatus.php' => 'Services\\CertificateService, Services\\CircuitBreaker, Services\\CredentialStore',
        'app/Domains/Platform/Services/QueueReport.php' => 'Services\\OfflineQueue',
        'app/Domains/Platform/Services/TenantDashboard.php' => 'Services\\CertificateService, Services\\ChainReport, Services\\CircuitBreaker, Services\\CredentialStore, Services\\QueueBacklog, Services\\SubmissionReport',
        'app/Http/Responses/ApiResponse.php' => 'Exceptions\\CertificateException, Exceptions\\FatooraException',
        'app/Logging/RedactSecrets.php' => 'Helpers\\LogSanitizer',
        'app/Providers/AppServiceProvider.php' => 'Config\\FatooraConfig, Http\\Middleware\\FilingCleared, Services\\CircuitBreaker, Services\\TimestampValidator',
        'app/Providers/ComplianceServiceProvider.php' => 'FatooraEngine',
        'app/Providers/EventServiceProvider.php' => 'Events\\InvoiceCleared, Events\\InvoiceFailed, Events\\InvoiceRejected, Events\\InvoiceReported, Events\\InvoiceSubmitted, Events\\InvoiceWarning, Listeners\\DispatchInvoiceWebhook',
        'bootstrap/app.php' => 'Exceptions\\CertificateException, Exceptions\\FatooraException, Exceptions\\SigningException',
        'resources/views/pdf/invoice.blade.php' => 'Config\\FatooraConfig, Helpers\\FatooraTime',
        'resources/views/portal/invoices/create.blade.php' => 'Config\\FatooraConfig',
        'resources/views/portal/invoices/print.blade.php' => 'Config\\FatooraConfig, Helpers\\FatooraTime',
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

            preg_match_all('/App\\\\Domains\\\\Compliance\\\\Fatoora\\\\(\w+(?:\\\\\w+)*)/', (string) file_get_contents($path), $matches);

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
