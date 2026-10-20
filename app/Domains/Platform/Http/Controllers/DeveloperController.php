<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Developers\ApiReference;
use App\Domains\Platform\Developers\QuickStart;
use App\Domains\Webhook\Services\WebhookEvents;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * /developers: the public page an integrator starts from - how to
 * authenticate, a quick start in four languages, the webhook events, and the
 * API reference rendered from the OpenAPI description. Public and read-only:
 * it shows what the repository's own documentation shows, and nothing of any
 * organization's.
 *
 * Everything on it is served from here, under the strict content security
 * policy: no script, style or font from anywhere else.
 */
class DeveloperController extends Controller
{
    public function __construct(
        private readonly ApiReference $reference,
        private readonly QuickStart $quickStart,
    ) {}

    public function __invoke(): View
    {
        return view('home.developers', [
            'base' => rtrim((string) config('app.url'), '/'),
            'version' => $this->reference->version(),
            'schemes' => $this->reference->schemes(),
            'groups' => $this->reference->groups(),
            'submissions' => $this->quickStart->submissions(),
            'status' => $this->quickStart->status(),
            'webhook' => $this->quickStart->webhook(),
            'languages' => QuickStart::LANGUAGES,
            'events' => WebhookEvents::labels(),
        ]);
    }
}
