/* Плашка демо в углу: переключение между частями проекта и выход к списку проектов.
   Настройка: window.__DEMO_BAR = { title, links: [{ label, href, current }] } */
(function () {
  'use strict';
  function mount() {
    var cfg = window.__DEMO_BAR;
    if (!cfg || document.getElementById('xp-demo-bar')) return;
    var bar = document.createElement('div');
    bar.id = 'xp-demo-bar';
    bar.setAttribute('role', 'navigation');
    bar.setAttribute('aria-label', 'Демо');
    bar.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:2147483647;display:flex;align-items:center;gap:4px;padding:4px;'
      + 'background:rgba(21,21,24,.92);border:1px solid #333340;border-radius:8px;font:500 12px/1 Inter,system-ui,sans-serif;'
      + 'box-shadow:0 8px 24px rgba(0,0,0,.35);backdrop-filter:blur(6px);';
    var tag = document.createElement('span');
    tag.textContent = cfg.title || 'Демо';
    tag.style.cssText = 'padding:6px 8px;color:#f9c940;font-family:"Space Mono",ui-monospace,monospace;font-size:11px;';
    bar.appendChild(tag);
    (cfg.links || []).forEach(function (l) {
      var a = document.createElement('a');
      a.href = l.href;
      a.textContent = l.label;
      var on = l.current;
      a.style.cssText = 'padding:6px 9px;border-radius:5px;text-decoration:none;color:' + (on ? '#151518' : '#efeff1') + ';background:' + (on ? '#f9c940' : 'transparent') + ';';
      bar.appendChild(a);
    });
    document.body.appendChild(bar);
  }
  // После загрузки: на страницах Next к этому времени гидратация уже прошла, лишний узел в body ей не мешает
  if (document.readyState === 'complete') setTimeout(mount, 300); else window.addEventListener('load', function () { setTimeout(mount, 300); });
})();
