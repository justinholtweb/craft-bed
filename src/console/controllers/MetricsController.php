<?php

namespace justinholtweb\bed\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\Plugin;
use yii\console\ExitCode;

/**
 * `craft bed/metrics/…` — what Bed has measured, and how to forget it.
 */
class MetricsController extends Controller
{
    /** @var int|null Override the retention window for a one-off prune. */
    public ?int $days = null;

    /** @var int|null Limit to one site. */
    public ?int $siteId = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'prune' => [...parent::options($actionID), 'days'],
            'purge' => [...parent::options($actionID), 'siteId'],
            default => parent::options($actionID),
        };
    }

    /**
     * Summarises the slot ledger.
     */
    public function actionReport(): int
    {
        $plugin = Plugin::getInstance();
        $stats = $plugin->ledger->stats();
        $settings = $plugin->getSettings();

        $this->stdout("\nBed\n", Console::BOLD);
        $this->stdout(str_repeat('─', 60) . "\n");

        $this->stdout(sprintf("  %-28s %s\n", 'Slots tracked', number_format($stats['slots'])));
        $this->stdout(sprintf("  %-28s %s\n", 'Slots with measurements', number_format($stats['measured'])));
        $this->stdout(sprintf("  %-28s %s\n", 'Samples collected', number_format($stats['samples'])));
        $this->stdout(sprintf("  %-28s %s\n", 'Row cap', number_format($settings->maxSlots) . ($stats['full'] ? '  (reached)' : '')));
        $this->stdout(sprintf("  %-28s %s\n", 'Reserve strategy', $settings->reserveStrategy));
        $this->stdout(sprintf("  %-28s %s\n", 'Samples wanted per bucket', (string)$settings->sampleTarget));

        if ($stats['providers'] !== []) {
            $this->stdout("\n  By provider\n", Console::BOLD);

            foreach ($stats['providers'] as $handle => $count) {
                $provider = $plugin->providers->byHandle($handle);
                $this->stdout(sprintf("    %-24s %s\n", $provider?->name ?? $handle, number_format($count)));
            }
        }

        $unmeasured = $stats['slots'] - $stats['measured'];

        if ($stats['slots'] === 0) {
            $this->stdout("\n  Nothing measured yet. Slots appear once a sampled visitor reports one.\n", Console::FG_GREY);
        } elseif ($unmeasured > 0) {
            $this->stdout(sprintf("\n  %s slots are still waiting for their first sample.\n", number_format($unmeasured)), Console::FG_GREY);
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Drops slots nobody has measured lately. Craft's garbage collection does this too.
     */
    public function actionPrune(): int
    {
        $days = $this->days ?? Plugin::getInstance()->getSettings()->retentionDays;
        $count = Plugin::getInstance()->ledger->prune($days);

        $this->stdout(sprintf("Pruned %s slots not seen in %d days.\n", number_format($count), $days), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Empties the ledger. Every reservation goes back to the provider's fallback until new
     * measurements arrive.
     */
    public function actionPurge(): int
    {
        if (!$this->confirm('Clear every measurement Bed has collected?')) {
            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->ledger->purge($this->siteId);

        $this->stdout(sprintf("Cleared %s slots.\n", number_format($count)), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists the viewport buckets measurements are grouped into.
     */
    public function actionBreakpoints(): int
    {
        foreach (Settings::BREAKPOINTS as $breakpoint) {
            $this->stdout(sprintf("  %-6d %s\n", $breakpoint, \justinholtweb\bed\services\Optimizer::mediaQuery($breakpoint)));
        }

        return ExitCode::OK;
    }
}
