<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Platform\Models\ApprovalRequest;

/**
 * What asking for an action came to: done now, with its result, or waiting
 * for approval, as a request.
 */
final readonly class Outcome
{
    private function __construct(
        public ?ActionResult $result,
        public ?ApprovalRequest $request,
    ) {}

    public static function done(ActionResult $result): self
    {
        return new self($result, null);
    }

    public static function waiting(ApprovalRequest $request): self
    {
        return new self(null, $request);
    }

    public function waits(): bool
    {
        return $this->request !== null;
    }
}
