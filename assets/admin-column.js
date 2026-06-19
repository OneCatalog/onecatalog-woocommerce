/* global OneCatalogColumn */
(function () {
    'use strict';

    var cfg = window.OneCatalogColumn || {};
    var I = cfg.i18n || {};

    function copy(text, el) {
        var done = function () {
            var badge = el.parentNode.querySelector('.oc-copied');
            if (badge) {
                badge.hidden = false;
                setTimeout(function () { badge.hidden = true; }, 1500);
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { fallback(text); done(); });
        } else {
            fallback(text); done();
        }
    }

    function fallback(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* noop */ }
        document.body.removeChild(ta);
    }

    function renderValue(col, id) {
        var openUrl = (cfg.openTpl || '').replace('__ID__', encodeURIComponent(id));
        col.innerHTML =
            '<span class="oc-id" role="button" tabindex="0" title="' + (I.copy || '') + '" data-id="' + escapeHtml(id) + '">' + escapeHtml(id) + '</span>' +
            '<span class="oc-copied" hidden>' + (I.copied || '') + '</span>' +
            '<div class="oc-actions"><a class="oc-open" href="' + openUrl + '" target="_blank" rel="noopener">' + (I.open || 'Open') + '</a></div>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function save(col, id) {
        var product = col.getAttribute('data-product');
        return fetch(cfg.restSet, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: JSON.stringify({ product_id: parseInt(product, 10), public_id: id })
        }).then(function (r) { return r.json(); });
    }

    document.addEventListener('click', function (e) {
        var t = e.target;

        // Копирование OC.ID
        if (t.classList && t.classList.contains('oc-id')) {
            copy(t.getAttribute('data-id') || t.textContent, t);
            return;
        }

        // «+ внести код» → показать поле
        if (t.classList && t.classList.contains('oc-add')) {
            e.preventDefault();
            var col = t.closest('.oc-col');
            t.hidden = true;
            var edit = col.querySelector('.oc-edit');
            if (edit) { edit.hidden = false; edit.querySelector('.oc-input').focus(); }
            return;
        }

        // Сохранить введённый код
        if (t.classList && t.classList.contains('oc-save')) {
            e.preventDefault();
            var col2 = t.closest('.oc-col');
            var input = col2.querySelector('.oc-input');
            var id = (input.value || '').trim();
            if (!id) { input.focus(); return; }
            t.disabled = true;
            save(col2, id).then(function (d) {
                if (d && d.ok) { renderValue(col2, d.public_id); }
                else { t.disabled = false; alert((I.error || 'Error') + (d && d.error ? ': ' + d.error : '')); }
            }).catch(function () { t.disabled = false; alert(I.error || 'Error'); });
            return;
        }
    });

    // Enter в поле = сохранить; Esc = отмена
    document.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('oc-input')) { return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            var btn = e.target.parentNode.querySelector('.oc-save');
            if (btn) { btn.click(); }
        } else if (e.key === 'Escape') {
            var col = e.target.closest('.oc-col');
            var edit = col.querySelector('.oc-edit');
            var add = col.querySelector('.oc-add');
            if (edit) { edit.hidden = true; }
            if (add) { add.hidden = false; }
        }
    });

    // Копирование с клавиатуры (Enter/Space на .oc-id)
    document.addEventListener('keydown', function (e) {
        if (e.target.classList && e.target.classList.contains('oc-id') && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            copy(e.target.getAttribute('data-id') || e.target.textContent, e.target);
        }
    });
})();
