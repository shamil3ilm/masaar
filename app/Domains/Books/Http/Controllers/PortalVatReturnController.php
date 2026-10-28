<?php

declare(strict_types=1);

namespace App\Domains\Books\Http\Controllers;

use App\Domains\Books\Http\Concerns\ReadsBooksPeriod;
use App\Domains\Books\Services\BooksCountry;
use App\Domains\Books\Services\BooksPdf;
use App\Domains\Books\Services\VatPeriods;
use App\Domains\Compliance\Engine\Contracts\VatReturn;
use App\Domains\Compliance\Engine\DTOs\VatReturnDraft;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchScope;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\CsvExport;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The VAT return drafted for a period, the country's own (VatReturn): on
 * screen with every figure linked to the documents behind it, as a CSV and
 * as a PDF. What the books cannot know is typed in and carried in the
 * query string, so a link or a file says the same as the page.
 *
 * A return is the taxable person's, one per period for the whole
 * organization (KSA VAT Implementing Regulations Art 59; UAE VAT
 * Executive Regulation, Cabinet Decision 52 of 2017, Art 64), so it is drafted from every branch - sales and
 * purchases alike - whatever the branch switcher shows, and only someone who
 * sees every branch opens it. The sales VAT report gives figures per branch.
 *
 * And the periods filed (VatPeriods): an owner, admin or accountant marks
 * the drafted period filed, snapshotting its figures; the page then shows
 * what has moved since. Only an owner reopens one, with a reason.
 */
class PortalVatReturnController extends Controller
{
    use ReadsBooksPeriod;
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BooksCountry $country,
        private readonly BooksPdf $pdf,
        private readonly BranchScope $branches,
        private readonly VatPeriods $periods,
    ) {}

    /** Who may mark a period filed: those who keep the books and answer for them. */
    private const FILERS = ['owner', 'admin', 'accountant'];

    public function show(Request $request): View
    {
        [$draft, $from, $to, $manual, $entries] = $this->draft($request);
        $organizationId = $this->portalOrganizationId($request);
        $role = $request->user()?->roleIn($organizationId);
        $periods = $this->periods->all($organizationId);
        $filed = $periods->first(fn ($period): bool => $period->isFiled()
            && $period->starts_on->toDateString() === $from->toDateString() && $period->ends_on->toDateString() === $to->toDateString());

        return view('portal.books.vat-return', [
            'periods' => $periods,
            'filed' => $filed,
            'drift' => $filed === null ? [] : $this->periods->drift($filed, $draft),
            'canFile' => in_array($role, self::FILERS, true),
            'canReopen' => $role === MemberRole::Owner->value,
            'draft' => $draft,
            'from' => $from,
            'to' => $to,
            'manual' => $manual,
            'entries' => $entries,
            'query' => $this->query($from, $to, $manual),
        ]);
    }

    /** Mark the drafted period filed, with its figures as they stand. */
    public function file(Request $request): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        abort_unless(in_array($request->user()?->roleIn($organizationId), self::FILERS, true), 403, __('Only an owner, admin or accountant marks a VAT period filed.'));
        [$draft, $from, $to, $manual] = $this->draft($request);
        $data = $request->validate(['filed_on' => ['required', 'date']]);

        try {
            $this->periods->file($organizationId, $from, $to, CarbonImmutable::parse($data['filed_on']), $draft, (string) $request->user()?->id);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.books.vat-return', $this->query($from, $to, $manual))->with('success', __('VAT period marked filed.'));
    }

    /** Open a filed period again: its owner's decision, with a reason. */
    public function reopen(Request $request, string $periodId): RedirectResponse
    {
        $organizationId = $this->portalOrganizationId($request);
        abort_unless($request->user()?->roleIn($organizationId) === MemberRole::Owner->value, 403, __('Only an owner reopens a filed VAT period.'));
        $period = $this->periods->find($organizationId, $periodId) ?? abort(404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->periods->reopen($period, $data['reason'], (string) $request->user()?->id);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('VAT period reopened.'));
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
        // Back to Books with the reason, which a bare 403 page would not show.
        if ($this->branches->allowed() !== null) {
            throw new HttpResponseException(redirect()->route('portal.books.index')
                ->with('error', __('The VAT return covers every branch of the organization. Ask an owner, an administrator or the accountant to open it.')));
        }

        $organization = $this->portalOrganization($this->tenant, $request);
        [$from, $to] = $this->period($request, $organization);
        $return = $this->vatReturn($organization);
        $entries = $return->manualEntries();
        $data = $request->validate(array_map(fn (): array => ['nullable', 'numeric', 'between:-999999999,999999999'], $entries));
        $manual = array_map(fn ($value): float => (float) $value, array_filter($data, fn ($value): bool => $value !== null && $value !== ''));

        // Every branch: null is all of them, for someone allowed all of them.
        $draft = $this->branches->within(null, fn (): VatReturnDraft => $return->draft((string) $organization->id, $from, $to, $manual));

        return [$draft, $from, $to, $manual, $entries];
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
