<?php

namespace justinholtweb\bed\models;

use craft\base\Model;

/**
 * One entry in the provider registry.
 *
 * A provider is not a service Bed talks to — Bed never makes an outbound request. It is a
 * description of what that provider's embed looks like in markup, so Bed can recognise one, know
 * roughly how tall it wants to be, and know which origins it is about to open connections to.
 */
class Provider extends Model
{
    /** An `<iframe>` pointing at the provider. */
    public const KIND_IFRAME = 'iframe';

    /** A placeholder element plus a loader `<script>` that turns it into an embed. */
    public const KIND_SCRIPT = 'script';

    /** A `<video>` or `<audio>` element served from anywhere. */
    public const KIND_MEDIA = 'media';

    public string $handle = '';

    public string $name = '';

    public string $kind = self::KIND_IFRAME;

    /**
     * @var string[] Host suffixes that identify this provider's frame. A suffix so
     *               `www.youtube.com` and `youtube.com` are one entry, and matched on a boundary
     *               so `notyoutube.com` is not.
     */
    public array $hosts = [];

    /**
     * @var string[] Path prefixes the frame URL must also start with. Google is one host with a
     *               dozen unrelated products on it, so the host alone is not an identification.
     */
    public array $pathPrefixes = [];

    /**
     * @var string[] Classes on the placeholder element for a script-driven embed.
     */
    public array $markerClasses = [];

    /**
     * @var string[] Attributes whose presence marks the placeholder element instead of a class.
     *               Pinterest and Flickr both mark an ordinary `<a>` this way.
     */
    public array $markerAttrs = [];

    /**
     * @var string[] Host suffixes of the loader scripts belonging to this provider.
     */
    public array $scriptHosts = [];

    /**
     * @var int[]|null The provider's natural aspect ratio as `[width, height]`, or null where the
     *                 embed has no natural shape — a tweet is as tall as the tweet.
     */
    public ?array $ratio = null;

    /**
     * @var int A height to hold before anything has been measured, for embeds with no ratio.
     *          A rough, deliberately conservative number: too short shifts once, too tall leaves a
     *          gap on every view until the measurements arrive.
     */
    public int $fallbackHeight = 0;

    /**
     * @var string[] Origins worth a `preconnect` when this provider is going to load immediately.
     */
    public array $preconnect = [];

    /**
     * @var string[] Origins worth a `dns-prefetch` when this provider is somewhere further down.
     */
    public array $dnsPrefetch = [];

    /**
     * @var string|null A pattern that pulls the media id out of a frame URL, for building a
     *                  facade poster without asking the provider anything.
     */
    public ?string $idPattern = null;

    /**
     * @var string|null A poster URL template with `{id}` in it.
     */
    public ?string $posterTemplate = null;

    /**
     * @var string A query string merged into the frame URL when a facade is opened, so clicking
     *             play actually plays rather than loading a paused player.
     */
    public string $activateParams = '';

    /** Whether a facade can be built for this provider. */
    public function supportsFacade(): bool
    {
        return $this->kind === self::KIND_IFRAME && $this->idPattern !== null;
    }

    /** The poster URL for a frame src, or null if there is not one to derive. */
    public function poster(string $src): ?string
    {
        if ($this->posterTemplate === null || $this->idPattern === null) {
            return null;
        }

        if (!preg_match($this->idPattern, $src, $matches)) {
            return null;
        }

        $id = $matches['id'] ?? ($matches[1] ?? null);

        if (!is_string($id) || $id === '') {
            return null;
        }

        return str_replace('{id}', rawurlencode($id), $this->posterTemplate);
    }

    /** Every origin this provider might want warmed, in priority order. */
    public function origins(): array
    {
        return array_values(array_unique(array_merge($this->preconnect, $this->dnsPrefetch)));
    }
}
