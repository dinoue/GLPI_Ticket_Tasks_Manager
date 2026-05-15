/**
 * Tasks Manager – auto-refresh when a workflow actually advances.
 *
 * The server-side hook (Workflow::applyStep / completion branch) sets the
 * response header `X-TM-Workflow-Advanced: 1` *only* when a workflow step
 * is created or the workflow completes. We watch every XHR/fetch response
 * for that header and reload the page when we see it — so the auto-refresh
 * triggers for real workflow advances, never for answer/followup edits or
 * any other timeline traffic.
 */
(function () {
    'use strict';

    var RELOAD_DELAY_MS = 1200;
    var reloadScheduled = false;

    function scheduleReload() {
        if (reloadScheduled) return;
        reloadScheduled = true;
        setTimeout(function () { window.location.reload(); }, RELOAD_DELAY_MS);
    }

    // ── XHR ──────────────────────────────────────────────────────────────────
    var OrigSend = window.XMLHttpRequest.prototype.send;

    window.XMLHttpRequest.prototype.send = function () {
        var self = this;
        this.addEventListener('load', function () {
            if (self.status >= 200 && self.status < 300) {
                try {
                    if (self.getResponseHeader('X-TM-Workflow-Advanced') === '1') {
                        scheduleReload();
                    }
                } catch (e) { /* ignore */ }
            }
        });
        return OrigSend.apply(this, arguments);
    };

    // ── fetch ────────────────────────────────────────────────────────────────
    if (typeof window.fetch === 'function') {
        var origFetch = window.fetch.bind(window);
        window.fetch = function (resource, init) {
            return origFetch(resource, init).then(function (response) {
                try {
                    if (response.ok &&
                        response.headers.get('X-TM-Workflow-Advanced') === '1') {
                        scheduleReload();
                    }
                } catch (e) { /* ignore */ }
                return response;
            });
        };
    }
})();
