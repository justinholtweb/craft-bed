# Bed

**Every embed gets a bed.**

An embed arrives in a page as somebody else's markup and behaves like it. It loads whatever it
wants whenever it wants, and it settles to a height nobody knew in advance — after the reader has
already started reading, so the paragraph they were halfway through jumps down the screen.

Bed lays a bed for each one. Space is reserved from heights measured on real page views, loading
is deferred until the embed is wanted, and connections are warmed only for the embeds that are
wanted immediately.

Free. Craft 5.3+, PHP 8.2+. No outbound requests, no third-party services, no build step.

Reference point: the WordPress *Embed Optimizer* plugin, and the *Optimization Detective* plugin
it leans on for the measurement half. Bed does both halves itself, because a Craft site should not
have to install two plugins to stop a tweet from moving the page.

## Install

```sh
composer require justinholtweb/craft-bed
php craft plugin/install bed
```

That is the whole setup. Every front-end HTML response is filtered from then on.

## What it does

### Reserves the space

Every embed is wrapped in a box that holds its height before it arrives.

Where the shape is knowable — a YouTube frame, or anything carrying `width` and `height` — the box
is an `aspect-ratio` and needs no measurement at all. Where it is not — a tweet is as tall as the
tweet — the box gets a `min-height` per viewport width, taken from what that embed actually
measured on real page views of that page.

```html
<!-- before -->
<iframe src="https://player.vimeo.com/video/76979871" width="640" height="360"></iframe>

<!-- after -->
<div class="bed bed--vimeo bed--iframe bed--ratio" data-bed="e87d290a…" style="--bed-ar:640/360">
  <iframe src="https://player.vimeo.com/video/76979871" width="640" height="360"
          loading="lazy" title="Vimeo embed" referrerpolicy="strict-origin-when-cross-origin"></iframe>
</div>
```

### Defers the loading

`loading="lazy"` on frames, `preload="none"` on video and audio, and for script-driven embeds — a
tweet, a TikTok, an Instagram post — the provider's loader `<script>` is lifted out of the document
entirely and injected by an IntersectionObserver when the embed nears the viewport.

The first embed on the page is left eager, and so is any embed measured inside the first
screenful. Lazy-loading the thing at the top of an article delays the Largest Contentful Paint,
which is the opposite of the point.

### Warms the connections

`preconnect` for the providers whose embeds load immediately, `dns-prefetch` for the rest. Both
deduped and capped, because a preconnect is a DNS lookup, a TCP connection and a TLS handshake
opened on spec and wasted entirely if the page never uses it.

### Puts a facade in front of the expensive ones

A YouTube player is a document, a few hundred kilobytes of script and a handful of third-party
connections, all spent before anybody has decided to watch anything. Bed puts a poster and a play
button there instead, and swaps in the real player on the click — with autoplay, so one click is
still one click.

Only providers whose poster can be worked out without asking them anything are eligible, so
building the facade costs no request of its own. Turn `facadePosters` off and the facade is drawn
from CSS alone: a reader who never clicks makes no third-party request at all.

## The measurement loop

Reserving space for an embed whose height nobody knows is guesswork, and a guess that is too big
is as bad as a guess that is too small — one leaves a hole, the other still moves the page. So Bed
does not guess.

1. **First render.** Each embed is wrapped and given a slot key, and the page carries a signed
   token listing exactly which slots are on it.
2. **The runtime reports.** `bed.js` measures each embed's settled height and whether it started
   inside the first screenful, and beacons a batch back — for a sampled fraction of page views
   (one in ten by default), and only while the server still says it wants samples.
3. **Later renders.** The aggregate is known per viewport bucket, so Bed emits a `min-height` for
   those widths, stops lazy-loading the embeds measured above the fold, and upgrades their
   providers from `dns-prefetch` to `preconnect`.

Measurements are grouped into seven viewport buckets — 360, 480, 768, 1024, 1280, 1536, 1920 — and
emitted as a media-query ladder:

```css
[data-bed="2a8cbce…"]{--bed-min:550px}
@media (min-width:1025px) and (max-width:1280px){[data-bed="2a8cbce…"]{--bed-min:623px}}
```

### About the endpoint

The collector is public, anonymous and CSRF-exempt, because it is called by `sendBeacon` from a
page that may have come out of a full-page cache hours ago. What makes that safe is not a header:

- The token is signed with **Craft's own security key** — `Security::hashData()`, the same
  primitive behind the CSRF token. No hand-rolled crypto anywhere. It names the site, the page and
  the exact list of slots, so a client can only report on embeds that were on a page it was
  actually served, and it cannot invent a provider or a URI.
- Every number is clamped and every viewport width is snapped to a bucket **on the server**, so the
  worst a valid-but-dishonest report can do is drag a mean around inside a sane range.
- Rows are aggregates only — a count, a sum, a maximum, and a tally of how often the embed was
  above the fold. Nothing per-visitor is stored: no address, no user agent, no URL from the client.
- A slot stops accepting samples once it has enough, one address gets a fixed number of beacons a
  minute, the ledger has a hard row cap, and old rows are swept by Craft's garbage collection.

## Twig

The response filter covers everything by default. Turn `autoOptimize` off to opt in instead:

```twig
{{ entry.body|bed }}
{{ bed(someHtml) }}
```

Both take the same path as the filter. The wrappers come back in the markup; the stylesheet,
resource hints and collector go through the view and land in the head and the foot by themselves.

```twig
{% for provider in craft.bed.providers() %}{{ provider.name }}{% endfor %}
{{ craft.bed.stats().samples }}
{% for slot in craft.bed.slots(20) %}{{ slot.uri }}{% endfor %}
```

## Opting an embed out

Put `data-bed-skip` on an embed or on anything containing one, and Bed will not touch it or
anything under it.

```twig
<div data-bed-skip>
  {{ entry.embedICareAboutMyself }}
</div>
```

Add URI patterns to **Never optimise these URIs** in the settings to exclude whole pages.

## Console

```sh
php craft bed/metrics/report        # what has been measured
php craft bed/metrics/prune         # drop slots nobody has seen lately
php craft bed/metrics/purge         # start over
php craft bed/metrics/breakpoints   # the viewport buckets and their media queries
php craft bed/providers/list        # the registry (--kind=iframe|script)
php craft bed/providers/match URL   # what Bed would make of one URL
```

## Providers

Around sixty, covering video (YouTube, Vimeo, Loom, Wistia, Twitch, Dailymotion, Brightcove,
Kaltura, JW Player, TED, Descript…), audio (Spotify, SoundCloud, Apple Music and Podcasts,
Bandcamp, Mixcloud, Podbean, Buzzsprout, Transistor), Google Maps, Docs, Drive and Calendar, forms
and scheduling (Calendly, Typeform, Tally, Jotform, Airtable), design and code (Figma, Canva, Miro,
CodePen, JSFiddle, CodeSandbox, StackBlitz, Replit, Sketchfab, Matterport), data and documents
(Datawrapper, Flourish, Tableau, SlideShare, Scribd, Issuu, OpenStreetMap, Mapbox), and the
script-driven social embeds (X/Twitter, Instagram, TikTok, Reddit, Bluesky, Mastodon, Threads,
Facebook, Pinterest, Flickr, Imgur, Giscus).

**An unrecognised embed is still handled.** It gets a bed built from its own dimensions, a lazy
attribute, a title and a referrer policy. Recognition buys the natural ratio, the resource hints
and the facade.

## Styling

Bed's stylesheet is about a kilobyte and is **inlined**, not linked — its whole job is to hold
space before the first paint, and putting that behind a render-blocking request would be a
performance plugin causing the problem it measures.

Everything is driven by two custom properties, so you can turn `registerCss` off and own the
appearance entirely without changing anything about how the beds work:

| Property | What it holds |
| --- | --- |
| `--bed-ar` | the aspect ratio, on embeds with a known shape |
| `--bed-min` | the reserved height, on embeds whose height was measured |

Classes: `.bed`, `.bed--{provider}`, `.bed--{iframe\|script\|media}`, `.bed--ratio`,
`.bed--facade`. The runtime stamps `data-bed-loaded` on a bed once its embed has arrived — there is
deliberately no rule attached to it, because releasing the reserved height at that moment would let
a short embed pull the page back up, and a layout shift is a layout shift whichever way it goes.

## What Bed is not

It does not create embeds. There is no field type, no picker, no reference tag and no element.
Bed takes HTML that already has embeds in it, from wherever they came from — a rich-text field, a
hard-coded template, another plugin's render — and makes them behave on the way out the door.

If you want to *author* embeds in Craft, that is [Eye](https://github.com/justinholtweb/craft-eye).
The two compose: Eye's rendered markup is exactly the kind of thing Bed optimises.

## How the rewrite works

A whole-page filter has one hard requirement: **the bytes it did not mean to change must come out
identical.** `DOMDocument` cannot promise that — it is libxml's HTML4 parser, it re-serialises the
whole document, it moves nodes to satisfy a content model that predates HTML5, and it has opinions
about `<template>`, inline `<svg>`, custom elements and entities.

So Bed tokenises the HTML once, records byte offsets for the tags it cares about, and splices its
edits back to front so no offset moves before it has been used. Everything outside those ranges is
copied through byte for byte. An `<iframe>` written inside a `<script>`, a `<textarea>` or an HTML
comment is text, and text is left alone.

It costs about **0.3 ms on a 33 KB page**, and a page with no embeds on it is rejected by a single
pattern match before any of that happens.

## Documentation

Full documentation is at
[justinholt.com/plugins/craft-bed/docs](https://justinholt.com/plugins/craft-bed/docs) —
installation, every setting, Twig, troubleshooting and an FAQ.

## Licence

The Craft License. See `LICENSE.md`. Bed is free: no editions, no licence key, and no licensing
code in the plugin.
