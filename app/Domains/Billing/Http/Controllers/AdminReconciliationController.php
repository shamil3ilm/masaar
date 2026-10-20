<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Reconciliation\Contracts\SettlementReport;
use App\Domains\Billing\Reconciliation\Models\ReconciliationMatch;
use App\Domains\Billing\Reconciliation\Models\StatementLine;
use App\Domains\Billing\Reconciliation\Services\Matcher;
use App\Domains\Billing\Reconciliation\Services\Reconciliation;
use App\Domains\Billing\Reconciliation\Services\StatementImports;
use App\Domains\Billing\Reconciliation\Services\StatementList;
use App\Domains\Billing\Reconciliation\StatementKind;
use App\Domains\Billing\Reconciliation\StatementRejected;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use App\Http\Listing\ListState;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin › Finance › Reconciliation: the platform's incoming money against
 * what customers owe it - the statements brought in, their lines, one
 * line's workbench, what is unmatched, short or over, and a report.
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
        private readonly Reconciliation $reconciliation,
        private readonly Matcher $matcher,
        private readonly FinanceSettings $settings,
        private readonly SettlementReport $settlement,
        private readonly Lists $lists,
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

    public function lines(Request $request): View
    {
        $list = $this->listState($request, 'admin.finance.reconciliation.lines.export');

        return view('admin.finance.reconciliation.lines', ['lines' => $this->list->lines($list), 'list' => $list]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->list->csv($this->listState($request));
    }

    /** One line: its suggestions, its match, and matching it by hand. */
    public function line(Request $request, string $lineId): View
    {
        $line = $this->findLine($lineId);
        $customer = $request->query('customer');

        return view('admin.finance.reconciliation.line', [
            'line' => $line,
            'matches' => $this->list->matchesOf($line),
            'manual' => $this->list->manualOptions($line, is_string($customer) && $customer !== '' ? $customer : null),
            'customer' => is_string($customer) ? $customer : '',
            'high' => Matcher::HIGH,
        ]);
    }

    public function suggest(Request $request, string $lineId): RedirectResponse
    {
        $line = $this->target($request, $this->findLine($lineId));

        return $this->back($line, $this->matcher->suggest($line) ? __('Suggestions found.') : __('Nothing found for this line. Match it by hand.'));
    }

    public function match(Request $request, string $lineId): RedirectResponse
    {
        $line = $this->target($request, $this->findLine($lineId));
        $data = $request->validate([
            'organization' => ['required', 'uuid'],
            'amounts' => ['array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'payments' => ['array'],
            'payments.*' => ['uuid'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        return $this->attempt($line, fn () => $this->reconciliation->manual([(string) $line->id], $data['organization'],
            array_map('strval', array_filter($data['amounts'] ?? [], fn ($amount): bool => $amount !== null)), $data['payments'] ?? [], (string) Auth::id(), isset($data['fee']) ? (string) $data['fee'] : null), __('Matched.'));
    }

    public function ignore(Request $request, string $lineId): RedirectResponse
    {
        $line = $this->target($request, $this->findLine($lineId));
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->attempt($line, fn () => $this->reconciliation->ignore($line, $data['reason'], (string) Auth::id()), __('The line is set aside.'));
    }

    public function restore(Request $request, string $lineId): RedirectResponse
    {
        $line = $this->target($request, $this->findLine($lineId));

        return $this->attempt($line, fn () => $this->reconciliation->restore($line, (string) Auth::id()), __('The line is back to be matched.'));
    }

    public function confirm(Request $request, string $matchId): RedirectResponse
    {
        [$match, $line] = $this->findMatch($request, $matchId);

        return $this->attempt($line, fn () => $this->reconciliation->confirm($match, (string) Auth::id(), checked: $request->boolean('checked')), __('Match confirmed.'));
    }

    public function reject(Request $request, string $matchId): RedirectResponse
    {
        [$match, $line] = $this->findMatch($request, $matchId);

        return $this->attempt($line, fn () => $this->reconciliation->reject($match, (string) Auth::id()), __('Suggestion rejected.'));
    }

    public function split(Request $request, string $matchId): RedirectResponse
    {
        [$match, $line] = $this->findMatch($request, $matchId);
        $data = $request->validate(['amounts' => ['required', 'array'], 'amounts.*' => ['nullable', 'numeric', 'min:0', 'max:99999999']]);

        return $this->attempt($line, fn () => $this->reconciliation->split($match,
            array_map('strval', array_filter($data['amounts'], fn ($amount): bool => $amount !== null)), (string) Auth::id()), __('Matched with your amounts.'));
    }

    public function unmatch(Request $request, string $matchId): RedirectResponse
    {
        [$match, $line] = $this->findMatch($request, $matchId);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->attempt($line, fn () => $this->reconciliation->unmatch($match, $data['reason'], (string) Auth::id()), __('The match is undone.'));
    }

    /** Lines of money in not matched yet, and matches short or over. */
    public function exceptions(): View
    {
        return view('admin.finance.reconciliation.exceptions', [
            'unmatched' => $this->list->unmatched(),
            'short' => $this->list->exceptions(short: true),
            'over' => $this->list->exceptions(short: false),
        ]);
    }

    public function reports(): View
    {
        return view('admin.finance.reconciliation.reports', ['summary' => $this->list->summary()]);
    }

    private function findLine(string $lineId): StatementLine
    {
        return $this->list->line($lineId) ?? abort(404);
    }

    /**
     * @return array{0: ReconciliationMatch, 1: StatementLine}
     */
    private function findMatch(Request $request, string $matchId): array
    {
        $match = $this->list->findMatch($matchId) ?? abort(404);
        $request->attributes->set(AuditAdminAction::TARGET, ['ReconciliationMatch', $matchId]);

        return [$match, $this->list->firstLineOf($match) ?? abort(404)];
    }

    private function target(Request $request, StatementLine $line): StatementLine
    {
        $request->attributes->set(AuditAdminAction::TARGET, ['StatementLine', (string) $line->id]);

        return $line;
    }

    /**
     * @throws ValidationException with the reason it was refused
     */
    private function attempt(StatementLine $line, callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['reconciliation' => $e->getMessage()]);
        }

        return $this->back($line, $done);
    }

    private function back(StatementLine $line, string $message): RedirectResponse
    {
        return redirect()->route('admin.finance.reconciliation.line', $line->id)->with('success', $message);
    }

    private function listState(Request $request, ?string $export = null): ListState
    {
        return $this->lists->admin($this->list->spec(), $request, $export);
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
