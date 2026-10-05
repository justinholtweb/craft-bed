<?php

namespace justinholtweb\bed;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\bed\models\Settings;
use justinholtweb\bed\services\Hints;
use justinholtweb\bed\services\Ledger;
use justinholtweb\bed\services\Metrics;
use justinholtweb\bed\services\Optimizer;
use justinholtweb\bed\services\Providers;
use justinholtweb\bed\services\Scanner;
use justinholtweb\bed\twig\BedVariable;
use justinholtweb\bed\twig\Extension;
use Throwable;
use yii\base\Event;

/**
 * Bed — every embed gets a bed.
 *
 * An embed arrives in a page as somebody else's markup and behaves like it. It loads whatever it
 * wants whenever it wants, and it settles to a height nobody knew in advance — after the reader
 * has already started reading. Bed lays a bed for each one: space reserved from heights measured
 * on real page views, loading deferred until the embed is wanted, and connections warmed only for
 * the embeds that are wanted immediately.
 *
 * Nothing in this plugin makes an outbound request. The provider registry is a description of
 * what embeds look like, not a client for talking to anybody.
 *
 * @property-read Providers $providers
 * @property-read Scanner $scanner
 * @property-read Optimizer $optimizer
 * @property-read Metrics $metrics
 * @property-read Ledger $ledger
 * @property-read Hints $hints
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'bed:viewEmbeds';
    public const PERMISSION_MANAGE = 'bed:manageEmbeds';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'bed';

    public string $schemaVersion = '1.1.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'providers' => Providers::class,
                'scanner' => Scanner::class,
                'optimizer' => Optimizer::class,
                'metrics' => Metrics::class,
                'ledger' => Ledger::class,
                'hints' => Hints::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerResponseFilter();
        $this->registerGarbageCollection();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('bed', 'Bed');

        $item['subnav'] = [
            'embeds' => [
                'label' => Craft::t('bed', 'Embeds'),
                'url' => 'bed/embeds',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('bed', 'Settings'),
                'url' => 'settings/plugins/bed',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('bed/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'stats' => $this->ledger->stats(),
            'facadeOptions' => $this->facadeOptions(),
        ]);
    }

    /** @return array<int, array<string, string>> The providers a facade can actually be built for. */
    public function facadeOptions(): array
    {
        $options = [];

        foreach ($this->providers->facadeCapable() as $provider) {
            $options[] = ['value' => $provider->handle, 'label' => $provider->name];
        }

        return $options;
    }

    /**
     * Optimises a fragment from a template, and the one place the Twig filter and function meet.
     *
     * Fragment mode, so the page additions go through the view rather than being spliced into a
     * string that is only part of a page. The URI defaults to the request's, because a slot is
     * identified by the page it is on and a fragment rendered inside a page is on that page.
     *
     * @param array{siteId?: int, uri?: string} $config
     */
    public function render(string $html, array $config = []): string
    {
        try {
            $request = Craft::$app->getRequest();

            return $this->optimizer->optimize($html, [
                'mode' => Optimizer::MODE_FRAGMENT,
                'siteId' => $config['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id,
                'uri' => $config['uri'] ?? ($request instanceof \craft\web\Request ? $request->getPathInfo() : ''),
            ]);
        } catch (Throwable $e) {
            Craft::error('Could not optimise a fragment: ' . $e->getMessage(), self::LOG_CATEGORY);

            return $html;
        }
    }

    // ------------------------------------------------------------------ the response filter

    /**
     * Rewriting front-end HTML on the way out the door.
     *
     * `EVENT_AFTER_PREPARE` and not a template hook, because the embeds Bed cares about arrive
     * from everywhere — a rich-text field, a hard-coded template, a third-party plugin's render —
     * and the only place all of them are in one string is the prepared response.
     */
    private function registerResponseFilter(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            try {
                $this->filter($event->sender);
            } catch (Throwable $e) {
                // A page that renders slightly worse is a much better failure than a page that
                // does not render. Nothing in here is allowed to take a site down.
                Craft::error('Could not optimise embeds: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    private function filter(Response $response): void
    {
        $settings = $this->getSettings();

        if (!$settings->enabled || !$settings->autoOptimize) {
            return;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request || $request->getIsCpRequest()) {
            return;
        }

        if ($response->getStatusCode() >= 400) {
            return;
        }

        if (!$this->isHtmlResponse((string)$response->getHeaders()->get('content-type'))) {
            return;
        }

        $html = $response->content;

        if (!is_string($html) || $html === '' || !str_contains($html, '<')) {
            return;
        }

        // Document mode splices into `<head>` and before `</body>`. A response with neither is a
        // fragment — an htmx swap, an Element API payload rendered as HTML — and it gets left
        // alone rather than being handed head content it has nowhere to put.
        if (stripos($html, '<head') === false) {
            return;
        }

        $uri = $request->getPathInfo();

        if ($settings->excludes($uri)) {
            return;
        }

        $optimized = $this->optimizer->optimize($html, [
            'mode' => Optimizer::MODE_DOCUMENT,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
            'uri' => $uri,
        ]);

        if ($optimized === $html) {
            return;
        }

        $response->content = $optimized;

        // `sendContentLengthHeader` makes Craft stamp the length during prepare — before this
        // runs. Leaving it stale truncates the page at exactly the byte the first edit was made
        // at, which looks like a broken template rather than a broken header.
        $headers = $response->getHeaders();

        if ($headers->get('content-length') !== null) {
            $headers->set('content-length', (string)strlen($response->content));
        }
    }

    /**
     * Whether a prepared response is a page.
     *
     * The content *type* answers this; the response format does not. Craft renders front-end
     * templates through its own `template` format, and that formatter takes the MIME type from
     * the template's file extension — so `feed.rss.twig` and `manifest.json.twig` are template
     * responses that must be left alone. Testing the format instead matches nothing at all.
     */
    public function isHtmlResponse(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'text/html');
    }

    // ------------------------------------------------------------------ wiring

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'bed' => 'bed/embeds/index',
                'bed/embeds' => 'bed/embeds/index',
                'bed/embeds/<slotId:\d+>' => 'bed/embeds/detail',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('bed', 'Bed'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('bed', 'View measured embeds'),
                        'nested' => [
                            self::PERMISSION_MANAGE => ['label' => Craft::t('bed', 'Clear measurements')],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('bed', BedVariable::class);
        });

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    /**
     * Slots nobody has measured in a long time are content that has moved on.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->ledger->prune();
            } catch (Throwable $e) {
                Craft::error('Could not prune the slot ledger: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }
}
