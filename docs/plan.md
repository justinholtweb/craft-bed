# Bed — plan

**Every embed gets a bed.** An embed arrives in a page as somebody else's markup and behaves like
it: it loads whatever it wants whenever it wants, and it settles to a height nobody knew in
advance — after the reader has already started reading. Bed lays a bed for each one. Space is
reserved from heights measured on real page views, loading is deferred until the embed is wanted,
and connections are warmed only for the embeds that are wanted immediately.

Package `justinholtweb/craft-bed`, namespace `justinholtweb\bed`, handle `bed`.
**Free — no editions, no licensing code.** Craft 5.3+, PHP 8.2+, no build step.

Reference point: the WordPress *Embed Optimizer* plugin, and the *Optimization Detective* plugin
it leans on for the measurement half. Bed does both halves itself, because a Craft site should not
have to install two plugins to stop a tweet from moving the page.

## Not Eye

`[[project_craft_eye]]` is about **authoring** an embed — an element, a picker, reference tags, a
proxy for pages that refuse to be framed. Bed never authors anything. It takes HTML that already
has embeds in it, from wherever they came from, and makes them behave on the way out the door.
The two compose: Eye's rendered markup is exactly the kind of thing Bed optimises.

## The four things Bed does

1. **Reserves the space** — a wrapper with `aspect-ratio` where the ratio is knowable, and a
   per-breakpoint `min-height` where it is not, taken from what the embed actually measured on
   real page views. This is the CLS half and the reason the plugin has a database.
2. **Defers the loading** — `loading="lazy"` on frames, `preload="none"` on media, and for
   script-driven embeds (a tweet, a TikTok, an Instagram post) the loader `<script>` is lifted out
   of the document and injected by an IntersectionObserver when the embed nears the viewport.
3. **Warms the connections** — `preconnect` for the providers that were measured *in* the initial
   viewport, `dns-prefetch` for the rest. Both are a budget, not a free win, so the set is deduped
   and capped.
4. **Puts a facade in front of the expensive ones** — a poster and a play button where a YouTube
   player would be, and the real player only once somebody clicks it.

Every one of these is off for an embed Bed has no confidence about, and every one of them can be
turned off wholesale in settings.

## The measurement loop (the load-bearing idea)

Reserving space for an embed whose height nobody knows is guesswork, and a guess that is too big
is as bad as a guess that is too small. So Bed does not guess: it asks the page.

1. **First render.** Bed wraps each embed in `<div class="bed" data-bed="{slot}">`, uses the
   provider's natural ratio if it has one, and — because it has no measurements yet — marks the
   page as wanting some. It emits a signed collection token listing exactly which slots are on
   this page.
2. **The runtime reports.** `bed.js` waits for load, measures each marked wrapper's rendered
   height and whether its top was inside the initial viewport, and beacons a batch back. It only
   does this for a sampled fraction of page views, and only while the server still says it wants
   samples.
3. **Later renders.** The aggregate is now known per viewport bucket, so Bed emits a
   `min-height` per breakpoint for those slots, stops lazy-loading the ones measured above the
   fold, and upgrades their providers from `dns-prefetch` to `preconnect`.

The endpoint that receives the beacon is a public, anonymous, CSRF-exempt write, so it is
designed as one:

- The token is `Craft::$app->getSecurity()->hashData()` over the site, the page and the exact list
  of slot keys — so a client can only report on embeds that were on a page it was actually served.
  No hand-rolled HMAC anywhere.
- Every reported number is clamped, and the viewport width is snapped to a fixed bucket, so the
  worst a forged-but-signed report can do is skew a mean inside a sane range.
- Rows are aggregates only — a count, a sum, a max. Nothing per-visitor is stored, no address, no
  user agent, no URL from the client.
- A slot stops accepting samples once it has enough, the ledger has a hard row cap, and old rows
  are swept by Craft's garbage collection.

## Why the HTML is rewritten with offsets and not `DOMDocument`

A whole-page filter has one hard requirement: **the bytes it did not mean to change must come out
identical.** `DOMDocument` cannot promise that. It is libxml's HTML4 parser — it moves nodes to
satisfy a content model it learned before HTML5 existed, it mangles `<template>`, inline `<svg>`
and custom elements, it has opinions about void tags and entities, and `saveHTML()` re-serialises
the entire document whether or not anything changed. Rewriting somebody's whole page as a side
effect of adding `loading="lazy"` to one iframe is not a trade worth making.

So `services\Scanner` tokenises the HTML looking only for the start tags it cares about, records
byte offsets, and `services\Optimizer` applies its edits **back to front** so earlier offsets stay
valid. Everything outside the ranges Bed touches is copied through untouched, byte for byte.

## Services

- `providers` — the registry. What each provider looks like in markup, its natural ratio, the
  origins it needs, and whether a facade can be built for it without a network call.
- `scanner` — HTML in, `EmbedNode[]` out. Offsets, tag name, attributes, provider, kind.
- `optimizer` — the rewrite. Orchestrates scan → per-node transforms → head injection.
- `metrics` — the aggregate: read reservations, accept beacons, decide whether a slot still wants
  samples.
- `ledger` — the slot table. The only thing that reads or writes `bed_slots` / `bed_metrics`.
- `hints` — turns the providers seen on a page into a capped, deduped set of `<link rel>` tags.

## Tables

- `bed_slots` — one row per (site, page, position, embed). `slotKey` unique per site, plus
  `provider`, `kind`, `uri`, `sampleSrc`, `position`, `lastSeen`.
- `bed_metrics` — one row per (slot, breakpoint): `samples`, `heightSum`, `heightMax`,
  `aboveFold`. Cascades from the slot.

## Surfaces

- **Automatic** — `Response::EVENT_AFTER_PREPARE` on front-end HTML responses. On by default.
- **Twig** — `{{ entry.body|bed }}`, `craft.bed.optimize(html)`, `craft.bed.providers`,
  `craft.bed.stats`, for sites that would rather opt in field by field.
- **CP** — a Bed section listing every slot, what it measured, and how confident Bed is about it.
- **Console** — `bed/metrics/report`, `bed/metrics/prune`, `bed/metrics/purge`,
  `bed/providers/list`.

## Build order

1. skeleton, settings, provider registry
2. HTML helper + scanner
3. records, install migration, ledger
4. metrics + signing + the collect controller
5. hints, optimizer, the response filter
6. runtime (`bed.js`, `bed.css`)
7. CP, console, Twig
8. integration checks
