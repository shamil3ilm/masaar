<?php

declare(strict_types=1);

namespace App\Domains\Platform\Website;

use InvalidArgumentException;

/**
 * The home page's sections, in their shipped order, and the text each one
 * starts with.
 *
 * The defaults are the words the page was written with, in both languages
 * (lang/ar.json), so a deployment nobody has edited shows exactly that page
 * and "Reset to default" returns to it. They are kept here rather than
 * copied into the database, so a better default reaches every section an
 * admin has not rewritten.
 *
 * What the page must say truthfully is not here and cannot be edited: the
 * countries, whether each is available or coming soon, what each supports
 * and the standards they follow come from the engine's registry
 * (PublicSite), and the sample invoice is the code's.
 */
final class SectionCatalogue
{
    public const LOCALES = ['en', 'ar'];

    /**
     * Every section by key, in the order the page shows them by default.
     *
     * @return array<string, SectionDefinition>
     */
    public function all(): array
    {
        $eyebrow = new SectionField('eyebrow', __('Small heading'));
        $title = new SectionField('title', __('Heading'));
        $intro = new SectionField('intro', __('Introduction'), paragraph: true);
        $itemTitle = new SectionField('title', __('Title'));
        $itemDetail = new SectionField('detail', __('Detail'), paragraph: true);

        $definitions = [
            new SectionDefinition('hero', __('Hero'), __('The first screen: the page\'s heading, what Masaar does, and the sign-up button. Always shown, always first.'),
                [
                    new SectionField('badge', __('Before the country names')),
                    new SectionField('title_1', __('Heading, first line')),
                    new SectionField('title_2', __('Heading, second line')),
                    new SectionField('lead', __('Introduction'), paragraph: true),
                    new SectionField('note', __('Note under the buttons')),
                ],
                pinned: true),
            new SectionDefinition('countries', __('Where we work'), __('One tab per country. The countries, whether each is available or coming soon, what each supports and the standards line come from the platform and cannot be edited here.'),
                [
                    $eyebrow,
                    $title,
                    new SectionField('other_tab', __('Last tab')),
                    new SectionField('other_title', __('Other countries: title')),
                    new SectionField('other_detail', __('Other countries: detail'), paragraph: true),
                    new SectionField('other_action', __('Other countries: button')),
                ]),
            new SectionDefinition('business', __('Built for how you sell'), __('The kinds of business Masaar serves.'),
                [$eyebrow, $title],
                [new SectionList('items', __('Kinds of business'), [$itemTitle, $itemDetail])]),
            new SectionDefinition('custom', __('Need something else?'), __('An invitation to ask for a country, a feature or a customization, and the offer to set Masaar up with the customer. Each ask opens the contact form on its own topic.'),
                [
                    $eyebrow,
                    $title,
                    $intro,
                    new SectionField('ask_action', __('Button on each ask')),
                    new SectionField('setup_title', __('Set-up: title')),
                    new SectionField('setup_intro', __('Set-up: introduction'), paragraph: true),
                    new SectionField('setup_action', __('Set-up: button')),
                    new SectionField('action', __('Main button')),
                ],
                [
                    new SectionList('asks', __('Asks: country, feature, customization'), [$itemTitle, $itemDetail], min: 3, max: 3, fixed: true),
                    new SectionList('setup_items', __('Set-up: what we do'), [new SectionField('text', __('Item'))]),
                ]),
            new SectionDefinition('features', __('What you get'), __('What every account gets, wherever it files.'),
                [$eyebrow, $title],
                [new SectionList('items', __('Features'), [$itemTitle, $itemDetail], max: 9)]),
            new SectionDefinition('steps', __('How it works'), __('From signing up to the first invoice. The last step is highlighted.'),
                [$eyebrow, $title],
                [new SectionList('items', __('Steps'), [new SectionField('text', __('Step'), paragraph: true)], max: 6)]),
            new SectionDefinition('plans', __('Plans'), __('The first month free, then a plan agreed with each customer. No prices: an amount in any currency is refused.'),
                [$eyebrow, $title, new SectionField('body', __('Text'), paragraph: true), new SectionField('action', __('Button'))]),
            new SectionDefinition('faq', __('Questions'), __('Questions asked before signing up. The first is shown open.'),
                [$eyebrow, $title],
                [new SectionList('items', __('Questions'), [new SectionField('question', __('Question')), new SectionField('answer', __('Answer'), paragraph: true)], max: 15)]),
            new SectionDefinition('contact', __('Contact us'), __('The contact form. The "Ask us" links elsewhere on the page lead here, so turning it off leaves them nowhere to go unless contact points elsewhere in Settings.'),
                [$eyebrow, $title, $intro]),
        ];

        $all = [];

        foreach ($definitions as $definition) {
            $all[$definition->key] = $definition;
        }

        return $all;
    }

    public function get(string $key): SectionDefinition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("No home page section is called {$key}.");
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * A section's shipped text, every field in both languages:
     * ['title' => ['en' => ..., 'ar' => ...], 'items' => [['title' => [...]], ...]].
     *
     * @return array<string, mixed>
     */
    public function defaults(string $key): array
    {
        $this->get($key);

        return $this->pair($this->texts($key, 'en'), $this->texts($key, 'ar'));
    }

    /**
     * Two languages' texts of the same shape, as one tree with both at
     * every leaf.
     *
     * @param  array<string, mixed>  $en
     * @param  array<string, mixed>  $ar
     * @return array<string, mixed>
     */
    private function pair(array $en, array $ar): array
    {
        $paired = [];

        foreach ($en as $name => $value) {
            $paired[$name] = is_array($value)
                ? array_map(fn (array $item, array $other): array => $this->pair($item, $other), $value, $ar[$name])
                : ['en' => $value, 'ar' => $ar[$name]];
        }

        return $paired;
    }

    /**
     * One language's default text for a section. Every string is the key
     * the page was written with, so lang/ar.json already holds its Arabic.
     *
     * @return array<string, mixed>
     */
    private function texts(string $key, string $l): array
    {
        return match ($key) {
            'hero' => [
                'badge' => __('E-invoicing for', [], $l),
                'title_1' => __('Every invoice,', [], $l),
                'title_2' => __('on the right path.', [], $l),
                'lead' => __('Issue invoices in the portal or send them from your ERP. Masaar checks, sends and keeps each one the way your tax authority expects - in Arabic and English.', [], $l),
                'note' => __('No card needed to sign up, and your first month is free.', [], $l),
            ],
            'countries' => [
                'eyebrow' => __('Where we work', [], $l),
                'title' => __('One account. The rules of each country.', [], $l),
                'other_tab' => __('Other countries', [], $l),
                'other_title' => __('Other countries: ask us', [], $l),
                'other_detail' => __('We can add another country\'s e-invoicing rules to the platform. Tell us where you need to file.', [], $l),
                'other_action' => __('Ask about a country', [], $l),
            ],
            'business' => [
                'eyebrow' => __('Built for how you sell', [], $l),
                'title' => __('From a single counter to a wholesale ledger.', [], $l),
                'items' => [
                    ['title' => __('Retail', [], $l), 'detail' => __('Invoices at the counter in seconds, from your saved products and their prices.', [], $l)],
                    ['title' => __('Wholesale', [], $l), 'detail' => __('Tax invoices to registered buyers, and a credit note when an order changes.', [], $l)],
                    ['title' => __('Services', [], $l), 'detail' => __('Invoice in your customer\'s currency, with their tax details saved once.', [], $l)],
                    ['title' => __('Online stores', [], $l), 'detail' => __('Connect your store or ERP through the API, and each order\'s invoice is issued for you.', [], $l)],
                ],
            ],
            'custom' => [
                'eyebrow' => __('Open to what you need', [], $l),
                'title' => __('Need something else?', [], $l),
                'intro' => __('Masaar is open to any country, any feature and any customization. Ask, and we will tell you what we can do.', [], $l),
                'ask_action' => __('Ask us', [], $l),
                'setup_title' => __('We set it up with you', [], $l),
                'setup_intro' => __('On request, we work with your team, on site or remotely, to set Masaar up end to end:', [], $l),
                'setup_action' => __('Talk to us', [], $l),
                'action' => __('Tell us what you need', [], $l),
                'asks' => [
                    ['title' => __('Another country', [], $l), 'detail' => __('We can add another country\'s e-invoicing rules to the platform. Tell us where you need to file.', [], $l)],
                    ['title' => __('A feature', [], $l), 'detail' => __('An integration, document scanning (OCR), a report or anything else: tell us what would help, and we will say whether and how we can build it.', [], $l)],
                    ['title' => __('A customization', [], $l), 'detail' => __('Workflows, layouts and terms made for the way your business works.', [], $l)],
                ],
                'setup_items' => [
                    ['text' => __('we connect your ERP or store;', [], $l)],
                    ['text' => __('we configure your branches, tills and printers;', [], $l)],
                    ['text' => __('we migrate your data;', [], $l)],
                    ['text' => __('we train your staff;', [], $l)],
                    ['text' => __('we stay with you through go-live.', [], $l)],
                ],
            ],
            'features' => [
                'eyebrow' => __('What you get', [], $l),
                'title' => __('The careful parts, done for you.', [], $l),
                'items' => [
                    ['title' => __('Bilingual invoices', [], $l), 'detail' => __('Arabic and English on one invoice, with your own logo and colours.', [], $l)],
                    ['title' => __('Checked before it leaves', [], $l), 'detail' => __('Your details and your buyer\'s are checked against your country\'s rules before an invoice is issued.', [], $l)],
                    ['title' => __('Filed with the authority', [], $l), 'detail' => __('Each invoice goes to your country\'s e-invoicing system as its rules require, and its status comes back to you.', [], $l)],
                    ['title' => __('Your ERP, connected', [], $l), 'detail' => __('A documented API, webhooks for every status change, and safe retries, so an invoice is never filed twice.', [], $l)],
                    ['title' => __('Ready for your accountant', [], $l), 'detail' => __('Tax reports for any period and CSV exports, with read-only access for whoever does your books.', [], $l)],
                    ['title' => __('Help inside the portal', [], $l), 'detail' => __('Ask us from where you work, and follow every reply in one conversation.', [], $l)],
                ],
            ],
            'steps' => [
                'eyebrow' => __('How it works', [], $l),
                'title' => __('Four steps to your first invoice.', [], $l),
                'items' => [
                    ['text' => __('Create your account with your company\'s tax registration and address.', [], $l)],
                    ['text' => __('We review your company. Once it is approved, your free first month begins.', [], $l)],
                    ['text' => __('Connect to your country\'s e-invoicing system, with the details the portal asks for.', [], $l)],
                    ['text' => __('Issue your first invoice.', [], $l)],
                ],
            ],
            'plans' => [
                'eyebrow' => __('Plans', [], $l),
                'title' => __('Your first month is on us.', [], $l),
                'body' => __('After that, we shape a plan around your needs: your volume, your branches and the countries you file in. There is no price list, and nothing is charged without a plan you have agreed to.', [], $l),
                'action' => __('Talk to us about a plan', [], $l),
            ],
            'faq' => [
                'eyebrow' => __('Questions', [], $l),
                'title' => __('Asked often.', [], $l),
                'items' => [
                    ['question' => __('Do I need my own ERP?', [], $l), 'answer' => __('No. Every invoice can be issued in the portal. If you already have an ERP or an online store, connect it through the API instead.', [], $l)],
                    ['question' => __('What happens after the free month?', [], $l), 'answer' => __('We agree a plan with you, sized to how many invoices you issue and where you file. Nothing is charged without a plan you have agreed to.', [], $l)],
                    ['question' => __('What do I need to sign up?', [], $l), 'answer' => __('Your company\'s legal name, its tax registration and its address, as the authority holds them. The form asks only for what your country\'s rules need, and says why.', [], $l)],
                    ['question' => __('Can I use my own logo and colours?', [], $l), 'answer' => __('Yes. An administrator sets your organization\'s logo and colours in the portal, and your logo is printed on the invoices you issue.', [], $l)],
                    ['question' => __('Which languages does it work in?', [], $l), 'answer' => __('Arabic and English, in the portal and on every invoice. Each person can switch at any time.', [], $l)],
                ],
            ],
            'contact' => [
                'eyebrow' => __('Contact us', [], $l),
                'title' => __('Tell us about your business.', [], $l),
                'intro' => __('Ask about a plan, a country, a feature or a customization, before or after you sign up.', [], $l),
            ],
        };
    }
}
