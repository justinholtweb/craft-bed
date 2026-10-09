# Release Notes for Bed

## 5.1.0 — 2026-10-09
### Added

- **Embeds can wait for consent.** List providers under **Require consent for** (`consentProviders`,
  provider → category, `*` for every recognised provider) and their embeds sit behind a short notice
  until the visitor allows the category. Nothing third-party is fetched before then: frames, players
  and facades (poster included) wait in an inert `<template>`, a script embed's loader script waits
  on the bed, and held providers get no resource hints. The notice offers **Load the embed** for just
  that one, a **Cookie settings** button where the platform can be reopened, and a link to the
  content at its source. Nothing is listed by default.
- **Toss is the consent manager when it is on.** With Toss installed and its cookie consent kit
  switched on, Bed follows the visitor's answer to Toss (`window.Toss`, the `toss:consent` event),
  releases embeds the moment they accept and puts frames back if they withdraw. Undecided is not
  refused: both wait, and the bed says which. `deferToToss` (on by default) is the way out.
- Without Toss, `consentSource` reads Cookiebot, CookieYes, Tape's consent state, a cookie of your
  own, or nothing at all — readers click to load each embed. Your own banner can call
  `Bed.consent('marketing', true)`.
- The page stays identical for every visitor — the answer is read in the browser — so held embeds
  are safe behind Blitz or a CDN.
- Embeds inside Eye's own click-to-load figure, or any element marked `data-bed-consent-managed`, are
  never asked about twice.

## 5.0.1 — 2026-10-05

### Security

- **One visitor could fix how much room an embed got, for good.** The beacon endpoint is public by
  design, so anyone could take a signed token from a public page and send the whole sample target
  themselves — twenty beacons, inside the rate limit — all reporting 20,000 px. The reservation was
  the mean of the samples and collection then stopped, so the embed carried a 20,000 px gap until an
  admin purged it. Now each visitor counts **once** per slot per viewport bucket, the reservation is
  the **median** of the measured heights (the `max` strategy takes the tallest within half again of
  the median), and a report many times taller than the viewport is wide is dropped.
- **The beacon rate limit believed any `X-Forwarded-For`**, so a client could name a fresh address
  with every beacon, and it counted without a lock. It now keys on the connecting address (the
  forwarded one only when `trustedHosts` names your proxies), counts under a lock, and sits under a
  site-wide ceiling.

Slots measured before this release keep their old reservation until they are re-measured. If a
page shows an embed with a gap far taller than it should be, `php craft bed/metrics/purge` (or the
Clear measurements screen) starts it over.

### Changed

- The measured heights are kept per slot and bucket (numbers only, up to the sample target) — a
  migration adds the column.
- PHPStan and ECS configuration.

## 5.0.0

Initial release.

- Reserves space for every embed — an `aspect-ratio` box where the shape is knowable, a
  per-viewport `min-height` measured from real page views where it is not.
- Lazy-loads frames and media, and lifts script-driven embeds' loader scripts out of the document
  until the embed nears the viewport.
- Leaves the first embed on a page, and anything measured above the fold, loading eagerly.
- Adds capped, deduped `preconnect` and `dns-prefetch` hints for the providers on a page.
- Builds poster-and-play-button facades for providers whose poster can be derived without an
  outbound request.
- Adds missing frame titles and a referrer policy.
- A measurement loop: a signed per-page token, a sampled `sendBeacon` from the runtime, and an
  aggregate-only ledger with a row cap, a rate limit and garbage collection.
- Around sixty providers, plus sensible handling of anything unrecognised.
- `|bed` filter and `bed()` function for sites that would rather opt in field by field.
- Control panel screens for what has been measured, and console commands for reporting, pruning
  and inspecting the registry.
