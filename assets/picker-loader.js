/**
 * OneCatalog Picker loader (§2.4): встраивает iframe виджета выбора товаров и
 * принимает выбор через postMessage. Выбор — по productPublicIds (стабильные ключи).
 *
 * Надёжность: доверяем сообщениям от НАШЕГО iframe (e.source), а не только по строке
 * origin; разбираем e.data, если это JSON-строка; своя кнопка закрытия (×) и Esc —
 * чтобы модалку всегда можно было закрыть, даже если виджет не прислал ONECATALOG_CLOSE.
 */
(function () {
    'use strict';

    window.OneCatalogPicker = {
        /**
         * @param {{pickerBase:string, token:string, parentOrigin:string}} cfg
         * @param {function(string[])} onSelected
         */
        open: function (cfg, onSelected) {
            var base = String(cfg.pickerBase || '').replace(/\/+$/, '');
            if (!base) { return; }

            var url = base + '/picker.html'
                + '?token=' + encodeURIComponent(cfg.token || '')
                + '&parentOrigin=' + encodeURIComponent(cfg.parentOrigin || '');

            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;';

            var frame = document.createElement('iframe');
            frame.src = url;
            frame.setAttribute('sandbox', 'allow-scripts allow-forms allow-same-origin allow-popups');
            frame.style.cssText =
                'position:absolute;top:4%;left:4%;width:92%;height:92%;border:0;'
                + 'background:#fff;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.3);';

            // Собственная кнопка закрытия — работает всегда, независимо от виджета.
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.setAttribute('aria-label', 'Close');
            closeBtn.innerHTML = '×';
            closeBtn.style.cssText =
                'position:absolute;top:calc(4% - 14px);right:calc(4% - 14px);width:30px;height:30px;'
                + 'border:0;border-radius:50%;background:#fff;color:#333;font:20px/30px sans-serif;'
                + 'cursor:pointer;box-shadow:0 1px 6px rgba(0,0,0,.4);z-index:1;';

            overlay.appendChild(frame);
            overlay.appendChild(closeBtn);
            document.body.appendChild(overlay);

            function cleanup() {
                window.removeEventListener('message', handler);
                document.removeEventListener('keydown', onKey);
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            }

            function parseData(d) {
                if (typeof d === 'string') { try { return JSON.parse(d); } catch (e) { return {}; } }
                return d || {};
            }

            function handler(e) {
                // Доверяем только сообщениям от нашего iframe (надёжнее строкового origin).
                if (e.source !== frame.contentWindow && e.origin !== base) { return; }
                var data = parseData(e.data);
                var type = data.type || data.event;
                if (type === 'ONECATALOG_SELECTED') {
                    var ids = data.productPublicIds || data.publicIds || [];
                    cleanup();
                    if (typeof onSelected === 'function') { onSelected(ids); }
                } else if (type === 'ONECATALOG_CLOSE') {
                    cleanup();
                }
            }
            function onKey(e) { if (e.key === 'Escape' || e.keyCode === 27) { cleanup(); } }

            window.addEventListener('message', handler);
            document.addEventListener('keydown', onKey);
            closeBtn.addEventListener('click', cleanup);
            overlay.addEventListener('click', function (ev) { if (ev.target === overlay) { cleanup(); } });
        }
    };
})();
