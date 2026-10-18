<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Enums\PaperSize;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchAccess;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\PrintPreferences;
use App\Domains\Organization\Services\Team;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organization's branches: their addresses, the till each is, its
 * numbering and printer, where its device stands with the authority, and
 * who works there.
 *
 * Its administrators only. The address is checked as the organization's
 * country checks a seller's (SellerRules), so a branch's address prints on
 * an invoice as the company's does; what registering a branch's device
 * means is the country's too (Devices).
 */
class PortalBranchController extends Controller
{
    use ResolvesPortalOrganization;

    /** The address fields a branch keeps, and the widths its columns hold. */
    private const ADDRESS = [
        'street' => 255,
        'building_number' => 4,
        'district' => 100,
        'city' => 100,
        'postal_code' => 5,
    ];

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BranchService $branches,
        private readonly BranchAccess $access,
        private readonly PrintPreferences $printing,
        private readonly Team $team,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $organizationId = (string) $organization->id;
        $devices = $this->jurisdictions->forRules($organization)->devices();
        $branches = $this->branches->list($organizationId);

        return view('portal.branches.index', [
            'organization' => $organization,
            'branches' => $branches,
            'people' => $this->access->counts($organizationId),
            // Why a branch cannot issue yet, where its country says so.
            'refusals' => $branches
                ->mapWithKeys(fn ($branch): array => [(string) $branch->id => $devices->refusal($organizationId, (string) $branch->id)])
                ->all(),
        ]);
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);

        return view('portal.branches.form', [
            'organization' => $organization,
            'branch' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        try {
            $branch = $this->branches->create($organization, [
                ...$this->validated($request, $organization),
                'country_code' => (string) ($organization->country ?: $this->jurisdictions->forRules($organization)->code()),
            ]);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.branches.edit', $branch->id)->with('success', __('Branch added as till :number.', ['number' => $branch->till_number]));
    }

    public function edit(Request $request, string $branchId): View
    {
        $organization = $this->organization($request);
        $organizationId = (string) $organization->id;
        $branch = $this->branches->find($organizationId, $branchId) ?? abort(404);

        return view('portal.branches.form', [
            'organization' => $organization,
            'branch' => $branch,
            'device' => $this->jurisdictions->forRules($organization)->devices()->branchStatus($organizationId, $branchId),
            'papers' => PaperSize::cases(),
            'paper' => $this->printing->branchPaper($organizationId, $branchId),
            'printer' => $this->printing->receiptPrinter($organizationId, $branchId),
            'members' => $this->team->members($organizationId),
            'assignments' => $this->access->assignments($organizationId, $branchId),
        ]);
    }

    public function update(Request $request, string $branchId): RedirectResponse
    {
        $organization = $this->organization($request);
        $organizationId = (string) $organization->id;
        $branch = $this->branches->find($organizationId, $branchId) ?? abort(404);

        $printing = $request->validate([
            'paper' => ['nullable', Rule::enum(PaperSize::class)],
            'dpi' => ['nullable', 'integer', 'between:100,600'],
            'dots' => ['nullable', 'integer', 'between:64,1280', 'multiple_of:8'],
        ]);

        $this->branches->update($branch, $this->validated($request, $organization));
        $this->printing->setBranchPaper($organizationId, $branchId, PaperSize::tryFrom((string) ($printing['paper'] ?? '')));
        $this->printing->setReceiptPrinter(
            $organizationId,
            $branchId,
            isset($printing['dpi']) ? (int) $printing['dpi'] : null,
            isset($printing['dots']) ? (int) $printing['dots'] : null,
        );

        return redirect()->route('portal.branches.edit', $branchId)->with('success', __('Branch saved.'));
    }

    /**
     * Who works at the branch, and as what: each member's choice is not
     * assigned, issuing (member) or reading (viewer).
     */
    public function people(Request $request, string $branchId): RedirectResponse
    {
        $organization = $this->organization($request);
        $organizationId = (string) $organization->id;
        $this->branches->find($organizationId, $branchId) ?? abort(404);

        $validated = $request->validate([
            'people' => ['array'],
            'people.*' => ['nullable', Rule::in(BranchAccess::ROLES)],
        ]);

        try {
            foreach ($this->team->members($organizationId) as $member) {
                $role = $validated['people'][$member->id] ?? null;
                $this->access->assign($organizationId, $branchId, (string) $member->id, $role === '' ? null : $role);
            }
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.branches.edit', $branchId)->with('success', __('Who works at this branch is saved.'));
    }

    /**
     * The branch's names and address, checked as the organization's country
     * checks a seller's address, and no wider than the branch's columns.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Organization $organization): array
    {
        $country = $this->jurisdictions->forRules($organization)->sellerRules();
        $seller = $country->rules(complete: true);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'additional_number' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];

        foreach (self::ADDRESS as $field => $width) {
            $rules[$field] = [...($seller[$field] ?? ['nullable', 'string']), 'max:'.$width];
        }

        $validated = $request->validate($rules, [
            ...$country->messages(),
            'additional_number.regex' => __('The additional number is 4 digits.'),
        ]);

        // The columns hold no null: a part of the address the country does
        // not use is kept empty.
        foreach (array_keys(self::ADDRESS) as $field) {
            $validated[$field] = (string) ($validated[$field] ?? '');
        }

        return $validated;
    }

    private function organization(Request $request): Organization
    {
        return $this->portalOrganization($this->tenant, $request, __('Only an administrator of this organization can manage its branches.'));
    }
}
