---
title: Usage
slug: usage
order: 30
summary: Twig, opting individual embeds out, styling the beds yourself, the control panel and the console.
---

Most sites never write any Twig for Bed — the response filter covers every front-end HTML response.
Everything on this page is for the cases where that is not what you want.

## Twig

Turn **Optimise front-end pages automatically** off and Bed becomes opt-in, one field at a time:

```twig
{{ entry.body|bed }}
{{ bed(someHtml) }}
```

The filter and the function are the same thing and take the same path as the whole-page filter. The
wrappers come back in the markup; the stylesheet, the resource hints and the collector go through
the view and land in the head and the foot by themselves.

Both are output-safe, so no `|raw` is needed — and adding one is harmless.

Calling the filter on six fields of one page does not inject six collectors. The additions are
registered once per request.

### Reading the registry and the ledger

```twig
{% for provider in craft.bed.providers() %}
  {{ provider.name }} — {{ provider.kind }}
{% endfor %}

{% for provider in craft.bed.facadeProviders() %}{{ provider.name }}{% endfor %}

{{ craft.bed.stats().slots }} slots, {{ craft.bed.stats().samples }} samples

{% for slot in craft.bed.slots(20) %}
  {{ slot.uri }} — {{ slot.provider }}
{% endfor %}
```

`craft.bed.slots()` takes a limit and an optional site ID. These read the ledger; they never write
to it.

## Opting an embed out

Put `data-bed-skip` on an embed, or on anything containing one, and Bed will not touch it or
anything underneath it:

```twig
<div data-bed-skip>
  {{ entry.embedICareAboutMyself }}
</div>
```

To exclude whole pages instead, add URI patterns to **Never optimise these URIs** in the settings.

Bed already leaves alone anything written inside `<script>`, `<style>`, `<textarea>`, `<title>` or
an HTML comment. An `<iframe>` in a code sample is a string, and rewriting it would corrupt the
sample.

## Styling

Everything a bed does visually is driven by two custom properties:

| Property | What it holds |
| --- | --- |
| `--bed-ar` | the aspect ratio, on embeds whose shape is known |
| `--bed-min` | the reserved height, on embeds whose height was measured |

`--bed-ar` is written inline, where nothing competes with it. Every measured `min-height` goes in
the stylesheet as a media-query ladder, because an inline `style` attribute beats a media query and
writing a fallback inline would silently disable every per-breakpoint height:

```css
[data-bed="2a8cbce…"]{--bed-min:550px}
@media (min-width:1025px) and (max-width:1280px){[data-bed="2a8cbce…"]{--bed-min:623px}}
```

The classes are `.bed`, `.bed--{provider}`, one of `.bed--iframe` / `.bed--script` / `.bed--media`,
plus `.bed--ratio` on embeds with a known shape and `.bed--facade` on the ones wearing a poster.

Turn **Inline the stylesheet** off in the settings and you own the appearance completely, without
changing anything about how the beds work.

The runtime stamps `data-bed-loaded` on a bed once its embed has arrived. There is deliberately no
rule attached to it: releasing the reserved height at that moment would let a short embed pull the
page back up, and a layout shift is a layout shift whichever way it goes.

## The control panel

**Bed → Embeds** lists what has been measured — the page, the provider, how many samples, and the
heights per viewport bucket. Clicking a row shows every bucket for that slot, and whether it has
been seen above the fold.

Two permissions:

- **View measured embeds** — read the Embeds screens
- **Clear measurements** (nested under it) — delete a slot, prune, or purge

Settings are admin-only, as Craft plugin settings always are.

## The console

```sh
php craft bed/metrics/report        # what has been measured, and by provider
php craft bed/metrics/prune         # drop slots nobody has seen lately (--days=90)
php craft bed/metrics/purge         # start over (--siteId=1)
php craft bed/metrics/breakpoints   # the viewport buckets and their media queries
php craft bed/providers/list        # the registry (--kind=iframe|script)
php craft bed/providers/match URL   # what Bed would make of one URL
```

`providers/match` is the useful one before you paste anything into a page:

```
$ php craft bed/providers/match https://youtu.be/dQw4w9WgXcQ

  YouTube (youtube)
  ──────────────────────────────────────────────────
  Kind             iframe
  Ratio            16:9
  Fallback height  —
  Preconnect       https://www.youtube.com, https://i.ytimg.com
  DNS prefetch     https://www.google.com, https://googleads.g.doubleclick.net, …
```

A URL Bed does not recognise says so, and says what would happen anyway: a lazy attribute, a title,
and a bed built from the embed's own dimensions.

`bed/metrics/prune` also runs by itself, as part of Craft's garbage collection.

## Providers

Around sixty, covering video (YouTube, Vimeo, Loom, Wistia, Twitch, Dailymotion, Brightcove,
Kaltura, JW Player, TED, Descript), audio (Spotify, SoundCloud, Apple Music and Podcasts, Bandcamp,
Mixcloud, Podbean, Buzzsprout, Transistor), Google Maps, Docs, Drive and Calendar, forms and
scheduling (Calendly, Typeform, Tally, Jotform, Airtable), design and code (Figma, Canva, Miro,
CodePen, JSFiddle, CodeSandbox, StackBlitz, Replit, Sketchfab, Matterport), data and documents
(Datawrapper, Flourish, Tableau, SlideShare, Scribd, Issuu, OpenStreetMap, Mapbox), and the
script-driven social embeds (X/Twitter, Instagram, TikTok, Reddit, Bluesky, Mastodon, Threads,
Facebook, Pinterest, Flickr, Imgur, Giscus).

**An unrecognised embed is still handled.** Recognition buys the natural ratio, the resource hints
and the facade — not the bed itself.

## What Bed does not do

It does not create embeds. There is no field type, no picker, no reference tag and no element. Bed
takes HTML that already has embeds in it — from a rich-text field, a hard-coded template, or another
plugin's render — and makes them behave on the way out the door.

If you want to *author* embeds in Craft, that is [Eye](https://github.com/justinholtweb/craft-eye).
The two compose: Eye's rendered markup is exactly the kind of thing Bed optimises.

## Next

- [Troubleshooting](https://justinholt.com/plugins/craft-bed/docs/troubleshooting) — when an embed is not getting a bed
- [FAQ](https://justinholt.com/plugins/craft-bed/docs/faq) — privacy, caching, and what happens if you remove it
