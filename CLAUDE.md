# Bed — Craft CMS 5 Plugin

## Project Overview

Bed takes HTML that already has embeds in it and makes them behave on the way out the door:
reserved space so nothing shifts, deferred loading, warmed connections for what loads immediately,
and facades in front of the expensive players.

Distributed as `justinholtweb/craft-bed`. **Free — no editions, no licensing code.**

Reference point: the WordPress *Embed Optimizer* plugin plus the *Optimization Detective* plugin it
leans on for measurement. Bed does both halves itself.

**Bed is not `[[project_craft_eye]]`.** Eye *authors* embeds — an element, a picker, ref tags, a
proxy. Bed never authors anything and has no field type. The two compose.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies beyond Craft's own, and **no outbound HTTP anywhere**.
  The provider registry is a description of what embeds look like, not a client for talking to
  anybody.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\bed`
- Package: `justinholtweb/craft-bed`
- Handle: `bed`

### The load-bearing idea: measure, do not guess

Reserving space for an embed whose height nobody knows is guesswork, and a guess that is too big is
as bad as one that is too small. So the page hands out a signed token naming its slots, a sampled
fraction of visitors beacon back what the embeds settled to at their viewport width, and later
renders reserve that.

**Rendering never writes to the database.** A response filter that inserts a row per page view has
turned every cache miss into a write. Slot rows are created by the collector; the renderer's one
question is answered by `Metrics::reservations()`, which is a cached read.

### Why offsets and not `DOMDocument`

A whole-page filter must return the bytes it did not mean to change unchanged. libxml's HTML4
parser cannot promise that — it re-serialises everything, moves nodes to satisfy a pre-HTML5
content model, and mangles `<template>`, inline `<svg>`, custom elements and entities.
`helpers\Html` tokenises once and records byte offsets; `Optimizer` splices **back to front** so no
offset moves before it is used. ~0.3 ms on a 33 KB page, and a page with no embeds is rejected by
one `preg_match` before any of it.

### Services

- `providers` — the registry: what each provider looks like in markup, its natural ratio, its
  origins, whether a facade can be built without a network call.
- `scanner` — HTML in, `EmbedNode[]` out, plus the loader scripts that are safe to lift.
- `optimizer` — the rewrite, in **document** mode (splices into head/body) or **fragment** mode
  (registers through the view).
- `metrics` — reservations, token signing and verification, accepting beacons.
- `ledger` — the only thing that reads or writes `bed_slots` / `bed_metrics`.
- `hints` — providers in, a capped and deduped set of `<link rel>` out.

### Tables

`bed_slots` (unique on `siteId` + `slotKey`; a slot is one page, one position, one embed) and
`bed_metrics` (unique on `slotId` + `breakpoint`; aggregates only, cascading key).

`embedKey` is *what* is embedded; `slotKey` is *where*. That split is what lets the ledger say
"this tweet measures 620px" while still knowing only one of the three places it appears is above
the fold.

## Traps found while building this

- **`iframe` is a raw-text element.** Its content is text under the HTML spec, so `<iframe … />`
  does *not* self-close — the frame runs to `</iframe>`. Treating the slash as authoritative wraps
  the bed round an empty tag and leaves the real frame outside it. The same list matters for
  `script`, `style`, `textarea` and `title`: an `<iframe>` inside any of them is a string, and
  rewriting it corrupts a code sample or somebody's unsaved form.
- **`strpos($html, '>')` cannot find the end of a start tag.** A `>` inside a quoted value is legal
  and common — `srcdoc`, inline styles, tracking URLs — so attribute values have to be *scanned*.
- **An inline `style` attribute beats a media query.** Writing `--bed-min` inline as a fallback
  silently disables every measured per-breakpoint height. All min-height rules go in the
  stylesheet; only `--bed-ar` is inline, where nothing competes with it.
- **Splice ordering needs a tiebreak.** Wrapping a frame is a zero-width insert at the frame's own
  start offset and rewriting its attributes begins there too. Sort by start descending *and end
  descending*, or the insert is applied first and the rewrite is dropped as an overlap.
- **`end()` cannot take a class constant** — it is by-reference. `Settings::bucket()` indexes
  instead.
- **Deferring is only a deferral if something puts it back.** `deferScripts` and facades both
  require the runtime, so `Optimizer` computes `$runtimeAvailable` once — settings *and* a
  successfully published asset URL — and everything downstream asks that rather than the setting.
  Without it a lifted script is just a removed script.
- **`craft\web\View` has no `getCss()`** — the registered CSS is the public `$css` array (Yii's).
  Only matters in the checks.
- **`eagerCount` counts embeds, not elements.** Two checks were wrong about this before the code
  was.
- **The collector must be re-checked server-side.** The page tells the client which buckets are
  already satisfied; `Metrics::isSatisfied()` asks again, because anything a client can be told is
  something a client can ignore.
- **Above the fold at any width counts as above the fold everywhere.** The two mistakes are not
  symmetrical: eagerly loading something below the fold costs a little bandwidth, lazily loading
  the thing at the top costs the LCP.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## The icon

`src/icon.svg` is a bed in side elevation — accent `#3B4B9A`, mark in `#FEFEFE`, on the family's
rounded tile (`rx="22.44"`).

The pillow is **punched out of** the mattress with `fill-rule="evenodd"` rather than drawn in a
second colour, so the whole mark is one white mass and cannot turn to mud at 32px. Headboard,
mattress and feet are **separate elements on purpose**: under evenodd, two overlapping subpaths of
one path cancel each other out, and the headboard would punch a notch through the mattress exactly
where the two meet. Separate elements simply union.

`src/icon-mask.svg` is the same geometry without the tile, for Craft's control-panel nav.

Three copies exist and all three have to move together: `src/icon.svg`, `promos/assets/icon.svg`,
and `justinholt/web/images/plugins/bed.svg` — followed by `ddev craft index-assets/all`, or the
page import relates no logo at all and "succeeds".

## Plugin Store promos

`promos/` renders the seven 1920×1080 marketing images for the Plugin Store listing:

```sh
./promos/build.sh          # all slides
./promos/build.sh "2 5"    # just those two
```

They live **in this repo**, not in a website repo — plugin marketing sites are pages inside the
justinholt.com install now, and the promos advertise the plugin rather than the page.

Three things learned building the deck:

- **The watermark is an outline, not the filled mark.** Filled, the bed is a wide slab with one
  small hole in it, and at 1180px across a slide it reads as a hard-edged grey box laid over the
  artwork. Same trap as Abacus's frame bars, Awesemo's chest box and Bandage's plaster: strip the
  mass, keep the line.
- **`.points li` is a flex container.** An inline `<span class="mono">` or `<em>` left loose in the
  text becomes a *sibling flex item* and picks up the 16px gap on both sides. Every point keeps its
  text in one `<span class="t">`.
- **The copy column has to fit beside the panel *and* above the footer.** The panel starts at
  `left: 900px` and the copy column is 780px, so a bullet that wraps to two lines both crowds the
  panel and pushes the last point onto the footer lockup — and nothing clips, the text simply lands
  on top. Two-line headings, three-or-four-line ledes, one line per bullet (~60 characters).

Bed is free, so unlike the paid plugins in the family there is no price on the cover badge and no
figure anywhere in the deck for a pricing change to strand.

## The marketing site

`justinholt.com/plugins/craft-bed`, a page inside that install — not a standalone project. The
procedure and its traps live in the **`plugin-marketing-site` skill** in that repo; invoke it rather
than working from memory.

`docs/*.md` is the source of truth for the site's documentation, synced by
`pluginsite/docs/sync craft-bed`. Front matter is required — a file without it is skipped, which is
how `docs/plan.md` stays off the site.

**Cross-document links in `docs/` must be absolute.** The site's docs sidebar links to
trailing-slash URLs, so a bare relative `](configuration)` resolves *under* the current page and
404s — even though the non-slashed URL answers fine when tested by hand. Craft's markdown gives
headings no `id` either, so a `#fragment` link is dead too; name the section in bold instead.

The page seed is `justinholt/scripts/seed/plugin-pages/craft-bed.json`. Everything the page says
about behaviour has to stay true of `src/models/Settings.php` — the defaults are quoted on the
marketing page and in `docs/configuration.md`, and nothing checks that they agree.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bed/tests/integration/checks.php   # 85 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-bed/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning. Every slot they write is on a URI under
`/__bed-checks/`, so they can never collide with a measurement from a real page view, they sweep up
strays from a run that died mid-way, and they put every setting they changed back at the end.

Live end-to-end: `tests/manual/bed-demo.twig` goes in the harness's `templates/`, then

```sh
curl -sk https://plugin-testing.ddev.site/bed-demo
```

should show the beds, the facade, the lifted Twitter script, the resource hints and the collector —
and the `<pre>` code sample and the `<script>` string literal untouched. Pull the token out of
`#bed-collect`, POST a measurement to the collect URL, and the min-height appears on the next
render.

`ddev exec` runs with `set -u`, so `docker exec ddev-plugin-testing-web …` is more reliable for
scripted work, and the Bash tool's working directory persists between calls — `cd` to the repo
explicitly. The harness is shared with other sessions and gets restarted from under you; wrap
container commands in a retry loop.

## Coding conventions

- `Craft::t('bed', '…')` for user-facing strings; `src/translations/en/bed.php` lists them all
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required` — it blocks fresh installs
- The response filter fails open. Every entry point is wrapped, and a page that renders slightly
  worse is a much better failure than a page that does not render.
