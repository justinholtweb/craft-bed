<?php

namespace justinholtweb\bed\models;

use Craft;
use craft\base\Model;

/**
 * Bed settings.
 *
 * The defaults are the plugin doing its job on a site nobody has configured: front-end HTML is
 * rewritten, space is reserved from measurements, frames below the first one are lazy, script
 * embeds wait for the viewport, and YouTube gets a facade. Everything here can be turned off, and
 * turning `enabled` off leaves the ledger alone so turning it back on picks up where it left off.
 *
 * Nothing in here is `required`. A required plugin setting blocks a fresh install, because Craft
 * validates the settings model before the plugin has ever been configured.
 */
class Settings extends Model
{
    /**
     * The viewport widths measurements are bucketed into.
     *
     * Fixed, and deliberately few. A bucket per pixel would be a table full of samples of one, and
     * a mean of one sample is not a measurement — it is the last visitor's window.
     */
    public const BREAKPOINTS = [360, 480, 768, 1024, 1280, 1536, 1920];

    /**
     * Reserve the typical measured height — the median since 5.0.1, so one wild report cannot move
     * it. The value keeps its old name so stored settings still mean the same choice.
     */
    public const RESERVE_MEAN = 'mean';

    /** Reserve the tallest measured height within half again of the median. */
    public const RESERVE_MAX = 'max';

    /**
     * @var bool The master switch. Off means no rewriting, no collection, and the Twig filter
     *           returns its input untouched.
     */
    public bool $enabled = true;

    /**
     * @var bool Whether front-end HTML responses are rewritten automatically. Off leaves the Twig
     *           filter as the only way in, for a site that wants to opt in field by field.
     */
    public bool $autoOptimize = true;

    // ------------------------------------------------------------------ reserving space

    /**
     * @var bool Whether an embed is wrapped in a box that holds its space.
     */
    public bool $reserveSpace = true;

    /**
     * @var string Which measurement becomes the reserved height — the typical one (the median,
     *             though the value is still `mean`) or the tallest. `mean` shifts a little in both
     *             directions and is the better default; `max` rarely shifts down but leaves
     *             whitespace under short embeds. Neither can be decided by one visitor — see
     *             Ledger::reserveHeight().
     */
    public string $reserveStrategy = self::RESERVE_MEAN;

    /**
     * @var bool Whether an embed with `width` and `height` attributes gets an aspect-ratio box
     *           built from them. This costs nothing and needs no measurements.
     */
    public bool $useIntrinsicRatio = true;

    // ------------------------------------------------------------------ deferring loads

    /**
     * @var bool Whether frames and media are marked lazy.
     */
    public bool $lazyLoad = true;

    /**
     * @var int How many embeds from the top of the document are left eager regardless of what was
     *          measured. One, because the embed at the top of an article is very often the reason
     *          somebody opened the page, and lazy-loading the LCP element is a own goal.
     */
    public int $eagerCount = 1;

    /**
     * @var bool Whether a script-driven embed's loader script is lifted out of the document and
     *           injected when the embed nears the viewport.
     */
    public bool $deferScripts = true;

    /**
     * @var string How far ahead of the viewport a deferred script starts loading. An
     *             IntersectionObserver root margin.
     */
    public string $scriptRootMargin = '400px';

    // ------------------------------------------------------------------ connections

    /**
     * @var bool Whether `preconnect` / `dns-prefetch` links are added for the providers on a page.
     */
    public bool $resourceHints = true;

    /**
     * @var int The most hints Bed will add to one page. A preconnect is a real cost — a socket, a
     *          DNS lookup and a TLS handshake the page may never use — so this is a budget and not
     *          a target.
     */
    public int $maxHints = 6;

    // ------------------------------------------------------------------ facades

    /**
     * @var string[] Providers whose embed is replaced by a poster and a play button until the
     *               reader clicks it. Only providers Bed can build a facade for without asking a
     *               third party anything are eligible.
     */
    public array $facadeProviders = ['youtube'];

    /**
     * @var bool Whether a facade may use the provider's own poster image. Off draws the facade
     *           from CSS alone, so a reader who never clicks makes no third-party request at all —
     *           slower to look at, better for privacy.
     */
    public bool $facadePosters = true;

    // ------------------------------------------------------------------ consent

    /** No consent platform: each held embed is loaded by a click, or by `Bed.consent()`. */
    public const CONSENT_CLICK = 'click';

    /** Cookiebot's `window.Cookiebot.consent`. */
    public const CONSENT_COOKIEBOT = 'cookiebot';

    /** CookieYes's `cookieyes-consent` cookie. */
    public const CONSENT_COOKIEYES = 'cookieyes';

    /** Tape's own consent state, `window.tape.consent.get()`. */
    public const CONSENT_TAPE = 'tape';

    /** A first-party cookie of the site's own. */
    public const CONSENT_COOKIE = 'cookie';

    /** The source used whenever Toss is the consent manager. Never stored as a setting. */
    public const CONSENT_TOSS = 'toss';

    /** The category names the family gates on — Toss's four. */
    public const CONSENT_CATEGORIES = ['necessary', 'preferences', 'analytics', 'marketing'];

    /** The provider key that stands for every recognised provider not listed by name. */
    public const CONSENT_ALL = '*';

    /**
     * @var array<string, string> Providers whose embeds are held behind a consent notice, as
     *      provider handle → consent category: `['youtube' => 'marketing', 'googlemaps' =>
     *      'preferences']`. `*` stands for every recognised provider not listed by name; `generic`
     *      and `media` (unrecognised frames, `<video>`/`<audio>`) only ever by name. Empty — the
     *      default — holds nothing back.
     */
    public array $consentProviders = [];

    /**
     * @var string Where the visitor's answer comes from when Toss is not the consent manager. One
     *             of the `CONSENT_*` source constants.
     */
    public string $consentSource = self::CONSENT_CLICK;

    /**
     * @var bool Take the answer from Toss whenever Toss is installed with its cookie consent kit on,
     *           whatever `consentSource` says. On by default, which is what makes the pairing
     *           zero-configuration.
     */
    public bool $deferToToss = true;

    /**
     * @var bool Whether a held embed offers a button that loads just that embed. A click on a
     *           named, described embed is the reader asking for it; off means only the consent
     *           platform can release anything.
     */
    public bool $consentClickToLoad = true;

    /** @var string The cookie read when `consentSource` is `cookie`. */
    public string $consentCookieName = '';

    /**
     * @var string What that cookie's value must contain for a category to count as granted.
     *             `{category}` is replaced by the category name.
     */
    public string $consentCookieMatch = '{category}';

    /**
     * @var string The notice shown in place of a held embed. `{provider}`, `{host}` and
     *             `{category}` are filled in. Empty uses Bed's own wording.
     */
    public string $consentMessage = '';

    // ------------------------------------------------------------------ measurement

    /**
     * @var bool Whether the runtime reports measurements back.
     */
    public bool $collectMetrics = true;

    /**
     * @var float The fraction of eligible page views that report. Measurements are cheap to want
     *            and expensive to collect from everybody.
     */
    public float $sampleRate = 0.1;

    /**
     * @var int How many samples a slot needs at a breakpoint before Bed stops asking for more.
     */
    public int $sampleTarget = 20;

    /**
     * @var int How many days a collection token stays valid. Longer than you would pick for a
     *          session, because the page carrying it may sit in a full-page cache for a while.
     */
    public int $tokenTtlDays = 7;

    /**
     * @var int How many days a slot survives without being seen again. Content moves; a slot for
     *          a tweet that was deleted from a page last spring is not a measurement any more.
     */
    public int $retentionDays = 90;

    /**
     * @var int A hard ceiling on rows in the slot ledger. A site with a million URLs and an embed
     *          on each one should stop collecting, not fill a disk.
     */
    public int $maxSlots = 20000;

    /**
     * @var int The most beacons one address may send per minute.
     */
    public int $collectRateLimit = 30;

    // ------------------------------------------------------------------ markup hygiene

    /**
     * @var bool Whether a frame with no accessible name is given one. An `<iframe>` without a
     *           `title` is a WCAG failure, and it is the single most common one in embedded
     *           content because the person who pasted it never wrote the tag.
     */
    public bool $addTitles = true;

    /**
     * @var string A referrer policy applied to frames that have none. Empty leaves them alone.
     */
    public string $referrerPolicy = 'strict-origin-when-cross-origin';

    // ------------------------------------------------------------------ scope

    /**
     * @var string[] URI patterns that are never rewritten. Leading slash optional, `*` wildcards.
     */
    public array $excludeUris = [];

    /**
     * @var bool Whether Bed's stylesheet is registered when a page has an embed on it.
     */
    public bool $registerCss = true;

    /**
     * @var bool Whether Bed's runtime is registered. Without it, deferred scripts never load and
     *           facades never open — reserved space and lazy frames are unaffected, because those
     *           are markup and CSS.
     */
    public bool $registerJs = true;

    public function rules(): array
    {
        return [
            [['reserveStrategy'], 'in', 'range' => [self::RESERVE_MEAN, self::RESERVE_MAX]],
            [['eagerCount'], 'integer', 'min' => 0, 'max' => 50],
            [['maxHints'], 'integer', 'min' => 0, 'max' => 20],
            [['sampleTarget'], 'integer', 'min' => 1, 'max' => 1000],
            [['tokenTtlDays'], 'integer', 'min' => 1, 'max' => 365],
            [['retentionDays'], 'integer', 'min' => 1, 'max' => 3650],
            [['maxSlots'], 'integer', 'min' => 100, 'max' => 1000000],
            [['collectRateLimit'], 'integer', 'min' => 1, 'max' => 10000],
            [['sampleRate'], 'number', 'min' => 0.001, 'max' => 1],
            [['scriptRootMargin'], 'match', 'pattern' => '/^-?\d+(px|%)?$/', 'skipOnEmpty' => true],
            [['referrerPolicy'], 'in', 'range' => [
                '', 'no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin',
                'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url',
            ]],
            [['facadeProviders', 'excludeUris'], 'validateList', 'skipOnEmpty' => false],
            [['consentProviders'], 'validateConsentProviders', 'skipOnEmpty' => false],
            [['consentSource'], 'in', 'range' => [
                self::CONSENT_CLICK, self::CONSENT_COOKIEBOT, self::CONSENT_COOKIEYES, self::CONSENT_TAPE, self::CONSENT_COOKIE,
            ]],
            [['consentCookieName'], 'match', 'pattern' => '/^[A-Za-z0-9_.-]{1,128}$/', 'skipOnEmpty' => true],
            // Not `required`: a required plugin setting blocks a fresh install, even conditionally.
            [['consentCookieName'], function(string $attribute) {
                if ($this->consentSource === self::CONSENT_COOKIE && trim($this->consentCookieName) === '') {
                    $this->addError($attribute, Craft::t('bed', 'Name the cookie to read.'));
                }
            }, 'skipOnEmpty' => false],
            [['consentCookieMatch', 'consentMessage'], 'string', 'max' => 500],
        ];
    }

    /**
     * Craft's editable table posts rows, not strings.
     *
     * The value that arrives from the settings form is `[['value' => 'youtube'], …]`, and the
     * value that arrives from a config file is `['youtube']`. Both have to end up as the second,
     * and `skipOnEmpty => false` matters because clearing the table is the one case a normaliser
     * on a non-empty value never sees.
     */
    public function validateList(string $attribute): void
    {
        $value = $this->$attribute;

        if (!is_array($value)) {
            $this->$attribute = [];
            return;
        }

        $out = [];

        foreach ($value as $row) {
            $item = is_array($row) ? ($row['value'] ?? reset($row)) : $row;
            $item = is_string($item) ? trim($item) : '';

            if ($item !== '') {
                $out[] = $item;
            }
        }

        $this->$attribute = array_values(array_unique($out));
    }

    /**
     * Normalises the consent list into `handle => category`.
     *
     * Three shapes arrive: a map from a config file, the editable table's rows from the settings
     * form (`[['provider' => 'youtube', 'category' => 'marketing'], …]`), and a plain list of
     * handles, which means `marketing` for each. A row with no provider is dropped, which is how
     * a table row is deleted; a category that is not a plain name is an error rather than a
     * silently ungated embed.
     */
    public function validateConsentProviders(string $attribute): void
    {
        $value = $this->$attribute;
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $row) {
            if (is_array($row)) {
                $handle = $row['provider'] ?? '';
                $category = $row['category'] ?? '';
            } elseif (is_int($key)) {
                $handle = $row;
                $category = 'marketing';
            } else {
                $handle = $key;
                $category = $row;
            }

            $handle = is_string($handle) ? strtolower(trim($handle)) : '';
            $category = is_string($category) ? trim($category) : '';

            if ($handle === '') {
                continue;
            }

            if ($category === '') {
                $category = 'marketing';
            }

            if (!preg_match('/^(\*|[a-z0-9_-]{1,64})$/', $handle)) {
                $this->addError($attribute, Craft::t('bed', '“{handle}” is not a provider handle.', ['handle' => $handle]));
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $category)) {
                $this->addError($attribute, Craft::t('bed', '“{category}” is not a consent category name.', ['category' => $category]));
                continue;
            }

            if ($category === 'necessary') {
                // Necessary is always granted, so holding an embed behind it holds nothing back.
                continue;
            }

            $out[$handle] = $category;
        }

        $this->$attribute = $out;
    }

    /**
     * The consent category an embed from this provider is held behind, or null if it is not held.
     *
     * A provider listed by name wins over `*`. `generic` and `media` are never covered by `*`: an
     * unrecognised frame or a `<video>` is as likely to be the site's own as anybody else's.
     */
    public function consentCategoryFor(string $handle): ?string
    {
        $map = $this->consentProviders;

        if (isset($map[$handle])) {
            return $map[$handle];
        }

        if (isset($map[self::CONSENT_ALL]) && !in_array($handle, ['generic', 'media'], true)) {
            return $map[self::CONSENT_ALL];
        }

        return null;
    }

    /**
     * The viewport bucket a reported width belongs to.
     *
     * Snapping happens on the server, never on the client, because the bucket list is the schema
     * and a client that sent 1367 would otherwise create a column of one.
     */
    public static function bucket(int $width): int
    {
        foreach (self::BREAKPOINTS as $breakpoint) {
            if ($width <= $breakpoint) {
                return $breakpoint;
            }
        }

        // Indexed rather than `end()`, which takes its argument by reference and so cannot be
        // handed a class constant at all.
        return self::BREAKPOINTS[count(self::BREAKPOINTS) - 1];
    }

    /** Whether a URI is excluded from rewriting. */
    public function excludes(string $uri): bool
    {
        $uri = '/' . ltrim($uri, '/');

        foreach ($this->excludeUris as $pattern) {
            $pattern = '/' . ltrim(trim((string)$pattern), '/');

            if ($pattern === '/') {
                continue;
            }

            if (fnmatch($pattern, $uri, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
