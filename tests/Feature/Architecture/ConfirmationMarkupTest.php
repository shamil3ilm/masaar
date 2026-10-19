<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Every action that changes or destroys something asks the same way.
 *
 * Thirty-five forms asked before they posted, and they asked in three shapes:
 * a question and its consequence run together as one sentence under the
 * title "Are you sure?", a question with no consequence at all, and - on
 * branding reset and deleting a saved view - an accept button labelled
 * Confirm, which says nothing about what it does. Meanwhile removing a
 * customer, deleting a QR code, revoking an invoice link, withdrawing an
 * invitation, turning off two-factor and changing someone's role posted at
 * the first click.
 *
 * The anatomy is one contract, read by resources/js/confirm.js:
 *   data-confirm         the question, naming the thing ("Remove customer Acme?")
 *   data-confirm-detail  one sentence: what happens, and whether it can be undone
 *   data-confirm-action  the accept button's verb ("Remove", never "OK")
 *   data-confirm-tone    danger, for an action that destroys or stops something
 *   data-confirm-type    the word to type first, for the irreversible few
 *
 * Read from the view source rather than a rendered page, because most of
 * these forms render only in a state a fixture would have to build: a
 * removable customer, a suspended licence, a pending ownership offer.
 */
class ConfirmationMarkupTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../../resources/views';

    private const JS = __DIR__.'/../../../resources/js';

    /**
     * The last part of a route name that destroys, stops or takes away.
     * A form posting to one asks, in red.
     */
    private const DANGER = ['destroy', 'delete', 'remove', 'revoke', 'suspend', 'reject', 'rotate', 'reset', 'reset-two-factor', 'off', 'stop'];

    /**
     * The last part of a route name that is consequential but not
     * destructive: it sends, issues, grants, changes who can do what, or
     * becomes a request for approval. A form posting to one asks.
     */
    private const ASK = [
        'archive', 'issue', 'invoice', 'send', 'role', 'approve', 'grant', 'invite', 'offer', 'accept', 'reactivate',
        'resume', 'enable', 'disable', 'withdraw', 'replay', 'password-reset', 'enrolment-link', 'sessions', 'import',
        'features', 'language-grace', 'notified', 'restore', 'restore-removed', 'bypass',
    ];

    /**
     * Forms that match a verb above and do not ask, and why.
     */
    private const ALLOWED = [
        // The person opened the invitation to accept it; asking again is noise.
        'invitations.accept' => 'accepting an invitation is what the page is for',
        'admin-invitations.accept' => 'accepting an invitation is what the page is for',
        // Undoing a removal or an archive restores what was there, and can
        // itself be undone at once.
        'portal.customers.restore-removed' => 'brings back a removed customer; remove it again to undo',
        'portal.products.restore-removed' => 'brings back a removed product; remove it again to undo',
        'portal.qr-codes.restore' => 'brings back a deleted QR code; delete it again to undo',
        // Archiving hides a record from the invoice form and nothing else;
        // the same button, now Restore, undoes it on the same page.
        'portal.customers.archive' => 'hides it from the invoice form; Restore undoes it',
        'portal.products.archive' => 'hides it from the invoice form; Restore undoes it',
        // An invitation into one company can be withdrawn until it is
        // accepted. The console's own invitation still asks: it is access to
        // every company.
        'portal.team.invite' => 'withdrawable until accepted, and scoped to one company',
        'verification.send' => 'sends the person their own verification link again',
    ];

    /**
     * Irreversible and high-impact: the accept button stays disabled until
     * the word is typed.
     */
    private const TYPED = ['portal.invoices.issue', 'admin.billing.invoice', 'admin.licences.revoke', 'admin.ownership.offer', 'admin.organizations.suspend'];

    /** Accept labels that say nothing about the action. */
    private const VAGUE = ['confirm', 'ok', 'yes', 'continue', 'submit', 'proceed'];

    public function test_consequential_forms_ask_first(): void
    {
        $missing = [];

        foreach ($this->forms() as [$where, $route, $tag]) {
            $verb = substr((string) strrchr('.'.$route, '.'), 1);
            $asks = str_contains($tag, 'data-confirm=');

            if (isset(self::ALLOWED[$route])) {
                continue;
            }

            if (in_array($verb, self::DANGER, true) && (! $asks || ! str_contains($tag, 'data-confirm-tone="danger"'))) {
                $missing[] = $where.' '.$route.' (destructive: needs data-confirm and data-confirm-tone="danger")';
            } elseif (in_array($verb, self::ASK, true) && ! $asks) {
                $missing[] = $where.' '.$route.' (needs data-confirm)';
            }
        }

        // A Reset button sharing a form with Save asks on its own.
        foreach ($this->views() as $relative => $contents) {
            preg_match_all('/<(?:x-ui\.button|button)\b(?:[^>"]|"[^"]*")*\bname="reset"(?:[^>"]|"[^"]*")*>/s', $contents, $resets);

            foreach ($resets[0] as $button) {
                if (! str_contains($button, 'data-confirm=')) {
                    $missing[] = $relative.' a Reset button (needs data-confirm on the button)';
                }
            }
        }

        $this->assertSame([], $missing,
            'These forms change or destroy something and post without asking. Give the form '
            .'data-confirm (the question), data-confirm-detail (the consequence) and data-confirm-action '
            .'(the verb) - or, if asking is noise, add the route to '.self::class.'::ALLOWED with the reason.');
    }

    public function test_irreversible_forms_need_typing(): void
    {
        $found = [];

        foreach ($this->forms() as [$where, $route, $tag]) {
            if (in_array($route, self::TYPED, true)) {
                $found[$route] = true;
                $this->assertStringContainsString('data-confirm-type=', $tag,
                    $where.' '.$route.' cannot be undone: the accept button waits for a typed word.');
            }
        }

        $this->assertSame(self::TYPED, array_values(array_intersect(self::TYPED, array_keys($found))),
            'A typed confirmation is listed for a form that no view posts to any more.');
    }

    /**
     * Wherever data-confirm is, the rest of the anatomy is beside it - on a
     * form, or on the one submit button of several that needs asking.
     */
    public function test_every_confirmation_has_its_anatomy(): void
    {
        $broken = [];

        foreach ($this->views() as $relative => $contents) {
            preg_match_all('/<[a-z][\w.-]*\b(?:[^>"]|"[^"]*")*\bdata-confirm=(?=")(?:[^>"]|"[^"]*")*>/s', $contents, $tags);

            foreach ($tags[0] as $tag) {
                preg_match('/\bdata-confirm="([^"]*)"/', $tag, $question);
                preg_match('/\bdata-confirm-action="([^"]*)"/', $tag, $action);
                $verb = strtolower(trim((string) preg_replace("/\{\{\s*__\('([^']*)'\)\s*\}\}/", '$1', $action[1] ?? '')));

                $problems = array_filter([
                    ! str_contains($question[1] ?? '', '?') ? 'the title is not a question' : null,
                    ! str_contains($tag, 'data-confirm-detail="') ? 'no data-confirm-detail' : null,
                    ($action[1] ?? '') === '' ? 'no data-confirm-action' : null,
                    in_array($verb, self::VAGUE, true) ? 'the accept button says "'.$verb.'"' : null,
                ]);

                if ($problems !== []) {
                    $broken[] = $relative.': '.implode(', ', $problems).' - '.substr((string) preg_replace('/\s+/', ' ', $tag), 0, 90);
                }
            }
        }

        $this->assertSame([], $broken,
            'A confirmation is a question naming the thing, one sentence of consequence, and the verb on '
            .'the button. See Admin › Design system › Confirmations.');
    }

    /**
     * One mechanism. window.confirm() is the fallback inside confirm.js and
     * nowhere else; an inline handler is refused by the content policy.
     */
    public function test_one_way_to_ask(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $contents) {
            // A comment may say what the code no longer does.
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contents);

            if (preg_match('/\bon(?:submit|click)=|\bconfirm\(/', $code) === 1) {
                $offenders[] = 'resources/views/'.$relative;
            }
        }

        foreach (glob(self::JS.'/*.js') ?: [] as $file) {
            if (basename($file) !== 'confirm.js' && preg_match('/\b(?:window\.)?confirm\(|\bprompt\(/', (string) file_get_contents($file)) === 1) {
                $offenders[] = 'resources/js/'.basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'These ask some other way. Use the data-confirm attributes; confirm.js asks in <x-ui.confirm-dialog />.');
    }

    /**
     * The dialog carries every part the attributes can ask for, translated
     * on the server: a JavaScript string would always be English.
     */
    public function test_the_dialog_has_every_part(): void
    {
        $html = Blade::render('<x-ui.confirm-dialog />');

        foreach (['data-confirm-dialog', 'data-confirm-title', 'data-confirm-message', 'data-confirm-approval',
            'data-confirm-typed', 'data-confirm-typed-input', 'data-confirm-cancel', 'aria-describedby'] as $part) {
            $this->assertStringContainsString($part, $html, 'The confirmation dialog has no '.$part.'.');
        }

        $this->assertStringContainsString('data-tone="danger"', $html);
        $this->assertStringContainsString('data-tone="primary"', $html);
    }

    /**
     * Every POST form whose action names a route.
     *
     * @return list<array{string, string, string}> where, route name, opening tag
     */
    private function forms(): array
    {
        $forms = [];

        foreach ($this->views() as $relative => $contents) {
            preg_match_all('/<form\b(?:[^>"]|"[^"]*")*>/s', $contents, $tags, PREG_OFFSET_CAPTURE);

            foreach ($tags[0] as [$tag, $offset]) {
                if (preg_match('/method="post"/i', $tag) !== 1) {
                    continue;
                }

                if (preg_match_all("/route\\(\\s*'([^']+)'/", $tag, $routes) === 0) {
                    continue;
                }

                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;

                foreach ($routes[1] as $route) {
                    $forms[] = [$relative.':'.$line, $route, $tag];
                }
            }
        }

        return $forms;
    }

    /** @return array<string, string> relative path => contents */
    private function views(): array
    {
        $found = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::VIEWS, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(self::VIEWS) + 1));
            $found[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($found);

        return $found;
    }
}
