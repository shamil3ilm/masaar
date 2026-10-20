<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Http\Requests\CreateScheduleRequest;
use App\Domains\Invoice\Services\InvoiceDrafter;
use App\Domains\Invoice\Support\UnitCode;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Compliance\UnitedArabEmirates\PintInvoices;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Every invoice the integration guides show is one the API accepts.
 *
 * The guides had drifted: an export zero-rated under an exemption code ZATCA
 * does not have, a request body in a shape no endpoint takes, field names the
 * API silently ignores. A guide is followed, so a wrong example costs more
 * than a missing one.
 *
 * Each request a guide shows is marked just above its code block:
 *
 *     <!-- example: POST /api/invoices sa -->           the whole body
 *     <!-- example: POST /api/invoices sa fragment -->  fields merged into a
 *                                                       minimal valid invoice
 *     <!-- example: response -->                        an answer, not checked
 *
 * and is sent here through the API it names, for an organization of the
 * country it names, and must be accepted with every field it sends being one
 * the endpoint takes. A block that looks like an invoice and carries no mark
 * fails, so a new example cannot skip the check. The codes the guides name -
 * exemption codes, units, payment means - and the paths they call must be
 * real, in prose and in client code alike.
 */
class DocumentedExamplesTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    /** The guides an integrator follows. */
    private const GUIDES = [
        'docs/PARTNER-INTEGRATION-GUIDE.md',
        'docs/PARTNER-ADVANCED-SCENARIOS.md',
        'docs/sa/INTEGRATION-GUIDE.md',
        'docs/ae/INTEGRATION-GUIDE.md',
        'docs/WEBHOOKS.md',
    ];

    private const MARK = '/<!--\s*example:\s*(response|(GET|POST|PUT|PATCH|DELETE)\s+(\S+)\s+(sa|ae)(\s+fragment)?)\s*-->/';

    /** Fields every API invoice may carry beside its country's (CreateInvoiceRequest), and the pipeline's own. */
    private const SHARED_FIELDS = ['branch_id', 'sold_at', 'issued_at', 'org_id', 'auto_submit', 'erp_reference_id', 'invoice_type', 'idempotency_key'];

    public function test_every_example_is_accepted(): void
    {
        Queue::fake();
        config(['peppol.countries.AE.enabled' => true]);
        $checked = 0;
        $this->organizations();

        foreach ($this->examples() as $index => [$where, $method, $path, $country, $fragment, $body]) {
            $payload = $fragment ? array_replace($this->base($country), $body) : $body;

            // One invoice each: an example's number or ERP reference is
            // made its own, so two examples showing the same never collide.
            foreach (['invoice_number', 'erp_reference_id'] as $unique) {
                if (isset($payload[$unique])) {
                    $payload[$unique] .= '-'.$index;
                }
            }

            $this->assertKnownFields($where, $country, $path, $payload);

            $response = $this->send($method, $path, $country, $payload);
            $this->assertTrue($response->isSuccessful(), "{$where}: {$method} {$path} answered {$response->status()}: ".$response->getContent());
            $checked++;
        }

        $this->assertGreaterThanOrEqual(6, $checked, 'the guides lost their examples');
    }

    public function test_no_example_is_unmarked(): void
    {
        $unmarked = [];

        foreach ($this->blocks() as [$where, $language, $code, $mark]) {
            $looksLikeInvoice = in_array($language, ['json', 'http'], true) && preg_match('/"(lines|charges|prepayments)"\s*:/', $code) === 1;

            if ($looksLikeInvoice && $mark === null) {
                $unmarked[] = $where;
            }
        }

        $this->assertSame([], $unmarked, 'Mark each invoice example with <!-- example: METHOD PATH COUNTRY [fragment] --> or <!-- example: response -->.');
    }

    /**
     * Exemption codes, unit codes and payment means the guides name, in prose
     * and in client code, are codes the country takes.
     */
    public function test_named_codes_are_real(): void
    {
        $wrong = [];
        $exemptions = [...array_keys(FatooraConfig::CODE_CATEGORIES), ...PintAe::EXEMPTION_REASONS];

        foreach (self::GUIDES as $guide) {
            $text = (string) file_get_contents(base_path($guide));

            preg_match_all('/VATEX-SA-[A-Z0-9]+(?:-[A-Z0-9]+)*/', $text, $codes);
            foreach (array_unique($codes[0]) as $code) {
                if (! in_array($code, $exemptions, true)) {
                    $wrong[] = "{$guide}: exemption code {$code}";
                }
            }

            preg_match_all('/[\'"]?unit_code[\'"]?\s*(?:=>|:)\s*(?:\$[^\'"]*\?\?\s*)?[\'"]([A-Za-z0-9]+)[\'"]/', $text, $units);
            foreach (array_unique($units[1]) as $unit) {
                // The code itself, not an old alias the API still maps
                // (PCE for H87): a guide teaches the code to send.
                if (! in_array($unit, UnitCode::accepted(), true) || UnitCode::normalise($unit) !== $unit) {
                    $wrong[] = "{$guide}: unit code {$unit}";
                }
            }

            preg_match_all('/[\'"]?payment_means_code[\'"]?\s*(?:=>|:)\s*[\'"]([A-Z0-9]+)[\'"]/', $text, $means);
            foreach (array_unique($means[1]) as $code) {
                if (! in_array($code, FatooraConfig::acceptedPaymentMeans(), true)) {
                    $wrong[] = "{$guide}: payment means {$code}";
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    /** Every /api/ path a guide calls is a route the API has. */
    public function test_named_paths_exist(): void
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routes[] = '/'.preg_replace('/\{[^}]+\}/', '{}', $route->uri());
        }

        $missing = [];

        foreach (self::GUIDES as $guide) {
            preg_match_all('#(?<![\w.])/api/[A-Za-z0-9_\-/{}$\[\]\'>.]+#', (string) file_get_contents(base_path($guide)), $paths);

            foreach (array_unique($paths[0]) as $path) {
                // Interpolated PHP and {placeholders} are one segment each.
                $normal = preg_replace(['/\{\$[^}]+\}/', '/\$[A-Za-z_\->\[\]\']+/', '/\{[^}]+\}/'], ['{}', '{}', '{}'], rtrim($path, '.\'/'));

                // A prefix named in prose ("the endpoints under ...") is
                // fine as long as routes sit under it.
                $prefix = array_filter($routes, fn (string $route): bool => str_starts_with($route, $normal.'/'));

                if (! in_array($normal, $routes, true) && $prefix === []) {
                    $missing[] = "{$guide}: {$path}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    /**
     * @return list<array{string, string, string, string, bool, array<string, mixed>}>
     */
    private function examples(): array
    {
        $examples = [];

        foreach ($this->blocks() as [$where, $language, $code, $mark]) {
            if ($mark === null || $mark[1] === 'response') {
                continue;
            }

            $json = $language === 'http' ? (string) preg_replace('/\A.*?\R\R/s', '', $code) : $code;
            $fragment = trim((string) ($mark[5] ?? '')) === 'fragment';
            $body = json_decode($fragment ? '{'.$json.'}' : $json, true);

            $this->assertIsArray($body, "{$where}: the example is not JSON");
            $examples[] = [$where, $mark[2], $mark[3], $mark[4], $fragment, $body];
        }

        return $examples;
    }

    /**
     * Each fenced block of the guides, with the example mark just above it.
     *
     * @return list<array{string, string, string, array<int, string>|null}>
     */
    private function blocks(): array
    {
        $blocks = [];

        foreach (self::GUIDES as $guide) {
            $text = (string) file_get_contents(base_path($guide));
            preg_match_all('/^```(\w*)\R(.*?)^```/ms', $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($found as $block) {
                $offset = $block[0][1];
                $before = substr($text, max(0, $offset - 300), min(300, $offset));
                $lines = array_slice(array_filter(explode("\n", trim($before)), fn ($line) => trim($line) !== ''), -2);
                $mark = null;

                foreach ($lines as $line) {
                    if (preg_match(self::MARK, $line, $matched) === 1) {
                        $mark = $matched;
                    }
                }

                $blocks[] = [$guide.':'.(substr_count(substr($text, 0, $offset), "\n") + 1), $block[1][0], $block[2][0], $mark];
            }
        }

        return $blocks;
    }

    private Organization $saudi;

    private Organization $uae;

    private function organizations(): void
    {
        $this->saudi = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'vat_number' => '399999999900003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $this->uae = $this->uaeOrganization();

        // What a final invoice's example settles.
        $advance = app(InvoiceDrafter::class)->draft([
            'invoice_number' => 'ADV-1', 'type' => 'standard', 'document_type' => 'prepayment', 'issue_date' => now()->toDateString(),
            'currency' => 'SAR', 'buyer_name' => 'Beta Industries', 'buyer_vat_number' => '399999999800003',
            'lines' => [['description' => 'Advance', 'quantity' => 1, 'unit_price' => '100.00']],
        ], (string) $this->saudi->id);
        $advance->forceFill(['status' => 'issued', 'hash' => base64_encode('ADV-1'), 'issued_at' => now()->subDay()])->saveQuietly();
    }

    /**
     * @return array<string, mixed>
     */
    private function base(string $country): array
    {
        return $country === 'sa'
            ? [
                'invoice_number' => 'DOC-1', 'type' => 'standard', 'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(),
                'buyer_name' => 'Beta Industries', 'buyer_name_ar' => 'شركة بيتا للصناعات', 'buyer_vat_number' => '399999999800003',
                'buyer_address' => ['street' => 'Olaya Street', 'building_number' => '4321', 'district' => 'Al Murooj', 'city' => 'Riyadh', 'postal_code' => '11564', 'country_code' => 'SA'],
                'lines' => [['description' => 'Consulting', 'description_ar' => 'خدمات استشارية', 'quantity' => 1, 'unit_price' => '1000.00']],
            ]
            : [
                'document_type' => 'invoice', 'document_category' => 'tax', 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'AED', 'payment_means_code' => '30', 'buyer_name' => 'Noor Electronics LLC', 'buyer_vat_number' => '100765432100003',
                'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi', 'country_code' => 'AE'], 'buyer_endpoint' => '0235:1007654321',
                'buyer_subdivision' => 'AUH', 'transaction_flags' => '00000000',
                'lines' => [['item_name' => 'Consulting', 'quantity' => 1, 'unit_price' => 500, 'tax_category' => 'S', 'tax_rate' => 5]],
            ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $path, string $country, array $payload): TestResponse
    {
        $organization = $country === 'sa' ? $this->saudi : $this->uae;

        if (str_starts_with($path, '/api/v1/pipeline/')) {
            $issued = License::createWithCredentials(['org_id' => $organization->id, 'organization_name' => 'Docs', 'contact_email' => 'docs@example.test', 'tier' => 'enterprise']);
            License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode(['invoice.submit', 'compliance.status'])]);

            return $this->withHeaders(['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']])
                ->json($method, $path, [...$payload, 'org_id' => $organization->id, 'auto_submit' => false]);
        }

        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        return $this->withToken(JWTAuth::claims(['org_id' => $organization->id, 'role' => 'admin'])->fromUser($user))->json($method, $path, $payload);
    }

    /**
     * Every field the example sends is one the endpoint takes: a misspelt
     * field is dropped by validation without a word, so an example using one
     * documents a field that does nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertKnownFields(string $where, string $country, string $path, array $payload): void
    {
        // A schedule: its own fields, and its invoice's as the invoice
        // endpoint takes them.
        if (str_starts_with($path, '/api/schedules')) {
            $known = array_keys((new CreateScheduleRequest)->rules());

            foreach ($this->paths(array_diff_key($payload, ['invoice' => true])) as $field) {
                $this->assertContains($field, $known, "{$where}: {$field} is not a field of {$path}");
            }

            $this->assertKnownFields($where, $country, '/api/invoices', (array) ($payload['invoice'] ?? []));

            return;
        }

        $organization = $country === 'sa' ? $this->saudi : $this->uae;
        $rules = app(JurisdictionResolver::class)->forRules($organization)->invoiceRules()->rules(Request::create($path, 'POST', $payload));
        $known = [...array_keys($rules), ...self::SHARED_FIELDS];

        foreach ($this->paths($payload) as $field) {
            $this->assertContains($field, $known, "{$where}: {$field} is not a field of {$path}");
        }
    }

    /**
     * The payload's fields as validation names them: lines.*.description.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function paths(array $data, string $prefix = ''): array
    {
        $paths = [];

        foreach ($data as $key => $value) {
            $name = $prefix.(is_int($key) ? '*' : $key);
            $paths[] = $name;

            if (is_array($value)) {
                $paths = [...$paths, ...$this->paths($value, $name.'.')];
            }
        }

        return array_values(array_filter(array_unique($paths), fn (string $path): bool => ! str_ends_with($path, '*')));
    }
}
