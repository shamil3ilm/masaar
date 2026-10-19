<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Platform\Website\ContactTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A message from the home page's contact form.
 *
 * Refused, the page comes back at the form with what was typed in it. The
 * spam guards' fields (ContactGuard) are read by the controller, not here:
 * a script that fills the honeypot must not learn so from an error.
 */
class ContactRequest extends FormRequest
{
    /** A header-safe line: no line breaks or other control characters. */
    private const ONE_LINE = 'not_regex:/[\x00-\x1F\x7F]/u';

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
            'name' => ['required', 'string', 'max:120', self::ONE_LINE],
            'email' => ['required', 'string', 'email', 'max:255', self::ONE_LINE],
            'company' => ['nullable', 'string', 'max:160', self::ONE_LINE],
            'country' => ['nullable', 'string', 'max:100', self::ONE_LINE],
            'topic' => ['required', Rule::enum(ContactTopic::class)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.min' => __('Tell us a little more, in at least :min characters.'),
        ];
    }

    /**
     * The fields as ContactInbox takes them.
     *
     * @return array{name: string, email: string, company: string|null, country: string|null, topic: string, message: string}
     */
    public function submission(): array
    {
        $data = $this->validated();

        return [
            'name' => trim((string) $data['name']),
            'email' => trim((string) $data['email']),
            'company' => isset($data['company']) ? trim((string) $data['company']) : null,
            'country' => isset($data['country']) ? trim((string) $data['country']) : null,
            'topic' => (string) $data['topic'],
            'message' => trim((string) $data['message']),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return url('/').'#contact';
    }
}
