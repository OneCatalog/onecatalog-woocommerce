/**
 * Лоадер OneCatalog Picker.
 *
 * Повторяет контракт https://tools.onecatalog.net/picker.js с двумя отличиями:
 *  - origin виджета задаётся через config.origin (вендорная тест-сборка берёт
 *    window.location.origin, что ломает встраивание на чужом домене);
 *  - реальный picker.html шлёт {productPublicIds, productIds} — поддерживаем
 *    оба, плюс поле products из вендорного примера.
 *
 * API: OneCatalogPicker.init({ mount, token, origin?, button?, onSelect }) → { open, close }
 */
(function () {
  'use strict';

  function init(config) {
    var WIDGET_ORIGIN = config.origin || 'https://tools.onecatalog.net';
    var mount = document.querySelector(config.mount);
    if (!mount) {
      console.error('[OneCatalogPicker] Mount element not found');
      return null;
    }
    var showButton = config.button !== false; // button:false — открытие только через .open()

    var host = document.createElement('div');
    var shadow = host.attachShadow({ mode: 'open' });

    shadow.innerHTML =
      '<style>' +
      ':host { all: initial; font-family: Arial, sans-serif; }' +
      '.oc-button { background:#111827; color:#fff; border:none; border-radius:8px; padding:10px 16px; font-size:14px; cursor:pointer; }' +
      '.oc-overlay { position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:2147483647; display:none; align-items:center; justify-content:center; }' +
      '.oc-modal { position:relative; width:100%; max-width:1200px; height:80vh; background:#fff; border-radius:16px; overflow:hidden; }' +
      '.oc-loading { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#6b7280; font-size:15px; }' +
      '.oc-iframe { position:relative; width:100%; height:100%; border:0; background:transparent; }' +
      '</style>' +
      (showButton ? '<button type="button" class="oc-button">Выбрать товары</button>' : '') +
      '<div class="oc-overlay"><div class="oc-modal"></div></div>';

    mount.appendChild(host);

    var button = shadow.querySelector('.oc-button');
    var overlay = shadow.querySelector('.oc-overlay');
    var modal = shadow.querySelector('.oc-modal');

    function open() {
      modal.innerHTML = '';

      var loading = document.createElement('div');
      loading.className = 'oc-loading';
      loading.textContent = config.loadingText || 'Loading OneCatalog…';
      modal.appendChild(loading);

      var iframe = document.createElement('iframe');
      var url = new URL('/picker.html', WIDGET_ORIGIN);
      url.searchParams.set('token', config.token);
      url.searchParams.set('parentOrigin', window.location.origin);
      iframe.src = url.toString();
      iframe.className = 'oc-iframe';
      iframe.sandbox = 'allow-scripts allow-forms allow-same-origin';
      iframe.addEventListener('load', function () {
        setTimeout(function () { if (loading.parentNode) { loading.remove(); } }, 400);
      });
      modal.appendChild(iframe);
      overlay.style.display = 'flex';
    }

    function close() {
      overlay.style.display = 'none';
      modal.innerHTML = '';
    }

    if (button) { button.addEventListener('click', open); }
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) { close(); }
    });

    window.addEventListener('message', function (event) {
      if (event.origin !== WIDGET_ORIGIN) { return; }
      if (event.data && event.data.type === 'ONECATALOG_SELECTED') {
        close();
        if (typeof config.onSelect === 'function') {
          var ids = event.data.productPublicIds || event.data.productIds || event.data.products || [];
          config.onSelect(ids);
        }
      }
      if (event.data && event.data.type === 'ONECATALOG_CLOSE') {
        close();
      }
    });

    return { open: open, close: close };
  }

  window.OneCatalogPicker = { init: init };
})();
