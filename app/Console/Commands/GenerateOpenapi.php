<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Responses\ApiResponse;
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
 * Everything here is read from something: paths and methods from the router,
 * credentials and scopes from the middleware, request bodies from the
 * FormRequest a controller asks for, and the response envelope from the
 * ApiResponse factory its method calls.
 *
 * What sits inside `data` is not described, because no endpoint declares it.
 * Naming a shape there would put a claim in the specification that nothing
 * enforces — which is how the hand-written one came to disagree with the API.
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
        'MetricsAccess' => 'metricsToken',
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
                    // Operational rather than customer-facing: the scrape
                    // endpoint admits an allowlisted source IP or this token,
                    // and is closed when neither is configured.
                    'metricsToken' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => $this->envelopes(),
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
     * The response envelopes, as App\Http\Responses\ApiResponse builds them.
     *
     * Taken from that class rather than composed here, so the description
     * stays a reading of the code. `data` is left untyped: what a given
     * endpoint puts inside the envelope is not declared anywhere, and naming
     * a shape for it would be the invention this file exists to avoid.
     *
     * @return array<string, mixed>
     */
    private function envelopes(): array
    {
        return [
            'Success' => [
                'type' => 'object',
                'required' => ['success'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'message' => ['type' => 'string'],
                    'data' => ['description' => 'Endpoint-specific payload'],
                ],
            ],
            'Paginated' => [
                'type' => 'object',
                'required' => ['success', 'data', 'meta'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'message' => ['type' => 'string'],
                    'data' => ['type' => 'array', 'items' => ['description' => 'Endpoint-specific item']],
                    'meta' => [
                        'type' => 'object',
                        'required' => ['current_page', 'last_page', 'per_page', 'total'],
                        'properties' => [
                            'current_page' => ['type' => 'integer'],
                            'last_page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                            'total' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'Error' => [
                'type' => 'object',
                'required' => ['success', 'error'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'error' => [
                        'type' => 'object',
                        'required' => ['message', 'code'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                            // ApiResponse::error puts validation failures here;
                            // the domain exception renderers add a category.
                            'details' => ['description' => 'Field errors, where the failure has them'],
                            'category' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
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

        // Routes registered in deprecated.php still answer, with a redirect.
        // Describing them as ordinary endpoints is how integrators end up
        // building against a prefix that is on its way out.
        if ($this->isDeprecated($route)) {
            $operation['deprecated'] = true;
        }

        // Which scopes the credential needs is the question integrators
        // actually hit, and it is enforced by middleware rather than stated
        // anywhere a reader would find it.
        if ($scopes = $this->scopes($route)) {
            $operation['description'] = 'Requires scope: '.implode(', ', $scopes);
        }

        return $operation;
    }

    /**
     * Whether the route declares itself deprecated.
     *
     * Read from the route's own defaults rather than inferred, so a route
     * says what it is at the point it is defined. Nothing else distinguishes
     * these: they are closures returning a redirect, with no controller to
     * inspect and no file recorded on the action.
     */
    private function isDeprecated(Route $route): bool
    {
        return (bool) ($route->defaults['deprecated'] ?? false);
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

    /**
     * A name unique across the document, as OpenAPI requires.
     *
     * Controller and method alone is not unique: twenty-three controller
     * methods are routed on both the session surface and the licence surface,
     * so `complianceController.submit` names two operations. The surface is
     * part of the identity, and prefixing it keeps the id readable where
     * folding the whole path into the name would not.
     */
    private function operationId(Route $route, string $method): string
    {
        $prefix = str_starts_with($route->uri(), 'api/v1/') ? 'v1.' : '';
        $action = $route->getActionName();

        // apiResource registers update under both PUT and PATCH, one method
        // serving two verbs on one path, so the verb joins the identity too.
        $verbs = array_diff($route->methods(), ['HEAD', 'OPTIONS']);
        $suffix = count($verbs) > 1 ? '.'.strtolower($method) : '';

        if (str_contains($action, '@')) {
            [$class, $function] = explode('@', $action);

            return $prefix.lcfirst(class_basename($class)).'.'.$function.$suffix;
        }

        return strtolower($method).Str::studly(str_replace(['/', '{', '}', '?'], ['-', '', '', ''], $route->uri()));
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
        $body = $this->methodBody($route);

        // Built from the class rather than written as a literal, so renaming
        // ApiResponse moves this with it instead of silently describing every
        // endpoint as returning the plain envelope.
        $factory = class_basename(ApiResponse::class);

        $created = str_contains($body, $factory.'::created(');
        $envelope = str_contains($body, $factory.'::paginated(') ? 'Paginated' : 'Success';

        $status = $created ? '201' : '200';

        $responses = [
            $status => [
                'description' => $created ? 'Created' : 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/'.$envelope],
                    ],
                ],
            ],
        ];

        if ($this->security($route) !== []) {
            $responses['401'] = $this->errorResponse('Missing or invalid credentials');
        }

        if ($this->rulesFor($route) !== []) {
            $responses['422'] = $this->errorResponse('Validation failed');
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Error'],
                ],
            ],
        ];
    }

    /**
     * The controller method's source.
     *
     * Which envelope an endpoint returns is not declared anywhere, so it is
     * read from the ApiResponse call the method makes. Reading the source is
     * blunt, but the alternative is asserting a shape nothing checks — and a
     * wrong response schema is the kind of thing an SDK is generated from.
     */
    private function methodBody(Route $route): string
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return '';
        }

        [$class, $function] = explode('@', $action);

        if (! class_exists($class) || ! method_exists($class, $function)) {
            return '';
        }

        $method = new ReflectionMethod($class, $function);
        $file = $method->getFileName();

        if ($file === false) {
            return '';
        }

        $lines = file($file) ?: [];
        $start = $method->getStartLine() - 1;

        return implode('', array_slice($lines, $start, $method->getEndLine() - $start));
    }

    /**
     * @return list<array<string, list<string>>>
     */
    private function security(Route $route): array
    {
        $schemes = [];

        foreach ($this->middlewareClasses($route) as $class) {
            $name = class_basename($class);

            if (isset(self::SECURITY[$name])) {
                $schemes[self::SECURITY[$name]] = true;
            }
        }

        return array_map(fn (string $scheme) => [$scheme => []], array_keys($schemes));
    }

    /**
     * Middleware as class names.
     *
     * gatherMiddleware() reports whatever the route was registered with, which
     * is usually an alias — "license", not ValidateLicense — and sometimes
     * carries parameters, as "scope:invoice.read" does. Reading the class off
     * that string directly matches nothing, which silently describes every
     * route as public.
     *
     * @return list<string>
     */
    private function middlewareClasses(Route $route): array
    {
        $aliases = Router::getMiddleware();
        $classes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $name = explode(':', $middleware)[0];
            $classes[] = $aliases[$name] ?? $name;
        }

        return $classes;
    }

    /**
     * Scopes the licence must carry, from the scope: middleware parameters.
     *
     * @return list<string>
     */
    private function scopes(Route $route): array
    {
        $scopes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'scope:')) {
                $scopes = array_merge($scopes, explode(',', substr($middleware, 6)));
            }
        }

        return array_values(array_unique($scopes));
    }
}
