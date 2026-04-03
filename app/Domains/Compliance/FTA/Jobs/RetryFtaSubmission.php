<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Jobs;

use App\Domains\Compliance\FTA\Models\FtaSubmission;
use App\Domains\Compliance\FTA\Services\FtaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryFtaSubmission implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // handled by our own retry logic

    public function __construct(
        private readonly FtaSubmission $submission,
    ) {}

    public function handle(FtaService $service): void
    {
        $service->retry($this->submission);
    }
}
