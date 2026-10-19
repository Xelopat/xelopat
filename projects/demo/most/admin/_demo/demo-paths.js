/* Сайт собран под подпапкой (window.__DEMO_BASE), но часть путей в нём корневые: обычные <a href="/..">,
   <img src="/images/..">. Дописываем подпапку к таким путям, в том числе у элементов, которые появляются позже.
   В SPA с хеш-роутером (window.__DEMO_HASH) корневой путь без расширения файла становится хеш-маршрутом. */
(function () {
  'use strict';
  var BASE = window.__DEMO_BASE || '';
  if (!BASE) return;
  var ATTRS = ['href', 'src', 'poster'];
  // Абсолютные адреса бэкенда, которые остались в данных страницы: [откуда, куда]
  var REWRITE = window.__DEMO_REWRITE || [];
  var ROOT = window.__DEMO_ROOT || '';
  var HASH = !!window.__DEMO_HASH;
  var ORIG = window.__DEMO_ORIGBASE || '';
  var FILE = /\.[a-z0-9]{2,5}([?#]|$)/i;

  function fix(v) {
    if (!v) return v;
    for (var i = 0; i < REWRITE.length; i++) {
      if (v.indexOf(REWRITE[i][0]) === 0) return REWRITE[i][1] + v.slice(REWRITE[i][0].length);
    }
    if (v.charAt(0) !== '/' || v.charAt(1) === '/') return v;
    // Путь уже внутри демо проекта (например, медиа сайта из админки): не трогаем
    if (ROOT && v.indexOf(ROOT) === 0) return v;
    if (v === BASE || v.indexOf(BASE + '/') === 0 || v.indexOf(BASE + '?') === 0 || v.indexOf(BASE + '#') === 0) return v;
    if (HASH && !FILE.test(v)) return BASE + '/#' + v;
    // Файл из исходной подпапки приложения (на проде админка жила в /admin/): кладём в папку демо
    if (ORIG && v.indexOf(ORIG + '/') === 0) return BASE + v.slice(ORIG.length);
    return BASE + v;
  }
  function fixSrcset(v) {
    return v.split(',').map(function (part) {
      var bits = part.trim().split(/\s+/);
      bits[0] = fix(bits[0]);
      return bits.join(' ');
    }).join(', ');
  }
  // Плашку «Демо» не трогаем: её ссылки уже полные
  function own(el) { return el.closest && el.closest('#xp-demo-bar'); }
  function fixEl(el) {
    if (el.nodeType !== 1 || own(el)) return;
    ATTRS.forEach(function (a) {
      var v = el.getAttribute(a);
      if (v && el.tagName !== 'SCRIPT') { var f = fix(v); if (f !== v) el.setAttribute(a, f); }
    });
    var ss = el.getAttribute('srcset');
    if (ss) { var f2 = fixSrcset(ss); if (f2 !== ss) el.setAttribute('srcset', f2); }
    // Фоновые картинки в инлайн-стилях: url(/images/..)
    var st = el.getAttribute('style');
    if (st && st.indexOf('url(') >= 0) {
      var f3 = st.replace(/url\((["']?)([^)"']+)\1\)/g, function (m, q, u) { return 'url(' + q + fix(u) + q + ')'; });
      if (f3 !== st) el.setAttribute('style', f3);
    }
    if (el.tagName === 'IMG') sizeNextImage(el);
  }

  // next/image без оптимизатора отдаёт файл в полный размер, а на настоящем сайте srcset даёт картинке
  // ширину из атрибута width. Повторяем это: srcset с реальной шириной файла и sizes по атрибуту width.
  function sizeNextImage(img) {
    if (img.getAttribute('data-nimg') !== '1' || img.hasAttribute('srcset') || !img.getAttribute('width')) return;
    var apply = function () {
      if (!img.naturalWidth || img.hasAttribute('srcset')) return;
      img.setAttribute('sizes', img.getAttribute('width') + 'px');
      img.setAttribute('srcset', (img.getAttribute('src') || '') + ' ' + img.naturalWidth + 'w');
    };
    if (img.complete) apply(); else img.addEventListener('load', apply, { once: true });
  }

  function fixTree(root) {
    if (root.nodeType !== 1) return;
    fixEl(root);
    root.querySelectorAll('[href],[src],[srcset],[poster],[style]').forEach(fixEl);
  }

  new MutationObserver(function (list) {
    list.forEach(function (m) {
      if (m.type === 'attributes') fixEl(m.target);
      else m.addedNodes.forEach(fixTree);
    });
  }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['href', 'src', 'srcset', 'poster', 'style'] });
  if (document.readyState !== 'loading') fixTree(document.documentElement);
  else document.addEventListener('DOMContentLoaded', function () { fixTree(document.documentElement); });

  // Переход по ссылке, которую React успел переписать обратно на корневой путь
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || own(a) || a.target === '_blank' || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey) return;
    var href = a.getAttribute('href');
    var fixed = fix(href);
    if (fixed !== href) { e.preventDefault(); location.href = fixed; }
  }, true);
})();
