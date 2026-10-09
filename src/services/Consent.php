<?php

namespace justinholtweb\bed\services;

use Craft;
use craft\base\Component;
use justinholtweb\bed\models\EmbedNode;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;

/**
 * Which embeds wait for consent, and where the answer comes from.
 *
 * The answer itself is never read here. A page Bed rewrites may be served from a full-page cache
 * to anybody, so the markup is identical for every visitor — every listed embed is held — and the
 * runtime releases it in the browser once the visitor's own answer says so. Everything this
 * service decides is a fact about the *site*: which providers are listed, and whether Toss is the
 * consent manager. Both are the same for every visitor, so both are safe to bake into a cached
 * page.
 *
 * Toss is the family's consent manager (`docs/consent-api.md` in craft-toss). When it is installed
 * with its cookie consent kit on, Bed reads the visitor's answer from `window.Toss` — never from
 * Toss's cookie, which is signed and means nothing to a browser. Without Toss, `consentSource`
 * decides.
 */
class Consent extends Component
{
    /**
     * Whether Toss is installed, enabled, and its cookie consent kit is on.
     *
     * The plugin check comes first and is not an optimisation: on a site without Toss, merely
     * naming a `justinholtweb\toss` class fatals. Toss's `isActive()` reads the kit's own switch,
     * not its licence, so a lapsed Toss Pro never makes Bed stop deferring.
     */
    public function tossIsActive(): bool
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('toss')) {
            return false;
        }

        $toss = \justinholtweb\toss\Plugin::getInstance();

        return $toss !== null && $toss->consent->isActive();
    }

    /** Whether Toss answers consent for Bed on this site. */
    public function defersToToss(): bool
    {
        return Plugin::getInstance()->getSettings()->deferToToss && $this->tossIsActive();
    }

    /** Where the runtime reads the visitor's answer from: `toss`, or the `consentSource` setting. */
    public function source(): string
    {
        return $this->defersToToss() ? Settings::CONSENT_TOSS : Plugin::getInstance()->getSettings()->consentSource;
    }

    /**
     * The category an embed waits for, or null if it loads without asking.
     *
     * Decided by the provider alone — never by the visitor — so the same embed is held for
     * everybody and a cached page is right for whoever receives it.
     */
    public function categoryFor(EmbedNode $node): ?string
    {
        if ($node->consentManaged) {
            return null;
        }

        return Plugin::getInstance()->getSettings()->consentCategoryFor($node->provider->handle);
    }

    /**
     * What the runtime needs to know, as the JSON island it reads. Nothing in it is about a
     * visitor.
     *
     * @return array<string, mixed>
     */
    public function runtimeConfig(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $source = $this->source();
        $config = ['source' => $source];

        if ($source === Settings::CONSENT_COOKIE) {
            $config['cookie'] = [
                'name' => $settings->consentCookieName,
                'match' => $settings->consentCookieMatch !== '' ? $settings->consentCookieMatch : '{category}',
            ];
        }

        return $config;
    }

    /** The notice shown in place of a held embed, as plain text. */
    public function message(EmbedNode $node, string $category): string
    {
        $template = trim(Plugin::getInstance()->getSettings()->consentMessage);
        $url = $node->sourceUrl();
        $host = $url !== null ? (string)parse_url($url, PHP_URL_HOST) : '';
        $params = [
            'provider' => $this->providerName($node),
            'host' => $host !== '' ? preg_replace('/^www\./i', '', $host) : $this->providerName($node),
            'category' => $category,
        ];

        if ($template === '') {
            return Craft::t('bed', 'This embed loads content from {provider}, which may set {category} cookies.', $params);
        }

        return strtr($template, [
            '{provider}' => $params['provider'],
            '{host}' => $params['host'],
            '{category}' => $params['category'],
        ]);
    }

    public function providerName(EmbedNode $node): string
    {
        return match ($node->provider->handle) {
            Providers::GENERIC => Craft::t('bed', 'another site'),
            Providers::MEDIA => Craft::t('bed', 'another site'),
            default => $node->provider->name,
        };
    }

    /** @return array<int, array{value: string, label: string}> Source options for the settings screen. */
    public function sourceOptions(): array
    {
        return [
            ['value' => Settings::CONSENT_CLICK, 'label' => Craft::t('bed', 'No consent platform — readers click to load each embed')],
            ['value' => Settings::CONSENT_COOKIEBOT, 'label' => 'Cookiebot'],
            ['value' => Settings::CONSENT_COOKIEYES, 'label' => 'CookieYes'],
            ['value' => Settings::CONSENT_TAPE, 'label' => Craft::t('bed', 'Tape’s consent state')],
            ['value' => Settings::CONSENT_COOKIE, 'label' => Craft::t('bed', 'A cookie of your own')],
        ];
    }
}
