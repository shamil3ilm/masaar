<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Http\Requests\ContactRequest;
use App\Domains\Platform\Website\ContactGuard;
use App\Domains\Platform\Website\ContactInbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The home page's contact form: kept, announced, and the sender thanked.
 *
 * Post, redirect, get: the thank-you is on the page the redirect lands on, so
 * reloading it sends nothing twice. A message the honeypot dropped is
 * thanked the same way, so a script cannot tell.
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactInbox $inbox) {}

    public function store(ContactRequest $request): RedirectResponse
    {
        try {
            $this->inbox->receive(
                $request->submission(),
                $request->string(ContactGuard::HONEYPOT)->toString(),
                $request->string(ContactGuard::STARTED)->toString(),
                (string) $request->ip(),
                $request->userAgent(),
            );
        } catch (ValidationException $e) {
            throw $e->redirectTo(url('/').'#contact');
        }

        return redirect()->to(url('/').'#contact')->with('contact_sent', true);
    }
}
