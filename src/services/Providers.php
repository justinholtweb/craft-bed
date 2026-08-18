<?php

namespace justinholtweb\bed\services;

use craft\base\Component;
use justinholtweb\bed\models\Provider;

/**
 * The provider registry.
 *
 * Bed never talks to any of these — there is no outbound HTTP anywhere in this plugin. The
 * registry is a description of what each provider's embed looks like once it is already in the
 * page: how to recognise one, what shape it wants to be, and which origins it is about to open
 * connections to.
 *
 * Anything unrecognised still gets the treatment that needs no knowledge — a bed built from its
 * own `width` and `height`, a lazy attribute, a title. Recognition buys the ratio, the resource
 * hints and the facade.
 */
class Providers extends Component
{
    /** The handle used for an iframe nobody recognised. */
    public const GENERIC = 'generic';

    /** The handle used for a `<video>` or `<audio>` element. */
    public const MEDIA = 'media';

    /** @var Provider[]|null */
    private ?array $providers = null;

    /** @var array{classes: array<string, string>, attrs: array<string, string>}|null */
    private ?array $markerIndex = null;

    /** @return Provider[] Keyed by handle. */
    public function all(): array
    {
        if ($this->providers === null) {
            $this->providers = [];

            foreach ($this->definitions() as $definition) {
                $provider = new Provider($definition);
                $this->providers[$provider->handle] = $provider;
            }
        }

        return $this->providers;
    }

    public function byHandle(string $handle): ?Provider
    {
        return $this->all()[$handle] ?? null;
    }

    /** @return Provider[] Only the ones a facade can be built for. */
    public function facadeCapable(): array
    {
        return array_filter($this->all(), fn(Provider $p) => $p->supportsFacade());
    }

    /**
     * The provider behind a frame URL.
     *
     * Host matching is on a label boundary, not a substring: `youtube.com` matches
     * `www.youtube.com` and `youtube.com` and never `notyoutube.com`, which is a real domain
     * somebody could register precisely because plugins do this wrong.
     */
    public function matchFrame(string $src): ?Provider
    {
        $host = $this->host($src);

        if ($host === null) {
            return null;
        }

        $path = (string)(parse_url($src, PHP_URL_PATH) ?: '/');

        foreach ($this->all() as $provider) {
            if ($provider->kind === Provider::KIND_SCRIPT || $provider->hosts === []) {
                continue;
            }

            if (!$this->hostMatches($host, $provider->hosts)) {
                continue;
            }

            if ($provider->pathPrefixes !== [] && !$this->pathMatches($path, $provider->pathPrefixes)) {
                continue;
            }

            return $provider;
        }

        return null;
    }

    /**
     * The provider behind a placeholder element, from its classes and its attribute names.
     *
     * @param string[] $classes
     * @param string[] $attrs Attribute names, lower-cased.
     */
    public function matchMarker(array $classes, array $attrs): ?Provider
    {
        if ($classes === [] && $attrs === []) {
            return null;
        }

        $index = $this->markerIndex();

        foreach ($classes as $class) {
            $handle = $index['classes'][strtolower($class)] ?? null;

            if ($handle !== null) {
                return $this->byHandle($handle);
            }
        }

        foreach ($attrs as $attr) {
            $handle = $index['attrs'][strtolower($attr)] ?? null;

            if ($handle !== null) {
                return $this->byHandle($handle);
            }
        }

        return null;
    }

    /**
     * Marker classes and attributes flattened into two lookup tables.
     *
     * The scanner asks this question of every `<div>`, `<p>` and `<a>` in the document, so it has
     * to be a hash lookup per class name and not a walk over sixty providers per element.
     *
     * @return array{classes: array<string, string>, attrs: array<string, string>}
     */
    private function markerIndex(): array
    {
        if ($this->markerIndex === null) {
            $this->markerIndex = ['classes' => [], 'attrs' => []];

            foreach ($this->all() as $provider) {
                if ($provider->kind !== Provider::KIND_SCRIPT) {
                    continue;
                }

                foreach ($provider->markerClasses as $class) {
                    $this->markerIndex['classes'][strtolower($class)] ??= $provider->handle;
                }

                foreach ($provider->markerAttrs as $attr) {
                    $this->markerIndex['attrs'][strtolower($attr)] ??= $provider->handle;
                }
            }
        }

        return $this->markerIndex;
    }

    /** The provider a loader `<script src>` belongs to. */
    public function matchScript(string $src): ?Provider
    {
        $host = $this->host($src);

        if ($host === null) {
            return null;
        }

        foreach ($this->all() as $provider) {
            if ($provider->scriptHosts !== [] && $this->hostMatches($host, $provider->scriptHosts)) {
                return $provider;
            }
        }

        return null;
    }

    /** A stand-in for anything Bed did not recognise, so callers never have to null-check. */
    public function generic(string $kind = Provider::KIND_IFRAME): Provider
    {
        return new Provider([
            'handle' => $kind === Provider::KIND_MEDIA ? self::MEDIA : self::GENERIC,
            'name' => $kind === Provider::KIND_MEDIA ? 'Media' : 'Embed',
            'kind' => $kind,
        ]);
    }

    // ------------------------------------------------------------------ matching internals

    private function host(string $url): ?string
    {
        // A protocol-relative `//player.vimeo.com/…` is still a frame pointed at Vimeo, and it is
        // what half the embed codes on the internet actually paste.
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** @param string[] $suffixes */
    private function hostMatches(string $host, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            $suffix = strtolower(ltrim($suffix, '.'));

            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @param string[] $prefixes */
    private function pathMatches(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ the registry

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        $i = Provider::KIND_IFRAME;
        $s = Provider::KIND_SCRIPT;

        return [
            // -------------------------------------------------------------- video
            [
                'handle' => 'youtube', 'name' => 'YouTube', 'kind' => $i,
                'hosts' => ['youtube.com', 'youtube-nocookie.com', 'youtu.be'],
                'ratio' => [16, 9],
                'preconnect' => ['https://www.youtube.com', 'https://i.ytimg.com'],
                'dnsPrefetch' => ['https://www.google.com', 'https://googleads.g.doubleclick.net', 'https://static.doubleclick.net'],
                'idPattern' => '#/embed/(?<id>[A-Za-z0-9_-]{6,})#',
                'posterTemplate' => 'https://i.ytimg.com/vi/{id}/hqdefault.jpg',
                'activateParams' => 'autoplay=1',
            ],
            [
                'handle' => 'vimeo', 'name' => 'Vimeo', 'kind' => $i,
                'hosts' => ['vimeo.com'],
                'ratio' => [16, 9],
                'preconnect' => ['https://player.vimeo.com'],
                'dnsPrefetch' => ['https://i.vimeocdn.com', 'https://f.vimeocdn.com'],
                'activateParams' => 'autoplay=1',
            ],
            ['handle' => 'loom', 'name' => 'Loom', 'kind' => $i, 'hosts' => ['loom.com'], 'ratio' => [16, 9], 'preconnect' => ['https://www.loom.com'], 'dnsPrefetch' => ['https://cdn.loom.com']],
            ['handle' => 'wistia', 'name' => 'Wistia', 'kind' => $i, 'hosts' => ['wistia.net', 'wistia.com'], 'ratio' => [16, 9], 'preconnect' => ['https://fast.wistia.net'], 'dnsPrefetch' => ['https://embed-ssl.wistia.com', 'https://embedwistia-a.akamaihd.net']],
            ['handle' => 'dailymotion', 'name' => 'Dailymotion', 'kind' => $i, 'hosts' => ['dailymotion.com', 'dai.ly'], 'ratio' => [16, 9], 'preconnect' => ['https://www.dailymotion.com'], 'dnsPrefetch' => ['https://static1.dmcdn.net']],
            ['handle' => 'twitch', 'name' => 'Twitch', 'kind' => $i, 'hosts' => ['twitch.tv'], 'ratio' => [16, 9], 'preconnect' => ['https://player.twitch.tv'], 'dnsPrefetch' => ['https://static.twitchcdn.net']],
            ['handle' => 'streamable', 'name' => 'Streamable', 'kind' => $i, 'hosts' => ['streamable.com'], 'ratio' => [16, 9], 'preconnect' => ['https://streamable.com']],
            ['handle' => 'vidyard', 'name' => 'Vidyard', 'kind' => $i, 'hosts' => ['vidyard.com'], 'ratio' => [16, 9], 'preconnect' => ['https://play.vidyard.com']],
            ['handle' => 'brightcove', 'name' => 'Brightcove', 'kind' => $i, 'hosts' => ['brightcove.net', 'brightcove.com'], 'ratio' => [16, 9], 'preconnect' => ['https://players.brightcove.net'], 'dnsPrefetch' => ['https://edge.api.brightcove.com']],
            ['handle' => 'kaltura', 'name' => 'Kaltura', 'kind' => $i, 'hosts' => ['kaltura.com'], 'ratio' => [16, 9], 'preconnect' => ['https://cdnapisec.kaltura.com']],
            ['handle' => 'jwplayer', 'name' => 'JW Player', 'kind' => $i, 'hosts' => ['jwplayer.com', 'jwpcdn.com'], 'ratio' => [16, 9], 'preconnect' => ['https://cdn.jwplayer.com']],
            ['handle' => 'ted', 'name' => 'TED', 'kind' => $i, 'hosts' => ['ted.com'], 'ratio' => [16, 9], 'preconnect' => ['https://embed.ted.com']],
            ['handle' => 'archiveorg', 'name' => 'Internet Archive', 'kind' => $i, 'hosts' => ['archive.org'], 'ratio' => [16, 9], 'preconnect' => ['https://archive.org']],
            ['handle' => 'descript', 'name' => 'Descript', 'kind' => $i, 'hosts' => ['descript.com'], 'ratio' => [16, 9], 'preconnect' => ['https://share.descript.com']],

            // -------------------------------------------------------------- audio
            ['handle' => 'spotify', 'name' => 'Spotify', 'kind' => $i, 'hosts' => ['spotify.com'], 'fallbackHeight' => 352, 'preconnect' => ['https://open.spotify.com'], 'dnsPrefetch' => ['https://i.scdn.co']],
            ['handle' => 'soundcloud', 'name' => 'SoundCloud', 'kind' => $i, 'hosts' => ['soundcloud.com'], 'fallbackHeight' => 166, 'preconnect' => ['https://w.soundcloud.com'], 'dnsPrefetch' => ['https://i1.sndcdn.com']],
            ['handle' => 'applemusic', 'name' => 'Apple Music', 'kind' => $i, 'hosts' => ['music.apple.com'], 'fallbackHeight' => 450, 'preconnect' => ['https://embed.music.apple.com'], 'dnsPrefetch' => ['https://is1-ssl.mzstatic.com']],
            ['handle' => 'applepodcasts', 'name' => 'Apple Podcasts', 'kind' => $i, 'hosts' => ['podcasts.apple.com'], 'fallbackHeight' => 175, 'preconnect' => ['https://embed.podcasts.apple.com']],
            ['handle' => 'bandcamp', 'name' => 'Bandcamp', 'kind' => $i, 'hosts' => ['bandcamp.com'], 'fallbackHeight' => 340, 'preconnect' => ['https://bandcamp.com'], 'dnsPrefetch' => ['https://f4.bcbits.com']],
            ['handle' => 'mixcloud', 'name' => 'Mixcloud', 'kind' => $i, 'hosts' => ['mixcloud.com'], 'fallbackHeight' => 180, 'preconnect' => ['https://www.mixcloud.com']],
            ['handle' => 'podbean', 'name' => 'Podbean', 'kind' => $i, 'hosts' => ['podbean.com'], 'fallbackHeight' => 150, 'preconnect' => ['https://www.podbean.com']],
            ['handle' => 'buzzsprout', 'name' => 'Buzzsprout', 'kind' => $i, 'hosts' => ['buzzsprout.com'], 'fallbackHeight' => 200, 'preconnect' => ['https://www.buzzsprout.com']],
            ['handle' => 'transistor', 'name' => 'Transistor', 'kind' => $i, 'hosts' => ['transistor.fm'], 'fallbackHeight' => 180, 'preconnect' => ['https://share.transistor.fm']],

            // -------------------------------------------------------------- google
            [
                'handle' => 'googlemaps', 'name' => 'Google Maps', 'kind' => $i,
                'hosts' => ['google.com', 'google.co.uk', 'maps.google.com'],
                'pathPrefixes' => ['/maps'],
                'fallbackHeight' => 450,
                'preconnect' => ['https://www.google.com'], 'dnsPrefetch' => ['https://maps.gstatic.com', 'https://khms0.googleapis.com'],
            ],
            ['handle' => 'googledocs', 'name' => 'Google Docs', 'kind' => $i, 'hosts' => ['docs.google.com'], 'fallbackHeight' => 600, 'preconnect' => ['https://docs.google.com'], 'dnsPrefetch' => ['https://lh3.googleusercontent.com']],
            ['handle' => 'googlecalendar', 'name' => 'Google Calendar', 'kind' => $i, 'hosts' => ['calendar.google.com'], 'fallbackHeight' => 600, 'preconnect' => ['https://calendar.google.com']],
            ['handle' => 'googledrive', 'name' => 'Google Drive', 'kind' => $i, 'hosts' => ['drive.google.com'], 'fallbackHeight' => 480, 'preconnect' => ['https://drive.google.com']],
            ['handle' => 'youtubemusic', 'name' => 'YouTube Music', 'kind' => $i, 'hosts' => ['music.youtube.com'], 'ratio' => [16, 9], 'preconnect' => ['https://music.youtube.com']],

            // -------------------------------------------------------------- forms & scheduling
            ['handle' => 'calendly', 'name' => 'Calendly', 'kind' => $i, 'hosts' => ['calendly.com'], 'fallbackHeight' => 700, 'preconnect' => ['https://calendly.com'], 'dnsPrefetch' => ['https://assets.calendly.com']],
            ['handle' => 'typeform', 'name' => 'Typeform', 'kind' => $i, 'hosts' => ['typeform.com'], 'fallbackHeight' => 500, 'preconnect' => ['https://form.typeform.com']],
            ['handle' => 'tally', 'name' => 'Tally', 'kind' => $i, 'hosts' => ['tally.so'], 'fallbackHeight' => 500, 'preconnect' => ['https://tally.so']],
            ['handle' => 'jotform', 'name' => 'Jotform', 'kind' => $i, 'hosts' => ['jotform.com'], 'fallbackHeight' => 600, 'preconnect' => ['https://form.jotform.com']],
            ['handle' => 'airtable', 'name' => 'Airtable', 'kind' => $i, 'hosts' => ['airtable.com'], 'fallbackHeight' => 533, 'preconnect' => ['https://airtable.com']],

            // -------------------------------------------------------------- design & code
            ['handle' => 'figma', 'name' => 'Figma', 'kind' => $i, 'hosts' => ['figma.com'], 'ratio' => [16, 9], 'preconnect' => ['https://www.figma.com'], 'dnsPrefetch' => ['https://s3-alpha.figma.com']],
            ['handle' => 'canva', 'name' => 'Canva', 'kind' => $i, 'hosts' => ['canva.com'], 'ratio' => [16, 9], 'preconnect' => ['https://www.canva.com']],
            ['handle' => 'miro', 'name' => 'Miro', 'kind' => $i, 'hosts' => ['miro.com'], 'ratio' => [16, 9], 'preconnect' => ['https://miro.com']],
            ['handle' => 'codepen', 'name' => 'CodePen', 'kind' => $i, 'hosts' => ['codepen.io'], 'fallbackHeight' => 400, 'preconnect' => ['https://codepen.io'], 'dnsPrefetch' => ['https://cpwebassets.codepen.io']],
            ['handle' => 'jsfiddle', 'name' => 'JSFiddle', 'kind' => $i, 'hosts' => ['jsfiddle.net'], 'fallbackHeight' => 400, 'preconnect' => ['https://jsfiddle.net']],
            ['handle' => 'codesandbox', 'name' => 'CodeSandbox', 'kind' => $i, 'hosts' => ['codesandbox.io'], 'fallbackHeight' => 500, 'preconnect' => ['https://codesandbox.io']],
            ['handle' => 'stackblitz', 'name' => 'StackBlitz', 'kind' => $i, 'hosts' => ['stackblitz.com'], 'fallbackHeight' => 500, 'preconnect' => ['https://stackblitz.com']],
            ['handle' => 'replit', 'name' => 'Replit', 'kind' => $i, 'hosts' => ['replit.com', 'repl.it'], 'fallbackHeight' => 500, 'preconnect' => ['https://replit.com']],
            ['handle' => 'sketchfab', 'name' => 'Sketchfab', 'kind' => $i, 'hosts' => ['sketchfab.com'], 'ratio' => [16, 9], 'preconnect' => ['https://sketchfab.com']],
            ['handle' => 'matterport', 'name' => 'Matterport', 'kind' => $i, 'hosts' => ['matterport.com'], 'ratio' => [16, 9], 'preconnect' => ['https://my.matterport.com']],

            // -------------------------------------------------------------- data & documents
            ['handle' => 'datawrapper', 'name' => 'Datawrapper', 'kind' => $i, 'hosts' => ['datawrapper.dwcdn.net', 'datawrapper.de', 'dwcdn.net'], 'fallbackHeight' => 400, 'preconnect' => ['https://datawrapper.dwcdn.net']],
            ['handle' => 'flourish', 'name' => 'Flourish', 'kind' => $i, 'hosts' => ['flourish.studio', 'flo.uri.sh'], 'fallbackHeight' => 500, 'preconnect' => ['https://public.flourish.studio']],
            ['handle' => 'tableau', 'name' => 'Tableau', 'kind' => $i, 'hosts' => ['tableau.com'], 'fallbackHeight' => 600, 'preconnect' => ['https://public.tableau.com']],
            ['handle' => 'slideshare', 'name' => 'SlideShare', 'kind' => $i, 'hosts' => ['slideshare.net'], 'ratio' => [16, 9], 'preconnect' => ['https://www.slideshare.net']],
            ['handle' => 'scribd', 'name' => 'Scribd', 'kind' => $i, 'hosts' => ['scribd.com'], 'fallbackHeight' => 600, 'preconnect' => ['https://www.scribd.com']],
            ['handle' => 'issuu', 'name' => 'Issuu', 'kind' => $i, 'hosts' => ['issuu.com'], 'ratio' => [16, 9], 'preconnect' => ['https://e.issuu.com']],
            ['handle' => 'openstreetmap', 'name' => 'OpenStreetMap', 'kind' => $i, 'hosts' => ['openstreetmap.org'], 'fallbackHeight' => 400, 'preconnect' => ['https://www.openstreetmap.org'], 'dnsPrefetch' => ['https://tile.openstreetmap.org']],
            ['handle' => 'mapbox', 'name' => 'Mapbox', 'kind' => $i, 'hosts' => ['mapbox.com'], 'fallbackHeight' => 400, 'preconnect' => ['https://api.mapbox.com']],

            // -------------------------------------------------------------- social, framed
            ['handle' => 'tiktokframe', 'name' => 'TikTok', 'kind' => $i, 'hosts' => ['tiktok.com'], 'fallbackHeight' => 750, 'preconnect' => ['https://www.tiktok.com'], 'dnsPrefetch' => ['https://p16-sign-va.tiktokcdn.com']],
            ['handle' => 'instagramframe', 'name' => 'Instagram', 'kind' => $i, 'hosts' => ['instagram.com'], 'fallbackHeight' => 700, 'preconnect' => ['https://www.instagram.com'], 'dnsPrefetch' => ['https://scontent.cdninstagram.com']],
            ['handle' => 'facebookframe', 'name' => 'Facebook', 'kind' => $i, 'hosts' => ['facebook.com'], 'pathPrefixes' => ['/plugins'], 'fallbackHeight' => 500, 'preconnect' => ['https://www.facebook.com'], 'dnsPrefetch' => ['https://static.xx.fbcdn.net']],
            ['handle' => 'redditframe', 'name' => 'Reddit', 'kind' => $i, 'hosts' => ['redditmedia.com'], 'fallbackHeight' => 400, 'preconnect' => ['https://www.redditmedia.com']],
            ['handle' => 'blueskyframe', 'name' => 'Bluesky', 'kind' => $i, 'hosts' => ['bsky.app'], 'fallbackHeight' => 400, 'preconnect' => ['https://embed.bsky.app']],
            ['handle' => 'substack', 'name' => 'Substack', 'kind' => $i, 'hosts' => ['substack.com'], 'fallbackHeight' => 320, 'preconnect' => ['https://substack.com']],
            ['handle' => 'beehiiv', 'name' => 'beehiiv', 'kind' => $i, 'hosts' => ['beehiiv.com'], 'fallbackHeight' => 320, 'preconnect' => ['https://embeds.beehiiv.com']],
            ['handle' => 'giphy', 'name' => 'Giphy', 'kind' => $i, 'hosts' => ['giphy.com'], 'preconnect' => ['https://giphy.com'], 'dnsPrefetch' => ['https://media.giphy.com']],

            // -------------------------------------------------------------- social, script-driven
            [
                'handle' => 'twitter', 'name' => 'X / Twitter', 'kind' => $s,
                'markerClasses' => ['twitter-tweet', 'twitter-timeline', 'twitter-follow-button'],
                'scriptHosts' => ['platform.twitter.com', 'platform.x.com'],
                'fallbackHeight' => 550,
                'preconnect' => ['https://platform.twitter.com'],
                'dnsPrefetch' => ['https://syndication.twitter.com', 'https://pbs.twimg.com', 'https://cdn.syndication.twimg.com'],
            ],
            [
                'handle' => 'instagram', 'name' => 'Instagram', 'kind' => $s,
                'markerClasses' => ['instagram-media'],
                'scriptHosts' => ['instagram.com'],
                'fallbackHeight' => 700,
                'preconnect' => ['https://www.instagram.com'],
                'dnsPrefetch' => ['https://scontent.cdninstagram.com'],
            ],
            [
                'handle' => 'tiktok', 'name' => 'TikTok', 'kind' => $s,
                'markerClasses' => ['tiktok-embed'],
                'scriptHosts' => ['tiktok.com'],
                'fallbackHeight' => 750,
                'preconnect' => ['https://www.tiktok.com'],
                'dnsPrefetch' => ['https://p16-sign-va.tiktokcdn.com'],
            ],
            [
                'handle' => 'reddit', 'name' => 'Reddit', 'kind' => $s,
                'markerClasses' => ['reddit-embed-bq', 'reddit-card', 'reddit-embed'],
                'scriptHosts' => ['embed.reddit.com', 'embed.redditmedia.com'],
                'fallbackHeight' => 400,
                'preconnect' => ['https://embed.reddit.com'],
            ],
            [
                'handle' => 'bluesky', 'name' => 'Bluesky', 'kind' => $s,
                'markerClasses' => ['bluesky-embed'],
                'scriptHosts' => ['embed.bsky.app'],
                'fallbackHeight' => 400,
                'preconnect' => ['https://embed.bsky.app'],
            ],
            [
                'handle' => 'mastodon', 'name' => 'Mastodon', 'kind' => $s,
                'markerClasses' => ['mastodon-embed'],
                'fallbackHeight' => 400,
            ],
            [
                'handle' => 'threads', 'name' => 'Threads', 'kind' => $s,
                'markerClasses' => ['text-post-media'],
                'scriptHosts' => ['threads.net', 'threads.com'],
                'fallbackHeight' => 500,
                'preconnect' => ['https://www.threads.net'],
            ],
            [
                'handle' => 'facebook', 'name' => 'Facebook', 'kind' => $s,
                'markerClasses' => ['fb-post', 'fb-video', 'fb-page', 'fb-comments'],
                'scriptHosts' => ['connect.facebook.net'],
                'fallbackHeight' => 500,
                'preconnect' => ['https://connect.facebook.net'],
                'dnsPrefetch' => ['https://static.xx.fbcdn.net'],
            ],
            [
                'handle' => 'pinterest', 'name' => 'Pinterest', 'kind' => $s,
                'markerAttrs' => ['data-pin-do'],
                'scriptHosts' => ['assets.pinterest.com'],
                'fallbackHeight' => 500,
                'preconnect' => ['https://assets.pinterest.com'],
                'dnsPrefetch' => ['https://i.pinimg.com'],
            ],
            [
                'handle' => 'flickr', 'name' => 'Flickr', 'kind' => $s,
                'markerAttrs' => ['data-flickr-embed'],
                'scriptHosts' => ['embedr.flickr.com'],
                'fallbackHeight' => 400,
                'preconnect' => ['https://embedr.flickr.com'],
            ],
            [
                'handle' => 'imgur', 'name' => 'Imgur', 'kind' => $s,
                'markerClasses' => ['imgur-embed-pub'],
                'scriptHosts' => ['s.imgur.com'],
                'fallbackHeight' => 500,
                'preconnect' => ['https://s.imgur.com'],
                'dnsPrefetch' => ['https://i.imgur.com'],
            ],
            [
                'handle' => 'giscus', 'name' => 'Giscus', 'kind' => $s,
                'markerClasses' => ['giscus'],
                'scriptHosts' => ['giscus.app'],
                'fallbackHeight' => 400,
                'preconnect' => ['https://giscus.app'],
            ],
            [
                'handle' => 'codepenscript', 'name' => 'CodePen', 'kind' => $s,
                'markerClasses' => ['codepen'],
                'scriptHosts' => ['cpwebassets.codepen.io', 'static.codepen.io'],
                'fallbackHeight' => 400,
                'preconnect' => ['https://cpwebassets.codepen.io'],
            ],
        ];
    }
}
