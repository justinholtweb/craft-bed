<?php

namespace justinholtweb\bed\services;

use craft\base\Component;
use justinholtweb\bed\models\Provider;
use justinholtweb\bed\Plugin;

/**
 * Resource hints for the providers on a page.
 *
 * A `preconnect` is not free. It is a DNS lookup, a TCP connection and a TLS handshake opened
 * speculatively, held open for ten seconds, and wasted entirely if the page never uses it — and
 * every one of them competes with the connections the page does need. So this is a budget: the
 * providers Bed expects to load immediately get a real connection opened, everything else gets a
 * DNS lookup, and the whole thing is capped.
 */
class Hints extends Component
{
    /**
     * @param Provider[] $eager Providers with an embed that is going to load right away.
     * @param Provider[] $lazy Providers whose embeds are all waiting for something.
     * @return array<int, array{rel: string, href: string}>
     */
    public function links(array $eager, array $lazy): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $max = $settings->maxHints;

        if (!$settings->resourceHints || $max < 1) {
            return [];
        }

        $links = [];
        $seen = [];

        foreach ($eager as $provider) {
            foreach ($provider->preconnect as $origin) {
                if (count($links) >= $max) {
                    break 2;
                }

                if (isset($seen[$origin])) {
                    continue;
                }

                $seen[$origin] = true;
                $links[] = ['rel' => 'preconnect', 'href' => $origin];
            }
        }

        // DNS is cheap — a lookup and nothing held open — so it gets a looser budget than the
        // connections do. An origin already being connected to is skipped: resolving a name you
        // are mid-handshake with is a line of markup that does nothing.
        $dnsBudget = $max * 2;
        $dns = 0;

        foreach ([...$eager, ...$lazy] as $provider) {
            foreach ($provider->origins() as $origin) {
                if ($dns >= $dnsBudget) {
                    break 2;
                }

                if (isset($seen[$origin])) {
                    continue;
                }

                $seen[$origin] = true;
                $dns++;
                $links[] = ['rel' => 'dns-prefetch', 'href' => $origin];
            }
        }

        return $links;
    }

    /**
     * @param array<int, array{rel: string, href: string}> $links
     */
    public function render(array $links): string
    {
        $out = '';

        foreach ($links as $link) {
            $out .= '<link rel="' . $link['rel'] . '" href="' . htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') . '">';
        }

        return $out;
    }
}
