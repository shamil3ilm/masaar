<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Http\Concerns\ReadsBooksPeriod;
use App\Domains\Books\Services\BooksCountry;
use App\Domains\Books\Services\BooksPdf;
use App\Domains\Compliance\Engine\Contracts\VatReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The VAT return drafted for a period, the country's own (VatReturn): on
 * screen with every figure linked to the documents behind it, as a CSV and
 * as a PDF. What the books cannot know is typed in and carried in the
 * query string, so a link or a file says the same as the page.
 */
class PortalVatReturnController extends Controller
{
    use ReadsBooksPeriod;
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BooksCountry $country,
        private readonly BooksPdf $pdf,
    ) {}

    public function show(Request $request): View
    {
        [$draft, $from, $to, $manual, $entries] = $this->draft($request);

        return view('portal.books.vat-return', [
            'draft' => $draft,
            'from' => $from,
            'to' => $to,
            'manual' => $manual,
            'entries' => $entries,
            'query' => $this->query($from, $to, $manual),
        ]);
    }

    /** The documents behind one box. */
    public function box(Request $request, string $box): View
    {
        [$draft, $from, $to, $manual] = $this->draft($request);

        return view('portal.books.vat-return-box', [
            'draft' => $draft,
            'box' => $draft->box($box) ?? abort(404),
            'from' => $from,
            'to' => $to,
            'query' => $this->query($from, $to, $manual),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$draft, $from, $to] = $this->draft($request);
        $rows = [];

        foreach ($draft->sections as $section => $boxes) {
            foreach ($boxes as $box) {
                $rows[] = [$section, $box];
            }
        }

        $money = fn (?float $value): string => $value === null ? '' : number_format($value, 2, '.', '');

        return CsvExport::download('vat-return-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', [
            __('Section') => fn (array $row): string => __($row[0]),
            __('Box') => fn (array $row): string => $row[1]->key,
            __('Description') => fn (array $row): string => __($row[1]->label),
            __('Amount (:currency)', ['currency' => $draft->currency]) => fn (array $row): string => $money($row[1]->amount),
            __('Adjustment (:currency)', ['currency' => $draft->currency]) => fn (array $row): string => $money($row[1]->adjustment),
            __('VAT (:currency)', ['currency' => $draft->currency]) => fn (array $row): string => $money($row[1]->vat),
            __('Documents') => fn (array $row): string => $row[1]->traced ? (string) count($row[1]->entries) : '',
        ], $rows);
    }

    public function pdf(Request $request): Response
    {
        [$draft, $from, $to] = $this->draft($request);
        $organization = $this->portalOrganization($this->tenant, $request);

        return $this->pdf->download('pdf.books-vat-return', [
            'draft' => $draft,
            'from' => $from,
            'to' => $to,
            'organization' => $organization,
        ], __($draft->title), 'vat-return-'.$from->toDateString().'-to-'.$to->toDateString().'.pdf');
    }

    /**
     * @return array{0: VatReturnDraft, 1: CarbonImmutable, 2: CarbonImmutable, 3: array<string, float>, 4: array<string, string>}
     */
    private function draft(Request $request): array
    {
        $organization = $this->portalOrganization($this->tenant, $request);
        [$from, $to] = $this->period($request, $organization);
        $return = $this->vatReturn($organization);
        $entries = $return->manualEntries();
        $data = $request->validate(array_map(fn (): array => ['nullable', 'numeric', 'between:-999999999,999999999'], $entries));
        $manual = array_map(fn ($value): float => (float) $value, array_filter($data, fn ($value): bool => $value !== null && $value !== ''));

        return [$return->draft((string) $organization->id, $from, $to, $manual), $from, $to, $manual, $entries];
    }

    private function vatReturn(Organization $organization): VatReturn
    {
        return $this->country->of($organization)->vatReturn();
    }

    /**
     * @param  array<string, float>  $manual
     * @return array<string, string>
     */
    private function query(CarbonImmutable $from, CarbonImmutable $to, array $manual): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), ...array_map(fn (float $value): string => (string) $value, $manual)];
    }
}
