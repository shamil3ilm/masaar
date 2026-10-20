<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\ReceivableStatus;
use App\Domains\Billing\Models\Receivable;
use App\Domains\Billing\Settings\FinanceSettings;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices to customers, as a list: every customer's in the console, or one
 * customer's own in the portal. Every query names the customer when it is
 * one customer's.
 */
class ReceivableList
{
    public function __construct(
        private readonly Receivables $receivables,
    ) {}

    /** @return array<string, string> */
    public static function statuses(): array
    {
        $statuses = [];

        foreach (ReceivableStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        return $statuses;
    }

    public function spec(string $key): ListSpec
    {
        $currencies = FinanceSettings::billable();

        return new ListSpec(
            key: $key,
            sorts: [
                'issued' => new Sort(__('Issued'), 'issue_date'),
                'due' => new Sort(__('Due'), 'due_date', 'asc'),
                'total' => new Sort(__('Total'), 'total'),
            ],
            defaultSort: 'issued',
            filters: [
                new Choice('status', __('Status'), self::statuses(), 'status'),
                new Choice('currency', __('Currency'), array_combine($currencies, $currencies), 'currency'),
                new DateRange('issue_date', __('Issued')),
            ],
        );
    }

    public function page(ListState $list, ?string $organizationId = null): LengthAwarePaginator
    {
        return Receivable::withoutTenantScope(fn () => $list->paginate($this->query($organizationId)));
    }

    public function csv(ListState $list, string $fileName, ?string $organizationId = null): StreamedResponse
    {
        $statuses = self::statuses();

        return CsvExport::download($fileName, [
            __('Invoice') => fn (Receivable $receivable): string => $this->receivables->number($receivable),
            __('Customer') => fn (Receivable $receivable): string => (string) $receivable->organization?->name,
            __('Issued') => fn (Receivable $receivable): string => $receivable->issue_date->toDateString(),
            __('Due') => fn (Receivable $receivable): string => $receivable->due_date->toDateString(),
            __('Status') => fn (Receivable $receivable): string => $statuses[$receivable->status->value] ?? $receivable->status->value,
            __('Currency') => fn (Receivable $receivable): string => (string) $receivable->currency,
            __('Net') => fn (Receivable $receivable): string => (string) $receivable->subtotal,
            __('VAT') => fn (Receivable $receivable): string => (string) $receivable->vat_amount,
            __('Total') => fn (Receivable $receivable): string => (string) $receivable->total,
            __('Paid') => fn (Receivable $receivable): string => (string) $receivable->amount_paid,
            __('Credited') => fn (Receivable $receivable): string => (string) $receivable->amount_credited,
            __('Written off') => fn (Receivable $receivable): string => (string) $receivable->amount_written_off,
            __('Balance') => fn (Receivable $receivable): string => $receivable->balance(),
            __('Exchange rate') => fn (Receivable $receivable): string => $receivable->exchange_rate === null ? '' : (string) $receivable->exchange_rate,
            __('VAT in tax currency') => fn (Receivable $receivable): string => (string) $receivable->vat_amount_tax.' '.$receivable->tax_currency,
        ], $list->apply($this->query($organizationId))->lazy(500));
    }

    /**
     * @return Builder<Receivable>
     */
    private function query(?string $organizationId): Builder
    {
        return $this->receivables->query()->when($organizationId !== null, fn (Builder $query) => $query->where('org_id', $organizationId));
    }
}
