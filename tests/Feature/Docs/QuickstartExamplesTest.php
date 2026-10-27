<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Domains\Compliance\Countries\SaudiArabia\Jobs\ProcessFatooraSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\ApiKey;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The quickstart, run as an integrator would run it.
 *
 * docs/integration/QUICKSTART.md promises a first filed invoice from a
 * sandbox key in ten minutes. Each answer it shows is marked
 *
 *     <!-- quickstart: STEP STATUS -->
 *
 * and each step is sent here through the HTTP kernel, with the body and the
 * Idempotency-Key the page shows, for an organization set up as the page
 * says: a sandbox key with the scopes it names, connected to ZATCA's
 * sandbox. Every key the page shows must be in the answer, and every value
 * it shows must be the answer's, except a placeholder ("<...>").
 */
class QuickstartExamplesTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const PAGE = 'docs/integration/QUICKSTART.md';

    private Organization $organization;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('creds');
        config(['fatoora.signing.disk' => 'creds', 'fatoora.environment' => 'sandbox', 'fatoora.endpoints.sandbox' => 'https://zatca.test/sandbox']);

        // Connected to ZATCA's sandbox, whose certificate names its test taxpayer.
        $this->organization = Organization::create([
            'name' => 'Acme Trading', 'name_ar' => 'شركة أكمي للتجارة', 'country' => 'SA', 'status' => 'active',
            'vat_number' => '399999999900003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
            'compliance_profile' => ['zatca_onboarded' => true],
        ]);
        $keys = $this->selfSignedCredentials();
        app(CredentialStore::class)->put($this->organization->id, null, CredentialStore::PCSID, ['privateKey' => $keys['privateKey'], 'pcsid' => $keys['certificate'], 'secret' => 's']);

        // Portal › Integrations › API keys: a sandbox key with the page's scopes.
        $issued = License::createWithCredentials([
            'org_id' => $this->organization->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'ops@example.test', 'tier' => LicenseTier::Enterprise,
        ]);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active']);

        $secret = License::generateApiSecret();
        $key = new ApiKey(['name' => 'ERP', 'environment' => LicenseEnvironment::Sandbox, 'scopes' => ['invoice.submit', 'compliance.status']]);
        $key->forceFill([
            'org_id' => $this->organization->id,
            'api_key' => License::generateApiKey(LicenseEnvironment::Sandbox),
            'secret_hash' => License::hashSecret($secret),
            'status' => ApiKey::ACTIVE,
        ])->save();

        $this->headers = ['X-API-Key' => $key->api_key, 'X-API-Secret' => $secret];
    }

    public function test_the_quickstart_runs_as_documented(): void
    {
        $answers = $this->answers();
        $this->assertSame(['submit', 'status', 'replay', 'reused', 'wrong-secret'], array_keys($answers));
        $this->assertStringStartsWith('cp_test_', $this->headers['X-API-Key'], 'the page says a sandbox key starts cp_test_');

        Http::fake(['*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']])]);
        Queue::fake();

        // 1. Submit: issued, and queued for ZATCA.
        $submit = $this->submit($this->invoice());
        $this->assertDocumented('submit', $answers['submit'], $submit);
        Queue::assertPushed(ProcessFatooraSubmission::class);

        // The queue reports it, as the worker would.
        Queue::pushedJobs()[ProcessFatooraSubmission::class][0]['job']->handle(...$this->dependencies());

        // 2. Status: accepted.
        $status = $this->withHeaders([...$this->headers, 'Accept' => 'application/json'])
            ->getJson('/api/v1/pipeline/status/'.$submit->json('data.invoice_id'));
        $this->assertDocumented('status', $answers['status'], $status);

        // 3. The same call again: the same invoice.
        $replay = $this->submit($this->invoice());
        $this->assertDocumented('replay', $answers['replay'], $replay);
        $this->assertSame($submit->json('data.invoice_id'), $replay->json('data.invoice_id'));

        // The same key with another body: refused.
        $reused = $this->submit([...$this->invoice(), 'erp_reference_id' => 'SO-0002']);
        $this->assertDocumented('reused', $answers['reused'], $reused);

        // 4. A wrong secret.
        $wrong = $this->withHeaders(['X-API-Key' => $this->headers['X-API-Key'], 'X-API-Secret' => 'wrong', 'Idempotency-Key' => $this->key()])
            ->postJson('/api/v1/pipeline/submit', $this->invoice());
        $this->assertDocumented('wrong-secret', $answers['wrong-secret'], $wrong);
    }

    /**
     * The page tells the reader where org_id is, and the scopes and paths it
     * uses are the ones sent above.
     */
    public function test_the_page_names_its_inputs(): void
    {
        $page = $this->page();

        foreach (['Organization ID', 'invoice.submit', 'compliance.status', '/api/v1/pipeline/submit', '/api/v1/pipeline/status/', 'X-API-Key', 'X-API-Secret', 'Audience:'] as $needle) {
            $this->assertStringContainsString($needle, $page);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function submit(array $body): TestResponse
    {
        return $this->withHeaders([...$this->headers, 'Idempotency-Key' => $this->key(), 'Accept' => 'application/json'])
            ->postJson('/api/v1/pipeline/submit', $body);
    }

    /**
     * The invoice the page saves as invoice.json, for this organization.
     *
     * @return array<string, mixed>
     */
    private function invoice(): array
    {
        $this->assertSame(1, preg_match('/<!-- example: POST \/api\/v1\/pipeline\/submit sa -->\s*```json\R(.*?)^```/ms', $this->page(), $match));
        $body = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('<your organization id>', $body['org_id']);
        $this->assertSame('<today>', $body['issue_date']);

        return [...$body, 'org_id' => $this->organization->id, 'issue_date' => now()->toDateString()];
    }

    private function key(): string
    {
        $this->assertSame(1, preg_match('/Idempotency-Key: ([A-Za-z0-9-]+)"/', $this->page(), $match));

        return $match[1];
    }

    /**
     * @return array<string, array{status: int, body: array<string, mixed>}>
     */
    private function answers(): array
    {
        preg_match_all('/<!-- quickstart: (\S+) (\d{3}) -->\s*```json\R(.*?)^```/ms', $this->page(), $found, PREG_SET_ORDER);
        $answers = [];

        foreach ($found as [, $step, $status, $json]) {
            $answers[$step] = ['status' => (int) $status, 'body' => json_decode($json, true, flags: JSON_THROW_ON_ERROR)];
        }

        return $answers;
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $documented
     */
    private function assertDocumented(string $step, array $documented, TestResponse $response): void
    {
        $this->assertSame($documented['status'], $response->status(), "{$step}: ".$response->getContent());
        $this->assertShown($step, $documented['body'], (array) $response->json());
    }

    /**
     * @param  array<array-key, mixed>  $shown
     * @param  array<array-key, mixed>  $actual
     */
    private function assertShown(string $where, array $shown, array $actual): void
    {
        foreach ($shown as $key => $value) {
            $this->assertArrayHasKey($key, $actual, "{$where}.{$key} is shown but not answered");

            if (is_array($value) && $value !== []) {
                $this->assertIsArray($actual[$key], "{$where}.{$key}");
                $this->assertShown("{$where}.{$key}", $value, $actual[$key]);
            } elseif (! (is_string($value) && str_starts_with($value, '<'))) {
                $this->assertSame($value, $actual[$key], "{$where}.{$key}");
            }
        }
    }

    /** @return list<mixed> */
    private function dependencies(): array
    {
        $parameters = (new \ReflectionMethod(ProcessFatooraSubmission::class, 'handle'))->getParameters();

        return array_map(fn (\ReflectionParameter $p) => app((string) $p->getType()), $parameters);
    }

    private function page(): string
    {
        return (string) file_get_contents(base_path(self::PAGE));
    }
}
