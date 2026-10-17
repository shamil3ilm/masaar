<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Http\Requests;

use App\Domains\Invoice\Services\IdempotentDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of pipeline submissions: the organization, and the invoices.
 *
 * Only the envelope is checked here. Each invoice is checked on its own, as
 * a PipelineSubmitRequest, when its turn comes (PipelineController::batch),
 * so one that is wrong is answered on its own line instead of refusing the
 * others.
 */
class PipelineBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'org_id' => ['required', 'string', 'uuid'],
            'invoices' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('pipeline.batch.max_items', 50)],
            'invoices.*' => ['required', 'array'],
            // Each invoice's own Idempotency-Key, since one header cannot
            // name many requests. Distinct, or two invoices of one batch
            // would answer as one.
            'invoices.*.idempotency_key' => ['nullable', 'string', 'max:'.IdempotentDraft::MAX_KEY_LENGTH, 'distinct'],
        ];
    }
}
