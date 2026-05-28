/**
 * Tasks Manager – auto-refresh on workflow advances + shared helper for
 * driving GLPI's solution form open with a pre-selected template.
 *
 * Signal headers emitted by the server-side hook
 * (see Workflow::applyStep and the completion branch in hook.php):
 *
 *   X-TM-Workflow-Advanced  : '1'    on EVERY workflow advance (step or done)
 *
 * Behaviour:
 *   1. Whenever any XHR/fetch comes back with X-TM-Workflow-Advanced, we
 *      schedule a page reload so the user sees the new state.
 *   2. We expose `window.tmOpenSolutionWithTemplate(id, name)` which both
 *      the TaskDashboard "Use this template" button on the Workflow tab
 *      and the "Recommended solution" button injected into the timeline
 *      footer via Hooks::TIMELINE_ACTIONS use to open GLPI's solution
 *      form with the suggested template pre-selected.
 *
 * Earlier versions (1.7.2) auto-opened the solution form on
 * DOMContentLoaded via a sessionStorage stash. That turned out to be too
 * intrusive — the form would pop unbidden, taking focus from whatever
 * the tech was reading. The "Recommended solution" button is the more
 * deliberate UX: tech sees the suggestion clearly, clicks when ready.
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

    // ─────────────────────────────────────────────────────────────────────────
    //  Shared helper: drive GLPI 11's timeline solution form open and
    //  pre-select a template.
    // ─────────────────────────────────────────────────────────────────────────
    //
    // Mechanism (see templates/components/itilobject/timeline/form_solution.html.twig
    // and ../answer.html.twig in GLPI 11):
    //   - The solution form lives in a Bootstrap collapse block
    //     #new-ITILSolution-block, toggled by a button with
    //     [data-bs-target="#new-ITILSolution-block"].
    //   - Inside the block, <select name="solutiontemplates_id"> is a
    //     Select2 widget whose `on_change` is GLPI's own
    //     `solutiontemplate_update<rand>(value)` — an AJAX call to
    //     /ajax/solution.php that fills the rich-text editor.
    //   - Select2 is remotely-sourced, so a plain .val() won't find our
    //     option locally — we inject it via `new Option(text, value, true, true)`,
    //     same pattern GLPI uses for solutiontypes_id higher in
    //     form_solution.html.twig.
    //
    // Returns true if the open+preselect could be initiated. Note that
    // some of the work is asynchronous (Bootstrap collapse animation +
    // Select2 init) so the form may take a moment to be visible.
    window.tmOpenSolutionWithTemplate = function (tplId, tplName) {
        if (!(tplId > 0)) return false;
        tplName = String(tplName || '');

        var block  = document.getElementById('new-ITILSolution-block');
        var toggle = document.querySelector('[data-bs-target="#new-ITILSolution-block"]');
        if (!block || !toggle) {
            return false; // Caller may fall back to a reminder toast
        }
        if (!block.classList.contains('show')) {
            toggle.click();
        }

        // Poll for the Select2 dropdown to be in DOM. Up to ~4.5s
        // (30 × 150ms) to absorb collapse animation + Select2 init.
        var attempts = 0;
        function tryPick() {
            if (attempts++ > 30) return;
            var sel = block.querySelector('select[name="solutiontemplates_id"]');
            if (!sel) {
                setTimeout(tryPick, 150);
                return;
            }
            if (typeof window.jQuery === 'undefined') return;
            var $sel = window.jQuery(sel);
            if (!$sel.find('option[value="' + tplId + '"]').length) {
                $sel.append(new Option(tplName, tplId, true, true));
            }
            $sel.val(tplId).trigger('change');
            block.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        setTimeout(tryPick, 150);
        return true;
    };
})();
