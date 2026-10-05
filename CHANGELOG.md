# Release Notes for Bed

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
