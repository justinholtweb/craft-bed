<?php

namespace justinholtweb\bed\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bed\models\Provider;
use justinholtweb\bed\Plugin;
use yii\console\ExitCode;

/**
 * `craft bed/providers/…` — what Bed knows how to recognise.
 */
class ProvidersController extends Controller
{
    /** @var string|null Only providers of one kind: iframe, script or media. */
    public ?string $kind = null;

    public function options($actionID): array
    {
        return $actionID === 'list' ? [...parent::options($actionID), 'kind'] : parent::options($actionID);
    }

    /**
     * Lists the provider registry.
     */
    public function actionList(): int
    {
        $providers = Plugin::getInstance()->providers->all();

        $this->stdout(sprintf("\n  %-18s %-22s %-8s %-10s %s\n", 'HANDLE', 'NAME', 'KIND', 'SHAPE', 'FACADE'), Console::BOLD);
        $this->stdout('  ' . str_repeat('─', 74) . "\n");

        $shown = 0;

        foreach ($providers as $provider) {
            if ($this->kind !== null && $provider->kind !== $this->kind) {
                continue;
            }

            $shape = $provider->ratio !== null
                ? $provider->ratio[0] . ':' . $provider->ratio[1]
                : ($provider->fallbackHeight > 0 ? $provider->fallbackHeight . 'px' : 'measured');

            $this->stdout(sprintf(
                "  %-18s %-22s %-8s %-10s %s\n",
                $provider->handle,
                mb_substr($provider->name, 0, 21),
                $provider->kind,
                $shape,
                $provider->supportsFacade() ? 'yes' : '—',
            ));

            $shown++;
        }

        $this->stdout(sprintf("\n  %d providers.\n\n", $shown), Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Says which provider a URL would be recognised as, and what Bed would do about it.
     *
     * @param string $url The frame URL to test.
     */
    public function actionMatch(string $url): int
    {
        $providers = Plugin::getInstance()->providers;
        $provider = $providers->matchFrame($url) ?? $providers->matchScript($url);

        if ($provider === null) {
            $this->stdout("Not recognised. It would still get a lazy attribute, a title and a bed built from its own dimensions.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("\n  " . $provider->name . " (" . $provider->handle . ")\n", Console::BOLD);
        $this->stdout('  ' . str_repeat('─', 50) . "\n");
        $this->stdout(sprintf("  %-16s %s\n", 'Kind', $provider->kind));
        $this->stdout(sprintf("  %-16s %s\n", 'Ratio', $provider->ratio !== null ? $provider->ratio[0] . ':' . $provider->ratio[1] : '—'));
        $this->stdout(sprintf("  %-16s %s\n", 'Fallback height', $provider->fallbackHeight > 0 ? $provider->fallbackHeight . 'px' : '—'));
        $this->stdout(sprintf("  %-16s %s\n", 'Preconnect', implode(', ', $provider->preconnect) ?: '—'));
        $this->stdout(sprintf("  %-16s %s\n", 'DNS prefetch', implode(', ', $provider->dnsPrefetch) ?: '—'));

        if ($provider->kind === Provider::KIND_IFRAME) {
            $poster = $provider->poster($url);
            $this->stdout(sprintf("  %-16s %s\n", 'Facade poster', $poster ?? '—'));
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }
}
