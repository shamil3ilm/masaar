<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\DTOs\OutgoingPayment;
use App\Domains\Billing\Http\Requests\RecordPayablePaymentRequest;
use App\Domains\Billing\Http\Requests\ReversePayablePaymentRequest;
use App\Domains\Billing\Http\Requests\StoreSupplierCreditRequest;
use App\Domains\Billing\Http\Requests\WriteOffPayableRequest;
use App\Domains\Billing\Models\Expense;
use App\Domains\Billing\Services\Expenses;
use App\Domains\Billing\Services\Payables;
use App\Domains\Billing\Services\Statements;
use App\Domains\Billing\Services\SupplierCredits;
use App\Domains\Billing\Services\SupplierPayments;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\Actions\ExpenseActions;
use App\Domains\Platform\Approvals\Approvals;
use App\Domains\Platform\Http\Concerns\HoldsForApproval;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin › Finance › Payables: what the platform owes its suppliers, by
 * age; and paying them (billing.manage). A large payment and every
 * write-off go through their approval policies.
 */
class AdminPayablesController extends Controller
{
    use HoldsForApproval;

    public function __construct(
        private readonly Payables $payables,
        private readonly Expenses $expenses,
        private readonly SupplierPayments $payments,
        private readonly Approvals $approvals,
        private readonly Lists $lists,
        private readonly SupplierCredits $credits,
    ) {}

    public function index(Request $request): View
    {
        $list = $this->listState($request, 'admin.finance.payables.export');

        return view('admin.finance.payables', [
            'payables' => $this->payables->page($list),
            'list' => $list,
            'ageing' => $this->payables->ageing(CarbonImmutable::now()->startOfDay()),
            'headings' => Statements::headings(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->payables->csv($this->listState($request), 'payables-'.now()->format('Y-m-d').'.csv');
    }

    /**
     * Money paid against the expense. A large one waits for approval while
     * its policy is on; a smaller one is recorded at once.
     */
    public function pay(RecordPayablePaymentRequest $request, string $expenseId): RedirectResponse
    {
        $expense = $this->expense($request, $expenseId);
        $data = $request->validated();
        $payment = OutgoingPayment::fromArray($data);
        $done = fn (ActionResult $result): RedirectResponse => redirect()->route('admin.finance.expenses.show', $expenseId)->with('success', $result->message);

        try {
            if ($this->payments->large($expense, $payment->amount)) {
                return $this->holdOrRun($this->approvals, $request, ExpenseActions::PAY, ['expense_id' => $expenseId, 'payment' => $payment->toArray()], $done);
            }

            $paid = $this->payments->pay($expense, $payment, (string) $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $done(new ActionResult(__('Payment of :amount recorded.', ['amount' => $paid->amount.' '.$paid->currency])));
    }

    public function writeOff(WriteOffPayableRequest $request, string $expenseId): RedirectResponse
    {
        $this->expense($request, $expenseId);
        $data = $request->validated();

        try {
            return $this->holdOrRun($this->approvals, $request, ExpenseActions::WRITE_OFF, ['expense_id' => $expenseId, 'reason' => $data['reason']],
                fn (ActionResult $done): RedirectResponse => redirect()->route('admin.finance.expenses.show', $expenseId)->with('success', $done->message), $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** A credit note the supplier sent against the bill. */
    public function credit(StoreSupplierCreditRequest $request, string $expenseId): RedirectResponse
    {
        $expense = $this->expense($request, $expenseId);
        $data = $request->validated();

        try {
            $note = $this->credits->credit($expense, (string) $data['net'], (string) ($data['vat'] ?? '0'), CarbonImmutable::parse($data['credit_date']),
                $data['supplier_credit_number'] ?? null, $data['reason'], (string) $request->user()?->id);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.finance.expenses.show', $expenseId)->with('success', __('Credit note of :amount recorded.', ['amount' => $note->gross.' '.$note->currency]));
    }

    /** A payment recorded in error, taken back. */
    public function reverse(ReversePayablePaymentRequest $request, string $expenseId, string $paymentId): RedirectResponse
    {
        $this->expense($request, $expenseId);
        $data = $request->validated();
        $payment = $this->payments->find($expenseId, $paymentId) ?? abort(404);

        try {
            $this->payments->reverse($payment, $data['reason'], (string) $request->user()?->id);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.finance.expenses.show', $expenseId)->with('success', __('Payment of :amount taken back.', ['amount' => $payment->amount.' '.$payment->currency]));
    }

    private function expense(Request $request, string $expenseId): Expense
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['Expense', $expenseId]);

        return $this->expenses->find($expenseId) ?? abort(404);
    }

    private function listState(Request $request, ?string $export = null): ListState
    {
        return $this->lists->admin($this->payables->spec(), $request, $export);
    }
}
