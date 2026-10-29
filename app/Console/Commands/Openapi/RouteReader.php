<?php

declare(strict_types=1);

namespace App\Console\Commands\Openapi;

use App\Domains\Platform\Http\Middleware\Deprecated;
use App\Http\Pagination\Keyset;
use App\Http\Responses\AnswersError;
use App\Http\Responses\ResponseData;
use App\Support\Errors\ErrorCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * What a route says about itself, for platform:openapi: its controller
 * method's source and declared data, the rules of the FormRequest it asks
 * for, and the credentials, scopes, keys and limits its middleware enforce.
 */
final class RouteReader
{
    /** The header a retry repeats so it is answered rather than acted on again. */
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /**
     * Middleware class => the security schemes it enforces, all of them at
     * once.
     *
     * ValidateLicense wants the key and the secret together. It was described
     * with the key alone, so a client generated from this spec - or an
     * integrator reading it - sent no secret, and every partner call was
     * refused.
     */
    private const SECURITY = [
        'JwtGuard' => ['bearerAuth'],
        'ValidateLicense' => ['apiKey', 'apiSecret'],
        'ApiKeyAuth' => ['apiKey'],
        'MetricsAccess' => ['metricsToken'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function rulesFor(Route $route): array
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

    /** What the method declares it puts in `data` (#[ResponseData]), if it does. */
    public function responseData(Route $route): ?ResponseData
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return null;
        }

        [$class, $function] = explode('@', $action);

        if (! class_exists($class) || ! method_exists($class, $function)) {
            return null;
        }

        $attribute = (new ReflectionMethod($class, $function))->getAttributes(ResponseData::class)[0] ?? null;

        return $attribute?->newInstance();
    }

    /**
     * The named refusals the method declares (#[AnswersError]).
     *
     * @return list<ErrorCode>
     */
    public function declaredErrors(Route $route): array
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return [];
        }

        [$class, $function] = explode('@', $action);

        if (! class_exists($class) || ! method_exists($class, $function)) {
            return [];
        }

        return array_map(
            static fn (ReflectionAttribute $attribute): ErrorCode => $attribute->newInstance()->code,
            (new ReflectionMethod($class, $function))->getAttributes(AnswersError::class),
        );
    }

    /** Whether the list can be walked by cursor (Keyset), as its method says. */
    public function walksByCursor(Route $route): bool
    {
        return str_contains($this->methodBody($route), class_basename(Keyset::class).'::requested(');
    }

    /**
     * Whether the route acts on an Idempotency-Key: the method reads the
     * header itself, or the idempotent middleware stands in front of it.
     */
    public function takesKey(Route $route): bool
    {
        return $this->idempotentRoute($route)
            || str_contains($this->methodBody($route), "header('".self::IDEMPOTENCY_HEADER."')");
    }

    public function idempotentRoute(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && explode(':', $middleware)[0] === 'idempotent') {
                return true;
            }
        }

        return false;
    }

    /**
     * The dates a route marked for removal names
     * (Deprecated::class.':SINCE,SUNSET'), or null for a route that stays.
     *
     * @return array{since: string, sunset: string, successor: string|null}|null
     */
    public function deprecation(Route $route): ?array
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, Deprecated::class.':')) {
                $parameters = explode(',', substr($middleware, strlen(Deprecated::class.':')));

                return ['since' => $parameters[0], 'sunset' => $parameters[1] ?? '', 'successor' => $parameters[2] ?? null];
            }
        }

        return null;
    }

    /** Whether a rate limit stands in front of the route. */
    public function throttled(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && in_array(explode(':', $middleware)[0], ['rate.api', 'throttle', 'license'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The controller method's source.
     *
     * Which envelope an endpoint returns is not declared anywhere, so it is
     * read from the ApiResponse call the method makes. Reading the source is
     * blunt, but the alternative is asserting a shape nothing checks — and a
     * wrong response schema is the kind of thing an SDK is generated from.
     */
    public function methodBody(Route $route): string
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
    public function security(Route $route): array
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
    public function scopes(Route $route): array
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
