<?php

namespace justinholtweb\bed\models;

use craft\base\Model;

/**
 * One embed found in a document: what it is, where it is, and how big it wants to be.
 *
 * Offsets, not nodes. `start`/`end` bracket the whole thing so a bed can be inserted around it;
 * `tagStart`/`tagEnd` bracket its opening tag so its attributes can be rewritten. Both ranges
 * belong to the original string and stay valid because the optimiser splices back to front.
 */
class EmbedNode extends Model
{
    public string $kind = Provider::KIND_IFRAME;

    public Provider $provider;

    public string $tagName = 'iframe';

    public int $start = 0;

    public int $end = 0;

    public int $tagStart = 0;

    public int $tagEnd = 0;

    /** @var array<string, string> */
    public array $attrs = [];

    public string $src = '';

    public int $width = 0;

    public int $height = 0;

    /** @var int Zero-based index among the embeds on the page, in document order. */
    public int $position = 0;

    /** What is embedded — the same tweet on two pages has one embed key. */
    public string $embedKey = '';

    /** Where it is embedded — page, position and embed together. Assigned by the optimiser. */
    public string $slotKey = '';

    /** The intrinsic aspect ratio as `[width, height]`, from the element's own attributes. */
    public function intrinsicRatio(): ?array
    {
        if ($this->width > 0 && $this->height > 0) {
            return [$this->width, $this->height];
        }

        return null;
    }

    /**
     * The best ratio available without any measurement.
     *
     * The element's own attributes win over the provider's default, because an author who wrote
     * `width="560" height="315"` has told you something specific and the registry has only told
     * you something typical.
     */
    public function ratio(bool $useIntrinsic = true): ?array
    {
        if ($useIntrinsic && ($intrinsic = $this->intrinsicRatio()) !== null) {
            return $intrinsic;
        }

        return $this->provider->ratio;
    }

    public function isFrame(): bool
    {
        return $this->kind === Provider::KIND_IFRAME;
    }

    public function isScriptEmbed(): bool
    {
        return $this->kind === Provider::KIND_SCRIPT;
    }

    public function isMedia(): bool
    {
        return $this->kind === Provider::KIND_MEDIA;
    }
}
