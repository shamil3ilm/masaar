<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Requests;

use App\Domains\Billing\Reconciliation\Parsers\CsvStatementParser;
use App\Domains\Billing\Reconciliation\Parsers\TextFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStatementProfileRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:80'],
            'bank_account' => ['required', 'string', 'max:40'],
            'delimiter' => ['required', 'string', Rule::in(array_keys(CsvStatementParser::DELIMITERS))],
            'encoding' => ['required', 'string', Rule::in(TextFile::ENCODINGS)],
            'date_format' => ['required', 'string', Rule::in(CsvStatementParser::DATE_FORMATS)],
            'decimal' => ['required', 'string', Rule::in(['.', ','])],
            'skip_rows' => ['nullable', 'integer', 'min:0', 'max:50'],
            ...array_fill_keys(CsvStatementParser::COLUMNS, ['nullable', 'string', 'max:60']),
        ];
    }
}
