<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Emit the TypeScript SDK's typed core from the OpenAPI description.
 *
 * The client in sdks/typescript is hand-written, and that part is worth
 * keeping — ergonomics are a judgement call a generator makes badly. What it
 * should not be hand-maintaining is the surface: which endpoints exist, what
 * they accept, which credential they need. That is exactly what drifted in the
 * description itself, and a client repeating it by hand drifts the same way.
 *
 * So the surface is generated and the ergonomics are not. SdkTypesDriftTest
 * fails when this output stops matching the committed file, so an endpoint
 * cannot change shape without the SDK being told.
 */
class GenerateSdkTypes extends Command
{
    protected $signature = 'masaar:sdk-types
                            {--path=sdks/typescript/src/generated.ts : Where to write}
                            {--print : Write to stdout instead of the file}';

    protected $description = 'Generate the TypeScript SDK types from docs/openapi.yaml';

    public function handle(): int
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));

        $output = $this->header()
            .$this->envelopes()
            .$this->bodies($spec)
            .$this->operations($spec);

        if ($this->option('print')) {
            $this->line($output);

            return self::SUCCESS;
        }

        $path = base_path((string) $this->option('path'));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $output);

        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }

    private function header(): string
    {
        return <<<'TS'
        // Generated from docs/openapi.yaml by `php artisan masaar:sdk-types`.
        // Do not edit: changes are overwritten and SdkTypesDriftTest fails.
        //
        // The hand-written client imports from here so the endpoint surface it
        // exposes is the one the API actually serves.


        TS;
    }

    /**
     * The response envelopes, mirroring components.schemas.
     *
     * `data` is generic rather than typed: no endpoint declares its payload,
     * so callers name the shape they expect and take responsibility for it.
     */
    private function envelopes(): string
    {
        return <<<'TS'
        export interface PageMeta {
          current_page: number;
          last_page: number;
          per_page: number;
          total: number;
        }

        export interface Success<T = unknown> {
          success: true;
          message?: string;
          data?: T;
        }

        export interface Paginated<T = unknown> {
          success: true;
          message?: string;
          data: T[];
          meta: PageMeta;
        }

        export interface ApiError {
          success: false;
          error: {
            message: string;
            code: string;
            details?: unknown;
            category?: string;
          };
        }

        export type ApiResult<T = unknown> = Success<T> | Paginated<T> | ApiError;


        TS;
    }

    /**
     * Request body interfaces, one per endpoint that declares a body.
     *
     * @param  array<string, mixed>  $spec
     */
    private function bodies(array $spec): string
    {
        $out = '';

        foreach ($this->eachOperation($spec) as [$path, $method, $operation]) {
            $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;

            if ($schema === null) {
                continue;
            }

            $name = $this->bodyName((string) ($operation['operationId'] ?? ''));
            $required = $schema['required'] ?? [];

            $out .= "export interface {$name} {\n";

            foreach ($schema['properties'] ?? [] as $field => $property) {
                $optional = in_array($field, $required, true) ? '' : '?';
                $out .= "  {$field}{$optional}: ".$this->tsType($property).";\n";
            }

            $out .= "}\n\n";
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private function tsType(array $property): string
    {
        return match ($property['type'] ?? null) {
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            'array' => 'unknown[]',
            'string' => 'string',
            default => 'unknown',
        };
    }

    /**
     * invoiceController.store -> InvoiceStoreBody
     */
    private function bodyName(string $operationId): string
    {
        $parts = explode('.', $operationId);
        $controller = str_replace('Controller', '', ucfirst($parts[0] ?? 'Unknown'));
        $action = ucfirst($parts[1] ?? 'Request');

        return $controller.$action.'Body';
    }

    /**
     * The operation table: every endpoint, with what it needs to be called.
     *
     * @param  array<string, mixed>  $spec
     */
    private function operations(array $spec): string
    {
        $out = "export type Security = 'bearerAuth' | 'apiKey' | 'metricsToken';\n\n"
            ."export interface Operation {\n"
            ."  method: 'get' | 'post' | 'put' | 'patch' | 'delete';\n"
            ."  path: string;\n"
            ."  security: Security[];\n"
            ."  scopes: string[];\n"
            ."  deprecated: boolean;\n"
            ."}\n\n"
            ."export const operations = {\n";

        foreach ($this->eachOperation($spec) as [$path, $method, $operation]) {
            $id = (string) ($operation['operationId'] ?? '');

            if ($id === '') {
                continue;
            }

            $security = [];

            foreach ($operation['security'] ?? [] as $requirement) {
                $security = array_merge($security, array_keys($requirement));
            }

            // The description carries the scopes the middleware enforces.
            $scopes = [];

            if (preg_match('/Requires scope: (.+)$/', (string) ($operation['description'] ?? ''), $m) === 1) {
                $scopes = array_map('trim', explode(',', $m[1]));
            }

            $out .= sprintf(
                "  %s: { method: '%s', path: '%s', security: [%s], scopes: [%s], deprecated: %s },\n",
                $this->key($id),
                $method,
                $path,
                $this->quoted($security),
                $this->quoted($scopes),
                ($operation['deprecated'] ?? false) ? 'true' : 'false'
            );
        }

        $out .= "} as const;\n\n"
            ."export type OperationId = keyof typeof operations;\n";

        return $out;
    }

    /**
     * @param  list<string>  $values
     */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $v) => "'".$v."'", $values));
    }

    /**
     * Operation ids carry a dot, so they are emitted as quoted keys.
     */
    private function key(string $id): string
    {
        return "'".$id."'";
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return iterable<array{string, string, array<string, mixed>}>
     */
    private function eachOperation(array $spec): iterable
    {
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (is_array($operation)) {
                    yield [(string) $path, (string) $method, $operation];
                }
            }
        }
    }
}
