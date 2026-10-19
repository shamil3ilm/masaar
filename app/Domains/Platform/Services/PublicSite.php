<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\DTOs\SampleInvoice;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Support\QrCodeRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * What the public pages decide from the admin console's settings, and what
 * they show of the countries this platform files in.
 *
 * Two settings, both under Settings' "Public site" group: whether the small
 * signs of the parent brand appear (the coral footer period, the jlmk note,
 * the not-found line, the console greeting), and where the home page's
 * contact action goes. Read here so the views ask one question each rather
 * than knowing setting keys.
 *
 * The countries come from the engine's registry, each module answering for
 * itself through its Website port; nothing here names one.
 */
final class PublicSite
{
    /** The parent brand's site, which its signs link to. */
    public const PARENT_BRAND_URL = 'https://justletmeknow.app';

    /**
     * The website QR code's width on the invoice card, in CSS pixels, near
     * enough: small beside the invoice, still big enough for a phone to read
     * from a screen.
     */
    public const SITE_QR_SIZE = 72;

    private const SITE_QR_CACHE = 'public-site.site-qr:';

    public function __construct(
        private readonly Settings $settings,
        private readonly JurisdictionRegistry $registry,
        private readonly QrCodeRenderer $qr,
    ) {}

    /**
     * Every country registered here, those switched on first: what someone
     * can sign up for today leads.
     *
     * @return list<Jurisdiction>
     */
    public function countries(): array
    {
        $countries = array_map(fn (string $code): Jurisdiction => $this->registry->get($code), $this->registry->registered());
        usort($countries, fn (Jurisdiction $a, Jurisdiction $b): int => [! $a->enabled(), $a->name()] <=> [! $b->enabled(), $b->name()]);

        return $countries;
    }

    /**
     * The standards the countries here are built to, each once, in the
     * order the countries lead: what the home page's "Standards we follow"
     * line lists.
     *
     * @return list<string>
     */
    public function standards(): array
    {
        $standards = [];

        foreach ($this->countries() as $country) {
            array_push($standards, ...$country->website()->standards());
        }

        return array_values(array_unique($standards));
    }

    /** The invoice the home page draws. Made up, and labelled a sample. */
    public function sampleInvoice(): SampleInvoice
    {
        return new SampleInvoice(
            sellerName: 'Sample Trading Co.',
            number: 'INV-2026-00418',
            issuedAt: CarbonImmutable::parse('2026-01-15 09:30:00', 'UTC'),
            lines: ['Annual maintenance' => '800.00', 'Installation' => '200.00'],
            tax: '150.00',
            total: '1150.00',
        );
    }

    /**
     * The QR code on the home page's invoice card: the website's own
     * address, with the Masaar mark in its middle, drawn as SVG. The same on
     * every country's page, so it is the website's, not a country's.
     *
     * Outside local development url('/') is APP_URL, so the code always
     * opens the public home page. The drawing is cached for good under that
     * address and its size; a new address gives a new key.
     *
     * @return array{svg: string, url: string}
     */
    public function siteQr(): array
    {
        $url = url('/');

        $svg = Cache::rememberForever(
            self::SITE_QR_CACHE.hash('sha256', self::SITE_QR_SIZE.'|'.$url),
            fn (): string => $this->qr->markedSvg($url, self::SITE_QR_SIZE),
        );

        return ['svg' => $svg, 'url' => $url];
    }

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

    /**
     * Where a contact link goes: the address an admin pointed contact at, or
     * the home page's own contact section, with the topic chosen when one is
     * given.
     */
    public function contactLink(?string $topic = null): string
    {
        return $this->contactUrl() ?? url('/').($topic === null ? '' : '?topic='.rawurlencode($topic)).'#contact';
    }

    /**
     * The address the public pages show for writing to us: the one an admin
     * set, else the operator's from config/company.php; null with neither.
     */
    public function contactEmail(): ?string
    {
        $email = (string) $this->settings->get('site.contact_email');

        if ($email === '') {
            $email = (string) config('company.email');
        }

        return $email === '' ? null : $email;
    }

    /** Where the contact form's messages are emailed; null when nowhere. */
    public function notifyEmail(): ?string
    {
        $email = (string) $this->settings->get('site.contact_notify');

        return $email === '' ? $this->contactEmail() : $email;
    }

    /**
     * What the sender is emailed back, in both languages, or null while the
     * automatic reply is off.
     *
     * @return array{en: string, ar: string}|null
     */
    public function autoReply(): ?array
    {
        if (! $this->settings->get('site.contact_auto_reply')) {
            return null;
        }

        return [
            'en' => (string) $this->settings->get('site.contact_auto_reply_en'),
            'ar' => (string) $this->settings->get('site.contact_auto_reply_ar'),
        ];
    }
}
