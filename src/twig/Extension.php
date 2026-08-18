<?php

namespace justinholtweb\bed\twig;

use Craft;
use justinholtweb\bed\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * `{{ entry.body|bed }}` and `{{ bed(html) }}`.
 *
 * Both are the same call. The filter reads better on a field, the function reads better on
 * something that was just built, and neither is needed at all on a site that leaves the response
 * filter switched on.
 */
class Extension extends AbstractExtension
{
    public function getName(): string
    {
        return 'bed';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('bed', [$this, 'optimize'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bed', [$this, 'optimize'], ['is_safe' => ['html']]),
        ];
    }

    public function optimize(string|Markup|null $html, array $config = []): Markup
    {
        return new Markup(Plugin::getInstance()->render((string)($html ?? ''), $config), Craft::$app->charset);
    }
}
