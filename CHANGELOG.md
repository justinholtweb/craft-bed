# Release Notes for Bed

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
