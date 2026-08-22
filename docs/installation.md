---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and what starts happening to your pages immediately.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

That is the whole list. Bed has no runtime dependencies beyond Craft's own, no build step, and
**makes no outbound HTTP request anywhere** — not at install, not on a render, not ever. The
provider registry is a description of what embeds look like, not a client for talking to anybody.

Bed is free. There are no editions, no licence key and no licensing code in the plugin.

## Install

```sh
composer require justinholtweb/craft-bed
php craft plugin/install bed
```

Or find **Bed** in the Plugin Store and install it there.

Installing creates two tables — `bed_slots` and `bed_metrics` — and nothing else. No project config
is written until you change a setting.

## What happens straight away

Nothing you have to do. From the next front-end page view:

- Every embed is wrapped in a box that holds its space. Embeds whose shape is knowable — a YouTube
  frame, or anything carrying `width` and `height` — get an `aspect-ratio` immediately and need no
  measurement at all.
- Frames below the first one get `loading="lazy"`; video and audio get `preload="none"`.
- Script-driven embeds — a tweet, a TikTok, an Instagram post — have their loader `<script>` lifted
  out of the document and injected by an IntersectionObserver as the embed nears the viewport.
- `preconnect` and `dns-prefetch` links are added for the providers on the page, capped at six.
- YouTube embeds get a poster-and-play-button facade, swapped for the real player on the click.
- Frames with no `title` get one, and frames with no referrer policy get
  `strict-origin-when-cross-origin`.

A page with no embeds on it is rejected by a single pattern match before any of that runs.

## Then it gets better on its own

The heights Bed cannot know in advance — a tweet is as tall as the tweet — are **measured, not
guessed**. That takes a little while to warm up:

1. A page renders. Each embed gets a slot key, and the page carries a token, signed with Craft's
   own security key, listing exactly which slots are on it.
2. One page view in ten measures what the embeds settled to and beacons it back.
3. Once a slot has twenty samples at a viewport bucket, Bed emits a `min-height` for that width and
   stops asking for more.

So the reserved heights appear over the first few hundred views of a page, not on the first one.
Nothing is broken in the meantime — those embeds simply have no reserved height yet, which is
exactly where they started.

Watch it happen:

```sh
php craft bed/metrics/report
```

## Verifying it works

View source on a page with an embed on it. You are looking for a wrapper like this:

```html
<div class="bed bed--vimeo bed--iframe bed--ratio" data-bed="e87d290a…" style="--bed-ar:640/360">
  <iframe src="https://player.vimeo.com/video/76979871" width="640" height="360"
          loading="lazy" title="Vimeo embed" referrerpolicy="strict-origin-when-cross-origin"></iframe>
</div>
```

If you see the original `<iframe>` unwrapped, check in this order:

- **Enabled** and **Optimise front-end pages automatically** are both on in the settings.
- The page's URI is not matched by **Never optimise these URIs**.
- Neither the embed nor anything containing it carries `data-bed-skip`.
- The response is an HTML front-end response with a status below 400 — Bed leaves control panel
  requests, JSON and error pages alone.

## Configuring it in a file

Like any Craft plugin, Bed reads `config/bed.php`, and anything set there overrides the control
panel and cannot be changed from it:

```php
<?php

return [
    'sampleRate' => 1.0,          // measure every view in dev, one in ten in production
    'facadeProviders' => ['youtube'],
    'excludeUris' => ['checkout/*'],
];
```

Multi-environment arrays work as they do everywhere else in Craft.

## Next

- [Configuration](https://justinholt.com/plugins/craft-bed/docs/configuration) — every setting, and what it defaults to
- [Usage](https://justinholt.com/plugins/craft-bed/docs/usage) — Twig, opting out, styling, the console
