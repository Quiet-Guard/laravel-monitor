/*
 * Quiet Guard browser errors. Printed inline by @quietGuardBrowser, with no
 * dependency. Reports uncaught errors and unhandled promise rejections to the
 * project's browser endpoint: the page address without its query string or
 * fragment, the user agent, the error and its stack. No cookie is read, no
 * identifier is set, and the server masks what it receives again.
 */
(function () {
    'use strict';

    var config = window.QuietGuardBrowser;

    if (!config || !config.endpoint || window.__quietGuardBrowserLoaded) {
        return;
    }

    window.__quietGuardBrowserLoaded = true;

    var MAX_REPORTS = 10;
    var sent = 0;
    var seen = {};

    function text(value, limit) {
        return typeof value === 'string' ? value.slice(0, limit) : null;
    }

    function report(error, fallbackMessage) {
        if (sent >= MAX_REPORTS) {
            return;
        }

        var message = String((error && error.message) || fallbackMessage || '');
        var stack = error && typeof error.stack === 'string' ? error.stack : '';

        // Opaque cross-origin errors carry nothing to act on.
        if (!stack && /^Script error\.?$/i.test(message)) {
            return;
        }

        var top = stack.split('\n').filter(function (line) { return /:\d+/.test(line); })[0] || '';
        var signature = message + '|' + top;

        if (seen[signature]) {
            return;
        }

        seen[signature] = true;
        sent++;

        var body = JSON.stringify({
            name: text(String((error && error.name) || 'Error'), 255),
            message: message.slice(0, 8192),
            stack: stack.slice(0, 16384),
            url: location.origin + location.pathname,
            ua: text(navigator.userAgent, 255),
            release: config.release || null,
            environment: config.environment || null,
            livewire: !!window.Livewire,
            // Alpine rethrows with the expression that failed attached.
            alpine: error ? text(error.expression, 500) : null
        });

        try {
            if (navigator.sendBeacon && navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'text/plain' }))) {
                return;
            }
        } catch (ignored) {}

        try {
            fetch(config.endpoint, {
                method: 'POST',
                body: body,
                mode: 'no-cors',
                keepalive: true,
                credentials: 'omit',
                headers: { 'Content-Type': 'text/plain' }
            });
        } catch (ignored) {}
    }

    window.addEventListener('error', function (event) {
        // A failed <img> or <script> load bubbles with neither.
        if (!event || (!event.error && !event.message)) {
            return;
        }

        report(event.error, event.message);
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event ? event.reason : null;

        if (reason instanceof Error) {
            report(reason);

            return;
        }

        var described;

        try {
            described = typeof reason === 'string' ? reason : JSON.stringify(reason);
        } catch (ignored) {
            described = String(reason);
        }

        report({ name: 'UnhandledRejection', message: String(described) });
    });
})();
