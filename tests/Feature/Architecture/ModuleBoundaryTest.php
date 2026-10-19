<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Who reaches into a country or network module, so the list can only get
 * shorter.
 *
 * Compliance is three layers (docs/architecture/JURISDICTION-ROUTING.md):
 * the engine, which names no country; one module per country under
 * Compliance/Countries; and the networks those countries send through under
 * Compliance/Networks. Four boundaries, each checked here:
 *
 * - Shared code - everything outside the three - still names module classes
 *   in places. Each is a place the platform assumes a country or a network
 *   instead of asking the engine, and the list of them is a ratchet.
 * - A country module never names another country's classes, nor, in its
 *   code, comments or messages, the other country at all.
 * - A network never names a country: any Peppol country uses it.
 * - The engine names neither.
 *
 * The class lists are file => the module classes it references, relative to
 * App\Domains\Compliance; the word list is file => the foreign words it
 * holds. The assertion is equality: a new reference fails
 * until it is listed, and one removed fails until it is delisted, so the
 * dependencies are seen to shrink rather than assumed to.
 *
 * Any reference counts - a use statement, a fully-qualified name, a Blade
 * view's - because each is a dependency whatever its spelling. Tests are not
 * scanned: they are allowed to reach into what they test.
 *
 * A country's Blade views are part of its module: they live in
 * resources/views/countries/<country>/, the module's folder name in kebab
 * case (SaudiArabia's in saudi-arabia/), and are held to the same lines as
 * its classes. A network's live in resources/views/networks/<network>/,
 * held to the network's.
 */
class ModuleBoundaryTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** Where the platform's own PHP lives, Blade views included. */
    private const SCANNED = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes'];

    private const COUNTRIES = 'app/Domains/Compliance/Countries/';

    private const NETWORKS = 'app/Domains/Compliance/Networks/';

    private const ENGINE = 'app/Domains/Compliance/Engine/';

    /** Each country module's views, in a folder of its own. */
    private const COUNTRY_VIEWS = 'resources/views/countries/';

    /** Each network's views, in a folder of its own. */
    private const NETWORK_VIEWS = 'resources/views/networks/';

    /**
     * Shared files referencing a country or network module, and what they reference.
     *
     * @var array<string, string>
     */
    private const SHARED = [
        'app/Domains/Invoice/Models/Customer.php' => 'Countries\UnitedArabEmirates\Rules\PintAe',
        'app/Domains/Invoice/Models/Invoice.php' => 'Countries\SaudiArabia\Models\InvoiceSubmission',
        'app/Domains/Organization/Models/Branch.php' => 'Countries\SaudiArabia\DTOs\AddressData, Countries\SaudiArabia\DTOs\CsrData',
        'app/Domains/Organization/Models/Organization.php' => 'Countries\SaudiArabia\DTOs\AddressData',
        'app/Domains/Organization/Services/BranchService.php' => 'Countries\SaudiArabia\Signing\CredentialStore',
        'app/Domains/Platform/Http/Controllers/AdminController.php' => 'Countries\SaudiArabia\Enums\RequeueOutcome, Countries\SaudiArabia\Filing\Requeuer',
        'app/Domains/Platform/Http/Controllers/AdminDashboardController.php' => 'Countries\SaudiArabia\Enums\RequeueOutcome, Countries\SaudiArabia\Filing\CircuitBreaker, Countries\SaudiArabia\Filing\Connectivity, Countries\SaudiArabia\Filing\Requeuer',
        'app/Domains/Platform/Services/IssueDetector.php' => 'Countries\SaudiArabia\Filing\CircuitBreaker, Countries\SaudiArabia\Filing\Connectivity, Countries\SaudiArabia\Filing\OfflineQueue',
        'app/Domains/Platform/Services/MetricsCollector.php' => 'Countries\SaudiArabia\Filing\CircuitBreaker',
        'app/Domains/Platform/Services/OrganizationReport.php' => 'Countries\SaudiArabia\Signing\CertificateService, Countries\SaudiArabia\Signing\CredentialStore',
        'app/Domains/Platform/Services/PlatformStatus.php' => 'Countries\SaudiArabia\Filing\CircuitBreaker, Countries\SaudiArabia\Signing\CertificateService, Countries\SaudiArabia\Signing\CredentialStore',
        'app/Domains/Platform/Services/QueueReport.php' => 'Countries\SaudiArabia\Filing\OfflineQueue',
        'app/Http/Responses/ApiResponse.php' => 'Countries\SaudiArabia\Exceptions\CertificateException, Countries\SaudiArabia\Exceptions\FatooraException',
        'app/Providers/AppServiceProvider.php' => 'Countries\SaudiArabia\Filing\CircuitBreaker, Countries\SaudiArabia\Filing\TimestampValidator, Countries\SaudiArabia\Http\Middleware\FilingCleared, Countries\SaudiArabia\Rules\FatooraConfig, Countries\UnitedArabEmirates\Http\Middleware\UaeInvoicing',
        'app/Providers/EventServiceProvider.php' => 'Countries\SaudiArabia\Events\InvoiceCleared, Countries\SaudiArabia\Events\InvoiceFailed, Countries\SaudiArabia\Events\InvoiceRejected, Countries\SaudiArabia\Events\InvoiceReported, Countries\SaudiArabia\Events\InvoiceSubmitted, Countries\SaudiArabia\Events\InvoiceWarning, Countries\SaudiArabia\Listeners\DispatchInvoiceWebhook',
        'bootstrap/app.php' => 'Countries\SaudiArabia\Exceptions\CertificateException, Countries\SaudiArabia\Exceptions\SigningException',
        // Each module's provider, registering it, and the network's.
        'bootstrap/providers.php' => 'Countries\SaudiArabia\ModuleServiceProvider, Countries\UnitedArabEmirates\ModuleServiceProvider, Networks\Peppol\NetworkServiceProvider',
        'routes/api/partner.php' => 'Countries\SaudiArabia\Http\Controllers\ComplianceController',
        'routes/api/public.php' => 'Networks\Peppol\Http\Controllers\InboundWebhookController, Networks\Peppol\Http\Controllers\StatusWebhookController',
        'routes/api/tenant.php' => 'Countries\SaudiArabia\Http\Controllers\BranchOnboardingController, Countries\SaudiArabia\Http\Controllers\ComplianceController, Countries\SaudiArabia\Http\Controllers\OnboardingController, Countries\UnitedArabEmirates\Http\Controllers\UaeDocumentController, Countries\UnitedArabEmirates\Http\Controllers\UaeInvoiceController, Countries\UnitedArabEmirates\Http\Controllers\UaeProfileController',
        'routes/web.php' => 'Countries\SaudiArabia\Http\Controllers\AdminKillSwitchController',
    ];

    /**
     * Country modules referencing another country's, and what they reference.
     *
     * @var array<string, string>
     */
    private const ACROSS_COUNTRIES = [];

    /**
     * What names another country, by the module it must not appear in: a
     * country module describes itself in its own terms or the engine's, and
     * a network names no country, so any country can send through it.
     *
     * @var array<string, string>
     */
    private const FOREIGN_WORDS = [
        self::COUNTRIES.'SaudiArabia/' => '/\buae\b|peppol|\bpint\b|emirat|dubai/i',
        self::COUNTRY_VIEWS.'saudi-arabia/' => '/\buae\b|peppol|\bpint\b|emirat|dubai/i',
        self::COUNTRIES.'UnitedArabEmirates/' => '/zatca|fatoora|saudi|VATEX-SA/i',
        self::COUNTRY_VIEWS.'united-arab-emirates/' => '/zatca|fatoora|saudi|VATEX-SA/i',
        self::NETWORKS.'Peppol/' => '/zatca|fatoora|saudi|\buae\b|emirat|dubai|dirham|\bAED\b|[\'"]AE[\'"]|0235/i',
        self::NETWORK_VIEWS.'peppol/' => '/zatca|fatoora|saudi|\buae\b|emirat|dubai|dirham|\bAED\b|[\'"]AE[\'"]|0235/i',
    ];

    /**
     * Module files that still name another country, and the words.
     *
     * @var array<string, string>
     */
    private const FOREIGN_DECLARED = [];

    public function test_modules_name_no_other_country(): void
    {
        $found = [];

        foreach (self::FOREIGN_WORDS as $module => $pattern) {
            foreach ($this->files() as $relative => $path) {
                if (! str_starts_with($relative, $module)) {
                    continue;
                }

                preg_match_all($pattern, (string) file_get_contents($path), $matches);
                $words = array_values(array_unique(array_map('strtolower', $matches[0])));

                if ($words !== []) {
                    sort($words);
                    $found[$relative] = implode(', ', $words);
                }
            }
        }

        ksort($found);

        $this->assertSame(self::FOREIGN_DECLARED, $found, "A module names another country. Found:\n".var_export($found, true));
    }

    public function test_shared_code_only_shrinks(): void
    {
        $found = $this->references(fn (string $file): bool => ! $this->inModule($file), '(?:Countries|Networks)');

        $this->assertSame(self::SHARED, $found, "References to the modules from shared code changed. Found:\n".var_export($found, true));
    }

    public function test_countries_stay_apart(): void
    {
        $found = [];

        foreach ($this->countries() as $country) {
            $found += $this->references(
                fn (string $file): bool => str_starts_with($file, self::COUNTRIES.$country.'/')
                    || str_starts_with($file, self::COUNTRY_VIEWS.$this->viewFolder($country).'/'),
                'Countries\\\\(?!'.$country.'\\\\)\w+',
            );
        }

        ksort($found);

        $this->assertNotSame([], $this->countries(), 'No country module was found.');
        $this->assertSame(self::ACROSS_COUNTRIES, $found, "References between country modules changed. Found:\n".var_export($found, true));
    }

    /**
     * Every folder of country views is a module's, so none escapes the lines
     * above by being named for a country that has no module.
     */
    public function test_country_views_have_a_module(): void
    {
        $root = str_replace('\\', '/', (string) realpath(self::ROOT)).'/';
        $folders = array_map('basename', glob($root.self::COUNTRY_VIEWS.'*', GLOB_ONLYDIR) ?: []);
        $modules = array_map(fn (string $country): string => $this->viewFolder($country), $this->countries());

        $this->assertNotSame([], $folders, 'No country views were found.');
        $this->assertSame([], array_values(array_diff($folders, $modules)), 'These view folders have no country module.');
    }

    public function test_networks_name_no_country(): void
    {
        $found = $this->references(fn (string $file): bool => str_starts_with($file, self::NETWORKS) || str_starts_with($file, self::NETWORK_VIEWS), 'Countries');

        $this->assertSame([], $found, "A network references a country module:\n".var_export($found, true));
    }

    public function test_engine_names_no_module(): void
    {
        $found = $this->references(fn (string $file): bool => str_starts_with($file, self::ENGINE), '(?:Countries|Networks)');

        $this->assertSame([], $found, "The engine references a country or network module:\n".var_export($found, true));
    }

    private function inModule(string $file): bool
    {
        foreach ([self::COUNTRIES, self::NETWORKS, self::ENGINE, self::COUNTRY_VIEWS, self::NETWORK_VIEWS] as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> the country modules, by folder
     */
    private function countries(): array
    {
        $root = str_replace('\\', '/', (string) realpath(self::ROOT)).'/';
        $folders = glob($root.self::COUNTRIES.'*', GLOB_ONLYDIR) ?: [];

        return array_map('basename', $folders);
    }

    /** A country module's folder of views: SaudiArabia's is saudi-arabia. */
    private function viewFolder(string $country): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $country));
    }

    /**
     * Module classes the selected files name, with $layer the regex for the
     * first segment after App\Domains\Compliance.
     *
     * @param  callable(string): bool  $select
     * @return array<string, string> file => its references, sorted
     */
    private function references(callable $select, string $layer): array
    {
        $found = [];

        foreach ($this->files() as $relative => $path) {
            if (! $select($relative)) {
                continue;
            }

            preg_match_all('/App\\\\Domains\\\\Compliance\\\\('.$layer.'(?:\\\\\w+)+)/', (string) file_get_contents($path), $matches);

            $classes = array_values(array_unique($matches[1]));

            if ($classes !== []) {
                sort($classes);
                $found[$relative] = implode(', ', $classes);
            }
        }

        ksort($found);

        return $found;
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
