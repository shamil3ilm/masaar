<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks a value against a schema in docs/openapi.yaml.
 *
 * The part of JSON Schema the generator writes, and no more: $ref, type
 * (one or a list, "null" among them), const, enum, required, properties,
 * additionalProperties, items, oneOf, anyOf. A keyword it does not know is a
 * failure rather than a pass, so the description cannot grow a rule this
 * check silently ignores.
 */
trait MatchesOpenapi
{
    /** @var array<string, mixed>|null */
    private static ?array $openapi = null;

    /**
     * The response's JSON matches the schema the description gives its
     * status, for that path and method.
     */
    private function assertResponseMatchesSpec(TestResponse $response, string $path, string $method): void
    {
        $status = (string) $response->status();
        $operation = $this->openapi()['paths'][$path][strtolower($method)] ?? null;
        $this->assertIsArray($operation, "{$method} {$path} is not described");
        $this->assertArrayHasKey($status, $operation['responses'], "{$method} {$path} does not describe {$status}: ".$response->getContent());

        $schema = $operation['responses'][$status]['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema, "{$method} {$path} {$status} has no JSON schema");

        $this->assertMatchesSchema($schema, $response->json(), "{$method} {$path} {$status}");
    }

    /**
     * @param  array<string, mixed>|string  $schema  a schema, or a component's name
     */
    private function assertMatchesSchema(array|string $schema, mixed $value, string $where = '$'): void
    {
        $schema = is_string($schema) ? ['$ref' => '#/components/schemas/'.$schema] : $schema;
        $problems = $this->schemaProblems($schema, $value, $where);

        $this->assertSame([], $problems, implode("\n", $problems)."\n".json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function schemaProblems(array $schema, mixed $value, string $at): array
    {
        if (isset($schema['$ref'])) {
            $name = substr((string) $schema['$ref'], strlen('#/components/schemas/'));
            $target = $this->openapi()['components']['schemas'][$name] ?? null;

            return is_array($target) ? $this->schemaProblems($target, $value, $at) : ["{$at}: unknown schema {$schema['$ref']}"];
        }

        $unknown = array_diff(array_keys($schema), ['type', 'const', 'enum', 'required', 'properties', 'additionalProperties', 'items', 'oneOf', 'anyOf', 'description', 'format', 'maxLength', 'examples']);

        if ($unknown !== []) {
            return ["{$at}: the check does not know ".implode(', ', $unknown)];
        }

        if (isset($schema['oneOf'])) {
            $matching = array_filter($schema['oneOf'], fn (array $option): bool => $this->schemaProblems($option, $value, $at) === []);

            if (count($matching) !== 1) {
                return ["{$at}: matches ".count($matching).' of the oneOf options'];
            }
        }

        if (isset($schema['anyOf']) && array_filter($schema['anyOf'], fn (array $option): bool => $this->schemaProblems($option, $value, $at) === []) === []) {
            return ["{$at}: matches none of the anyOf options"];
        }

        if (isset($schema['type']) && ! $this->isOfType($value, (array) $schema['type'])) {
            return ["{$at}: ".get_debug_type($value).' is not '.implode('|', (array) $schema['type'])];
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return ["{$at}: is not ".json_encode($schema['const'])];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$at}: ".json_encode($value).' is not one of '.implode(', ', array_map('json_encode', $schema['enum']))];
        }

        $problems = [];

        if (is_array($value) && ! array_is_list($value) || $value === [] && isset($schema['properties'])) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $problems[] = "{$at}.{$key}: required and missing";
                }
            }

            foreach ($value as $key => $member) {
                $memberSchema = $schema['properties'][$key] ?? $schema['additionalProperties'] ?? true;

                if ($memberSchema === false) {
                    $problems[] = "{$at}.{$key}: not described";
                } elseif (is_array($memberSchema)) {
                    $problems = [...$problems, ...$this->schemaProblems($memberSchema, $member, "{$at}.{$key}")];
                }
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $problems = [...$problems, ...$this->schemaProblems($schema['items'], $item, "{$at}[{$index}]")];
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $types
     */
    private function isOfType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                // JSON's [] decodes the same whether it was a list or an
                // object, so an empty array is either.
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function openapi(): array
    {
        return self::$openapi ??= Yaml::parseFile(base_path('docs/openapi.yaml'));
    }
}
