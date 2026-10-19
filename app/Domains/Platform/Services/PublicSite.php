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
     * The sample QR code's width on the page, in CSS pixels: big enough for
     * a phone to read from a screen, small enough for the invoice card.
     */
    public const SAMPLE_QR_SIZE = 108;

    private const SAMPLE_QR_CACHE = 'public-site.sample-qr:';

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
     * The invoice the home page draws. Made up, and labelled a sample: the
     * card and its QR code are drawn from these same figures, so they agree.
     */
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
     * The sample invoice's QR code, as the leading country that prints one
     * encodes it, drawn as SVG; null when no country here prints a QR code.
     *
     * The drawing is cached for good under its payload: the same figures
     * always give the same code, and new figures give a new key.
     *
     * @return array{svg: string, payload: string, taxNumber: string}|null
     */
    public function sampleQr(): ?array
    {
        $sample = $this->sampleInvoice();

        foreach ($this->countries() as $country) {
            $qr = $country->website()->sampleQr($sample);

            if ($qr === null) {
                continue;
            }

            $svg = Cache::rememberForever(
                self::SAMPLE_QR_CACHE.hash('sha256', self::SAMPLE_QR_SIZE.'|'.$qr->encoding.'|'.$qr->payload),
                fn (): string => $this->qr->gridSvg($qr->payload, self::SAMPLE_QR_SIZE, $qr->encoding),
            );

            return ['svg' => $svg, 'payload' => $qr->payload, 'taxNumber' => $qr->taxNumber];
        }

        return null;
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
}
