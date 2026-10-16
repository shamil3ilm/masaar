<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Console;

use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\Console\Concerns\ChecksCompliance;
use App\Domains\Compliance\Fatoora\Console\Concerns\EncodesCsr;
use App\Domains\Compliance\Fatoora\Console\Concerns\FindsOpenSsl;
use App\Domains\Compliance\Fatoora\Console\Concerns\OnboardsLocally;
use App\Domains\Compliance\Fatoora\Console\Concerns\StampsSampleQr;
use App\Domains\Compliance\Fatoora\Console\Concerns\StoresCsids;
use App\Domains\Compliance\Fatoora\Console\Concerns\SubmitsWithPcsid;
use App\Domains\Compliance\Fatoora\Console\Concerns\WritesSecrets;
use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\ComplianceSampleSet;
use App\Domains\Compliance\Fatoora\Services\InvoiceHasher;
use App\Domains\Compliance\Fatoora\Services\QrCodeGenerator;
use App\Domains\Compliance\Fatoora\Services\QrCodeInjector;
use App\Domains\Compliance\Fatoora\Services\XadesSigner;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * ZATCA EGS Onboarding Command
 *
 * Handles the complete onboarding flow for Simulation/Production environments:
 * 1. Request Compliance CSID (CCSID) using OTP
 * 2. Generate and submit 6 compliance invoices
 * 3. Request Production CSID (PCSID)
 *
 * IMPORTANT: In Simulation/Production, you MUST submit all 6 invoice types
 * for compliance verification BEFORE you can obtain the PCSID.
 *
 * Usage:
 *   php artisan fatoora:onboard --step=ccsid --otp=<your-otp>
 *   php artisan fatoora:onboard --step=compliance
 *   php artisan fatoora:onboard --step=pcsid
 *   php artisan fatoora:onboard --step=full --otp=<your-otp>
 *
 * The endpoint paths are FatooraClient's. The requests are made here rather
 * than through FatooraClient or CsidOnboarding because what this reports is
 * the raw exchange - the HTTP status, the body, tokenType, each error's code
 * - which CsidResponse and FatooraResponse do not keep. The CSR is the PEM
 * file on disk, trimmed, rather than one CsrBuilder makes; the client's base
 * URL is fixed when it is built, before --target names the environment; and
 * a sandbox run retries a 5xx, which a production submission must not.
 */
class FatooraOnboarding extends Command
{
    use ChecksCompliance;
    use EncodesCsr;
    use FindsOpenSsl;
    use OnboardsLocally;
    use StampsSampleQr;
    use StoresCsids;
    use SubmitsWithPcsid;
    use WritesSecrets;

    protected $signature = 'fatoora:onboard
                            {--step=info : Step to execute (info|ccsid|compliance|pcsid|submit|full)}
                            {--otp= : One-Time Password from Fatoora Portal}
                            {--target= : Target environment (sandbox|local; a real organization onboards through the portal); default config fatoora.environment}
                            {--csr= : Path to CSR file (default: storage/app/zatca/taxpayer.csr)}
                            {--key= : Path to private key file (default: storage/app/zatca/taxpayer.key)}';

    protected $description = 'Complete ZATCA EGS onboarding with 6-invoice compliance check';

    /**
     * Local mode makes no API calls and signs against a self-signed
     * certificate; the real environments come from config, which is where the
     * rest of the platform reads them.
     */
    private const LOCAL = 'local';

    private string $environment;

    private ComplianceSampleSet $samples;

    private string $baseUrl;

    /**
     * The container's signing pipeline, not a second one wired here: a
     * command that builds its own signer drifts from the one DocumentBuilder
     * uses (CommandConstructionTest).
     */
    public function __construct(
        private readonly InvoiceHasher $hasher,
        private readonly XadesSigner $signer,
        private readonly QrCodeGenerator $qrGenerator,
        private readonly QrCodeInjector $qrInjector,
        private readonly CertificateService $certificateService,
    ) {
        parent::__construct();
    }

    /**
     * A client pointed at ZATCA, verifying TLS unless told otherwise.
     *
     * The three calls below each opened with Http::withoutVerifying(), which
     * is not conditional on anything. So --target=production reached the live
     * authority with certificate verification off, carrying the OTP up and
     * bringing the production CSID and its secret back — the two things on
     * this path worth intercepting.
     *
     * FatooraClient has always gated this on fatoora.ssl_verify, which
     * defaults to true. This reads the same setting, so turning verification
     * off is a deliberate act in one place rather than a property of the
     * console.
     */
    private function zatca(): PendingRequest
    {
        $client = Http::timeout(60)
            ->connectTimeout((int) config('fatoora.connect_timeout', 10))
            ->retry(
                (int) config('fatoora.retry_attempts', 3),
                (int) config('fatoora.retry_delay', 1000),
                // Only retry a gateway that failed to answer. A refusal is an
                // answer, and repeating it three times delays the report
                // without changing it. throw: false keeps the response so the
                // caller's error classification still runs.
                fn (\Throwable $e) => ! $e instanceof RequestException
                    || $e->response->serverError(),
                throw: false,
            )
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Accept-Version' => 'V2',
                'Accept-Language' => 'en',
            ]);

        if (! config('fatoora.ssl_verify', true)) {
            $client->withoutVerifying();
        }

        return $client;
    }

    public function handle(ComplianceSampleSet $samples): int
    {
        $this->samples = $samples;

        // No hardcoded default: the environment comes from the configuration
        // that fatoora:generate-csr reads too, so with no flags the request's
        // template and the endpoint it is sent to name the same environment.
        // This defaulted to simulation while the configuration defaulted to
        // sandbox, which is two answers to one question.
        $this->environment = (string) ($this->option('target') ?: config('fatoora.environment'));

        // The documents this builds are always ZATCA's test taxpayer's, and in
        // simulation or production the OTP belongs to a real taxpayer whose
        // VAT number has to be on them. So this can only ever be refused
        // there, after spending the OTP. A real organization onboards through
        // its own onboarding (the portal, or /api/compliance/onboarding).
        if (! in_array($this->environment, ['sandbox', self::LOCAL], true)) {
            $this->error("fatoora:onboard is for the sandbox and local runs; it files as ZATCA's test taxpayer. "
                .'Onboard a real organization through its own onboarding instead, which uses its VAT number and address.');

            return Command::FAILURE;
        }

        // And the target is the environment for the whole run, not just the
        // endpoint. The signer asks the configuration where it is - it lets the
        // sandbox's shared certificate through and refuses a mismatched key
        // anywhere else - so a production .env with --target=sandbox had the
        // signer refusing the sandbox's own certificate. That is how the
        // nightly round trip failed once .env.example said production.
        if ($this->environment !== self::LOCAL) {
            config(['fatoora.environment' => $this->environment]);
        }
        $this->baseUrl = $this->environment === self::LOCAL
            ? self::LOCAL
            : (string) config("fatoora.endpoints.{$this->environment}");

        $step = $this->option('step');

        return match ($step) {
            'info' => $this->showInfo(),
            'ccsid' => $this->requestComplianceCsid(),
            'compliance' => $this->runComplianceCheck(),
            'pcsid' => $this->requestProductionCsid(),
            'submit' => $this->submitWithProductionCsid(),
            'full' => $this->runFullOnboarding(),
            default => $this->showInfo(),
        };
    }

    private function showInfo(): int
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           ZATCA EGS Onboarding Flow                          ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->comment('The onboarding process has 3 steps:');
        $this->newLine();

        $this->table(['Step', 'Description', 'Command'], [
            ['1. CCSID', 'Request Compliance CSID using OTP', 'php artisan fatoora:onboard --step=ccsid --otp=<otp>'],
            ['2. Compliance', 'Submit 6 invoice types for validation', 'php artisan fatoora:onboard --step=compliance'],
            ['3. PCSID', 'Request Production CSID', 'php artisan fatoora:onboard --step=pcsid'],
        ]);
        $this->newLine();

        $this->warn('⚠️  IMPORTANT: Simulation/Production requires all 6 invoices!');
        $this->newLine();

        $this->info('📋 Required Invoice Types (for compliance check):');
        $this->table(['ICV', 'Type', 'Code', 'SubType'], [
            ['1', 'Standard Invoice', '388', '0100000'],
            ['2', 'Standard Credit Note', '381', '0100000'],
            ['3', 'Standard Debit Note', '383', '0100000'],
            ['4', 'Simplified Invoice', '388', '0200000'],
            ['5', 'Simplified Credit Note', '381', '0200000'],
            ['6', 'Simplified Debit Note', '383', '0200000'],
        ]);
        $this->newLine();

        $this->info('📌 ICV/PIH Rules (per ZATCA specification):');
        $this->line('  • ICV is SEQUENTIAL across ALL invoice types (one counter per CSID)');
        $this->line('  • PIH chain links ALL documents regardless of type');
        $this->line('  • One certificate per company is sufficient');
        $this->line('  • Multiple branches can share one CSID (central server)');
        $this->line('  • For branch-specific invoices, use "Other Seller ID" field');
        $this->newLine();

        // This command refuses simulation and production (see handle()), so
        // it must not send anyone to the Fatoora portal for an OTP it will
        // then refuse after spending it.
        $this->info('🔗 Which OTP:');
        $this->line("  Sandbox: the fixed OTP ZATCA's developer portal documents.");
        $this->line('  A real organization (simulation or production) onboards through the portal');
        $this->line('  or /api/compliance/onboarding, with an OTP from '.config('fatoora.portal_url'));
        $this->newLine();

        $this->info('Quick Start (full sandbox onboarding):');
        $this->line('  php artisan fatoora:onboard --step=full --otp=<otp> --target=sandbox');

        return Command::SUCCESS;
    }

    private function requestComplianceCsid(): int
    {
        // Handle local test mode
        if ($this->environment === 'local') {
            return $this->generateLocalCcsid();
        }

        $otp = $this->option('otp');
        if (! $otp) {
            $this->error('OTP is required. Use --otp=<your-otp>');
            $this->line('Get OTP from: '.config('fatoora.portal_url'));
            $this->newLine();
            $this->info('TIP: Use --target=local for local testing without OTP');

            return Command::FAILURE;
        }

        $csrPath = $this->option('csr') ?? storage_path('app/zatca/taxpayer.csr');
        if (! file_exists($csrPath)) {
            $this->error("CSR not found at: {$csrPath}");
            $this->line('Generate CSR first: php artisan fatoora:generate-csr');

            return Command::FAILURE;
        }

        $endpoint = $this->baseUrl.FatooraClient::COMPLIANCE_CSID;

        $this->info("Requesting Compliance CSID from ZATCA {$this->environment}...");
        $this->line("Endpoint: {$endpoint}");
        $this->newLine();

        $csrContent = file_get_contents($csrPath);

        $csrBase64 = $this->encodeCsrForZatca($csrContent);

        try {
            $response = $this->zatca()
                ->withHeaders(['OTP' => $otp])
                ->post($endpoint, [
                    'csr' => $csrBase64,
                ]);

            $this->line("Status: {$response->status()}");

            if ($response->successful()) {
                $data = $response->json();

                $this->info('✓ Compliance CSID obtained successfully!');
                $this->newLine();

                // Save credentials
                $this->saveCcsidCredentials($data);

                $this->csidTable($data);

                $this->info('NEXT: Run compliance check with 6 invoices:');
                $this->line("  php artisan fatoora:onboard --step=compliance --target={$this->environment}");

                return Command::SUCCESS;
            }

            $this->error('Failed to get CCSID');
            $this->line('Response: '.$response->body());

            if ($response->status() === 400) {
                $errors = $response->json('errors') ?? [];
                foreach ($errors as $error) {
                    $this->line("  - {$error['code']}: {$error['message']}");
                }
            }

            return Command::FAILURE;

        } catch (\Exception $e) {
            $this->error('Request failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function requestProductionCsid(): int
    {
        // Handle local test mode
        if ($this->environment === 'local') {
            return $this->generateLocalPcsid();
        }

        $ccsid = $this->loadCcsidCredentials();
        if (! $ccsid) {
            $this->error('CCSID credentials not found. Run --step=ccsid first.');

            return Command::FAILURE;
        }

        $endpoint = $this->baseUrl.FatooraClient::PRODUCTION_CSID;

        $this->info("Requesting Production CSID from ZATCA {$this->environment}...");
        $this->line("Endpoint: {$endpoint}");
        $this->newLine();

        try {
            $response = $this->zatca()
                ->withBasicAuth($ccsid['token'], $ccsid['secret'])
                ->post($endpoint, [
                    'compliance_request_id' => $ccsid['requestId'],
                ]);

            $this->line("Status: {$response->status()}");

            if ($response->successful()) {
                $data = $response->json();

                $this->info('✓ Production CSID obtained successfully!');
                $this->newLine();

                // Save production credentials
                $this->savePcsidCredentials($data);

                $this->csidTable($data);

                $this->info('🎉 Onboarding Complete!');
                $this->line('Your EGS device is now registered with ZATCA.');
                $this->newLine();
                $this->line('Production credentials saved to: storage/app/zatca/pcsid_*.txt');

                return Command::SUCCESS;
            }

            $this->error('Failed to get PCSID');
            $this->line('Response: '.$response->body());

            if ($response->status() === 400) {
                $this->warn('Common reasons for PCSID failure:');
                $this->line('  - Not all 6 invoice types submitted for compliance');
                $this->line('  - Compliance check not completed successfully');
                $this->line('  - Invalid request ID');
            }

            return Command::FAILURE;

        } catch (\Exception $e) {
            $this->error('Request failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function runFullOnboarding(): int
    {
        $this->info('Running Full Onboarding Flow...');
        $this->newLine();

        // Step 1: Get CCSID
        $this->info('═══ Step 1/3: Request Compliance CSID ═══');
        $result = $this->requestComplianceCsid();
        if ($result !== Command::SUCCESS) {
            return $result;
        }
        $this->newLine();

        // Step 2: Compliance check
        $this->info('═══ Step 2/3: Submit 6 Compliance Invoices ═══');
        $result = $this->runComplianceCheck();
        if ($result !== Command::SUCCESS) {
            return $result;
        }
        $this->newLine();

        // Step 3: Get PCSID
        $this->info('═══ Step 3/3: Request Production CSID ═══');

        return $this->requestProductionCsid();
    }

    /**
     * What ZATCA said about the CSID it issued.
     */
    private function csidTable(array $data): void
    {
        $this->table(['Field', 'Value'], [
            ['Request ID', $data['requestID'] ?? 'N/A'],
            ['Disposition', $data['dispositionMessage'] ?? 'N/A'],
            ['Token Type', $data['tokenType'] ?? 'N/A'],
        ]);
        $this->newLine();
    }

    /**
     * The six documents, as ZATCA's test taxpayer.
     *
     * @return array<string, array{xml: string, hash: string, data: InvoiceXmlData}>
     */
    private function sampleDocuments(string $numberPrefix, ?Closure $finalize): array
    {
        return $this->samples->build(
            sellerName: 'Maximum Speed Tech Supply LTD',
            sellerVatNumber: '399999999900003',
            sellerCrNumber: '1010010000',
            sellerAddress: new AddressData(
                street: 'King Fahd Road',
                buildingNumber: '1234',
                plotIdentification: '5678',
                district: 'Al Olaya',
                city: 'Riyadh',
                postalCode: '12345',
                countrySubentity: 'Riyadh Region',
                countryCode: 'SA',
            ),
            numberPrefix: $numberPrefix,
            finalize: $finalize,
        );
    }
}
