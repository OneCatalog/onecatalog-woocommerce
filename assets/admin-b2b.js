/* global jQuery, OneCatalogB2B */
(function ($) {
    'use strict';

    $(function () {
        // Drag-and-drop приоритеты (регионы/поставщики). Порядок hidden-инпутов = DOM-порядок.
        if ($.fn.sortable) {
            $('.oc-sortable').sortable({ axis: 'y', cursor: 'move', placeholder: 'oc-sortable-ph' });
        }

        var cfg = window.OneCatalogB2B || {};
        var btn = document.getElementById('oc-b2b-sync');
        var prog = document.getElementById('oc-b2b-progress');
        var logEl = document.getElementById('oc-b2b-log');
        if (!btn) { return; }
        var I = cfg.i18n || {};

        function renderLog(log) {
            logEl.textContent = (log || []).map(function (e) {
                return '[' + e.status + '] ' + (e.key || '') + (e.message ? ' — ' + e.message : '');
            }).join('\n');
        }

        function showProgress(p, finished) {
            p = p || {};
            var scanned = p.scanned || 0, total = p.total || 0, changed = p.changed || 0;
            var label = finished ? (I.done || 'Done.') : (I.running || 'Syncing…');
            prog.textContent = label + ' ' + scanned + (total ? '/' + total : '')
                + ' — ' + (I.changed || 'changed') + ': ' + changed;
        }

        // Браузерный степпер: каждый запрос обрабатывает одну страницу синхронно.
        function step(start, reset) {
            var body = reset ? { reset: true } : { start: start };
            fetch(cfg.restSync, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) {
                        prog.textContent = (I.error || 'Error.') + ' ' + d.error;
                        btn.disabled = false;
                        return;
                    }
                    if (d.log) { renderLog(d.log); }
                    showProgress(d.progress, !d.more);
                    if (d.more) {
                        step(d.next, false);
                    } else {
                        btn.disabled = false;
                    }
                })
                .catch(function () { prog.textContent = I.error || 'Error.'; btn.disabled = false; });
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            prog.textContent = I.starting || 'Starting…';
            step(0, true); // первый вызов — со сбросом
        });
    });
})(jQuery);
