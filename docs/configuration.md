---
title: Configuration
slug: configuration
order: 20
summary: Every setting, what it defaults to, and the reasoning behind the defaults worth arguing with.
---

Settings live at **Settings → Plugins → Bed**, and every one of them can be set in `config/bed.php`
instead. The defaults are Bed doing its job on a site nobody has configured, so most sites never
open this screen.

## The master switches

| Setting | Key | Default |
| --- | --- | --- |
| Enabled | `enabled` | `true` |
| Optimise front-end pages automatically | `autoOptimize` | `true` |

Turning **Enabled** off stops the rewriting, stops the collection, and makes the Twig filter return
its input untouched. It leaves the ledger alone, so turning it back on picks up where it left off.

Turning **autoOptimize** off leaves Bed installed but silent until a template asks for it —
see [Usage](https://justinholt.com/plugins/craft-bed/docs/usage) for `|bed`. This is the setting for
a site that would rather opt in field by field than filter whole pages.

## Reserving space

| Setting | Key | Default |
| --- | --- | --- |
| Reserve space | `reserveSpace` | `true` |
| Reserved height | `reserveStrategy` | `mean` |
| Use the embed's own dimensions | `useIntrinsicRatio` | `true` |

**Reserved height** picks which measurement becomes the reserved height once a slot has samples:

- `mean` — the typical measured height: the **median** of the samples, so it shifts a little in both
  directions and one wild report cannot move it. The better default. (The setting keeps its old
  name; until 5.0.1 it was a true mean, and one visitor could drag it anywhere.)
- `max` — the tallest height measured that is within half again of the median. Rarely shifts down,
  but leaves whitespace under short embeds; a single absurd report does not count.

**Use the embed's own dimensions** turns `width` and `height` attributes into an `aspect-ratio` box.
It costs nothing, needs no measurement, and is why a Vimeo embed is correct on its very first
render.

## Deferring loads

| Setting | Key | Default |
| --- | --- | --- |
| Lazy-load frames and media | `lazyLoad` | `true` |
| Always load the first | `eagerCount` | `1` |
| Defer embed scripts | `deferScripts` | `true` |
| Start loading this far ahead | `scriptRootMargin` | `400px` |

**Always load the first** is the setting worth understanding. It counts *embeds*, not elements, from
the top of the document, and those are left eager whatever the measurements say. One, because the
embed at the top of an article is very often the reason somebody opened the page, and lazy-loading
the Largest Contentful Paint element is an own goal.

Anything Bed has *measured* above the fold is also left eager, at every width — above the fold at
any width counts as above the fold everywhere. The two mistakes are not symmetrical: eagerly loading
something below the fold costs a little bandwidth, and lazily loading the thing at the top costs the
LCP.

**Start loading this far ahead** is an IntersectionObserver root margin, so `400px` means the loader
script fires four hundred pixels before the embed reaches the viewport.

## Connections

| Setting | Key | Default |
| --- | --- | --- |
| Add resource hints | `resourceHints` | `true` |
| Most connections per page | `maxHints` | `6` |

Providers whose embeds load immediately get `preconnect`; the rest get `dns-prefetch`. Both are
deduped and capped, because a `preconnect` is a DNS lookup, a TCP connection and a TLS handshake
opened on spec and wasted entirely if the page never uses it. Six is a budget, not a target.

Once a slot is measured above the fold, its provider is upgraded from `dns-prefetch` to
`preconnect` automatically.

## Facades

| Setting | Key | Default |
| --- | --- | --- |
| Use a facade for | `facadeProviders` | `['youtube']` |
| Use the provider's poster image | `facadePosters` | `true` |

Only providers whose poster can be worked out without asking them anything are offered in the
control panel, so building a facade never costs a request of its own.

Turn **Use the provider's poster image** off and the facade is drawn from CSS alone — slower to look
at, and a reader who never clicks makes no third-party request at all. This is the privacy setting.

## Consent

| Setting | Key | Default |
| --- | --- | --- |
| Require consent for | `consentProviders` | `[]` |
| Use Toss for consent when it is on | `deferToToss` | `true` |
| Consent source | `consentSource` | `click` |
| Consent cookie | `consentCookieName` | `''` |
| Granted when the cookie contains | `consentCookieMatch` | `{category}` |
| Offer a "Load the embed" button | `consentClickToLoad` | `true` |
| Notice text | `consentMessage` | `''` (Bed's own wording) |

**Require consent for** maps a provider handle to the consent category its embeds wait for —
`['youtube' => 'marketing', 'googlemaps' => 'preferences']`. `*` covers every recognised provider;
`generic` and `media` only ever by name. Empty holds nothing back.

When Toss is installed with its cookie consent kit on, Bed reads the visitor's answer from Toss and
**Consent source** is set aside until Toss is off. The whole picture, including the notice and what
each source reads, is in [Consent](https://justinholt.com/plugins/craft-bed/docs/consent).

## Measurement

| Setting | Key | Default |
| --- | --- | --- |
| Collect measurements | `collectMetrics` | `true` |
| Sample rate | `sampleRate` | `0.1` |
| Samples wanted | `sampleTarget` | `20` |
| Collection token lifetime | `tokenTtlDays` | `7` |
| Retention | `retentionDays` | `90` |
| Row cap | `maxSlots` | `20000` |
| Beacons per minute | `collectRateLimit` | `30` |

**Sample rate** is the fraction of eligible page views that report. Measurements are cheap to want
and expensive to collect from everybody. On a quiet site, or in development, turn it up to `1` to
warm the ledger in an afternoon; on a busy one, `0.1` fills a bucket in a morning.

**Samples wanted** is how many samples a slot needs at a bucket before Bed stops asking. Twenty is
enough for a median to be a measurement rather than the last visitor's window. Each visitor counts
**once** per slot per bucket, so twenty samples means twenty visitors.

**Collection token lifetime** is longer than you would pick for a session — seven days — because the
page carrying the token may sit in a full-page cache for a while before anybody loads it.

**Retention** drops slots nobody has seen in ninety days. Content moves; a slot for a tweet that was
deleted from a page last spring is not a measurement any more. Craft's garbage collection runs the
prune.

**Row cap** and **Beacons per minute** are the two limits that make a public endpoint safe to leave
open. Beacons per minute is per connecting address, under a site-wide ceiling of twenty times that;
`X-Forwarded-For` only counts once Craft's `trustedHosts` names your proxies. A site with a million URLs and an embed on each one should stop collecting, not fill a disk.

## Markup hygiene

| Setting | Key | Default |
| --- | --- | --- |
| Add missing frame titles | `addTitles` | `true` |
| Referrer policy | `referrerPolicy` | `strict-origin-when-cross-origin` |

An `<iframe>` without a `title` is a WCAG failure, and it is the single most common one in embedded
content, because the person who pasted it never wrote the tag. Bed only fills in a name where there
is none — it never overwrites one you wrote.

Set **Referrer policy** to *Leave alone* and frames without a policy keep not having one.

## Scope

| Setting | Key | Default |
| --- | --- | --- |
| Never optimise these URIs | `excludeUris` | `[]` |
| Inline the stylesheet | `registerCss` | `true` |
| Load the runtime | `registerJs` | `true` |

**Never optimise these URIs** takes one pattern per row. The leading slash is optional and `*` is a
wildcard, so `checkout/*` and `/checkout/*` both exclude everything under checkout.

**Inline the stylesheet** — Bed's CSS is about a kilobyte and is inlined rather than linked, because
its whole job is to hold space before the first paint and putting that behind a render-blocking
request would be a performance plugin causing the problem it measures. Turn it off to own the
appearance entirely; the two custom properties are documented in
[Usage](https://justinholt.com/plugins/craft-bed/docs/usage).

**Load the runtime** controls `bed.js`. Without it, deferred scripts never load and facades never
open — so Bed notices, and stops deferring scripts and stops building facades rather than removing
things nothing will put back. Reserved space and lazy frames are unaffected, because those are
markup and CSS.

## Next

- [Usage](https://justinholt.com/plugins/craft-bed/docs/usage) — Twig, opting out, styling, the console
- [Consent](https://justinholt.com/plugins/craft-bed/docs/consent) — holding embeds until the visitor agrees
- [Troubleshooting](https://justinholt.com/plugins/craft-bed/docs/troubleshooting) — when an embed is not getting a bed
