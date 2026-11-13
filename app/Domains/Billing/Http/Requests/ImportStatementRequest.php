<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Reconciliation\Contracts\SettlementReport;
use App\Domains\Billing\Reconciliation\StatementKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kinds = StatementKind::labels();
        if (! app(SettlementReport::class)->available()) {
            unset($kinds[StatementKind::Settlement->value]);
        }

        return [
            'kind' => ['required', 'string', Rule::in(array_keys($kinds))],
            'file' => ['required', 'file', 'max:'.(int) config('billing.reconciliation.max_kilobytes')],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'profile' => ['nullable', 'string', 'max:40'],
        ];
    }
}
