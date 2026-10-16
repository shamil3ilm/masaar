<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * The privacy notice and the terms of service: public, in the reader's
 * language, with the operator's details from config/company.php.
 */
class LegalController extends Controller
{
    public function privacy(): View
    {
        return view('legal.page', [
            'page' => 'privacy',
            'title' => __('Privacy notice'),
            'version' => (string) config('company.privacy_version', '1'),
        ]);
    }

    public function terms(): View
    {
        return view('legal.page', [
            'page' => 'terms',
            'title' => __('Terms of service'),
            // The version accepted at sign-up is this one.
            'version' => (string) config('signup.terms_version', '1'),
        ]);
    }
}
