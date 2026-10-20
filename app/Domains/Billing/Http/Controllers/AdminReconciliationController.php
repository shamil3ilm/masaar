<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Reconciliation\Contracts\SettlementReport;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\Services\StatementList;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Reconciliation\StatementRejected;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin › Finance › Reconciliation: the platform's incoming money against
 * what customers owe it.
 *
 * Every admin reads it; importing, matching and setting lines aside take
 * billing.manage. Each change is on the audit record (the services' own
 * entries, and the console's record of the request).
 */
class AdminReconciliationController extends Controller
{
    public function __construct(
        private readonly StatementImports $imports,
        private readonly StatementList $list,
        private readonly FinanceSettings $settings,
        private readonly SettlementReport $settlement,
    ) {}

    /** The statements brought in, and the form to bring in another. */
    public function imports(): View
    {
        return view('admin.finance.reconciliation.imports', [
            'imports' => $this->list->imports(),
            'accounts' => $this->settings->bankAccounts(),
            'profiles' => $this->settings->statementProfiles(),
            'kinds' => $this->kinds(),
            'maxKb' => (int) config('billing.reconciliation.max_kilobytes'),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['StatementImport', null]);
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(array_keys($this->kinds()))],
            'file' => ['required', 'file', 'max:'.(int) config('billing.reconciliation.max_kilobytes')],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'profile' => ['nullable', 'string', 'max:40'],
        ]);

        $file = $request->file('file');

        try {
            $import = $this->imports->import(
                StatementKind::from($data['kind']),
                (string) $file->getClientOriginalName(),
                (string) file_get_contents((string) $file->getRealPath()),
                $data['bank_account'] ?? null,
                $data['profile'] ?? null,
                (string) Auth::id(),
            );
        } catch (StatementRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('admin.finance.reconciliation.imports')->with('success', __(':new new lines imported; :duplicate already imported were skipped.', [
            'new' => $import->lines_new, 'duplicate' => $import->lines_duplicate,
        ]));
    }

    /**
     * What can be brought in: the bank statement formats, and the card
     * settlement report while there is a gateway to have one.
     *
     * @return array<string, string>
     */
    private function kinds(): array
    {
        $kinds = StatementKind::labels();

        if (! $this->settlement->available()) {
            unset($kinds[StatementKind::Settlement->value]);
        }

        return $kinds;
    }
}
