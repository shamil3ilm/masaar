<?php

declare(strict_types=1);

namespace App\Console\Commands\Openapi;

use App\Http\Pagination\Keyset;
use App\Http\Pagination\PageSize;
use Illuminate\Routing\Route;

/**
 * The request side of an operation, for platform:openapi: its path, query
 * and header parameters, and the body the FormRequest it asks for validates.
 */
final class RequestDescriber
{
    public function __construct(private readonly RouteReader $reader) {}

    /**
     * The path's parameters, and the headers the controller method reads.
     *
     * @return list<array<string, mixed>>
     */
    public function parameters(Route $route): array
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

        if ($this->reader->walksByCursor($route)) {
            $parameters = [...$parameters, ...self::listParameters()];
        }

        // Read from the method's source, as the envelope is: a header the
        // method asks the request for is one it acts on.
        if ($this->reader->takesKey($route)) {
            $parameters[] = [
                'name' => RouteReader::IDEMPOTENCY_HEADER,
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
    public function requestBody(Route $route): ?array
    {
        $rules = $this->reader->rulesFor($route);

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
}
