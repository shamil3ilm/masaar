<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * docs/integration/ERRORS.md is the one place an integrator reads error
 * codes, so it names every code the API can answer with; it and API.md name
 * nothing else.
 */
class ApiReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'docs/integration/ERRORS.md';

    /** The rest of the reference, which names codes in passing. */
    private const REFERENCE = 'docs/integration/API.md';

    /** The codes ApiResponse derives from an HTTP status. */
    private const STATUS_CODES = [
        'BAD_REQUEST', 'UNAUTHORIZED', 'FORBIDDEN', 'NOT_FOUND', 'METHOD_NOT_ALLOWED', 'REQUEST_TIMEOUT', 'CONFLICT',
        'PAYLOAD_TOO_LARGE', 'VALIDATION_ERROR', 'RATE_LIMITED', 'SERVER_ERROR', 'UPSTREAM_ERROR', 'SERVICE_UNAVAILABLE', 'UPSTREAM_TIMEOUT',
    ];

    public function test_every_compliance_code_is_documented(): void
    {
        $page = $this->page();

        foreach (ErrorCode::cases() as $code) {
            $this->assertStringContainsString('`'.$code->value.'`', $page, $code->name);
        }

        foreach (self::STATUS_CODES as $code) {
            $this->assertStringContainsString('`'.$code.'`', $page);
        }
    }

    /**
     * Every named code the application answers with, found where it is
     * raised: a licence refusal, or an error code passed to ApiResponse.
     */
    public function test_every_named_code_is_documented(): void
    {
        $page = $this->page();

        foreach ($this->raised() as $code) {
            $this->assertStringContainsString('`'.$code.'`', $page, "{$code} is answered by the API and missing from ".self::PAGE);
        }
    }

    public function test_every_documented_code_is_real(): void
    {
        preg_match_all('/`([A-Z]+(?:_[A-Z0-9]+)+)`/', $this->page().(string) file_get_contents(base_path(self::REFERENCE)), $found);
        $known = [...array_map(fn (ErrorCode $code): string => $code->value, ErrorCode::cases()), ...self::STATUS_CODES, ...$this->raised()];

        $this->assertSame([], array_values(array_diff(array_unique($found[1]), $known)));
    }

    /** The page's word on categories, sent through the API. */
    public function test_scope_refusal_category_is_license(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        License::create([
            'org_id' => $organization->id, 'api_key' => 'cp_test_docs', 'api_secret_hash' => License::hashSecret('docs-secret'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@example.test', 'environment' => 'sandbox',
            'tier' => 'starter', 'status' => 'active', 'scopes' => ['invoice.read'],
        ]);

        $this->withHeaders(['X-API-Key' => 'cp_test_docs', 'X-API-Secret' => 'docs-secret'])
            ->getJson('/api/v1/pipeline/status/'.$organization->id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED')
            ->assertJsonPath('error.category', 'license')
            ->assertJsonPath('error.details.required_scope', 'compliance.status');
    }

    /**
     * @return list<string>
     */
    private function raised(): array
    {
        $codes = [];
        $patterns = [
            // LicenseException::...(…, 'CODE', …) and its INVALID_CREDENTIALS constant
            '/new self\(\s*[^,]+,\s*\'([A-Z][A-Z_]+)\'/s',
            '/INVALID_CREDENTIALS = \'([A-Z_]+)\'/',
            // ApiResponse::error(message, status, details, 'CODE' …)
            '/(?:ApiResponse|self)::error\((?:[^;]*?),\s*\'([A-Z][A-Z_]+)\'/s',
            // Exceptions that render themselves with a code
            '/ApiResponse::error\(\s*\$this->getMessage\(\),\s*\d+,\s*\$this->errors\(\),\s*\'([A-Z_]+)\'/s',
        ];

        foreach ([...File::allFiles(app_path()), new \SplFileInfo(base_path('bootstrap/app.php'))] as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $found);
                $codes = [...$codes, ...$found[1]];
            }
        }

        // Console-only answers, not on any route an integrator calls.
        $internal = ['LICENSE_NOT_FOUND', 'SUSPENSION_FAILED', 'REACTIVATION_FAILED', 'ACTIVATION_FAILED'];

        return array_values(array_diff(array_unique($codes), $internal));
    }

    private function page(): string
    {
        return (string) file_get_contents(base_path(self::PAGE));
    }
}
