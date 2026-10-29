<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\Errors\ErrorCode;
use Attribute;

/**
 * A named refusal a controller method can answer that its shape does not
 * show - raised in a service it calls - for the OpenAPI description
 * (platform:openapi), which describes it at the code's status with the code.
 * OpenapiDriftTest holds every declared code to its description.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class AnswersError
{
    public function __construct(
        public readonly ErrorCode $code,
    ) {}
}
