<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\BillingServiceProvider;
use App\Domains\Billing\Exceptions\PaymentGatewayException;
use App\Domains\Billing\Gateways\SimulatedPaymentGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The card simulator's "provider page": the payment, and a button to
 * approve it and one to decline it. Answers only while the simulator is the
 * card gateway, and is never registered in production.
 */
class PaymentSimulatorController extends Controller
{
    public function __construct(
        private readonly SimulatedPaymentGateway $simulator,
    ) {}

    public function show(string $sessionId): View
    {
        $this->assertSimulating();

        try {
            $session = $this->simulator->session($sessionId);
        } catch (PaymentGatewayException) {
            abort(404);
        }

        return view('billing.simulator', ['sessionId' => $sessionId, 'session' => $session]);
    }

    public function decide(Request $request, string $sessionId): RedirectResponse
    {
        $this->assertSimulating();

        try {
            return redirect()->to($this->simulator->decide($sessionId, $request->input('outcome') === 'approve'));
        } catch (PaymentGatewayException) {
            abort(404);
        }
    }

    private function assertSimulating(): void
    {
        abort_unless(config('billing.gateway') === BillingServiceProvider::SIMULATOR, 404);
    }
}
