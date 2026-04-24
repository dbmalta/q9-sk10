// appCore — project JavaScript
//
// Loaded globally from base.html.twig after Bootstrap, Alpine, and HTMX.
// Keep it minimal; module-specific behaviour belongs in its own file.

(function () {
    'use strict';

    // HTMX: inject the CSRF token on every request.
    if (window.htmx && document.body) {
        document.body.addEventListener('htmx:configRequest', function (event) {
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                event.detail.headers['X-CSRF-Token'] = meta.getAttribute('content');
            }
        });
    }

    // Theme toggle: persist user preference across reloads.
    window.appCoreToggleTheme = function () {
        var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        var next = current === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('app-theme', next); } catch (e) { /* ignore */ }
    };

    (function restoreTheme() {
        try {
            var stored = localStorage.getItem('app-theme');
            if (stored) {
                document.documentElement.setAttribute('data-bs-theme', stored);
            }
        } catch (e) { /* ignore */ }
    })();
})();
