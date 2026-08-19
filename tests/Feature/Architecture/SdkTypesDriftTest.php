<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps the TypeScript SDK's typed core equal to the API description.
 *
 * The client's ergonomics are hand-written and should stay that way. Its
 * surface — which endpoints exist, what they accept, which credential they
 * need — is generated, because that is the part that drifts, and a client
 * quietly describing endpoints the API no longer serves is the same failure as
 * a specification doing it.
 *
 *     php artisan masaar:sdk-types
 */
class SdkTypesDriftTest extends TestCase
{
    private const GENERATED = 'sdks/typescript/src/generated.ts';

    public function test_committed_types_match_the_spec(): void
    {
        $committed = (string) file_get_contents(base_path(self::GENERATED));

        Artisan::call('masaar:sdk-types', ['--print' => true]);

        $this->assertSame(
            $this->normalise($committed),
            $this->normalise(Artisan::output()),
            self::GENERATED.' is out of date. Run: php artisan masaar:sdk-types'
        );
    }

    /**
     * One entry per operation, so the client cannot be missing an endpoint the
     * API serves.
     */
    public function test_every_operation_is_exported(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $generated = (string) file_get_contents(base_path(self::GENERATED));

        $missing = [];

        foreach ($spec['paths'] ?? [] as $methods) {
            foreach ($methods as $operation) {
                $id = is_array($operation) ? ($operation['operationId'] ?? null) : null;

                if ($id !== null && ! str_contains($generated, "'{$id}':")) {
                    $missing[] = $id;
                }
            }
        }

        $this->assertSame([], $missing, 'Operations absent from the SDK types.');
    }

    /**
     * Duplicate keys in a TypeScript object literal are a compile error, and
     * the generator produced them until operation ids were made unique. This
     * catches it without needing a TypeScript toolchain in CI.
     */
    public function test_no_duplicate_operation_keys(): void
    {
        $generated = (string) file_get_contents(base_path(self::GENERATED));

        preg_match_all("/^  '([^']+)': \{ method:/m", $generated, $matches);

        $keys = $matches[1] ?? [];
        $duplicates = array_values(array_unique(array_diff_assoc($keys, array_unique($keys))));

        $this->assertSame([], $duplicates, implode("\n", $duplicates));
    }

    private function normalise(string $source): string
    {
        return trim(str_replace("\r\n", "\n", $source));
    }
}
