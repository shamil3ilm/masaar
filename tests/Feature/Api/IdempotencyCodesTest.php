<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Invoice\Exceptions\IdempotencyKeyReusedException;
use App\Http\Responses\ApiResponse;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * One vocabulary for an Idempotency-Key's refusals, whichever path answers.
 *
 * The middleware and an invoice's own key answered IDEMPOTENCY_KEY_REUSED
 * with 422; the Saudi filing path answered IDEM_003 with 400 for the same
 * mistake, so a client had two codes and two statuses to handle for one.
 */
class IdempotencyCodesTest extends TestCase
{
    public function test_a_reused_key_answers_alike(): void
    {
        $request = Request::create('/api/v1/pipeline/submit', 'POST');
        $fromKey = IdempotencyKeyReusedException::forRequest()->render($request);
        $fromFiling = ApiResponse::fromFatoora(new ComplianceException('reused', ErrorCode::IDEMPOTENCY_KEY_REUSED));

        foreach ([$fromKey, $fromFiling] as $response) {
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(['IDEMPOTENCY_KEY_REUSED', 'idempotency', false], [
                $response->getData(true)['error']['code'],
                $response->getData(true)['error']['category'],
                $response->getData(true)['error']['retryable'],
            ]);
        }
    }

    public function test_in_progress_is_a_conflict(): void
    {
        $code = ErrorCode::IDEMPOTENCY_KEY_IN_PROGRESS;

        $this->assertSame([409, 'idempotency', true], [$code->getHttpStatus(), $code->getCategory(), $code->isRetryable()]);
        $this->assertNull(ErrorCode::tryFrom('IDEM_003'));
        $this->assertNull(ErrorCode::tryFrom('IDEM_004'));
    }
}
