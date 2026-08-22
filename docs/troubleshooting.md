---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: When an embed gets no bed, when no heights are reserved, and when something else on the page is fighting back.
---

## An embed is not getting a bed at all

Work down this list; it is roughly in order of likelihood.

1. **The settings.** **Enabled** and **Optimise front-end pages automatically** both have to be on.
   If a `config/bed.php` exists, it wins over the control panel and the control panel will show the
   field as overridden.
2. **The URI is excluded.** Check **Never optimise these URIs**. Patterns match with `*` wildcards
   and the leading slash is optional, so `checkout*` also matches `/checkouts-report`.
3. **Something has `data-bed-skip` on it.** The attribute applies to everything inside the element
   carrying it, so one on a wrapper high up the page can silence a whole region.
4. **The embed is inside a raw-text element.** An `<iframe>` written inside `<script>`, `<style>`,
   `<textarea>`, `<title>` or an HTML comment is *text*, not an embed, and Bed leaves it alone
   deliberately. This is why a code sample showing an iframe does not get wrapped.
5. **The response is not a front-end HTML page.** Bed skips control panel requests, non-HTML
   responses and any status of 400 or above.
6. **The embed is added by JavaScript after the response.** Bed rewrites the HTML on the way out of
   the server. Markup that does not exist until a client-side script runs was never in the bytes it
   saw.

## The bed is there, but no height is reserved

This is almost always the measurement warm-up, and it is normal.

An embed whose shape is knowable is reserved on the very first render, with an `aspect-ratio`. An
embed whose height is not knowable — a tweet, a Bluesky post, most script embeds — needs
measurements first, and needs them per viewport bucket:

```sh
php craft bed/metrics/report
```

- **Slots tracked at 0** — nothing has reported yet. Check **Collect measurements** is on, that
  **Load the runtime** is on, and that `bed.js` is loading in the browser without a console error.
- **Slots tracked, samples at 0** — the beacon is not arriving. See below.
- **Samples at, say, 4** — it is working, and it needs more views. The default is one view in ten
  sampled and twenty samples wanted per bucket, so a bucket takes about two hundred views to fill.
  Raise **Sample rate** to `1` while you are testing.

Remember that a bucket is per viewport width. A page whose visitors are all on desktop will fill
`1280` and `1536` and never fill `360`, and that is correct — those widths stay unreserved rather
than getting a desktop height that would be wrong.

## The beacon is not arriving

Open the network panel and look for a `POST` to `/actions/bed/metrics/collect` as you scroll.

- **No request at all** — the runtime is not on the page (**Load the runtime**), or this page view
  was not in the sample (**Sample rate**), or every bucket on the page is already satisfied.
- **A 400** — the token did not verify. Tokens are signed per page and expire after seven days by
  default, so a page served from a full-page cache older than that will fail. Clear the cache, or
  raise **Collection token lifetime**.
- **A 429** — the per-address rate limit. Thirty beacons a minute from one address is generous for a
  reader and stingy for a load test; raise **Beacons per minute** if you are hammering it yourself.
- **Nothing stored despite a 200** — the server re-checks whether a bucket still wants samples, and
  says 200 either way. Anything a client can be told, a client can ignore, so the server never
  trusts the page's word for what is already satisfied.

## The reserved height is wrong

- **Too short, and the page still shifts** — the embed grows after it loads (a thread that expands,
  a comment widget). Switch **Reserved height** to `max` for a strategy that never shifts down.
- **A gap under short embeds** — that is `max` doing what it promises. Switch back to `mean`.
- **The heights are stale** — the embed changed, or the page did. `php craft bed/metrics/purge`
  starts that site over; deleting a single slot from **Bed → Embeds** starts one page over.

## Layout shift on the way *up*

A bed keeps its reserved height after the embed has arrived, on purpose. If you have added a rule
against `data-bed-loaded` that releases it, that is where the upward shift is coming from — a short
embed pulls the page back up, and a layout shift is a layout shift whichever way it goes.

## The facade is not appearing

- Facades need the runtime. If **Load the runtime** is off, Bed stops building facades rather than
  leaving a poster nothing can open.
- Only providers Bed can build a poster for without a network request are eligible, and only the
  ones listed in **Use a facade for**. The control panel only offers the eligible ones.
- With **Use the provider's poster image** off the facade is drawn from CSS alone, so it is a plain
  panel with a play button rather than a still from the video. That is the setting working.

## Something else on the page is fighting it

- **A CSS rule with `!important` on `.bed`** will beat Bed's inlined stylesheet. Bed's own rules
  carry no `!important`, deliberately, so yours win — including by accident.
- **A stylesheet that sets `height` on iframes** overrides the reserved box. Bed reserves space on
  the wrapper and lets the embed fill it; a fixed height on the embed makes the wrapper irrelevant.
- **Another plugin rewriting the response** may run after Bed and re-serialise the document. If
  wrappers are appearing in the source but not in the browser, look at what else is listening on
  `Response::EVENT_AFTER_PREPARE`.

## Something broke and the page still rendered

That is the design. Every entry point is wrapped, and a page that renders slightly worse is a much
better failure than a page that does not render. When Bed gives up on a page it says so in the log
under the `bed` category:

```sh
tail -f storage/logs/web.log | grep bed
```

If the log has an exception in it, that is a bug — please
[report it](mailto:justin@justinholt.com) with the exception and, if you can, the markup that
triggered it.

## Next

- [Configuration](https://justinholt.com/plugins/craft-bed/docs/configuration) — every setting and what it defaults to
- [FAQ](https://justinholt.com/plugins/craft-bed/docs/faq) — privacy, caching, and what happens if you remove it
