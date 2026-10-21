<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Http\Concerns\ReadsBooksPeriod;
use App\Domains\Books\Services\SalesReports;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The period's sales by customer, item, branch, VAT category or month, on
 * screen and as a CSV. Read by everyone in the organization.
 */
class PortalSalesReportController extends Controller
{
    use ReadsBooksPeriod;
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly SalesReports $reports,
    ) {}

    public function index(Request $request): View
    {
        return view('portal.books.sales', $this->report($request));
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->report($request);
        $report = $data['report'];
        $money = fn (string $key): \Closure => fn (array $row): string => number_format($row[$key], 2, '.', '');

        return CsvExport::download('sales-by-'.$data['group'].'-'.$data['from']->toDateString().'-to-'.$data['to']->toDateString().'.csv', [
            SalesReports::groupLabel($data['group']) => fn (array $row): string => $row['label'],
            __('Documents') => fn (array $row): string => (string) $row['documents'],
            __('Quantity') => fn (array $row): string => $row['quantity'] === null ? '' : (string) $row['quantity'],
            __('Net (:currency)', ['currency' => $report['currency']]) => $money('base'),
            __('VAT (:currency)', ['currency' => $report['currency']]) => $money('vat'),
            __('Total (:currency)', ['currency' => $report['currency']]) => $money('total'),
        ], $report['rows']);
    }

    /** @return array<string, mixed> */
    private function report(Request $request): array
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        [$from, $to] = $this->period($request, $organization);
        $data = $request->validate([
            'by' => ['nullable', Rule::in(SalesReports::GROUPS)],
            'sort' => ['nullable', Rule::in(['amount', 'name'])],
        ]);
        $group = $data['by'] ?? 'customer';
        $sort = $data['sort'] ?? 'amount';

        return [
            'report' => $this->reports->by($organization, $group, $from, $to, $sort),
            'group' => $group,
            'groups' => array_combine(SalesReports::GROUPS, array_map(fn (string $group): string => SalesReports::groupLabel($group), SalesReports::GROUPS)),
            'sort' => $sort,
            'from' => $from,
            'to' => $to,
        ];
    }
}
