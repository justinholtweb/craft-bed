<?php

namespace justinholtweb\bed\twig;

use Craft;
use justinholtweb\bed\models\Provider;
use justinholtweb\bed\Plugin;
use Twig\Markup;

/**
 * `craft.bed` — for sites that would rather opt in field by field than have the whole response
 * filtered, and for anyone who wants to see what Bed knows.
 */
class BedVariable
{
    /**
     * Optimises a blob of HTML and returns it ready to print.
     *
     * The same code path the response filter uses, in fragment mode: the wrappers and attributes
     * come back in the returned markup, and the stylesheet, resource hints and collector go
     * through the view so they land in the head and the foot where they belong.
     */
    public function optimize(string|Markup|null $html, array $config = []): Markup
    {
        $html = (string)($html ?? '');

        return new Markup(Plugin::getInstance()->render($html, $config), Craft::$app->charset);
    }

    /** @return Provider[] Everything Bed knows how to recognise. */
    public function providers(): array
    {
        return Plugin::getInstance()->providers->all();
    }

    /** @return Provider[] The ones a facade can be built for without asking anybody anything. */
    public function facadeProviders(): array
    {
        return Plugin::getInstance()->providers->facadeCapable();
    }

    /**
     * @return array{slots: int, measured: int, samples: int, providers: array<string, int>, full: bool}
     */
    public function stats(): array
    {
        return Plugin::getInstance()->ledger->stats();
    }

    /**
     * What Bed has measured, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function slots(int $limit = 50, ?int $siteId = null): array
    {
        $query = Plugin::getInstance()->ledger->query()
            ->orderBy(['slots.lastSeen' => SORT_DESC])
            ->limit(max(1, min($limit, 500)));

        if ($siteId !== null) {
            $query->where(['slots.siteId' => $siteId]);
        }

        return $query->all();
    }
}
