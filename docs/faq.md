---
title: FAQ
slug: faq
order: 50
summary: Cost, privacy, caching, GDPR, what it stores, and what happens to your pages if you remove it.
---

## Is Bed really free?

Entirely, permanently, with no editions and no licence key. Every feature in the documentation is in
the one version there is, and there is no licensing code in the plugin to remove later.

## Does it phone home, or call any third party?

No. **Bed makes no outbound HTTP request anywhere** — not at install, not on a render, not on a
schedule. The provider registry is a static description of what embeds look like, not a client for
talking to anybody, and facade posters are derived from the embed URL rather than fetched.

The only network traffic Bed adds to a page is the beacon from your own visitors to your own site,
and the `preconnect` hints — which are a browser opening a connection to a host the page was already
going to use.

## What does it store about my visitors?

Nothing that identifies one. A measurement row is an aggregate: a count of samples, the heights
measured (numbers only, up to the sample target), the tallest seen, and a tally of how often the
embed started above the fold — per slot, per viewport bucket.

To count each visitor once per slot, Bed keeps a cache entry for a day keyed on a **hash** of the
connecting address and the slot. It is never written to the database, holds no address, and
expires on its own.

No address, no user agent, no session, no URL taken from the client, no cookie, no localStorage. The
page tells the server which slots are on it, and the server already knew, because the page's token
is signed with Craft's own security key and names them.

## Do I need a cookie banner or a GDPR entry for it?

Bed itself stores no personal data and sets no cookie, so it does not create a consent obligation of
its own. Left to itself it does not change your position on the embeds — a YouTube player still
does whatever YouTube does.

In fact facades usually improve it: with **Use the provider's poster image** turned off, a reader who
never clicks makes **no third-party request at all**, which is a stronger privacy position than the
page had before Bed was installed.

And it can do the holding back for you. List a provider under **Require consent for** and its embeds
wait behind a short notice until the visitor allows the category — read from Toss, Cookiebot,
CookieYes, Tape or a cookie of your own — with nothing third-party fetched before then. See
[Consent](https://justinholt.com/plugins/craft-bed/docs/consent).

## The collector is a public, CSRF-exempt endpoint. Is that safe?

Yes, and it has to be public — it is called by `sendBeacon` from a page that may have come out of a
full-page cache hours ago, when no session and no CSRF token can be assumed.

What makes it safe is not a header. The page carries a token signed with `Security::hashData()`, the
same primitive behind Craft's own CSRF token, naming the site, the page and the exact list of slots.
A client can only report on embeds that were on a page it was actually served, and it cannot invent
a provider or a URI. Every number is clamped and every viewport width is snapped to a bucket on the
server, and a report many times taller than the viewport is wide is dropped. Each visitor counts
once per slot, and the reserved height is a median, so moving it takes more than half the samples
from as many different visitors — one dishonest visitor cannot. On top of that: a per-address rate
limit under a site-wide ceiling, a hard row cap on the ledger, and a slot that stops accepting
samples once it has enough.

## Does it write to the database on every page view?

No, and this is deliberate. **Rendering never writes to the database** — a filter that inserts a row
per page view turns every cache miss into a write. Slot rows are created by the collector endpoint,
and the renderer's one question is answered by a cached read.

## Will it work with full-page caching, Blitz, or a CDN?

Yes. The rewrite happens on the way out of Craft, so it is baked into whatever gets cached, and
cached pages carry their token with them. That is why the token's default lifetime is seven days
rather than a session's worth.

Two things worth knowing:

- A page cached *before* a slot was measured keeps its unreserved bed until the cache is refreshed.
  Reserved heights appear at the next render, not in the cached bytes.
- A page cached for longer than the token lifetime will fail verification and its beacons will be
  rejected with a 400. Raise **Collection token lifetime** or shorten the cache.

## How long before it actually reserves anything?

Immediately for anything with a knowable shape — a YouTube frame, or any embed carrying `width` and
`height`, gets its `aspect-ratio` on the first render with no measurement at all.

For the rest: one page view in ten is sampled, and a slot wants twenty samples at a viewport bucket,
so roughly two hundred views of a page fills one bucket. Turn **Sample rate** up to `1` in
development to see it in an afternoon.

## Will it slow my pages down?

The rewrite costs about **0.3 ms on a 33 KB page**, and a page with no embeds on it is rejected by a
single pattern match before any of that runs. The stylesheet is about a kilobyte and is inlined
rather than linked, because its whole job is to hold space before the first paint.

## Why not use `DOMDocument`?

Because a whole-page filter has one hard requirement — **the bytes it did not mean to change must
come out identical** — and libxml's HTML4 parser cannot promise that. It re-serialises the whole
document, moves nodes to satisfy a content model that predates HTML5, and has opinions about
`<template>`, inline `<svg>`, custom elements and entities.

Bed tokenises the HTML once, records byte offsets for the tags it cares about, and splices its edits
back to front so no offset moves before it has been used. Everything outside those ranges is copied
through byte for byte.

## Does it work on embeds authored by another plugin?

Yes. Bed reads the finished response, so it does not care where an embed came from — a rich-text
field, a hard-coded template, a Matrix block, or another plugin's render.

## How is this different from Eye?

[Eye](https://github.com/justinholtweb/craft-eye) *authors* embeds: an element, a picker, reference
tags, a proxy. Bed authors nothing and has no field type — it takes HTML that already has embeds in
it and makes them behave. The two compose, and Eye's rendered markup is exactly the kind of thing
Bed optimises.

## What happens to my content if I uninstall it?

Nothing. Bed never modifies stored content — the wrappers are added to the response on the way out,
so the entry in the database is the same markup you pasted in. Uninstalling drops `bed_slots` and
`bed_metrics`, which are measurements rather than content, and every page goes back to exactly the
embeds it had before.

## Which versions are supported?

Craft CMS 5.3 or later and PHP 8.2 or later, with no runtime dependencies beyond Craft's own.

## Something is wrong. Where do I report it?

[justin@justinholt.com](mailto:justin@justinholt.com), or the
[issue tracker](https://github.com/justinholtweb/craft-bed/issues). Include the Craft and PHP
versions and, if a page is being rewritten wrongly, the markup that triggers it — that is the part
that is hard to guess. Have a look at
[Troubleshooting](https://justinholt.com/plugins/craft-bed/docs/troubleshooting) first.
