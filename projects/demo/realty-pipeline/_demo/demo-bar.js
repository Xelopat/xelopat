/* Плашка демо внизу слева: кнопка «назад» и переключение между частями проекта.
   Назад ведёт на страницу проекта, а из ботов и игр на общий список (оттуда на проект).
   Настройка (необязательно): window.__DEMO_BAR = { title, titleHref, links: [{ label, href, current }] }. null скрывает плашку. */
(function () {
  'use strict';
  var HUBS = { bots: 'Ко всем ботам', games: 'Ко всем играм' };
  var MEMBERS = { 'pay-bot': 'bots', 'natal-bot': 'bots', 'radar-bot': 'bots', 'booking-bot': 'bots' };

  function back() {
    var p = location.pathname.split('/').filter(Boolean);           // projects, demo, имя, ...
    if (p[0] !== 'projects' || p[1] !== 'demo' || !p[2]) return null;
    var name = p[2], sub = p[3];
    if (MEMBERS[name]) return { href: '/projects/demo/' + MEMBERS[name] + '/', label: HUBS[MEMBERS[name]] };
    if (name === 'games' && sub && sub !== 'index.html' && sub !== '_demo' && sub !== 'covers') return { href: '/projects/demo/games/', label: HUBS.games };
    return { href: '/projects?demo=' + encodeURIComponent('/projects/demo/' + name + '/'), label: 'К проекту' };
  }

  function mount() {
    var cfg = window.__DEMO_BAR;
    if (cfg === null || document.getElementById('xp-demo-bar')) return;
    if (window.top !== window || /[?&]shot/.test(location.search)) return;
    var b = back();
    if (!b && !cfg) return;

    var st = document.createElement('style');
    st.textContent =
      '#xp-demo-bar{position:fixed;left:14px;bottom:14px;z-index:2147483647;display:flex;align-items:center;gap:6px;padding:6px;max-width:calc(100vw - 28px);' +
      'background:rgba(17,17,21,.9);border:1px solid rgba(255,255,255,.1);border-radius:16px;font:500 13px/1 Inter,system-ui,sans-serif;' +
      'box-shadow:0 14px 40px rgba(0,0,0,.45),inset 0 1px 0 rgba(255,255,255,.06);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px)}' +
      '#xp-demo-bar a{text-decoration:none;white-space:nowrap}' +
      '#xp-demo-bar .xp-back{display:flex;align-items:center;gap:9px;padding:5px 15px 5px 5px;border-radius:12px;color:#1b1606;font-weight:700;' +
      'background:linear-gradient(180deg,#ffd75a,#f2b92f);box-shadow:0 6px 18px -6px rgba(249,201,64,.65),inset 0 1px 0 rgba(255,255,255,.5);transition:transform .18s ease,box-shadow .18s ease}' +
      '#xp-demo-bar .xp-back:hover{transform:translateY(-1px);box-shadow:0 10px 24px -6px rgba(249,201,64,.8),inset 0 1px 0 rgba(255,255,255,.5)}' +
      '#xp-demo-bar .xp-back:focus-visible,#xp-demo-bar .xp-link:focus-visible{outline:2px solid #fff;outline-offset:2px}' +
      '#xp-demo-bar .xp-ic{position:relative;display:block;width:30px;height:30px;border-radius:9px;overflow:hidden;background:rgba(27,22,6,.14);transition:background .18s ease}' +
      '#xp-demo-bar .xp-back:hover .xp-ic{background:rgba(27,22,6,.9)}' +
      '#xp-demo-bar .xp-ic svg{position:absolute;left:50%;top:50%;width:16px;height:16px;margin:-8px 0 0 -8px;transition:transform .3s cubic-bezier(.5,0,.2,1),color .18s ease;color:#1b1606}' +
      '#xp-demo-bar .xp-ic svg+svg{transform:translateX(26px)}' +
      '#xp-demo-bar .xp-back:hover .xp-ic svg{transform:translateX(-26px);color:#f9c940}' +
      '#xp-demo-bar .xp-back:hover .xp-ic svg+svg{transform:translateX(0)}' +
      '#xp-demo-bar .xp-tag{padding:6px 9px;color:#f9c940;font:400 11px "Space Mono",ui-monospace,monospace}' +
      '#xp-demo-bar .xp-link{padding:9px 12px;border-radius:10px;color:#efeff1;transition:background .15s ease}' +
      '#xp-demo-bar .xp-link:hover{background:rgba(255,255,255,.08)}' +
      '#xp-demo-bar .xp-link.on{background:rgba(249,201,64,.16);color:#f9c940}' +
      '@media(max-width:560px){#xp-demo-bar{left:10px;bottom:10px;gap:4px;padding:5px}#xp-demo-bar .xp-tag{display:none}#xp-demo-bar .xp-back{padding-right:12px}#xp-demo-bar .xp-link{padding:8px 9px;font-size:12px}}';
    document.head.appendChild(st);

    var bar = document.createElement('div');
    bar.id = 'xp-demo-bar';
    bar.setAttribute('role', 'navigation');
    bar.setAttribute('aria-label', 'Демо');
    var arrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m11 6-6 6 6 6"/></svg>';
    if (b) {
      var a = document.createElement('a');
      a.className = 'xp-back'; a.href = b.href;
      a.innerHTML = '<span class="xp-ic">' + arrow + arrow + '</span><span>' + b.label + '</span>';
      bar.appendChild(a);
    }
    if (cfg) {
      if (cfg.title) {
        var tag = document.createElement(cfg.titleHref ? 'a' : 'span');
        tag.className = 'xp-tag'; if (cfg.titleHref) tag.href = cfg.titleHref;
        tag.textContent = cfg.title; bar.appendChild(tag);
      }
      (cfg.links || []).forEach(function (l) {
        // кнопки «Все проекты», «Все боты», «Все игры» заменила кнопка назад
        if (/^Все (проекты|боты|игры)$/.test(l.label) && b) return;
        var x = document.createElement('a');
        x.className = 'xp-link' + (l.current ? ' on' : ''); x.href = l.href; x.textContent = l.label;
        bar.appendChild(x);
      });
    }
    document.body.appendChild(bar);
  }
  // После загрузки: на страницах Next к этому времени гидратация уже прошла, лишний узел в body ей не мешает
  if (document.readyState === 'complete') setTimeout(mount, 300); else window.addEventListener('load', function () { setTimeout(mount, 300); });
})();
