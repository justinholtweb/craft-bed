<?php

namespace justinholtweb\bed\models;

use craft\base\Model;

/**
 * What one pass of the scanner found.
 */
class ScanResult extends Model
{
    /** @var EmbedNode[] In document order. */
    public array $nodes = [];

    /**
     * @var array<int, array{start: int, end: int, src: string, provider: string}> Loader scripts
     *      that belong to a provider with at least one placeholder on this page, and that sit
     *      outside every embed's range so lifting one cannot collide with wrapping one.
     */
    public array $scripts = [];

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /** @return string[] Provider handles present, deduped, in first-appearance order. */
    public function providerHandles(): array
    {
        $handles = [];

        foreach ($this->nodes as $node) {
            $handles[$node->provider->handle] = true;
        }

        return array_keys($handles);
    }
}
