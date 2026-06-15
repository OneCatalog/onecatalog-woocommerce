/**
 * Страница «Товары»: кнопка «OneCatalog(Товары)» после «Экспорт», модалка
 * выбора, постановка выбранных ID в фоновую очередь и поллинг статуса.
 * Конфиг приходит через wp_localize_script → window.OneCatalogImport.
 */
(function () {
  'use strict';

  var cfg = window.OneCatalogImport;
  if (!cfg || !window.OneCatalogPicker) { return; }
  var T = cfg.i18n || {};

  function sprintf(tpl) {
    var args = Array.prototype.slice.call(arguments, 1);
    return String(tpl).replace(/%(\d+)\$s/g, function (_, n) { return String(args[n - 1]); });
  }

  var POLL_MS = 4000;
  var running = false;

  // Невидимый mount для модалки (НЕ display:none — внутри живёт fixed-оверлей).
  var mount = document.createElement('div');
  mount.id = 'onecatalog-picker';
  document.body.appendChild(mount);

  var picker = OneCatalogPicker.init({
    mount: '#onecatalog-picker',
    origin: cfg.origin,
    token: cfg.token,
    button: false,
    loadingText: T.loadingText,
    onSelect: function (selected) { importIds((selected || []).map(String)); }
  });

  // Кнопка после стандартной «Экспорт».
  var btn = document.createElement('a');
  btn.href = '#';
  btn.className = 'page-title-action';
  btn.id = 'onecatalog-products-btn';
  btn.textContent = T.buttonText || 'OneCatalog (Products)';
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    if (picker) { picker.open(); }
  });
  var actions = Array.prototype.slice.call(document.querySelectorAll('.page-title-action'));
  var exportBtn = actions.filter(function (a) { return /^(Экспорт|Export)/i.test(a.textContent.trim()); }).pop();
  var anchor = exportBtn || actions.pop();
  if (anchor) { anchor.insertAdjacentElement('afterend', btn); }

  function noticeEl() {
    var n = document.getElementById('onecatalog-notice');
    if (!n) {
      n = document.createElement('div');
      n.id = 'onecatalog-notice';
      n.className = 'notice notice-info';
      n.innerHTML = '<p></p>';
      var place = document.querySelector('.wp-header-end') || document.querySelector('.wrap h1');
      if (place) { place.insertAdjacentElement('afterend', n); }
    }
    return n.querySelector('p');
  }

  function api(url, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce }, options.headers || {});
    return fetch(url, options).then(function (r) { return r.json(); });
  }

  function summarize(ids, log) {
    var byId = {};
    (log || []).forEach(function (e) {
      if (ids.indexOf(e.identifier) !== -1 && !byId[e.identifier]) { byId[e.identifier] = e; }
    });
    var ok = 0;
    var errors = [];
    ids.forEach(function (id) {
      var e = byId[id];
      if (e && (e.status === 'created' || e.status === 'updated')) { ok++; }
      else if (e && e.error) { errors.push(id + ' (' + e.error + ')'); }
    });
    return { ok: ok, errors: errors };
  }

  function finish(out, ids, sum) {
    running = false;
    btn.style.pointerEvents = '';
    out.innerHTML = sprintf(T.done, '<b>' + sum.ok + '</b>', ids.length)
      + (sum.errors.length ? ' ' + T.errorsLabel + ' ' + sum.errors.join(', ') + '.' : '')
      + ' <a href="#" onclick="location.reload();return false;">' + T.refresh + '</a>';
  }

  function importIds(ids) {
    if (!ids || !ids.length || running) { return; }
    running = true;
    btn.style.pointerEvents = 'none';
    var out = noticeEl();
    out.textContent = sprintf(T.queueing, ids.length);

    api(cfg.restImport, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids: ids })
    }).then(function (data) {
      if (data.code) {
        running = false;
        btn.style.pointerEvents = '';
        out.textContent = T.errorPrefix + (data.message || data.code);
        return;
      }
      if (data.sync) { // без Action Scheduler — выполнено сразу
        finish(out, ids, summarize(ids, (data.results || []).map(function (r) {
          return { identifier: r.identifier, status: r.status, error: r.error || '' };
        })));
        return;
      }
      out.textContent = sprintf(T.queued, data.queued, data.batches, data.step);

      var poll = setInterval(function () {
        api(cfg.restStatus).then(function (st) {
          if (st.code) { return; }
          var left = (st.pending || 0) + (st.running || 0);
          if (left > 0) {
            out.textContent = sprintf(T.progress, left, data.batches);
            return;
          }
          clearInterval(poll);
          finish(out, ids, summarize(ids, st.log));
        });
      }, POLL_MS);
    }).catch(function (e) {
      running = false;
      btn.style.pointerEvents = '';
      out.textContent = T.networkError + e;
    });
  }

  // Публичный хук для отладки/автотестов.
  window.OneCatalogImportUI = { importIds: importIds };
})();
