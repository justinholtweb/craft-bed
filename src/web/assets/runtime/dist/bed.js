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

    function watchScripts() {
        var beds = document.querySelectorAll('[data-bed-script]');

        if (!beds.length) {
            return;
        }

        // No IntersectionObserver means no way to know when to start, so start now. A slow embed
        // is a better outcome than an embed that never appears.
        if (typeof IntersectionObserver !== 'function') {
            each(beds, function (bed) {
                loadScript(bed.getAttribute('data-bed-script'));
            });

            return;
        }

        // One observer per root margin, because that is the only thing that varies between beds
        // and an observer per embed on a page of forty tweets is forty observers.
        var observers = {};

        each(beds, function (bed) {
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

    function watchFacades() {
        each(document.querySelectorAll('[data-bed-facade]'), function (button) {
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
        });
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

            if (!bed) {
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
    };
})();
