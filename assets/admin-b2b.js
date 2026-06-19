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
        var polling = null;

        function renderLog(log) {
            logEl.textContent = (log || []).map(function (e) {
                return '[' + e.status + '] ' + (e.key || '') + (e.message ? ' — ' + e.message : '');
            }).join('\n');
        }

        function poll() {
            fetch(cfg.restStatus, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var p = d.progress || {};
                    var done = p.done || 0, total = p.total || 0;
                    if (d.log) { renderLog(d.log); }
                    if (p.finished && !d.pending) {
                        prog.textContent = (I.done || 'Done.') + ' ' + done + (total ? '/' + total : '');
                        btn.disabled = false;
                        clearInterval(polling); polling = null;
                    } else {
                        prog.textContent = (I.running || 'Syncing…') + ' ' + done + (total ? '/' + total : '')
                            + (d.pending ? ' (' + d.pending + ' pending)' : '');
                    }
                })
                .catch(function () { /* keep polling */ });
        }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            prog.textContent = I.starting || 'Starting…';
            fetch(cfg.restSync, { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) { prog.textContent = (I.error || 'Error.') + ' ' + d.error; btn.disabled = false; return; }
                    if (d.sync) { // синхронный фолбэк — уже всё сделано
                        poll();
                        btn.disabled = false;
                        return;
                    }
                    if (!polling) { polling = setInterval(poll, 2000); }
                    poll();
                })
                .catch(function () { prog.textContent = I.error || 'Error.'; btn.disabled = false; });
        });
    });
})(jQuery);
