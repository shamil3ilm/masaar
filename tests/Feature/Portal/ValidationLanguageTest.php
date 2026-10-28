<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceRules as SaudiInvoiceRules;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\InvoiceRules as UaeInvoiceRules;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * A refused form speaks the page's language, with the field named as the
 * form names it.
 *
 * A field with no name of its own was shown by its key - "The buyer
 * address.postal code field must be 5 digits" - and a rule with no Arabic
 * line fell back to English, so an Arabic page read "The الرقم الضريبي
 * للمشتري must be exactly 15 digits". This reads every field the portal's
 * forms check and every rule they check it by, and asks the validator for
 * the message in each language.
 */
class ValidationLanguageTest extends TestCase
{
    use RefreshDatabase;

    /** The customer-facing forms: the portal, sign-in and sign-up. The console is English. */
    private const SOURCES = [
        'app/Domains/*/Http/Controllers/Portal*.php',
        'app/Domains/*/Http/Requests/*.php',
        'app/Domains/Compliance/Countries/*/Http/Controllers/Portal*.php',
        'app/Domains/Compliance/Countries/*/Http/Controllers/OnboardingController.php',
        'app/Domains/Compliance/Countries/*/Http/Controllers/BranchOnboardingController.php',
        'app/Domains/Compliance/Countries/*/Http/Requests/*.php',
        'app/Domains/Auth/Http/Controllers/AccountController.php',
        'app/Domains/Auth/Http/Controllers/AuthController.php',
        'app/Domains/Auth/Http/Controllers/PasswordResetController.php',
        'app/Domains/Auth/Http/Controllers/SessionAuthController.php',
        'app/Domains/Auth/Http/Controllers/TwoFactorController.php',
        'app/Domains/Organization/Http/Controllers/InvitationController.php',
        'app/Domains/Books/Http/Concerns/*.php',
        'app/Domains/Branding/Http/Controllers/BrandingController.php',
        'app/Http/Controllers/LocaleController.php',
    ];

    /** Short forms a sentence writes in capitals. */
    private const ACRONYMS = ['vat', 'trn', 'tin', 'cr', 'iban', 'url', 'api', 'sku', 'qr', 'pdf', 'csv', 'otp', 'id', 'ip'];

    public function test_every_field_named_in_arabic(): void
    {
        $leaks = [];

        foreach (array_keys($this->fields()) as $field) {
            $message = $this->message($field, 'ar');

            if (preg_match('/[a-z_]/', $message) === 1) {
                $leaks[] = $field.' => '.$message;
            }
        }

        $this->assertSame([], $leaks, 'These fields have no Arabic name in lang/ar/validation.php attributes.');
    }

    public function test_every_field_named_in_english(): void
    {
        $leaks = [];
        $acronyms = implode('|', self::ACRONYMS);

        foreach (array_keys($this->fields()) as $field) {
            $message = $this->message($field, 'en');

            if (preg_match('/\w\.\w|_|\b('.$acronyms.')\b/', $message) === 1) {
                $leaks[] = $field.' => '.$message;
            }
        }

        $this->assertSame([], $leaks, 'These fields read as their keys; name them in lang/en/validation.php attributes.');
    }

    public function test_every_rule_has_arabic(): void
    {
        $known = array_keys((array) Lang::get('validation', [], 'en'));
        $missing = [];

        foreach ($this->fields() as $rules) {
            foreach ($rules as $rule) {
                if (in_array($rule, $known, true) && ! Lang::has('validation.'.$rule, 'ar', false)) {
                    $missing[$rule] = $rule;
                }
            }
        }

        $this->assertSame([], array_values($missing), 'These rules have no Arabic line in lang/ar/validation.php.');
    }

    /**
     * The walk's case: a buyer VAT number and postal code that are wrong,
     * on the Arabic form.
     */
    public function test_buyer_errors_read_in_arabic(): void
    {
        $errors = $this->postBadBuyer('ar');

        foreach ($errors as $field => $messages) {
            $this->assertDoesNotMatchRegularExpression('/[a-z_]/', implode(' ', $messages), $field);
        }

        $this->assertArrayHasKey('buyer_vat_number', $errors);
        $this->assertArrayHasKey('buyer_address.postal_code', $errors);
    }

    public function test_buyer_errors_read_in_english(): void
    {
        $errors = $this->postBadBuyer('en');

        $this->assertSame('The buyer VAT number must be exactly 15 digits.', $errors['buyer_vat_number'][0] ?? null);
        $this->assertSame('The buyer postal code field must be 5 digits.', $errors['buyer_address.postal_code'][0] ?? null);
    }

    /**
     * @return array<string, list<string>>
     */
    private function postBadBuyer(string $locale): array
    {
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $user = User::factory()->create(['locale' => $locale]);
        $user->organizations()->attach($organization->id, ['role' => 'owner', 'status' => 'active']);

        $response = $this->actingAs($user)->withSession(['portal_org_id' => $organization->id, 'locale' => $locale])
            ->from(route('portal.invoices.create'))
            ->post(route('portal.invoices.store'), [
                'type' => 'standard',
                'issue_date' => now()->toDateString(),
                'buyer_name' => 'Gulf Supplies',
                'buyer_vat_number' => '12345',
                'buyer_address' => ['street' => 'King Fahd Road', 'building_number' => '1234', 'city' => 'Riyadh', 'district' => 'Olaya', 'postal_code' => '1', 'country_code' => 'SA'],
                'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
            ]);

        $response->assertSessionHasErrors(['buyer_vat_number', 'buyer_address.postal_code']);

        return session('errors')->getBag('default')->toArray();
    }

    /** What the validator says when the field is left out, in the language given. */
    private function message(string $field, string $locale): string
    {
        app()->setLocale($locale);
        $concrete = str_replace('*', '0', $field);
        $data = [];

        return (string) Validator::make($data, [$concrete => 'required'])->errors()->first($concrete);
    }

    /**
     * Every field the forms check, with the names of the rules it is checked by.
     *
     * @return array<string, list<string>>
     */
    private function fields(): array
    {
        $fields = [];

        // The invoice forms, whose rules each country builds.
        foreach ([SaudiInvoiceRules::class, UaeInvoiceRules::class] as $class) {
            $rules = app($class);

            foreach ([
                $rules->rules(Request::create('/', 'POST', ['type' => 'standard', 'document_type' => 'credit_note', 'currency' => 'USD'])),
                $rules->buyerRules(Request::create('/', 'POST', ['exempt_code' => 'VATEX-SA-HEA'])),
            ] as $set) {
                foreach ($set as $field => $list) {
                    $fields[$field] = [...($fields[$field] ?? []), ...$this->ruleNames(is_array($list) ? $list : [$list])];
                }
            }
        }

        foreach (self::SOURCES as $pattern) {
            foreach (glob(base_path($pattern)) ?: [] as $file) {
                foreach ($this->extract((string) file_get_contents($file)) as $field => $names) {
                    $fields[$field] = [...($fields[$field] ?? []), ...$names];
                }
            }
        }

        ksort($fields);

        return $fields;
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return list<string>
     */
    private function ruleNames(array $rules): array
    {
        $names = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                foreach (explode('|', $rule) as $part) {
                    $names[] = strtolower(explode(':', $part)[0]);
                }
            }
        }

        return $names;
    }

    /**
     * The fields of each literal rules array in a source: the array after
     * validate(, Validator::make( or a rules() method, read as text.
     *
     * @return array<string, list<string>>
     */
    private function extract(string $source): array
    {
        $fields = [];

        preg_match_all('/(?:->validate(?:WithBag)?\(|Validator::make\(|function rules\(\)[^{]*\{\s*return)/', $source, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[0] as [$match, $offset]) {
            $open = $offset + strlen($match);

            // Validator::make's rules are its second argument.
            if (str_starts_with($match, 'Validator::make')) {
                $open = strpos($source, ',', $open);
                $open = $open === false ? strlen($source) : $open + 1;
            }

            // Only a literal array: rules held in a variable are built elsewhere.
            $open += strspn($source, " \t\r\n", $open);

            if (($source[$open] ?? '') !== '[') {
                continue;
            }

            $array = $this->balanced($source, $open);

            // Top-level entries only: 'field' => rules.
            $depth = 0;
            $entry = '';

            foreach (str_split(substr($array, 1, -1)) as $char) {
                $depth += match ($char) {
                    '[', '(' => 1,
                    ']', ')' => -1,
                    default => 0,
                };

                if ($char === ',' && $depth === 0) {
                    $this->entry($entry, $fields);
                    $entry = '';

                    continue;
                }

                $entry .= $char;
            }

            $this->entry($entry, $fields);
        }

        return $fields;
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    private function entry(string $entry, array &$fields): void
    {
        if (preg_match("/^\s*'([a-z][a-z0-9_]*(?:\.[a-z0-9_*]+)*)'\s*=>(.*)$/s", $entry, $parts) !== 1) {
            return;
        }

        preg_match_all("/'([a-z_]+(?::[^'|]*)?(?:\|[a-z_]+(?::[^'|]*)?)*)'/", $parts[2], $strings);

        $fields[$parts[1]] = [...($fields[$parts[1]] ?? []), ...$this->ruleNames($strings[1])];
    }

    private function balanced(string $source, int $open): string
    {
        $depth = 0;

        for ($i = $open, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];

            if ($char === "'" || $char === '"') {
                // Skip a quoted string, so brackets inside it do not count.
                $end = $i + 1;

                while ($end < $length && $source[$end] !== $char) {
                    $end += $source[$end] === '\\' ? 2 : 1;
                }

                $i = $end;

                continue;
            }

            $depth += $char === '[' ? 1 : ($char === ']' ? -1 : 0);

            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        return '';
    }
}
