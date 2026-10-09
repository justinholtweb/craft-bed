/**
 * Bed — the front-end runtime.
 *
 * Zero dependencies, no build step, and deliberately small: everything that can be done in markup
 * and CSS already has been by the time this file runs. What is left is the three things a
 * stylesheet cannot do.
 *
 *   1. Load a third-party embed script when its embed is nearly on screen, and not before.
 *   2. Swap a facade for the real player when somebody actually asks for it.
 *   3. Measure what the embeds on this page settled to, so the next visitor gets the space
 *      reserved for them.
 *   4. Release an embed held for consent once the visitor's own answer allows it — read here, in
 *      the browser, because the page may have come out of a cache built for somebody else.
 *
 * If this file never loads, a page still has its beds, its lazy frames and its resource hints.
 * Deferred scripts and facades are the only things that depend on it — which is why the server
 * refuses to defer or to build a facade unless it knows this file is going to be there.
 */
(function () {
    'use strict';

    var LOADED = 'data-bed-loaded';

    function each(list, fn) {
        Array.prototype.forEach.call(list, fn);
    }

    /**
     * The tallest element child of a bed, ignoring the parts that are not the embed.
     *
     * Measuring the bed itself would measure the reservation — `min-height` is doing its job, so
     * the wrapper is never shorter than what the server already guessed. The embed's own height
     * is the thing worth reporting back.
     */
    function contentHeight(bed) {
        var tallest = 0;

        each(bed.children, function (child) {
            var tag = child.tagName;

            if (tag === 'TEMPLATE' || tag === 'SCRIPT' || tag === 'STYLE') {
                return;
            }

            var height = child.getBoundingClientRect().height;

            if (height > tallest) {
                tallest = height;
            }
        });

        return Math.round(tallest);
    }

    /**
     * Whether an element starts inside the first screenful.
     *
     * Document-relative on purpose. `getBoundingClientRect().top` alone answers "is it visible
     * now", and a browser restoring a scroll position on reload would make the same element
     * report differently every time. Adding the scroll offset back asks the question that
     * actually matters: where is this on the page.
     */
    function isAboveFold(el) {
        var rect = el.getBoundingClientRect();
        var top = rect.top + (window.scrollY || window.pageYOffset || 0);

        return top < (window.innerHeight || 0);
    }

    // ------------------------------------------------------------------ deferred scripts

    var requested = {};

    function loadScript(src) {
        if (requested[src]) {
            return;
        }

        requested[src] = true;

        var script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.setAttribute('data-bed-loader', '');
        document.body.appendChild(script);
    }

    // One observer per root margin, because that is the only thing that varies between beds and
    // an observer per embed on a page of forty tweets is forty observers.
    var observers = {};

    function watchScript(bed, now) {
        var src = bed.getAttribute('data-bed-script');

        if (!src || bed.__bedWatched) {
            return;
        }

        bed.__bedWatched = true;

        // No IntersectionObserver means no way to know when to start, so start now. A slow embed
        // is a better outcome than an embed that never appears. A click on a held embed is the
        // reader asking for it, so that starts now too.
        if (now || typeof IntersectionObserver !== 'function') {
            loadScript(src);
            bed.setAttribute(LOADED, '');
            return;
        }

        var margin = bed.getAttribute('data-bed-margin') || '400px';

        if (!observers[margin]) {
            observers[margin] = new IntersectionObserver(function (entries, observer) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    observer.unobserve(entry.target);
                    loadScript(entry.target.getAttribute('data-bed-script'));
                    entry.target.setAttribute(LOADED, '');
                });
            }, { rootMargin: margin });
        }

        observers[margin].observe(bed);
    }

    function watchScripts() {
        each(document.querySelectorAll('[data-bed-script]'), function (bed) {
            // A bed held for consent is watched once it is released, and not before.
            if (isHeld(bed)) {
                return;
            }

            watchScript(bed, false);
        });
    }

    // ------------------------------------------------------------------ facades

    /**
     * Merges the provider's activation parameters into a frame URL.
     *
     * Clicking play has to actually play. Without this the facade swaps in a paused player and
     * the reader has to press play a second time, which is worse than not having a facade.
     */
    function activate(src, params) {
        if (!params) {
            return src;
        }

        var hash = '';
        var hashAt = src.indexOf('#');

        if (hashAt !== -1) {
            hash = src.slice(hashAt);
            src = src.slice(0, hashAt);
        }

        return src + (src.indexOf('?') === -1 ? '?' : '&') + params + hash;
    }

    function openFacade(bed, button) {
        var template = bed.querySelector('template[data-bed-frame]');

        if (!template) {
            return;
        }

        var frame = template.content ? template.content.firstElementChild : null;

        if (!frame) {
            return;
        }

        frame = frame.cloneNode(true);

        var params = bed.getAttribute('data-bed-activate');

        if (params && frame.getAttribute('src')) {
            frame.setAttribute('src', activate(frame.getAttribute('src'), params));
        }

        // `allow="autoplay"` or the autoplay parameter is ignored. A click is a user gesture, so
        // this is autoplay the browser is happy to permit — but only if the player is told.
        var allow = frame.getAttribute('allow') || '';

        if (allow.indexOf('autoplay') === -1) {
            frame.setAttribute('allow', (allow ? allow + '; ' : '') + 'autoplay');
        }

        button.parentNode.replaceChild(frame, button);
        template.parentNode.removeChild(template);
        bed.setAttribute(LOADED, '');

        if (typeof frame.focus === 'function') {
            frame.focus();
        }
    }

    function wireFacade(button) {
        if (button.__bedWired) {
            return;
        }

        button.__bedWired = true;

        var bed = button.closest ? button.closest('.bed') : button.parentNode;

        if (!bed) {
            return;
        }

        button.addEventListener('click', function () {
            openFacade(bed, button);
        });

        // Warming the connection on hover buys most of a round trip on a click that is about
        // to happen, and costs nothing on one that is not.
        button.addEventListener('pointerenter', function () {
            var template = bed.querySelector('template[data-bed-frame]');
            var frame = template && template.content ? template.content.firstElementChild : null;
            var src = frame && frame.getAttribute('src');

            if (!src || bed.hasAttribute('data-bed-warm')) {
                return;
            }

            bed.setAttribute('data-bed-warm', '');

            var link = document.createElement('link');
            link.rel = 'preconnect';

            try {
                link.href = new URL(src, window.location.href).origin;
            } catch (e) {
                return;
            }

            document.head.appendChild(link);
        }, { once: true });
    }

    function watchFacades() {
        each(document.querySelectorAll('[data-bed-facade]'), wireFacade);
    }

    // ------------------------------------------------------------------ consent

    /*
     * Three answers, not two. `true` granted, `false` refused, `null` not decided yet — which is
     * not the same as no, and is shown differently (`data-bed-consent="pending"` against
     * `"denied"`), though either way nothing loads. A held embed is released the moment its
     * category reads `true`, and put back if a decision is withdrawn later on the same page.
     *
     * Toss is the family's consent manager. Its cookie is signed, so the browser never reads it:
     * the answer comes from `window.Toss` and the `toss:consent` event, per Toss's published
     * consent API. Everything else is a reader for a platform the site already has.
     */
    var consentSettings = null;
    var consentStarted = false;
    var manualAnswers = {};
    var tossSnapshot = null;

    function own(object, key) {
        return Object.prototype.hasOwnProperty.call(object, key);
    }

    function readCookie(name) {
        var parts = document.cookie ? document.cookie.split(';') : [];

        for (var i = 0; i < parts.length; i++) {
            var at = parts[i].indexOf('=');
            var key = parts[i].slice(0, at).trim();

            if (key === name) {
                try {
                    return decodeURIComponent(parts[i].slice(at + 1).trim());
                } catch (e) {
                    return parts[i].slice(at + 1).trim();
                }
            }
        }

        return null;
    }

    /** Subscribes to Toss whether its runtime has run yet or not — the recipe from its consent API. */
    function onTossConsent(callback) {
        if (window.Toss && typeof window.Toss.onConsent === 'function') {
            return window.Toss.onConsent(callback);
        }

        var handler = function (event) { callback(event.detail); };
        document.addEventListener('toss:consent', handler);

        return function () { document.removeEventListener('toss:consent', handler); };
    }

    var READERS = {
        toss: function (category) {
            var snapshot = tossSnapshot;

            if (!snapshot && window.Toss && typeof window.Toss.consent === 'function') {
                snapshot = window.Toss.consent();
            }

            if (!snapshot || !snapshot.categories) {
                return null;
            }

            // Toss answers `false` for a category the site does not use; so does this.
            return own(snapshot.categories, category) ? snapshot.categories[category] : false;
        },

        cookiebot: function (category) {
            var cookiebot = window.Cookiebot;

            if (!cookiebot || !cookiebot.consent || cookiebot.hasResponse === false) {
                return null;
            }

            var key = { preferences: 'preferences', analytics: 'statistics', marketing: 'marketing' }[category] || category;

            return cookiebot.consent[key] === true;
        },

        cookieyes: function (category) {
            var raw = readCookie('cookieyes-consent');

            if (!raw) {
                return null;
            }

            var values = {};

            raw.split(',').forEach(function (pair) {
                var at = pair.indexOf(':');

                if (at > 0) {
                    values[pair.slice(0, at).trim()] = pair.slice(at + 1).trim();
                }
            });

            // Before the visitor acts, CookieYes writes its defaults with `action:no`. Those are
            // not an answer.
            if (values.action !== 'yes') {
                return null;
            }

            var key = { preferences: 'functional', analytics: 'analytics', marketing: 'advertisement' }[category] || category;

            return values[key] === 'yes';
        },

        tape: function (category) {
            var tape = window.tape;

            if (!tape || Array.isArray(tape) || !tape.consent || typeof tape.consent.get !== 'function') {
                return null;
            }

            var signal = { preferences: 'functionality_storage', analytics: 'analytics_storage', marketing: 'ad_storage' }[category];
            var value = signal ? tape.consent.get()[signal] : undefined;

            return value === 'granted' ? true : (value === 'denied' ? false : null);
        },

        cookie: function (category) {
            var config = consentSettings && consentSettings.cookie;
            var value = config && config.name ? readCookie(config.name) : null;

            if (value === null) {
                return null;
            }

            return value.indexOf(String(config.match || '{category}').split('{category}').join(category)) !== -1;
        },

        click: function () {
            return null;
        },
    };

    var OPENERS = {
        toss: function () {
            return window.Toss && typeof window.Toss.open === 'function' ? function () { window.Toss.open(); } : null;
        },
        cookiebot: function () {
            return window.Cookiebot && typeof window.Cookiebot.renew === 'function' ? function () { window.Cookiebot.renew(); } : null;
        },
        cookieyes: function () {
            return typeof window.revisitCkyConsent === 'function' ? function () { window.revisitCkyConsent(); } : null;
        },
    };

    function answerFor(category) {
        if (category === 'necessary') {
            return true;
        }

        if (own(manualAnswers, category)) {
            return manualAnswers[category];
        }

        var reader = consentSettings && READERS[consentSettings.source];

        return reader ? reader(category) : null;
    }

    function opener() {
        var find = consentSettings && OPENERS[consentSettings.source];

        return find ? find() : null;
    }

    /** Whether a bed is still waiting for consent. */
    function isHeld(bed) {
        var state = bed.getAttribute('data-bed-consent');

        return bed.hasAttribute('data-bed-gate') && state !== 'granted' && state !== 'loaded';
    }

    /**
     * Lets a held embed load.
     *
     * A frame or player comes out of its inert `<template>`; the notice and the template are kept
     * aside, so a decision withdrawn later can put them back. A facade comes out as a facade —
     * or, when the reader clicked "Load", straight into the player, because they have just asked
     * for it once already. A script embed simply starts being watched.
     */
    function release(bed, byClick) {
        var notice = bed.querySelector('[data-bed-notice]');
        var template = bed.querySelector('template[data-bed-held]');

        bed.setAttribute('data-bed-consent', byClick ? 'loaded' : 'granted');

        if (template) {
            var content = template.content ? template.content.cloneNode(true) : null;

            bed.__bedHeld = [];
            bed.__bedHeldFrame = true;
            each([notice, template], function (node) {
                if (node && node.parentNode === bed) {
                    bed.__bedHeld.push(node);
                    bed.removeChild(node);
                }
            });

            if (content) {
                bed.appendChild(content);
            }

            var facade = bed.querySelector('[data-bed-facade]');

            if (facade) {
                wireFacade(facade);

                if (byClick) {
                    openFacade(bed, facade);
                }
            }

            return;
        }

        if (notice && notice.parentNode === bed) {
            bed.__bedHeld = [notice];
            bed.removeChild(notice);
        }

        watchScript(bed, byClick);
    }

    /**
     * Puts a released frame back behind its notice, when the visitor withdraws the category it
     * needed. A script embed cannot be unloaded once its script has run, so it stays; a reload
     * honours the new answer.
     */
    function hold(bed, state) {
        if (!bed.__bedHeld || !bed.__bedHeldFrame) {
            bed.setAttribute('data-bed-consent', state);
            return;
        }

        while (bed.firstChild) {
            bed.removeChild(bed.firstChild);
        }

        each(bed.__bedHeld, function (node) {
            bed.appendChild(node);
        });

        bed.__bedHeld = null;
        bed.__bedHeldFrame = false;
        bed.setAttribute('data-bed-consent', state);
    }

    function applyConsent() {
        var canOpen = !!opener();
        var waiting = 0;

        each(document.querySelectorAll('[data-bed-gate]'), function (bed) {
            var answer = answerFor(bed.getAttribute('data-bed-gate'));
            var state = bed.getAttribute('data-bed-consent');

            if (answer === true) {
                if (state !== 'granted' && state !== 'loaded') {
                    release(bed, false);
                }

                return;
            }

            var held = answer === false ? 'denied' : 'pending';

            if (state === 'granted') {
                hold(bed, held);
            } else if (state !== 'loaded') {
                bed.setAttribute('data-bed-consent', held);
            }

            if (bed.getAttribute('data-bed-consent') !== 'loaded') {
                waiting++;
            }
        });

        each(document.querySelectorAll('[data-bed-manage]'), function (button) {
            button.hidden = !canOpen;
        });

        return waiting;
    }

    function startConsent() {
        if (consentStarted) {
            applyConsent();
            return;
        }

        var el = document.getElementById('bed-consent');

        if (el) {
            try {
                consentSettings = JSON.parse(el.textContent || '{}');
            } catch (e) {
                consentSettings = null;
            }
        }

        if (!document.querySelector('[data-bed-gate]')) {
            return;
        }

        consentStarted = true;

        document.addEventListener('click', function (event) {
            var target = event.target && event.target.closest ? event.target : null;
            var load = target && target.closest('[data-bed-load]');
            var manage = target && target.closest('[data-bed-manage]');

            if (manage) {
                var open = opener();

                if (open) {
                    open();
                }

                return;
            }

            var bed = load && load.closest('[data-bed-gate]');

            if (!bed) {
                return;
            }

            // A script embed's loader renders every placeholder of its provider on the page, so
            // every one of them is released together rather than leaving notices on posts that
            // are about to render anyway.
            if (bed.hasAttribute('data-bed-script')) {
                var provider = bed.getAttribute('data-bed-provider');

                each(document.querySelectorAll('[data-bed-gate][data-bed-script]'), function (other) {
                    if (other.getAttribute('data-bed-provider') === provider && isHeld(other)) {
                        release(other, true);
                    }
                });

                return;
            }

            release(bed, true);
        });

        var source = consentSettings ? consentSettings.source : 'click';

        if (source === 'toss') {
            onTossConsent(function (snapshot) {
                tossSnapshot = snapshot;
                applyConsent();
            });
        }

        // Platforms announce themselves differently and several announce twice, so applying is
        // idempotent and simply listens for all of them.
        ['CookiebotOnAccept', 'CookiebotOnDecline', 'CookiebotOnConsentReady', 'cookieyes_consent_update'].forEach(function (name) {
            window.addEventListener(name, applyConsent, false);
            document.addEventListener(name, applyConsent, false);
        });

        applyConsent();

        if (source === 'click' || source === 'toss') {
            return;
        }

        // Tape and a plain cookie announce nothing, and a platform may answer only after its own
        // script loads. Checked once a second for as long as anything is still waiting.
        var timer = window.setInterval(function () {
            if (!applyConsent()) {
                window.clearInterval(timer);
            }
        }, 1000);
    }

    // ------------------------------------------------------------------ measurement

    function config() {
        var el = document.getElementById('bed-collect');

        if (!el) {
            return null;
        }

        try {
            return JSON.parse(el.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function bucketFor(width, breakpoints) {
        for (var i = 0; i < breakpoints.length; i++) {
            if (width <= breakpoints[i]) {
                return breakpoints[i];
            }
        }

        return breakpoints[breakpoints.length - 1];
    }

    function measure(settings) {
        var width = window.innerWidth || document.documentElement.clientWidth || 0;

        if (!width) {
            return;
        }

        var bucket = bucketFor(width, settings.breakpoints || []);
        var payload = [];

        Object.keys(settings.slots || {}).forEach(function (slot) {
            var full = settings.slots[slot] || [];

            // The server already has enough samples at this width for this slot. Sending more
            // would be traffic that changes nothing.
            if (full.indexOf(bucket) !== -1) {
                return;
            }

            var bed = document.querySelector('[data-bed="' + slot + '"]');

            // A held bed is the size of its notice, which is not a measurement of the embed.
            if (!bed || isHeld(bed)) {
                return;
            }

            var height = contentHeight(bed);

            if (height < 1) {
                return;
            }

            payload.push({ slot: slot, height: height, above: bed.__bedAboveFold ? 1 : 0 });
        });

        if (!payload.length) {
            return;
        }

        var body = JSON.stringify({ token: settings.token, width: width, measurements: payload });

        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(settings.url, new Blob([body], { type: 'application/json' }));
                return;
            }

            fetch(settings.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                keepalive: true,
                credentials: 'omit',
            });
        } catch (e) {
            /* A measurement that could not be sent is not worth breaking a page over. */
        }
    }

    function collect() {
        var settings = config();

        if (!settings || !settings.token || !settings.url) {
            return;
        }

        if (Math.random() >= (settings.rate || 0)) {
            return;
        }

        // Where each bed sits is decided now, before anything has had a chance to scroll or to
        // grow. The heights are read much later, once the embeds have settled.
        Object.keys(settings.slots || {}).forEach(function (slot) {
            var bed = document.querySelector('[data-bed="' + slot + '"]');

            if (bed) {
                bed.__bedAboveFold = isAboveFold(bed);
            }
        });

        var send = function () {
            // Third-party embeds resize themselves for a while after load — a tweet grows when its
            // images arrive, an Instagram frame when its script hands back a height. Reading too
            // early records the reservation rather than the result.
            window.setTimeout(function () {
                measure(settings);
            }, 2500);
        };

        if (document.readyState === 'complete') {
            send();
        } else {
            window.addEventListener('load', send, { once: true });
        }
    }

    // ------------------------------------------------------------------ start

    function start() {
        startConsent();
        watchScripts();
        watchFacades();
        collect();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }

    window.Bed = {
        refresh: start,
        openFacade: openFacade,

        /**
         * Tells Bed a consent answer from a banner of the site's own: `Bed.consent('marketing',
         * true)`, or several at once with `Bed.consent({ marketing: true, analytics: false })`.
         * `null` withdraws an answer given here, handing the category back to the configured
         * source. Applies to this page view only; the banner is what remembers it.
         */
        consent: function (category, granted) {
            var answers = {};

            if (category && typeof category === 'object') {
                answers = category;
            } else {
                answers[category] = granted;
            }

            Object.keys(answers).forEach(function (key) {
                if (answers[key] === null || answers[key] === undefined) {
                    delete manualAnswers[key];
                } else {
                    manualAnswers[key] = answers[key] === true;
                }
            });

            applyConsent();
        },
    };
})();
