---
title: Consent
slug: consent
order: 25
summary: Holding embeds behind a notice until the visitor allows their consent category — with Toss, Cookiebot, CookieYes, Tape, a cookie of your own, or a click.
---

A YouTube player sets advertising cookies the moment it loads, and so does nearly every social
embed. Under most consent regimes that means it may not load until the visitor has agreed. Bed can
hold those embeds back for you, on every page, without anybody editing the content they were pasted
into.

## Choosing what waits

List the providers under **Require consent for** (`consentProviders`), each with the consent
category its embeds belong to:

```php
// config/bed.php
return [
    'consentProviders' => [
        'youtube' => 'marketing',
        'vimeo' => 'marketing',
        'twitter' => 'marketing',
        'googlemaps' => 'preferences',
    ],
];
```

- The categories are the family's four: `necessary`, `preferences`, `analytics` and `marketing`.
  `necessary` is always granted, so listing a provider under it holds nothing back. A site using
  Toss with categories of its own can name those too.
- `*` stands for **every recognised provider** at once. A provider listed by name overrides it, so
  `['*' => 'marketing', 'googlemaps' => 'preferences']` holds everything for marketing except maps.
- `generic` (frames Bed does not recognise) and `media` (`<video>` and `<audio>`) are never covered by
  `*`, because an unrecognised frame or a video is as likely to be your own as anybody else's. Name
  them if you want them held.
- A plain list, `['youtube', 'vimeo']`, means `marketing` for each.

Nothing is listed by default, so installing or updating Bed changes nothing until you list something.

## What a held embed looks like

In place of the embed, inside the same reserved space, the visitor sees a short notice:

> This embed loads content from YouTube, which may set marketing cookies.
> **Load the embed** · Cookie settings · Open on youtube.com

- **Load the embed** loads that one embed now, without changing the visitor's cookie choices. A
  click on a named, described embed is the reader asking for it. Turn **Offer a "Load the embed"
  button** (`consentClickToLoad`) off if only the consent platform should be able to release
  anything. For a script embed — a tweet, an Instagram post — the button reads *Load X / Twitter
  posts*, because the platform's script renders every one of its posts on the page at once.
- **Cookie settings** appears when the consent platform can be reopened: Toss, Cookiebot and
  CookieYes.
- **Open on …** links to the content at its source. It works without JavaScript.

Change the wording with **Notice text** (`consentMessage`). `{provider}`, `{host}` and `{category}`
are filled in; the text is escaped, so it is plain text, not HTML.

Until consent, **nothing third-party is fetched**:

- A frame, a `<video>` or an `<audio>` waits in an inert `<template>`. A `hidden` iframe would
  already have made its request; a template's contents make none.
- A facade waits whole, poster included — the poster is an image on the provider's servers.
- A script embed's placeholder stays visible, because it is the platform's own fallback (the text of
  the post and a link) and loads nothing by itself. Its loader script is lifted out of the page and
  waits on the bed, even with **Defer embed scripts** off.
- No `preconnect` or `dns-prefetch` is sent to a held provider. A connection is exactly what is
  being held.

## It is safe behind a full-page cache

The page Bed renders is **the same for every visitor**: every listed embed is held, for everybody,
and the runtime releases it in the browser once *that* visitor's answer allows it. Bed never reads
consent on the server to decide what to render, so Blitz, a CDN or Craft's own template caches can
store the page and hand it to anyone.

## Where the answer comes from

### Toss

[Toss](https://justinholt.com/plugins/craft-toss) is the consent manager in this plugin family.
Install it and switch on its **Cookie consent** kit, and Bed takes the visitor's answer from Toss.
There is nothing to configure.

- The answer is read from `window.Toss.onConsent()`, or the `toss:consent` event if Bed's runtime
  happens to run first — Toss's published consent API. Toss's cookie is signed, so Bed never tries to
  read it.
- Embeds are released the moment the visitor accepts the category, and frames go back behind their
  notice if the visitor withdraws it on the same page.
- **Cookie settings** reopens Toss's panel.
- **Not decided yet is not no.** Toss answers `true`, `false`, or `null` for a visitor who has not
  decided. The embed waits in both of the last two cases, and the bed says which:
  `data-bed-consent="pending"` or `"denied"`.

Turn off **Use Toss for consent when it is on** (`deferToToss`) to keep Bed on a different source
while Toss's kit is on. Your other settings are kept, and take over again as soon as Toss is off.

### Without Toss

**Consent source** (`consentSource`) decides:

| `consentSource` | Reads |
| --- | --- |
| `click` | Nothing. Every held embed waits for a click on **Load the embed**, or for your own code to call `Bed.consent()`. The default. |
| `cookiebot` | `window.Cookiebot.consent` — `preferences`, `statistics` (analytics) and `marketing`. |
| `cookieyes` | The `cookieyes-consent` cookie — `functional` (preferences), `analytics` and `advertisement` (marketing). Defaults written before the visitor acts count as undecided. |
| `tape` | [Tape](https://justinholt.com/plugins/craft-tape)'s consent state — `functionality_storage`, `analytics_storage` and `ad_storage`. |
| `cookie` | A cookie of your own, named in **Consent cookie** (`consentCookieName`). A category is granted when the cookie's value contains **Granted when the cookie contains** (`consentCookieMatch`, default `{category}`), with `{category}` replaced. No cookie means undecided. |

### Your own banner

Whatever the source, your own code can answer:

```js
Bed.consent('marketing', true);                            // one category
Bed.consent({ marketing: true, preferences: false });      // several
Bed.consent('marketing', null);                            // hand it back to the configured source
```

An answer given this way applies to the current page view. Your banner is what remembers it.

## Embeds that already ask

An embed rendered by [Eye](https://justinholt.com/plugins/craft-eye) sits in Eye's own figure, which
carries its own click-to-load consent. Bed still reserves its space but never holds it behind a
second notice. To mark any other container the same way, give it a `data-bed-consent-managed`
attribute.

## Without the runtime

The notice is markup, so a held embed stays held even if Bed's runtime is switched off
(**Load the runtime**, `registerJs`) — consent fails closed. There is then no load button and no
settings button, but the link to the source still works, and a held provider's loader script is
still removed.

## Limits

- A script embed's loader script has to be in the same HTML Bed rewrites as the post: the whole page
  with automatic optimisation on (the default), or the same field with `|bed`. A loader hard-coded in
  your layout while the posts come through `|bed` with automatic optimisation off is outside what
  Bed sees.
- A script embed that has already run cannot be unloaded. If the visitor withdraws consent, its
  posts stay until the next page load; frames go back behind their notice straight away.
