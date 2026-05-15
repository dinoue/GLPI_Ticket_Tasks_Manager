/**
 * Tasks Manager – auto-refresh when a ticket task is marked as "Done".
 *
 * GLPI 11 saves inline timeline edits (including the task "Done" checkbox)
 * via POST to /ajax/timeline.php. We watch for that POST and, when it
 * succeeds, reload the page so the sidebar reflects the new step's
 * assigned group.
 */
(function () {
    'use strict';

    function onTicketPage() {
        var loc = (window.location.pathname + window.location.search).toLowerCase();
        return loc.indexOf('ticket') !== -1;
    }

    // URL fragments that signal a ticket-task save in GLPI 11.
    var SAVE_URL_PATTERNS = [
        '/ajax/timeline.php',
        'tickettask',
        'commonitiltask',
    ];

    function isSaveUrl(url) {
        if (!url) return false;
        var lower = String(url).toLowerCase();
        for (var i = 0; i < SAVE_URL_PATTERNS.length; i++) {
            if (lower.indexOf(SAVE_URL_PATTERNS[i].toLowerCase()) !== -1) return true;
        }
        return false;
    }

    var RELOAD_DELAY_MS = 1200;
    var reloadScheduled = false;
    function scheduleReload() {
        if (reloadScheduled) return;
        reloadScheduled = true;
        setTimeout(function () { window.location.reload(); }, RELOAD_DELAY_MS);
    }

    // Heuristic: POST (not GET) to a save URL while viewing a ticket.
    // GETs to those same URLs happen on page load (tab content fetch).
    function isInterestingSave(method, url) {
        if (!onTicketPage()) return false;
        if (!isSaveUrl(url)) return false;
        var m = String(method || '').toUpperCase();
        return m === 'POST' || m === 'PUT' || m === 'PATCH';
    }

    // ── XHR ──────────────────────────────────────────────────────────────────
    var OrigOpen = window.XMLHttpRequest.prototype.open;
    var OrigSend = window.XMLHttpRequest.prototype.send;

    window.XMLHttpRequest.prototype.open = function (method, url) {
        this.__tm_url    = url;
        this.__tm_method = method;
        return OrigOpen.apply(this, arguments);
    };

    window.XMLHttpRequest.prototype.send = function (body) {
        var self = this;
        this.addEventListener('load', function () {
            if (self.status >= 200 && self.status < 300 &&
                isInterestingSave(self.__tm_method, self.__tm_url)) {
                scheduleReload();
            }
        });
        return OrigSend.apply(this, arguments);
    };

    // ── fetch ────────────────────────────────────────────────────────────────
    if (typeof window.fetch === 'function') {
        var origFetch = window.fetch.bind(window);
        window.fetch = function (resource, init) {
            var url    = (typeof resource === 'string') ? resource
                       : (resource && resource.url)     ? resource.url
                       : '';
            var method = (init && init.method) ? init.method
                       : (resource && resource.method) ? resource.method
                       : 'GET';

            return origFetch(resource, init).then(function (response) {
                if (response.ok && isInterestingSave(method, url)) {
                    scheduleReload();
                }
                return response;
            });
        };
    }

    // ── Form-submit fallback (full-page POST navigation) ─────────────────────
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (!onTicketPage()) return;

        var action = form.getAttribute('action') || '';
        if (!isSaveUrl(action)) return;

        var stateInput = form.querySelector('[name="state"]');
        if (stateInput && String(stateInput.value) === '2') {
            try { sessionStorage.setItem('tm_force_reload', '1'); } catch (err) { /* ignore */ }
        }
    }, true);

    // If the previous page told us to force-reload (form-submit fallback)
    try {
        if (sessionStorage.getItem('tm_force_reload') === '1') {
            sessionStorage.removeItem('tm_force_reload');
            scheduleReload();
        }
    } catch (err) { /* ignore */ }
})();
