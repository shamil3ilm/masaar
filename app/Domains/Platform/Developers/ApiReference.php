<?php

declare(strict_types=1);

namespace App\Domains\Platform\Developers;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The API reference the public developer page shows: docs/openapi.yaml, as
 * the JSON copy platform:openapi writes beside it
 * (resources/developers/openapi.json) - the same description, readable in
 * production, where docs/ is not shipped and the YAML parser is a
 * development dependency.
 *
 * Grouped for a reader rather than as the file lists it: the partner API
 * first - what an ERP or a store calls with a key and secret - then every
 * other endpoint by its tag.
 */
class ApiReference
{
    public const FILE = 'resources/developers/openapi.json';

    /** The partner API's paths: what an organization's own keys call. */
    public const PARTNER = '/api/v1/';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var array<string, mixed>|null */
    private ?array $spec = null;

    /**
     * @return array<string, mixed>
     */
    public function spec(): array
    {
        if ($this->spec !== null) {
            return $this->spec;
        }

        $text = @file_get_contents(base_path(self::FILE));

        if ($text === false) {
            throw new RuntimeException(self::FILE.' is missing. Run: php artisan platform:openapi');
        }

        return $this->spec = (array) json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    }

    public function version(): string
    {
        return (string) ($this->spec()['info']['version'] ?? '');
    }

    /**
     * The security schemes, by name: how each kind of caller proves who it is.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemes(): array
    {
        return (array) ($this->spec()['components']['securitySchemes'] ?? []);
    }

    /**
     * The partner API's operations, then everyone else's, each group by tag.
     *
     * @return array{partner: array<string, list<array<string, mixed>>>, other: array<string, list<array<string, mixed>>>}
     */
    public function groups(): array
    {
        $groups = ['partner' => [], 'other' => []];

        foreach ((array) ($this->spec()['paths'] ?? []) as $path => $operations) {
            foreach ((array) $operations as $method => $operation) {
                if (! in_array($method, self::METHODS, true) || ! is_array($operation)) {
                    continue;
                }

                $surface = str_starts_with((string) $path, self::PARTNER) ? 'partner' : 'other';
                $tag = (string) ($operation['tags'][0] ?? 'API');
                $groups[$surface][$tag][] = $this->operation((string) $path, $method, $operation);
            }
        }

        foreach ($groups as $surface => $tags) {
            ksort($tags);
            $groups[$surface] = $tags;
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function operation(string $path, string $method, array $operation): array
    {
        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? [];
        $required = (array) ($schema['required'] ?? []);

        return [
            'id' => 'op-'.Str::slug($method.' '.$path),
            'method' => strtoupper($method),
            'path' => $path,
            'summary' => (string) ($operation['summary'] ?? ''),
            'description' => (string) ($operation['description'] ?? ''),
            'security' => array_values(array_unique(array_merge(...array_map('array_keys', (array) ($operation['security'] ?? []))) ?: [])),
            'parameters' => array_map(fn (array $parameter): array => [
                'name' => (string) ($parameter['name'] ?? ''),
                'in' => (string) ($parameter['in'] ?? ''),
                'required' => (bool) ($parameter['required'] ?? false),
                'description' => (string) ($parameter['description'] ?? ''),
            ], array_values(array_filter((array) ($operation['parameters'] ?? []), 'is_array'))),
            'fields' => array_map(fn (string $name, mixed $property): array => [
                'name' => $name,
                'type' => $this->type(is_array($property) ? $property : []),
                'required' => in_array($name, $required, true),
            ], array_keys((array) ($schema['properties'] ?? [])), array_values((array) ($schema['properties'] ?? []))),
            'responses' => array_map(fn (string|int $code, mixed $response): array => [
                'code' => (string) $code,
                'description' => is_array($response) ? (string) ($response['description'] ?? '') : '',
            ], array_keys((array) ($operation['responses'] ?? [])), array_values((array) ($operation['responses'] ?? []))),
        ];
    }

    /**
     * A property's type as one word: a list of what, where it says.
     *
     * @param  array<string, mixed>  $property
     */
    private function type(array $property): string
    {
        $type = (string) ($property['type'] ?? 'object');

        if ($type === 'array' && isset($property['items']['type'])) {
            return 'array<'.$property['items']['type'].'>';
        }

        return $type;
    }
}
