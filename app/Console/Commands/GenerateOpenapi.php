<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Yaml\Yaml;

/**
 * Write the OpenAPI description from the routes that actually exist.
 *
 * The specification was maintained by hand and drifted: it described sixteen
 * paths out of a hundred and twenty-nine, documented a prefix that now answers
 * 301, and omitted the licence-authenticated surface integrators actually use.
 * A description that disagrees with the API is worse than none — someone
 * writes an integration against it and blames the platform when it 404s.
 *
 * Generating it removes the way it drifts. OpenapiDriftTest runs this and
 * fails when the committed file differs, so adding a route without describing
 * it breaks the build rather than the next integrator.
 *
 * What is derived: paths, methods, path parameters, which credential a route
 * accepts, and request bodies for endpoints taking a FormRequest. Response
 * schemas are not — nothing in the code states them, and inventing them would
 * reintroduce exactly the fiction this replaces.
 */
class GenerateOpenapi extends Command
{
    protected $signature = 'masaar:openapi
                            {--path=docs/openapi.yaml : Where to write}
                            {--print : Write to stdout instead of the file}';

    protected $description = 'Generate the OpenAPI description from the route table';

    /** Middleware class => the security scheme it enforces. */
    private const SECURITY = [
        'JwtGuard' => 'bearerAuth',
        'ValidateLicense' => 'apiKey',
        'ApiKeyAuth' => 'apiKey',
    ];

    public function handle(): int
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Masaar Compliance API',
                'version' => (string) config('app.version', '1.0.0'),
                'description' => 'GCC e-invoicing compliance. Generated from the route table by '
                    .'`php artisan masaar:openapi` — edit the routes, not this file.',
            ],
            'servers' => [
                ['url' => 'https://api.masaar.sa', 'description' => 'Production'],
                ['url' => 'https://sandbox.masaar.sa', 'description' => 'Sandbox'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                    'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
            'paths' => $this->paths(),
        ];

        $yaml = Yaml::dump($spec, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

        if ($this->option('print')) {
            $this->line($yaml);

            return self::SUCCESS;
        }

        $path = base_path((string) $this->option('path'));
        file_put_contents($path, $yaml);

        $this->info(sprintf('Wrote %d paths to %s', count($spec['paths']), $path));

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function paths(): array
    {
        $paths = [];

        foreach ($this->apiRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            foreach ($route->methods() as $method) {
                // HEAD is implied by GET, and OPTIONS is transport.
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$uri][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @return list<Route>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method): array
    {
        $operation = [
            'tags' => [$this->tag($route)],
            'operationId' => $this->operationId($route, $method),
            'summary' => $this->summary($route),
        ];

        if ($parameters = $this->parameters($route)) {
            $operation['parameters'] = $parameters;
        }

        if ($body = $this->requestBody($route)) {
            $operation['requestBody'] = $body;
        }

        $operation['responses'] = $this->responses($route);
        $operation['security'] = $this->security($route);

        return $operation;
    }

    /**
     * Group by the domain the controller lives in, so the rendered page is
     * organised the way the codebase is.
     */
    private function tag(Route $route): string
    {
        if (preg_match('/App\\\\Domains\\\\([A-Za-z]+)\\\\/', (string) $route->getActionName(), $m) === 1) {
            return $m[1];
        }

        return 'Platform';
    }

    private function operationId(Route $route, string $method): string
    {
        $action = $route->getActionName();

        if (str_contains($action, '@')) {
            [$class, $function] = explode('@', $action);

            return lcfirst(class_basename($class)).'.'.$function;
        }

        return strtolower($method).Str::studly(str_replace('/', '-', $route->uri()));
    }

    private function summary(Route $route): string
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return 'Closure route';
        }

        [$class, $function] = explode('@', $action);

        return Str::headline($function).' — '.Str::headline(class_basename($class));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(Route $route): array
    {
        return array_map(
            fn (string $name) => [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
            $route->parameterNames()
        );
    }

    /**
     * Derive the body from the FormRequest the controller asks for.
     *
     * Only endpoints using one are described. Where validation is written
     * inline in the controller there is nothing to read, and guessing would
     * put claims in the specification that nothing enforces.
     *
     * @return array<string, mixed>|null
     */
    private function requestBody(Route $route): ?array
    {
        $rules = $this->rulesFor($route);

        if ($rules === []) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($rules as $field => $rule) {
            $rule = is_array($rule) ? $rule : explode('|', (string) $rule);
            $rule = array_map(fn ($r) => is_string($r) ? $r : '', $rule);

            // Nested keys ("lines.*.quantity") describe array members; the
            // parent already appears as its own rule.
            if (str_contains((string) $field, '.')) {
                continue;
            }

            $properties[$field] = ['type' => $this->typeFor($rule)];

            if (in_array('required', $rule, true)) {
                $required[] = $field;
            }
        }

        if ($properties === []) {
            return null;
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return [
            'required' => true,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * @param  list<string>  $rule
     */
    private function typeFor(array $rule): string
    {
        foreach ($rule as $token) {
            $name = explode(':', $token)[0];

            $type = match ($name) {
                'integer', 'int' => 'integer',
                'numeric', 'decimal' => 'number',
                'boolean', 'bool' => 'boolean',
                'array' => 'array',
                default => null,
            };

            if ($type !== null) {
                return $type;
            }
        }

        return 'string';
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesFor(Route $route): array
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return [];
        }

        [$class, $function] = explode('@', $action);

        if (! class_exists($class) || ! method_exists($class, $function)) {
            return [];
        }

        foreach ((new ReflectionMethod($class, $function))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if (! is_subclass_of($name, FormRequest::class)) {
                continue;
            }

            if (! (new ReflectionClass($name))->hasMethod('rules')) {
                continue;
            }

            try {
                return (new $name)->rules();
            } catch (\Throwable) {
                // Rules that reach for the container or the authenticated user
                // cannot be built outside a request; skip rather than fail the
                // whole description over one endpoint.
                return [];
            }
        }

        return [];
    }

    /**
     * Only what the shape of the route establishes. A 200 body is not
     * described because nothing in the code declares one.
     *
     * @return array<string, mixed>
     */
    private function responses(Route $route): array
    {
        $responses = ['200' => ['description' => 'Success']];

        if ($this->security($route) !== []) {
            $responses['401'] = ['description' => 'Missing or invalid credentials'];
        }

        if ($this->rulesFor($route) !== []) {
            $responses['422'] = ['description' => 'Validation failed'];
        }

        return $responses;
    }

    /**
     * @return list<array<string, list<string>>>
     */
    private function security(Route $route): array
    {
        $schemes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            $name = class_basename(is_string($middleware) ? $middleware : '');

            if (isset(self::SECURITY[$name])) {
                $schemes[self::SECURITY[$name]] = true;
            }
        }

        return array_map(fn (string $scheme) => [$scheme => []], array_keys($schemes));
    }
}
