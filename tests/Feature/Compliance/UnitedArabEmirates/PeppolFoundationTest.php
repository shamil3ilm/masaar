<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * What the UAE's Peppol work stands on: the switch, the provider settings,
 * the schema, and the retired direct-to-authority stub staying retired.
 */
class PeppolFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read with no environment at all: the UAE off, no provider, and a blank
     * value the same as an absent one.
     */
    public function test_config_defaults_are_safe(): void
    {
        $config = $this->withoutEnvironment(
            ['PEPPOL_AE_ENABLED', 'PEPPOL_ASP_BASE_URL', 'PEPPOL_ASP_CLIENT_ID', 'PEPPOL_ASP_CLIENT_SECRET', 'PEPPOL_ASP_WEBHOOK_SECRET', 'PEPPOL_ARTEFACTS_PATH', 'PEPPOL_XSLT_JAR'],
            ['PEPPOL_ASP_BASE_URL' => ''],
        );

        $this->assertFalse($config['countries']['AE']['enabled']);
        $this->assertNull($config['asp']['base_url']);
        $this->assertNull($config['asp']['client_secret']);
        $this->assertNull($config['asp']['webhook_secret']);
        $this->assertNull($config['artefacts_path']);
        $this->assertNull($config['xslt_jar']);
    }

    /**
     * The stub's routes, their gate and its settings are gone; the UAE
     * module brings its own.
     */
    public function test_direct_authority_routes_are_gone(): void
    {
        $uae = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/compliance/ae'))
            ->reject(fn ($route): bool => str_starts_with($route->getActionName(), 'App\\Domains\\Compliance\\Countries\\UnitedArabEmirates\\'));

        $this->assertCount(0, $uae);
        $this->assertArrayNotHasKey('uae.enabled', app('router')->getMiddleware());
        $this->assertNull(config('fta'));
        $this->assertFalse(Schema::hasTable('fta_submissions'));
    }

    public function test_peppol_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('invoices', ['due_date', 'jurisdiction_data']));
        $this->assertTrue(Schema::hasColumns('invoice_lines', ['item_name']));
        $this->assertTrue(Schema::hasColumns('customers', ['peppol_endpoint', 'subdivision']));
        $this->assertTrue(Schema::hasColumns('peppol_documents', [
            'id', 'org_id', 'invoice_id', 'direction', 'country', 'customization_id', 'profile_id',
            'document_type', 'xml', 'asp_message_id', 'status', 'mls_code', 'errors',
            'sender_endpoint', 'receiver_endpoint', 'attempts', 'last_error', 'created_at', 'updated_at',
        ]));
    }

    /**
     * 'AE' is two letters, and the column was one.
     */
    public function test_line_keeps_reverse_charge_category(): void
    {
        $organization = Organization::create(['name' => 'Gulf', 'country' => 'AE']);

        $invoice = Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'AE-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'jurisdiction_data' => ['transaction_flags' => '00100000'],
            'currency' => 'AED',
        ]);

        $line = InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting',
            'item_name' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 5,
            'tax_category' => PintTaxCategory::ReverseCharge->value,
        ]);

        $this->assertSame('AE', $line->fresh()->tax_category);
        $this->assertSame(['transaction_flags' => '00100000'], Invoice::withoutTenantScope(fn () => $invoice->fresh())->jurisdiction_data);
    }

    public function test_document_is_stored_per_tenant(): void
    {
        $organization = Organization::create(['name' => 'Gulf', 'country' => 'AE']);

        $document = PeppolDocument::create([
            'org_id' => $organization->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'invoice',
            'xml' => '<Invoice/>',
            'status' => 'ready',
            'errors' => ['none'],
        ]);

        $stored = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::findOrFail($document->id));

        $this->assertSame(['none'], $stored->errors);
        $this->assertSame(0, $stored->attempts);
        $this->assertSame($organization->id, $stored->org->id);
    }

    /**
     * config/peppol.php as it reads with these variables absent, and the
     * blank ones set to an empty string.
     *
     * @param  list<string>  $absent
     * @param  array<string, string>  $blank
     * @return array<string, mixed>
     */
    private function withoutEnvironment(array $absent, array $blank): array
    {
        $saved = [];

        foreach ($absent as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        foreach ($blank as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return require config_path('peppol.php');
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);

                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($process !== false) {
                    putenv("{$name}={$process}");
                }
            }
        }
    }
}
