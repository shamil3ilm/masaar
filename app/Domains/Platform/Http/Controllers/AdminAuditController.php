<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Services\AuditReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The audit trail, read-only.
 *
 * Nothing on this screen changes a record. An audit trail an admin can edit
 * from the console is not one.
 */
class AdminAuditController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuditReport $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = array_filter([
            'category' => $this->given($request->query('category')),
            'actor' => $this->given($request->query('actor')),
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ], fn (?string $value): bool => $value !== null);

        return view('admin.audit', [
            'entries' => $this->audit->page($filters, self::PER_PAGE)->withQueryString(),
            'filters' => $filters,
            'categories' => AuditReport::CATEGORIES,
        ]);
    }

    private function given(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A date the query can trust, or nothing. A malformed one is ignored
     * rather than compared as a string against a timestamp.
     */
    private function date(mixed $value): ?string
    {
        $value = $this->given($value);

        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
