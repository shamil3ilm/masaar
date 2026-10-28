<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Attribute;

/**
 * What a controller method puts in the envelope's `data`, for the OpenAPI
 * description (platform:openapi) and for the contract tests that hold real
 * answers to it (MatchesOpenapi).
 *
 * The schema is a component's name - one ResponseSchemas builds - or a
 * schema written out, for a shape a module owns. Under a key, data is an
 * object holding it there ({"invoice": {...}}); as a list, each item of a
 * paginated or cursor page is one.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ResponseData
{
    /**
     * @param  string|array<string, mixed>  $schema
     */
    public function __construct(
        public readonly string|array $schema,
        public readonly ?string $under = null,
        public readonly bool $list = false,
    ) {}
}
