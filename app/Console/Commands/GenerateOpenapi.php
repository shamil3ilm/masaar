<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Webhook\Services\WebhookService;
use App\Http\Pagination\Keyset;
use App\Http\Pagination\PageSize;
use App\Http\Responses\ApiResponse;
use App\Support\Brand;
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
    protected $signature = 'platform:openapi
                            {--path=docs/openapi.yaml : Where to write}
                            {--reference=resources/developers/openapi.json : Where to write the copy the developer page reads}
                            {--print : Write the YAML to stdout instead of the files}
                            {--print-reference : Write the developer page copy to stdout instead of the files}';

    protected $description = 'Generate the OpenAPI description from the route table';

    /**
     * Middleware class => the security schemes it enforces, all of them at
     * once.
     *
     * ValidateLicense wants the key and the secret together. It was described
     * with the key alone, so a client generated from this spec - or an
     * integrator reading it - sent no secret, and every partner call was
     * refused.
     */
    /** The header a retry repeats so it is answered rather than acted on again. */
    private const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /** The header an answer carries when it repeats an earlier one. */
    private const REPLAYED_HEADER = 'Idempotent-Replayed';

    /**
     * The paths /developers publishes, by prefix: the partner API, the
     * sign-in that gives a customer's people their token, and the tenant API
     * they call with it. Anything not listed - the console (/api/admin), the
     * probes (/api/health), the metrics scrape, the network provider's
     * callbacks - is the platform's own and is not published.
     */
    private const PUBLISHED = [
        '/api/v1',
        '/api/auth',
        '/api/invoices',
        '/api/customers',
        '/api/products',
        '/api/schedules',
        '/api/settings/numbering',
        '/api/organizations',
        '/api/compliance',
        '/api/webhooks',
        '/api/dashboard',
    ];

    /**
     * And by path: what a licence reads about itself. Not the whole of
     * /api/license, whose /status is the deployment's own licence.
     */
    private const PUBLISHED_PATHS = [
        '/api/license',
        '/api/license/features/{feature}',
        '/api/license/health',
        '/api/license/quotas',
        '/api/license/usage',
    ];

    /** @var array<string, true> paths with a route behind platform.admin, never published */
    private array $adminOnly = [];

    private const SECURITY = [
        'JwtGuard' => ['bearerAuth'],
        'ValidateLicense' => ['apiKey', 'apiSecret'],
        'ApiKeyAuth' => ['apiKey'],
        'MetricsAccess' => ['metricsToken'],
    ];

    public function handle(): int
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => Brand::name('en').' Compliance API',
                'version' => (string) config('app.version', '1.0.0'),
                'description' => 'GCC e-invoicing compliance. Generated from the route table by '
                    .'`php artisan platform:openapi` — edit the routes, not this file.',
            ],
            'servers' => [
                ['url' => 'https://api.example.com', 'description' => 'Production'],
                ['url' => 'https://sandbox.example.com', 'description' => 'Sandbox'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                    'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                    'apiSecret' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Secret'],
                    // Operational rather than customer-facing: the scrape
                    // endpoint admits an allowlisted source IP or this token,
                    // and is closed when neither is configured.
                    'metricsToken' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => [...$this->envelopes(), 'WebhookEvent' => $this->webhookEvent()],
            ],
            'paths' => $this->paths(),
        ];

        // The layout is pinned rather than left to the library's default,
        // which changed in symfony/yaml 8.1.6: a patch upgrade rewrote the
        // whole committed spec, and the drift test read that as a changed API.
        $yaml = Yaml::dump($spec, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_COMPACT_NESTED_MAPPING);

        // The public part of the same description as JSON, for the public
        // developer page (/developers). docs/ is not part of a production
        // image and the YAML parser is a development dependency, so the page
        // reads this copy, written beside the YAML from the same array; the
        // drift test holds it equal to the routes as the YAML is held.
        // Without the title: the page names the product from the brand when
        // it renders, so a rename reaches it without regenerating.
        $reference = $this->published($spec);
        unset($reference['info']['title']);
        $json = json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if ($this->option('print')) {
            $this->line($yaml);

            return self::SUCCESS;
        }

        if ($this->option('print-reference')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $path = base_path((string) $this->option('path'));
        file_put_contents($path, $yaml);

        $reference = base_path((string) $this->option('reference'));

        if (! is_dir(dirname($reference))) {
            mkdir(dirname($reference), 0755, true);
        }

        file_put_contents($reference, $json);

        $this->info(sprintf('Wrote %d paths to %s', count($spec['paths']), $path));

        return self::SUCCESS;
    }

    /**
     * What /developers publishes: the partner API and the API a customer's
     * own people call, and only those (PUBLISHED, PUBLISHED_PATHS), with the
     * security schemes they use. The console's and the platform's routes,
     * the probes, the metrics scrape and the provider's callbacks stay in
     * docs/openapi.yaml for the team, and off the public page; a route
     * behind platform.admin is left out whatever its path.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function published(array $spec): array
    {
        $paths = array_filter(
            $spec['paths'],
            fn (string $path): bool => ! isset($this->adminOnly[$path]) && $this->isPublished($path),
            ARRAY_FILTER_USE_KEY,
        );

        $schemes = [];

        foreach ($paths as $operations) {
            foreach ($operations as $operation) {
                foreach ($operation['security'] ?? [] as $requirement) {
                    $schemes += array_flip(array_keys($requirement));
                }
            }
        }

        $spec['paths'] = $paths;
        $spec['components']['securitySchemes'] = array_intersect_key($spec['components']['securitySchemes'], $schemes);

        return $spec;
    }

    private function isPublished(string $path): bool
    {
        if (in_array($path, self::PUBLISHED_PATHS, true)) {
            return true;
        }

        foreach (self::PUBLISHED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The events a webhook may subscribe to, as WebhookService::EVENTS
     * lists them, and "*" for all of them: the `events` the webhook routes
     * take.
     *
     * @return array<string, mixed>
     */
    private function webhookEvent(): array
    {
        return [
            'type' => 'string',
            'description' => 'An event a webhook subscribes to (WebhookService::EVENTS), or "*" for every one',
            'enum' => [...WebhookService::EVENTS, '*'],
        ];
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
            // Every key ApiResponse::error writes, and only those: closed, so
            // a key added to the envelope is undescribed until it is named
            // here (ErrorSchemaTest).
            // ApiResponse::cursor: a page walked by cursor (Keyset).
            'CursorPage' => [
                'type' => 'object',
                'required' => ['success', 'data', 'meta'],
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => true],
                    'data' => ['type' => 'array', 'items' => ['description' => 'Endpoint-specific item']],
                    'meta' => [
                        'type' => 'object',
                        'required' => ['per_page', 'next_cursor'],
                        'properties' => [
                            'per_page' => ['type' => 'integer'],
                            'next_cursor' => ['type' => ['string', 'null'], 'description' => 'Send as ?cursor= for the next page; null on the last'],
                        ],
                    ],
                ],
            ],
            'Error' => [
                'type' => 'object',
                'required' => ['success', 'error'],
                'additionalProperties' => false,
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message', 'category', 'retryable'],
                        'additionalProperties' => false,
                        'properties' => [
                            'code' => ['type' => 'string', 'description' => 'A platform code named from the status (NOT_FOUND, VALIDATION_ERROR ...), a named code, or a compliance code (VAL_025, ZATCA_003 ...): docs/integration/ERRORS.md'],
                            'message' => ['type' => 'string'],
                            'category' => ['type' => 'string', 'description' => 'Which vocabulary code is from, or the kind of failure: authentication, validation, rate_limit, system, request, license ...'],
                            'retryable' => ['type' => 'boolean', 'description' => 'True for 408, 429, 502, 503 and 504: the same request may succeed later'],
                            'retry_after' => ['type' => 'integer', 'description' => 'Seconds to wait before retrying, on a retryable error that says; also sent as Retry-After'],
                            'max_retries' => ['type' => 'integer', 'description' => 'How many times a retryable compliance error is worth retrying'],
                            'details' => ['type' => 'object', 'description' => 'Field errors (field => messages) for a 422, or the context the failure carries'],
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

            if (in_array('platform.admin', $route->gatherMiddleware(), true)) {
                $this->adminOnly[$uri] = true;
            }

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

        // Which scopes the credential needs is the question integrators
        // actually hit, and it is enforced by middleware rather than stated
        // anywhere a reader would find it.
        if ($scopes = $this->scopes($route)) {
            $operation['description'] = 'Requires scope: '.implode(', ', $scopes);
        }

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
     * The path's parameters, and the headers the controller method reads.
     *
     * @return list<array<string, mixed>>
     */
    private function parameters(Route $route): array
    {
        $parameters = array_map(
            fn (string $name) => [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ],
            $route->parameterNames()
        );

        if ($this->walksByCursor($route)) {
            $parameters = [...$parameters, ...self::listParameters()];
        }

        // Read from the method's source, as the envelope is: a header the
        // method asks the request for is one it acts on.
        if ($this->takesKey($route)) {
            $parameters[] = [
                'name' => self::IDEMPOTENCY_HEADER,
                'in' => 'header',
                'required' => false,
                'description' => 'Names one request. A retry with the same key answers with what the first '
                    .'request made instead of making it again. The key is kept with a SHA-256 of the request body '
                    .'(object keys sorted), and the same key sent with a different body is refused: 422 IDEMPOTENCY_KEY_REUSED.',
                'schema' => ['type' => 'string', 'maxLength' => 255],
            ];
        }

        return $parameters;
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

        $properties = $this->members($rules, $properties);

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
     * The members of a top-level array, one level down, from its "x.*.y"
     * rules: what a line of an invoice holds, or an invoice of a batch.
     * Deeper keys are left out, as nested keys were before.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, array<string, mixed>>
     */
    private function members(array $rules, array $properties): array
    {
        foreach ($rules as $field => $rule) {
            if (preg_match('/^([^.]+)\.\*\.([^.*]+)$/', (string) $field, $m) !== 1
                || ($properties[$m[1]]['type'] ?? null) !== 'array') {
                continue;
            }

            $rule = array_map(fn ($r) => is_string($r) ? $r : '', is_array($rule) ? $rule : explode('|', (string) $rule));

            $properties[$m[1]]['items']['type'] = 'object';
            $properties[$m[1]]['items']['properties'][$m[2]] = ['type' => $this->typeFor($rule)];
        }

        return $properties;
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
        $schema = ['$ref' => '#/components/schemas/'.$envelope];

        // Page numbers, or a cursor when the request sends one (Keyset).
        if ($this->walksByCursor($route)) {
            $schema = ['oneOf' => [$schema, ['$ref' => '#/components/schemas/CursorPage']]];
        }

        $status = $created ? '201' : '200';

        $responses = [
            $status => [
                'description' => $created ? 'Created' : 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => $schema,
                    ],
                ],
            ],
        ];

        // A retry under the same key answered with the first request's own
        // answer, status and all, by the idempotent middleware.
        if ($this->idempotentRoute($route)) {
            $responses[$status]['headers'][self::REPLAYED_HEADER] = self::replayedHeader();
            $responses['409'] = $this->errorResponse('A request under the same Idempotency-Key is still being answered (IDEMPOTENCY_KEY_IN_PROGRESS); send it again shortly');
        }

        // A retry under the same key answered from what the first request
        // made, as the method says when it sets the header.
        if (str_contains($body, "'".self::REPLAYED_HEADER."'")) {
            $responses['200'] ??= [
                'description' => 'Replayed: the first request\'s result',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Success']]],
            ];
            $responses['200']['headers'][self::REPLAYED_HEADER] = self::replayedHeader();
        }

        // The pipeline answers 207 when a step after drafting did not go
        // through: its own literal, or the outcome it shares with submit.
        if (preg_match('/\b207\b|->outcome\(/', $body) === 1) {
            $responses['207'] = [
                'description' => 'Partly done: a step after the first did not go through (for an invoice: drafted but not issued, or issued but not filed); data.errors says which',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Success']]],
            ];
        }

        // A state the request cannot act on: a draft asked for its PDF, a
        // schedule paused twice. Read from the status the method answers.
        if (preg_match('/,\s*409\)/', $body) === 1) {
            $responses['409'] ??= $this->errorResponse('Conflict: the record\'s state does not allow it (CONFLICT); error.message says why');
        }

        if ($this->security($route) !== []) {
            $responses['401'] = $this->errorResponse('Missing or invalid credentials');
            $responses['403'] = $this->errorResponse('Refused: the credential\'s status, scope, licence feature, environment or role');
        }

        if ($route->parameterNames() !== []) {
            $responses['404'] = $this->errorResponse('Not found, or not the caller\'s organization\'s');
        }

        if ($this->rulesFor($route) !== [] || $this->takesKey($route)) {
            $responses['422'] = $this->errorResponse('Validation failed (VALIDATION_ERROR), or the Idempotency-Key was used with another body (IDEMPOTENCY_KEY_REUSED)');
        }

        if ($this->throttled($route)) {
            $responses['429'] = [
                ...$this->errorResponse('Too many requests, or the licence\'s allowance is used up'),
                'headers' => ['Retry-After' => [
                    'description' => 'Seconds to wait, where the limit says',
                    'schema' => ['type' => 'integer'],
                ]],
            ];
        }

        ksort($responses);

        return $responses;
    }

    /** Whether the list can be walked by cursor (Keyset), as its method says. */
    private function walksByCursor(Route $route): bool
    {
        return str_contains($this->methodBody($route), class_basename(Keyset::class).'::requested(');
    }

    /**
     * The query parameters of a list that pages by number or by cursor.
     *
     * @return list<array<string, mixed>>
     */
    private static function listParameters(): array
    {
        $query = fn (string $name, string $description, array $schema): array => ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $description, 'schema' => $schema];

        return [
            $query(Keyset::CURSOR, 'Walk by cursor: empty for the first page, then each answer\'s meta.next_cursor until it is null. Oldest change first (updated_at, then id); a record changed during the walk comes again later. Answers CursorPage', ['type' => 'string']),
            $query(Keyset::SINCE, 'Only records changed at or after this time (ISO 8601). Start the next sync from the last record\'s updated_at; expect a record or two again', ['type' => 'string', 'format' => 'date-time']),
            $query('per_page', 'Records a page, at most '.PageSize::MAX, ['type' => 'integer']),
            $query('page', 'The page, without a cursor (newest first)', ['type' => 'integer']),
        ];
    }

    /** @return array<string, mixed> */
    private static function replayedHeader(): array
    {
        return [
            'description' => '"true" when the answer is a replay of an earlier request under the same Idempotency-Key, or of a step already done',
            'schema' => ['type' => 'string', 'enum' => ['true']],
        ];
    }

    /**
     * Whether the route acts on an Idempotency-Key: the method reads the
     * header itself, or the idempotent middleware stands in front of it.
     */
    private function takesKey(Route $route): bool
    {
        return $this->idempotentRoute($route)
            || str_contains($this->methodBody($route), "header('".self::IDEMPOTENCY_HEADER."')");
    }

    private function idempotentRoute(Route $route): bool
    {
        return in_array('idempotent', $route->gatherMiddleware(), true);
    }

    /** Whether a rate limit stands in front of the route. */
    private function throttled(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && in_array(explode(':', $middleware)[0], ['rate.api', 'throttle', 'license'], true)) {
                return true;
            }
        }

        return false;
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
        $requirements = [];

        foreach ($this->middlewareClasses($route) as $class) {
            $name = class_basename($class);

            // One requirement per middleware, naming every scheme it needs:
            // in OpenAPI the schemes inside one requirement are all required,
            // while separate requirements are alternatives.
            if (isset(self::SECURITY[$name])) {
                $requirements[$name] = array_fill_keys(self::SECURITY[$name], []);
            }
        }

        return array_values($requirements);
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
