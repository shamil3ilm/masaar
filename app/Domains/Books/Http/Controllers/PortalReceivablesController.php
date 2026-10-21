<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Enums\PaymentMethod;
use App\Domains\Books\Http\Concerns\ReadsBooksPeriod;
use App\Domains\Books\Services\BooksPdf;
use App\Domains\Books\Services\CustomerPayments;
use App\Domains\Books\Services\CustomerStatements;
use App\Domains\Books\Services\Receivables;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\CsvExport;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What customers owe: open invoices and their ageing as at a date, one
 * invoice's payments with the form to record another, and a customer's
 * statement on screen or as a PDF. BooksAccess decides who may record.
 */
class PortalReceivablesController extends Controller
{
    use ReadsBooksPeriod;
    use ResolvesPortalOrganization;

    private const SORTS = ['due', 'balance', 'customer', 'number'];

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Receivables $receivables,
        private readonly CustomerPayments $payments,
        private readonly CustomerStatements $statements,
        private readonly BooksPdf $pdf,
    ) {}

    public function index(Request $request): View
    {
        [$rows, $asAt, $filters] = $this->rows($request);

        return view('portal.books.receivables', [
            'rows' => $rows,
            'ageing' => $this->receivables->ageing($rows),
            'asAt' => $asAt,
            'filters' => $filters,
            'sorts' => self::SORTS,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$rows, $asAt] = $this->rows($request);
        $money = fn (string $key): \Closure => fn (array $row): string => number_format($row[$key], 2, '.', '');

        return CsvExport::download('receivables-'.$asAt->toDateString().'.csv', [
            __('Invoice') => fn (array $row): string => $row['number'],
            __('Customer') => fn (array $row): string => $row['customer'],
            __('Issued') => fn (array $row): string => $row['issue_date'],
            __('Due') => fn (array $row): string => $row['due_date'],
            __('Currency') => fn (array $row): string => $row['currency'],
            __('Total') => $money('total'),
            __('Credited') => $money('credited'),
            __('Paid') => $money('paid'),
            __('Owed') => $money('balance'),
            __('Days overdue') => fn (array $row): string => (string) $row['days_overdue'],
            __('Ageing') => fn (array $row): string => Receivables::bucketLabel($row['bucket']),
            __('Status') => fn (array $row): string => Receivables::statusLabel($row['status']),
        ], $rows);
    }

    /** One invoice's payments, and the form to record one. */
    public function invoice(Request $request, string $invoiceId): View
    {
        $organizationId = $this->portalOrganizationId($request);
        $invoice = $this->payments->payable($organizationId, $invoiceId) ?? abort(404);
        $payments = $this->payments->of($invoice);

        return view('portal.books.invoice', [
            'invoice' => $invoice,
            'payments' => $payments,
            'reversed' => $payments->filter(fn ($payment): bool => $payment->reverses_id !== null)->pluck('reverses_id')->map(fn ($id): string => (string) $id)->all(),
            'owed' => $this->receivables->outstanding($invoice),
            'methods' => PaymentMethod::options(),
            'records' => MemberRole::keepsBooks($request->user()?->roleIn($organizationId)),
            'today' => $this->today($this->portalOrganization($this->tenant, $request)),
        ]);
    }

    public function pay(Request $request, string $invoiceId): RedirectResponse
    {
        $invoice = $this->payments->payable($this->portalOrganizationId($request), $invoiceId) ?? abort(404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_on' => ['required', 'date'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->payments->record($invoice, (string) $data['amount'], CarbonImmutable::parse($data['paid_on'])->startOfDay(),
                PaymentMethod::from($data['method']), $data['reference'] ?? null, $data['notes'] ?? null);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.books.invoices.show', $invoice->id)->with('success', __('Payment recorded.'));
    }

    public function reverse(Request $request, string $paymentId): RedirectResponse
    {
        $payment = $this->payments->find($this->portalOrganizationId($request), $paymentId) ?? abort(404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->payments->reverse($payment, $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.books.invoices.show', $payment->invoice_id)->with('success', __('Payment reversed.'));
    }

    public function statement(Request $request, string $customerId): View
    {
        return view('portal.books.statement', $this->statementData($request, $customerId));
    }

    public function statementPdf(Request $request, string $customerId): Response
    {
        $data = $this->statementData($request, $customerId);

        return $this->pdf->download('pdf.books-statement', $data, __('Statement of account'),
            'statement-'.$data['from']->toDateString().'-to-'.$data['to']->toDateString().'.pdf');
    }

    /** @return array<string, mixed> */
    private function statementData(Request $request, string $customerId): array
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $customer = $this->statements->customer((string) $organization->id, $customerId) ?? abort(404);
        [$from, $to] = $this->period($request, $organization);
        $currency = $request->validate(['currency' => ['nullable', 'string', 'size:3']])['currency'] ?? null;

        return [
            'organization' => $organization,
            'customer' => $customer,
            'from' => $from,
            'to' => $to,
            'statement' => $this->statements->for((string) $organization->id, $customer, $from, $to, $currency),
        ];
    }

    /**
     * @return array{0: Collection<int, array<string, mixed>>, 1: CarbonImmutable, 2: array<string, string>}
     */
    private function rows(Request $request): array
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        $asAt = $this->day($request, $organization, 'as_at');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['open', Receivables::UNPAID, Receivables::PART_PAID, Receivables::PAID, Receivables::CREDITED, Receivables::CREDIT, 'all'])],
            'sort' => ['nullable', Rule::in(self::SORTS)],
        ]);
        $status = $filters['status'] ?? 'open';
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        $rows = $this->receivables->asAt((string) $organization->id, $asAt)
            ->filter(fn (array $row): bool => match ($status) {
                'all' => true,
                'open' => abs($row['balance']) >= 0.005,
                default => $row['status'] === $status,
            })
            ->filter(fn (array $row): bool => $search === '' || str_contains(mb_strtolower($row['customer'].' '.$row['number']), $search));

        $rows = match ($filters['sort'] ?? 'due') {
            'balance' => $rows->sortByDesc('balance'),
            'customer' => $rows->sortBy('customer', SORT_NATURAL | SORT_FLAG_CASE),
            'number' => $rows->sortBy('number', SORT_NATURAL),
            default => $rows->sortBy('due_date'),
        };

        return [$rows->values(), $asAt, ['q' => (string) ($filters['q'] ?? ''), 'status' => $status, 'sort' => (string) ($filters['sort'] ?? 'due')]];
    }
}
