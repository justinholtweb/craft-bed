<?php

namespace justinholtweb\bed\models;

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
