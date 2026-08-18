<?php

namespace justinholtweb\bed\helpers;

use Craft;
use justinholtweb\bed\Plugin;
use Throwable;

/**
 * Where the front-end runtime lives, and how it gets onto a page.
 *
 * The stylesheet is inlined rather than linked. It is about a kilobyte of custom-property
 * plumbing, and its whole job is to hold space before the first paint — putting that behind a
 * render-blocking request would be a plugin that measures layout shift causing it. The script is
 * a published asset instead, because it is bigger, it is identical on every page, and nothing
 * about it needs to run before the document is parsed.
 */
class Runtime
{
    private static ?string $css = null;

    private static ?string $scriptUrl = null;

    public static function dir(): string
    {
        return dirname(__DIR__) . '/web/assets/runtime/dist';
    }

    /** Bed's base stylesheet, ready to inline. */
    public static function css(): string
    {
        if (self::$css === null) {
            $path = self::dir() . '/bed.css';
            $css = is_file($path) ? file_get_contents($path) : false;
            self::$css = $css === false ? '' : trim($css);
        }

        return self::$css;
    }

    /** The published URL of the runtime script, or null if it could not be published. */
    public static function scriptUrl(): ?string
    {
        if (self::$scriptUrl === null) {
            try {
                self::$scriptUrl = Craft::$app->getAssetManager()->getPublishedUrl(self::dir(), true, 'bed.js') ?: '';
            } catch (Throwable $e) {
                Craft::error('Could not publish the Bed runtime: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
                self::$scriptUrl = '';
            }
        }

        return self::$scriptUrl !== '' ? self::$scriptUrl : null;
    }
}
