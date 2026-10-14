// Ядро страницы OSI: вкладки, адрес в hash и общий API для модулей.
//
// Как добавить функцию: положить файл в osi/modules/ (index.php подключит его сам) и вызвать
//   OSI.register({
//     id: 'my-tool',            // попадёт в адрес: #my-tool/параметр
//     title: 'Моя вкладка',
//     order: 50,                // место среди вкладок, меньше = левее
//     mount(root, api) {},      // один раз, при первом открытии вкладки
//     show(param, api) {},      // при каждом открытии; param — часть адреса после «/»
//   });
// api: data, layer(n), layers(), h(), on(), emit(), go(), setParam(), shuffle()
(function () {
  const data = window.OSI_DATA;
  const modules = [];
  const listeners = {};
  const state = { current: null, mounted: new Set() };

  // Создание элементов: h('div', { class: 'x', onClick: fn, style: { '--lc': c } }, 'текст', child)
  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v == null || v === false) return;
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'style' && typeof v === 'object') {
        Object.entries(v).forEach(([prop, val]) => el.style.setProperty(prop, val));
      } else if (k.startsWith('on') && typeof v === 'function') {
        el.addEventListener(k.slice(2).toLowerCase(), v);
      } else el.setAttribute(k, v === true ? '' : v);
    });
    kids.flat(Infinity).forEach((c) => {
      if (c == null || c === false) return;
      el.append(c.nodeType ? c : String(c));
    });
    return el;
  }

  function shuffle(arr) {
    const a = arr.slice();
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
  }

  const api = {
    data,
    h,
    shuffle,
    layer: (n) => data.layers.find((l) => l.n === Number(n)),
    layers: () => data.layers.slice(), // L7 → L1
    on(ev, fn) { (listeners[ev] = listeners[ev] || []).push(fn); },
    emit(ev, payload) { (listeners[ev] || []).forEach((fn) => fn(payload)); },
    // Открыть вкладку (с записью в историю браузера)
    go(id, param) { location.hash = id + (param != null && param !== '' ? '/' + param : ''); },
    // Обновить параметр текущей вкладки без новой записи в истории
    setParam(param) {
      if (!state.current) return;
      const hash = '#' + state.current.id + (param != null && param !== '' ? '/' + param : '');
      if (location.hash !== hash) history.replaceState(null, '', hash);
    },
  };

  window.OSI = {
    api,
    register(mod) {
      if (!mod || !mod.id || modules.some((m) => m.id === mod.id)) return;
      modules.push(Object.assign({ order: 100, title: mod.id }, mod));
    },
  };

  function boot() {
    const tabsEl = document.getElementById('osiTabs');
    const panelsEl = document.getElementById('osiPanels');
    if (!tabsEl || !panelsEl || !data) return;

    modules.sort((a, b) => a.order - b.order);
    modules.forEach((m) => {
      m.tab = h('button', { type: 'button', class: 'osi-tab', role: 'tab', onClick: () => api.go(m.id) }, m.title);
      m.panel = h('section', { class: 'osi-panel', role: 'tabpanel', hidden: true, 'data-module': m.id });
      tabsEl.append(m.tab);
      panelsEl.append(m.panel);
    });

    function route() {
      const [id, ...rest] = decodeURIComponent(location.hash.slice(1)).split('/');
      const mod = modules.find((m) => m.id === id) || modules[0];
      if (!mod) return;
      const param = rest.join('/');

      if (state.current !== mod) {
        modules.forEach((m) => {
          const on = m === mod;
          m.panel.hidden = !on;
          m.tab.classList.toggle('active', on);
          m.tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (state.current && state.current.hide) state.current.hide(api);
        state.current = mod;
      }
      if (!state.mounted.has(mod.id)) {
        state.mounted.add(mod.id);
        if (mod.mount) mod.mount(mod.panel, api);
      }
      if (mod.show) mod.show(param, api);
      api.emit('tab', mod.id);
    }

    window.addEventListener('hashchange', route);
    route();
  }

  // Модули подключаются следом (defer) и регистрируются раньше DOMContentLoaded, поэтому ждём его,
  // даже если документ уже в состоянии interactive
  if (document.readyState === 'complete') setTimeout(boot);
  else document.addEventListener('DOMContentLoaded', boot);
})();
