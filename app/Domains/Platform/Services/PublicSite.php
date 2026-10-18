<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

/**
 * What the public pages decide from the admin console's settings.
 *
 * Two things, both under Settings' "Public site" group: whether the small
 * signs of the parent brand appear (the coral footer period, the jlmk note,
 * the not-found line, the console greeting), and where the home page's
 * contact action goes. Read here so the views ask one question each rather
 * than knowing setting keys.
 */
final class PublicSite
{
    /** The parent brand's site, which its signs link to. */
    public const PARENT_BRAND_URL = 'https://justletmeknow.app';

    public function __construct(private readonly Settings $settings) {}

    /**
     * Whether the parent brand's signs show. Public pages only: the views
     * that ask are the public layout and the not-found page, never a
     * document, an email or a working screen.
     */
    public function easterEggs(): bool
    {
        return (bool) $this->settings->get('site.easter_eggs');
    }

    /**
     * Where the home page's contact action goes: an address an admin set, or
     * null for the page's own contact section. Empty by default, so nothing
     * leaves the site until someone chooses that it should.
     */
    public function contactUrl(): ?string
    {
        $url = (string) $this->settings->get('site.contact_url');

        return $url === '' ? null : $url;
    }
}
