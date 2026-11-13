<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Platform\Http\Controllers\SavedListViewController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavedListViewRequest extends FormRequest
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
        $allowed = ($this->routeIs('account.*') || $this->routeIs('admin.*'))
            ? SavedListViewController::ADMIN_LISTS
            : SavedListViewController::PORTAL_LISTS;

        return [
            'list' => ['required', 'string', Rule::in($allowed)],
            'name' => ['required', 'string', 'max:60'],
            'query' => ['nullable', 'string', 'max:'.SavedListViewController::MAX_QUERY],
        ];
    }
}
