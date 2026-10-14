// Сетевая лаборатория: холст, меню, журнал и анимация пакетов.
// Логика сети живёт в net.js, окна устройств в windows.js, сохранение карт на сервере в maps.js.
//
// Управление: левая кнопка двигает устройство или холст, двойной щелчок открывает настройки,
// кабель тянется правой кнопкой от устройства к устройству (или левой от точки над ним, или с Shift),
// щелчок правой кнопкой открывает меню. На телефоне кабель тянется после долгого нажатия,
// долгое нажатие без движения открывает меню, у выделенного кабеля появляется кнопка ✕.
(function () {
  const NS = 'http://www.w3.org/2000/svg';
  const STORE = 'cs-lab:v2';
  const TICK_MS = 950;
  const LONG_PRESS_MS = 450;
  const MAX_DEVICES = 5000;
  const LC = { 7: '#ff8f8f', 6: '#f5a964', 5: '#f9c940', 4: '#61d1ad', 3: '#5aa7ff', 2: '#9d8ff0', 1: '#d68fe0' };
  // Приглушённые цвета: сеть различима, но не кричит
  const DOMAIN_COLORS = ['#5f9e8a', '#6f8fb8', '#b39a5a', '#a87878', '#8a80b0', '#7fa3a8', '#9c8a6c', '#8e7f9c', '#7f9c78'];
  // Четыре цвета на все пакеты: ARP (L2), ICMP, ошибка, прикладные службы
  const KIND_COLOR = { arp: '#c9a640', req: '#61d1ad', rep: '#61d1ad', err: '#e06c6c', dhcp: '#8fa6c8', dns: '#8fa6c8', tcp: '#8fa6c8', http: '#8fa6c8' };
  const KIND_TEXT = { arp: 'ARP', req: 'ICMP', rep: 'ICMP', err: 'ICMP', dhcp: 'DHCP', dns: 'DNS', tcp: 'TCP', http: 'HTTP' };
  const VIEWS = {
    l1: { label: 'L1', title: 'Физический вид: кабели и порты' },
    l2: { label: 'L2', title: 'Канальный вид: широковещательные домены и VLAN' },
    l3: { label: 'L3', title: 'Сетевой вид: подсети и IP-адреса' },
  };

  const S = {
    net: null, sim: null,
    view: 'l2',
    sel: null,          // id устройства или 'link:<id>'
    cam: { x: 0, y: 0, k: 1 },
    speed: 1,
    anim: null,
    preset: null,
    map: null,          // { id, name } карты на сервере, если она открыта
  };
  const E = {};

  /* ===== DOM-помощники ===== */
  function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    setAttrs(el, attrs);
    append(el, kids);
    return el;
  }
  function s(tag, attrs, ...kids) {
    const el = document.createElementNS(NS, tag);
    setAttrs(el, attrs);
    append(el, kids);
    return el;
  }
  function setAttrs(el, attrs) {
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v == null || v === false) return;
      if (k === 'class') el.setAttribute('class', v);
      else if (k === 'style' && typeof v === 'object') Object.entries(v).forEach(([p, val]) => el.style.setProperty(p, val));
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    });
  }
  function append(el, kids) {
    kids.flat(Infinity).forEach((c) => {
      if (c == null || c === false) return;
      el.append(c.nodeType ? c : String(c));
    });
  }
  const typeOf = (dev) => LAB.types[dev.type];
  const kindOf = (dev) => typeOf(dev).kind;
  const isTyping = (t) => t instanceof Element && !!t.closest('input, textarea, select, .term');

  /* ===== Иконки устройств (центр в 0,0) ===== */
  const ICONS = {
    pc: (c) => s('g', null,
      s('rect', { x: -22, y: -20, width: 44, height: 30, rx: 2, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      s('rect', { x: -17, y: -15, width: 34, height: 20, rx: 1, fill: c, opacity: 0.12 }),
      s('path', { d: 'M-6 10 L-8 17 L8 17 L6 10', fill: '#1e1e25', stroke: c, 'stroke-width': 2, 'stroke-linejoin': 'round' })),
    server: (c) => s('g', null,
      s('rect', { x: -16, y: -24, width: 32, height: 44, rx: 2, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      [-14, -3, 8].map((y) => s('g', null,
        s('line', { x1: -10, y1: y, x2: 4, y2: y, stroke: c, 'stroke-width': 2, opacity: 0.6 }),
        s('circle', { cx: 9, cy: y, r: 2, fill: c })))),
    switch: (c) => s('g', null,
      s('rect', { x: -28, y: -14, width: 56, height: 28, rx: 3, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      s('path', { d: 'M-16 -4 H14 M9 -8 L14 -4 L9 0 M16 5 H-14 M-9 1 L-14 5 L-9 9', fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })),
    router: (c) => s('g', null,
      s('circle', { r: 22, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      s('path', { d: 'M0 -14 V-4 M-4 -10 L0 -14 L4 -10 M0 14 V4 M-4 10 L0 14 L4 10 M-14 0 H-4 M-10 -4 L-14 0 L-10 4 M14 0 H4 M10 -4 L14 0 L10 4', fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })),
    hub: (c) => s('g', null,
      s('rect', { x: -26, y: -11, width: 52, height: 22, rx: 3, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      [-12, -4, 4, 12].map((x) => s('circle', { cx: x, cy: 0, r: 2.5, fill: c }))),
  };
  // Иконки нейтральные: тип читается по форме, цвет оставлен для уровней OSI и пакетов
  const ICON_COLOR = '#a4a8bb';
  function iconFor(type) {
    const t = LAB.types[type];
    if (typeof t.icon === 'function') return t.icon(s);
    const fn = ICONS[t.icon];
    if (fn) return fn(ICON_COLOR);
    return s('g', null,
      s('rect', { x: -22, y: -18, width: 44, height: 36, rx: 4, fill: '#1e1e25', stroke: ICON_COLOR, 'stroke-width': 2 }),
      s('text', { y: 5, 'text-anchor': 'middle', fill: ICON_COLOR, 'font-size': 14, 'font-family': 'var(--mono)' }, t.title.slice(0, 2)));
  }
  function miniIcon(type, w) {
    return s('svg', { viewBox: '-30 -26 60 52', width: w || 22, height: Math.round((w || 22) * 0.86), 'aria-hidden': 'true' }, iconFor(type));
  }

  /* ===== Хранение в браузере ===== */
  function save() {
    try { localStorage.setItem(STORE, JSON.stringify({ net: S.net, preset: S.preset, view: S.view, map: S.map })); } catch (e) { /* без хранилища просто не запоминаем */ }
  }
  function load() {
    try {
      const raw = localStorage.getItem(STORE) || localStorage.getItem('cs-lab:v1');
      if (!raw) return false;
      const data = JSON.parse(raw);
      S.net = LAB.Net.from(data.net);
      S.preset = data.preset || null;
      S.map = data.map && data.map.id ? data.map : null;
      if (VIEWS[data.view]) S.view = data.view;
      return true;
    } catch (e) {
      return false;
    }
  }

  function setNet(net, presetId, map) {
    stopAnim();
    closeMenus();
    if (LAB.Win) LAB.Win.closeAll();
    S.net = net;
    S.sim = new LAB.Sim(net);
    S.sel = null;
    S.preset = presetId || null;
    S.map = map || null;
    clearLog();
    save();
    renderAll();
    fit();
    fillTry();
  }

  function loadPreset(id) {
    const p = LAB.presets.find((x) => x.id === id);
    if (p) setNet(LAB.buildPreset(p), p.id);
  }

  // Изменились схема или настройки: таблицы ARP, MAC и аренды устаревают
  function changed() {
    stopAnim();
    S.sim.reset();
    save();
    renderAll();
  }

  // Узел сам поменял свои настройки (DHCP): сохранить и перерисовать без сброса таблиц
  function refreshed() {
    save();
    renderAll();
  }

  /* ===== Камера ===== */
  function applyCam() {
    const r = E.svg.getBoundingClientRect();
    if (!r.width) return;
    const w = r.width / S.cam.k, hh = r.height / S.cam.k;
    E.svg.setAttribute('viewBox', [S.cam.x - w / 2, S.cam.y - hh / 2, w, hh].join(' '));
  }
  function toWorld(e) {
    const r = E.svg.getBoundingClientRect();
    return { x: S.cam.x + (e.clientX - r.left - r.width / 2) / S.cam.k, y: S.cam.y + (e.clientY - r.top - r.height / 2) / S.cam.k };
  }
  function fit() {
    const r = E.svg.getBoundingClientRect();
    if (!r.width) return;
    const ds = S.net.devices;
    if (!ds.length) { S.cam = { x: 0, y: 0, k: 1 }; applyCam(); return; }
    let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
    ds.forEach((d) => { minX = Math.min(minX, d.x); maxX = Math.max(maxX, d.x); minY = Math.min(minY, d.y); maxY = Math.max(maxY, d.y); });
    minX -= 90; maxX += 90; minY -= 80; maxY += 100;
    const k = Math.min(r.width / (maxX - minX), r.height / (maxY - minY), 1.3);
    S.cam = { x: (minX + maxX) / 2, y: (minY + maxY) / 2, k: Math.max(0.05, k) };
    applyCam();
  }
  function zoom(f, around) {
    const r = E.svg.getBoundingClientRect();
    const k2 = Math.min(3, Math.max(0.05, S.cam.k * f));
    if (around) {
      const w = toWorld(around);
      S.cam.x = w.x - (around.clientX - r.left - r.width / 2) / k2;
      S.cam.y = w.y - (around.clientY - r.top - r.height / 2) / k2;
    }
    S.cam.k = k2;
    applyCam();
  }

  /* ===== Граф ===== */
  function domainColor(n) { return DOMAIN_COLORS[n % DOMAIN_COLORS.length]; }

  // Подпись порта: в L1 видны все, в L2 и L3 только важные (VLAN, адрес маршрутизатора),
  // остальные появляются при наведении на кабель или выделении устройства
  function portLabel(d, itf) {
    const kind = kindOf(d);
    if (S.view === 'l1') return { text: itf.name, key: true };
    if (S.view === 'l2' && kind === 'switch' && (itf.vlan || 1) !== 1) return { text: itf.name + ' v' + itf.vlan, key: true };
    if (S.view === 'l3' && kind === 'router') return { text: itf.ip ? '.' + itf.ip.split('.').pop() : 'без IP', key: true };
    return { text: itf.name, key: false };
  }

  function linkGeom(l) {
    const a = S.net.dev(l.a.dev), b = S.net.dev(l.b.dev);
    const len = Math.hypot(b.x - a.x, b.y - a.y) || 1;
    const t = Math.min(0.42, 44 / len);
    return {
      x1: a.x, y1: a.y, x2: b.x, y2: b.y,
      pa: { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t + 3 },
      pb: { x: b.x + (a.x - b.x) * t, y: b.y + (a.y - b.y) * t + 3 },
    };
  }

  function renderGraph() {
    const net = S.net;
    const dom = S.view === 'l1' ? null : net.domains();
    E.gLinks.replaceChildren();
    E.gNodes.replaceChildren();
    E.nodeEls = new Map();
    E.linkEls = new Map();
    E.stage.dataset.view = S.view;

    const linksFrag = document.createDocumentFragment();
    net.links.forEach((l) => {
      const a = net.dev(l.a.dev), b = net.dev(l.b.dev);
      const down = net.linkDown(l);
      const color = down ? '#55394a' : dom ? domainColor(dom.linkDomain[l.id]) : '#6a6c80';
      const gm = linkGeom(l);
      const hit = s('line', { class: 'lab-link__hit', x1: gm.x1, y1: gm.y1, x2: gm.x2, y2: gm.y2 });
      const line = s('line', { class: 'lab-link__line', x1: gm.x1, y1: gm.y1, x2: gm.x2, y2: gm.y2, stroke: color });
      const la = portLabel(a, a.ifaces[l.a.i]), lb = portLabel(b, b.ifaces[l.b.i]);
      const ta = s('text', { class: 'lab-port' + (la.key ? ' is-key' : '') + (a.ifaces[l.a.i].down ? ' is-down' : ''), x: gm.pa.x, y: gm.pa.y, 'text-anchor': 'middle' }, la.text);
      const tb = s('text', { class: 'lab-port' + (lb.key ? ' is-key' : '') + (b.ifaces[l.b.i].down ? ' is-down' : ''), x: gm.pb.x, y: gm.pb.y, 'text-anchor': 'middle' }, lb.text);
      const g = s('g', { class: 'lab-link' + (S.sel === 'link:' + l.id ? ' is-sel' : '') + (down ? ' is-down' : ''), 'data-link': l.id }, hit, line, ta, tb);
      E.linkEls.set(l.id, { g, hit, line, ta, tb });
      linksFrag.append(g);
    });
    E.gLinks.append(linksFrag);

    const nodesFrag = document.createDocumentFragment();
    net.devices.forEach((d) => {
      const t = typeOf(d);
      const g = s('g', {
        class: 'lab-node' + (S.sel === d.id ? ' is-sel' : ''),
        'data-node': d.id,
        transform: 'translate(' + d.x + ' ' + d.y + ')',
      });
      g.append(s('title', null, d.name + ', ' + t.title.toLowerCase() + '. Двойной щелчок: настройки'));
      g.append(s('rect', { class: 'lab-node__halo', x: -36, y: -46, width: 80, height: S.view === 'l1' ? 96 : 108, rx: 4 }));
      g.append(iconFor(d.type));
      g.append(s('g', { class: 'lab-node__plug' },
        s('title', null, 'Потяни к другому устройству, чтобы соединить кабелем'),
        s('circle', { cx: 0, cy: -34, r: 9, class: 'lab-node__plug-hit' }),
        s('circle', { cx: 0, cy: -34, r: 4.5, class: 'lab-node__plug-dot' })));
      const bar = s('g', { class: 'lab-bar', transform: 'translate(31 -24)' });
      for (let n = 7; n >= 1; n--) {
        const on = n >= t.layers[0] && n <= t.layers[1];
        bar.append(s('rect', { class: 'lab-seg' + (on ? ' is-in' : ''), 'data-l': n, x: 0, y: (7 - n) * 7, width: 5, height: 5, rx: 0.5, fill: on ? LC[n] : '#2c2c38' }));
      }
      g.append(bar);
      const svc = d.services ? ['dns', 'dhcp', 'http'].filter((k) => d.services[k].on) : [];
      if (svc.length && S.view !== 'l1') g.append(s('text', { class: 'lab-node__svc', x: -34, y: -22 }, svc.map((k) => k.toUpperCase()).join(' ')));
      g.append(s('text', { class: 'lab-node__name', y: 38, 'text-anchor': 'middle' }, d.name));
      const sub = nodeSub(d);
      if (sub) g.append(s('text', { class: 'lab-node__sub' + (sub.err ? ' is-err' : ''), y: 51, 'text-anchor': 'middle' }, sub.text));
      E.nodeEls.set(d.id, g);
      nodesFrag.append(g);
    });
    E.gNodes.append(nodesFrag);
    markNear();
    drawLinkDel();
  }

  // Подсветить подписи портов у выделенного устройства
  function markNear() {
    E.gLinks.querySelectorAll('.is-near').forEach((x) => x.classList.remove('is-near'));
    if (!S.sel || S.sel.startsWith('link:')) return;
    S.net.links.forEach((l) => {
      if (l.a.dev === S.sel || l.b.dev === S.sel) {
        const el = E.linkEls.get(l.id);
        if (el) el.g.classList.add('is-near');
      }
    });
  }

  // Сдвинуть одно устройство и его кабели, не перерисовывая всю схему
  function moveNode(d, links) {
    const g = E.nodeEls.get(d.id);
    if (g) g.setAttribute('transform', 'translate(' + d.x + ' ' + d.y + ')');
    links.forEach((l) => {
      const el = E.linkEls.get(l.id);
      if (!el) return;
      const gm = linkGeom(l);
      [el.hit, el.line].forEach((ln) => { ln.setAttribute('x1', gm.x1); ln.setAttribute('y1', gm.y1); ln.setAttribute('x2', gm.x2); ln.setAttribute('y2', gm.y2); });
      el.ta.setAttribute('x', gm.pa.x); el.ta.setAttribute('y', gm.pa.y);
      el.tb.setAttribute('x', gm.pb.x); el.tb.setAttribute('y', gm.pb.y);
    });
    if (S.sel && S.sel.startsWith('link:')) drawLinkDel();
  }

  // Кнопка ✕ в середине выделенного кабеля
  // Кнопка нужна только на сенсорном экране: мышью кабель удаляется через правую кнопку или Delete
  function drawLinkDel() {
    E.gSel.replaceChildren();
    if (!S.touch || !S.sel || !S.sel.startsWith('link:')) return;
    const l = S.net.link(S.sel.slice(5));
    if (!l) return;
    const gm = linkGeom(l);
    E.gSel.append(s('g', { class: 'lab-link-del', transform: 'translate(' + (gm.x1 + gm.x2) / 2 + ' ' + (gm.y1 + gm.y2) / 2 + ')' },
      s('title', null, 'Удалить кабель (Delete)'),
      s('rect', { x: -10, y: -10, width: 20, height: 20, rx: 3 }),
      s('path', { d: 'M-4 -4 L4 4 M4 -4 L-4 4' })));
  }

  function select(id) {
    if (S.sel === id) return;
    const prev = S.sel;
    S.sel = id;
    [prev, id].forEach((x) => {
      if (!x) return;
      const el = x.startsWith('link:') ? (E.linkEls.get(x.slice(5)) || {}).g : E.nodeEls.get(x);
      if (el) el.classList.toggle('is-sel', x === id);
    });
    markNear();
    drawLinkDel();
  }

  function nodeSub(d) {
    const kind = kindOf(d);
    const i0 = d.ifaces[0];
    if (S.view === 'l1') return null;
    if (S.view === 'l2') {
      if (kind === 'host') return { text: i0.mac };
      if (kind === 'switch') {
        const vl = [...new Set(d.ifaces.filter((i) => i.link).map((i) => i.vlan || 1))].sort((a, b) => a - b);
        return { text: 'VLAN ' + (vl.length ? vl.join(', ') : '1') };
      }
      return null;
    }
    if (kind === 'host') {
      if (!i0.ip) return { text: d.dhcp ? 'ждёт DHCP' : 'нет IP', err: !d.dhcp };
      return { text: i0.ip + '/' + i0.prefix };
    }
    if (kind === 'router') {
      const n = d.ifaces.filter((i) => i.ip && LAB.ifUp(i)).length;
      return { text: n + ' сет' + (n === 1 ? 'ь' : n > 1 && n < 5 ? 'и' : 'ей') + ', ' + d.routes.length + ' маршр.' };
    }
    return null;
  }

  function renderLegend() {
    E.legend.replaceChildren();
    if (S.view === 'l1') { E.legend.hidden = true; return; }
    const dom = S.net.domains();
    if (!dom.info.length) { E.legend.hidden = true; return; }
    E.legend.hidden = false;
    const rows = dom.info.slice(0, 12).map((inf, n) => {
      let text;
      if (S.view === 'l2') {
        const v = [...inf.vlans];
        text = 'домен ' + (n + 1) + (v.length ? ', VLAN ' + v.join(', ') : '');
      } else {
        const nets = [...inf.nets];
        text = nets.length ? nets.join(', ') : 'без IP';
        if (nets.length > 1) text += ' (разные подсети!)';
      }
      return h('div', { class: 'lab-legend__row' }, h('i', { style: { background: domainColor(n) } }), text);
    });
    if (dom.info.length > 12) rows.push(h('div', { class: 'lab-legend__row' }, 'и ещё ' + (dom.info.length - 12)));
    E.legend.append(...rows);
  }

  /* ===== Тулбар ===== */
  const TB_ICONS = {
    export: 'M12 4v11M7 10l5 5 5-5M5 19h14',
    import: 'M12 20V9M7 14l5-5 5 5M5 5h14',
    clear: 'M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12',
    full: 'M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5',
    exit: 'M9 4v5H4M20 9h-5V4M15 20v-5h5M4 15h5v5',
  };
  function tbIcon(name) {
    return s('svg', { viewBox: '0 0 24 24', width: 16, height: 16, 'aria-hidden': 'true' },
      s('path', { d: TB_ICONS[name], fill: 'none', stroke: 'currentColor', 'stroke-width': 1.8, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
  }
  function iconBtn(name, title, onClick, extra) {
    return h('button', Object.assign({ type: 'button', class: 'lab-btn lab-btn--icon', title, 'aria-label': title, onClick }, extra || {}), tbIcon(name));
  }
  function buildToolbar() {
    const palette = h('div', { class: 'lab-group' },
      Object.values(LAB.types).map((t) => h('button', {
        type: 'button', class: 'lab-add', title: 'Добавить ' + t.title.toLowerCase() + ': ' + t.hint, 'aria-label': 'Добавить: ' + t.title,
        onClick: () => addDevice(t.type),
      }, miniIcon(t.type), h('span', { class: 'lab-add__t' }, t.title))));

    E.viewBtns = {};
    const views = h('div', { class: 'lab-group lab-seg-ctl' },
      Object.entries(VIEWS).map(([id, v]) => (E.viewBtns[id] = h('button', { type: 'button', title: v.title, onClick: () => setView(id) }, v.label))));

    E.presetSel = h('select', { class: 'lab-select', 'aria-label': 'Готовые схемы', onChange: (e) => { if (e.target.value) loadPreset(e.target.value); e.target.value = ''; } },
      h('option', { value: '' }, 'Готовые схемы'),
      LAB.presets.map((p) => h('option', { value: p.id }, (p.task ? 'Задача: ' : '') + p.title.replace(/^Задача: /, ''))));
    const file = h('input', { type: 'file', accept: '.json,application/json', hidden: true, onChange: importFile });
    E.mapsBtn = h('button', { type: 'button', class: 'lab-btn', 'data-pop-toggle': '', onClick: (e) => { e.stopPropagation(); if (LAB.Maps) LAB.Maps.toggle(); }, title: 'Сохранённые карты в аккаунте' }, 'Мои карты');
    E.fullBtn = iconBtn('full', 'На весь экран (Esc чтобы выйти)', toggleFull);
    const files = h('div', { class: 'lab-group lab-group--end' },
      E.presetSel,
      E.mapsBtn,
      iconBtn('export', 'Экспорт: скачать схему в JSON', exportFile),
      iconBtn('import', 'Импорт: загрузить схему из JSON', () => file.click()),
      iconBtn('clear', 'Очистить холст', () => { if (confirm('Очистить холст?')) setNet(new LAB.Net(), null); }),
      E.fullBtn,
      file);

    E.toolbar.append(palette, views, files);
  }

  function setView(id) {
    S.view = id;
    Object.entries(E.viewBtns).forEach(([k, b]) => b.classList.toggle('active', k === id));
    save();
    renderGraph();
    renderLegend();
  }

  // Короткое сообщение в углу холста; без текста скрывается
  let hintTimer = null;
  function hint(text, level) {
    clearTimeout(hintTimer);
    E.hint.textContent = text || '';
    E.hint.className = 'lab-hint' + (level ? ' is-' + level : '');
    E.hint.hidden = !text;
    if (text) hintTimer = setTimeout(() => { E.hint.hidden = true; }, 3500);
  }

  /* ===== Полный экран ===== */
  function isFull() { return E.root.classList.contains('lab--full'); }
  function setFullClass(on) {
    E.root.classList.toggle('lab--full', on);
    document.documentElement.classList.toggle('lab-noscroll', on);
    E.fullBtn.replaceChildren(tbIcon(on ? 'exit' : 'full'));
    E.fullBtn.title = on ? 'Свернуть (Esc)' : 'На весь экран (Esc чтобы выйти)';
    requestAnimationFrame(() => { applyCam(); if (LAB.Win) LAB.Win.clamp(); });
  }
  function toggleFull() {
    if (!isFull()) {
      setFullClass(true);
      if (E.root.requestFullscreen) E.root.requestFullscreen().catch(() => { /* остаёмся в развёрнутом окне */ });
    } else {
      if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
      setFullClass(false);
    }
  }
  document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement && isFull()) setFullClass(false);
  });

  /* ===== Действия со схемой ===== */
  function addDevice(type, at) {
    if (S.net.devices.length >= MAX_DEVICES) { hint('На карте уже ' + MAX_DEVICES + ' устройств, это предел', 'err'); return; }
    const n = S.net.devices.length;
    const p = at || { x: S.cam.x + ((n * 37) % 120) - 60, y: S.cam.y + ((n * 53) % 100) - 50 };
    const d = S.net.add(type, Math.round(p.x / 10) * 10, Math.round(p.y / 10) * 10);
    S.sel = d.id;
    changed();
    hint(d.name + ' добавлен. Щёлкни по нему правой кнопкой, потом по другому устройству, чтобы соединить', 'ok');
  }

  function removeDevice(id) {
    const d = S.net.dev(id);
    if (!d) return;
    if (LAB.Win) LAB.Win.close(id);
    S.net.remove(id);
    if (S.sel === id) S.sel = null;
    changed();
    hint(d.name + ' удалён', 'ok');
  }

  function removeLink(id) {
    S.net.disconnect(id);
    if (S.sel === 'link:' + id) S.sel = null;
    changed();
    hint('Кабель удалён', 'ok');
  }

  function connect(aId, bId) {
    const r = S.net.connect(aId, bId);
    if (r.error) { hint(r.error, 'err'); return; }
    changed();
    const a = S.net.dev(r.link.a.dev), b = S.net.dev(r.link.b.dev);
    hint('Кабель: ' + a.name + ' ' + a.ifaces[r.link.a.i].name + ' ↔ ' + b.name + ' ' + b.ifaces[r.link.b.i].name, 'ok');
  }

  /* ===== Мышь и касания на холсте ===== */
  function bindStage() {
    let drag = null, pan = null, wire = null, press = null, lastTap = null, wireEnded = 0;

    const startWire = (id, e) => {
      wire = { from: id, moved: false, x: e.clientX, y: e.clientY, btn: e.button, pointer: e.pointerType };
      E.stage.classList.add('is-wiring');
    };
    const endWire = () => {
      wire = null;
      wireEnded = performance.now();
      E.gPreview.replaceChildren();
      E.stage.classList.remove('is-wiring');
    };
    // Устройство под курсором ищем по координатам схемы: ближайшее, в пределах его рамки
    const nodeAt = (e) => {
      const w = toWorld(e);
      let best = null, bd = Infinity;
      S.net.devices.forEach((d) => {
        const dx = w.x - d.x, dy = w.y - d.y;
        if (Math.abs(dx) > 40 || dy < -46 || dy > 62) return;
        const dist = dx * dx + dy * dy;
        if (dist < bd) { bd = dist; best = d.id; }
      });
      return best;
    };

    // Закончить кабель в точке e: над другим устройством соединить.
    // Щелчок мышью без движения не заканчивает кабель, а «прилепляет» его к курсору: дальше щёлкни по второму
    // устройству. Так кабель тянется и без зажатой кнопки, а значит без жестов мыши браузера.
    // Повторный щелчок по тому же устройству открывает его меню, по пустому месту или Esc отменяет.
    const finishWire = (e, cancel) => {
      const target = cancel ? null : nodeAt(e);
      const from = wire.from;
      const moved = wire.moved || Math.abs(e.clientX - wire.x) + Math.abs(e.clientY - wire.y) > 8;
      if (!cancel && !moved && !wire.sticky && wire.pointer === 'mouse') {
        wire.sticky = true;
        const d = S.net.dev(from);
        hint('Кабель от ' + (d ? d.name : '?') + ': щёлкни по второму устройству. Щелчок по этому же: меню, Esc: отмена', 'ok');
        return;
      }
      const wasSticky = wire.sticky;
      endWire();
      if (target && target !== from) connect(from, target);
      else if (cancel) return;
      else if (target === from && (wasSticky || !moved)) {
        // На следующем такте: это же нажатие ещё дойдёт до document и закрыло бы меню
        const at = { clientX: e.clientX, clientY: e.clientY };
        select(from);
        setTimeout(() => openMenu(at, { node: from }), 0);
      }
      else if (!wasSticky && moved) hint('Отпусти кабель над другим устройством', 'err');
    };

    E.svg.addEventListener('contextmenu', (e) => e.preventDefault());
    // Жесты мыши в браузере (Яндекс, Opera, Vivaldi) съедают движение и отпускание правой кнопки,
    // но в конце обычно присылают contextmenu с координатами: по нему и заканчиваем кабель
    window.addEventListener('contextmenu', (e) => {
      // На Linux и macOS contextmenu приходит при нажатии, кнопка ещё зажата: тогда ждём отпускания
      if (wire) { e.preventDefault(); if (!wire.sticky && !(e.buttons & 2)) finishWire(e); return; }
      if (wireEnded > performance.now() - 400) e.preventDefault();
    }, true);

    E.svg.addEventListener('pointerdown', (e) => {
      if (wire) { finishWire(e); return; }
      closeMenus();
      const nodeEl = e.target.closest('[data-node]');
      const linkEl = e.target.closest('[data-link]');
      const touch = e.pointerType === 'touch';
      if (touch !== !!S.touch) { S.touch = touch; drawLinkDel(); }
      if (e.button === 0 && e.target.closest('.lab-link-del')) { removeLink(S.sel.slice(5)); return; }
      try { E.svg.setPointerCapture(e.pointerId); } catch (err) { /* без захвата тоже работает */ }

      if (e.button === 2) {
        // Правой кнопкой от устройства: кабель; без движения это просто меню
        if (nodeEl) { select(nodeEl.dataset.node); startWire(nodeEl.dataset.node, e); }
        else press = { x: e.clientX, y: e.clientY, link: linkEl ? linkEl.dataset.link : null };
        return;
      }
      if (e.button !== 0) return;

      if (nodeEl && (e.target.closest('.lab-node__plug') || e.shiftKey)) {
        select(nodeEl.dataset.node);
        startWire(nodeEl.dataset.node, e);
        return;
      }
      if (nodeEl) {
        const id = nodeEl.dataset.node;
        const d = S.net.dev(id);
        const w = toWorld(e);
        drag = { id, dx: d.x - w.x, dy: d.y - w.y, moved: false, x: e.clientX, y: e.clientY, links: S.net.links.filter((l) => l.a.dev === id || l.b.dev === id) };
        select(id);
        // Долгое нажатие на сенсорном экране: тянуть кабель
        if (e.pointerType === 'touch') {
          drag.timer = setTimeout(() => {
            if (drag && !drag.moved) { const from = drag.id; drag = null; startWire(from, e); hint('Тяни к другому устройству', 'ok'); }
          }, LONG_PRESS_MS);
        }
        return;
      }
      if (linkEl) {
        select('link:' + linkEl.dataset.link);
        // Долгое нажатие на кабель на телефоне: меню
        if (touch) { const id = linkEl.dataset.link; press = { x: e.clientX, y: e.clientY, link: id, touch: true, timer: setTimeout(() => { if (press && press.link === id) { press = null; openMenu(e, { link: id }); } }, LONG_PRESS_MS) }; }
        return;
      }
      pan = { x: e.clientX, y: e.clientY, cx: S.cam.x, cy: S.cam.y, moved: false };
    });

    E.svg.addEventListener('pointermove', (e) => {
      if (wire) {
        if (!wire.sticky && wire.pointer === 'mouse' && wire.btn === 2 && !(e.buttons & 2)) { finishWire(e); return; }
        if (Math.abs(e.clientX - wire.x) + Math.abs(e.clientY - wire.y) > 4) wire.moved = true;
        const a = S.net.dev(wire.from);
        const w = toWorld(e);
        const over = nodeAt(e);
        E.gPreview.replaceChildren(s('line', { class: 'lab-preview' + (over && over !== wire.from ? ' is-ok' : ''), x1: a.x, y1: a.y, x2: w.x, y2: w.y }));
        return;
      }
      if (drag) {
        if (!drag.moved && Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 4) return;
        drag.moved = true;
        clearTimeout(drag.timer);
        const d = S.net.dev(drag.id);
        if (!d) return;
        const w = toWorld(e);
        d.x = Math.round((w.x + drag.dx) / 10) * 10;
        d.y = Math.round((w.y + drag.dy) / 10) * 10;
        moveNode(d, drag.links);
        return;
      }
      if (pan) {
        const dx = e.clientX - pan.x, dy = e.clientY - pan.y;
        if (Math.abs(dx) + Math.abs(dy) > 3) pan.moved = true;
        S.cam.x = pan.cx - dx / S.cam.k;
        S.cam.y = pan.cy - dy / S.cam.k;
        applyCam();
      }
    });

    const up = (e) => {
      if (wire) { finishWire(e, e.type !== 'pointerup'); return; }
      if (drag) {
        clearTimeout(drag.timer);
        if (drag.moved) save();
        else if (e.type === 'pointerup') {
          // Двойной щелчок считаем сами: из-за захвата указателя dblclick приходит на холст, а не на устройство
          const now = performance.now();
          if (lastTap && lastTap.id === drag.id && now - lastTap.t < 420) { lastTap = null; openDevice(drag.id); }
          else lastTap = { id: drag.id, t: now };
        }
        drag = null;
        return;
      }
      if (press && press.touch) { clearTimeout(press.timer); press = null; return; }
      if (press) {
        if (Math.abs(e.clientX - press.x) + Math.abs(e.clientY - press.y) < 8) {
          if (press.link) { select('link:' + press.link); openMenu(e, { link: press.link }); }
          else openMenu(e, { at: toWorld(e) });
        }
        press = null;
        return;
      }
      if (pan) {
        if (!pan.moved) select(null);
        pan = null;
      }
    };
    E.svg.addEventListener('pointerup', up);
    E.svg.addEventListener('pointercancel', up);


    E.svg.addEventListener('wheel', (e) => {
      e.preventDefault();
      zoom(e.deltaY < 0 ? 1.12 : 1 / 1.12, e);
    }, { passive: false });

    if (window.ResizeObserver) new ResizeObserver(() => { applyCam(); if (LAB.Win) LAB.Win.clamp(); }).observe(E.svg);
    else window.addEventListener('resize', applyCam);

    document.addEventListener('pointerdown', (e) => {
      if (!e.target.closest('.lab-menu, .lab-pop, [data-pop-toggle]')) closeMenus();
    });

    document.addEventListener('keydown', (e) => {
      if (isTyping(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'escape') {
        if (E.menu.childElementCount || !E.info.hidden || document.querySelector('.lab-pop:not([hidden])')) { closeMenus(); return; }
        if (wire) { endWire(); return; }
        if (isFull() && !document.fullscreenElement) setFullClass(false);
        return;
      }
      if ((k === 'delete' || k === 'backspace') && S.sel) {
        e.preventDefault();
        if (S.sel.startsWith('link:')) removeLink(S.sel.slice(5)); else removeDevice(S.sel);
      }
    });
  }

  function openDevice(id) {
    const d = S.net.dev(id);
    if (!d || !LAB.Win) return;
    if (!LAB.Win.tabsFor(d).length) { hint(d.name + ': неуправляемое устройство, настроек нет', 'err'); return; }
    LAB.Win.open(d.id);
  }

  /* ===== Контекстное меню ===== */
  function menuItem(text, fn, cls) {
    return h('button', { type: 'button', class: 'lab-menu__item' + (cls ? ' ' + cls : ''), onClick: () => { closeMenus(); fn(); } }, text);
  }

  function openMenu(e, what) {
    const items = [];
    if (what.node) {
      const d = S.net.dev(what.node);
      if (!d) return;
      items.push(h('div', { class: 'lab-menu__title' }, d.name));
      const tabs = LAB.Win ? LAB.Win.tabsFor(d) : [];
      tabs.forEach((t) => items.push(menuItem(t.label, () => LAB.Win.open(d.id, t.id))));
      if (!tabs.length) items.push(h('div', { class: 'lab-menu__note' }, 'неуправляемое устройство'));
      items.push(h('div', { class: 'lab-menu__sep' }));
      items.push(menuItem('Переименовать', () => {
        const v = prompt('Новое имя для ' + d.name, d.name);
        if (v && v.trim()) { d.name = v.trim().slice(0, 24); refreshed(); }
      }));
      items.push(menuItem('Удалить', () => removeDevice(d.id), 'is-danger'));
    } else if (what.link) {
      const l = S.net.link(what.link);
      if (!l) return;
      const a = S.net.dev(l.a.dev), b = S.net.dev(l.b.dev);
      items.push(h('div', { class: 'lab-menu__title' }, a.name + ' ' + a.ifaces[l.a.i].name + ' ↔ ' + b.name + ' ' + b.ifaces[l.b.i].name));
      items.push(menuItem('Удалить кабель', () => removeLink(l.id), 'is-danger'));
    } else {
      items.push(h('div', { class: 'lab-menu__title' }, 'Добавить сюда'));
      Object.values(LAB.types).forEach((t) => items.push(menuItem(t.title, () => addDevice(t.type, what.at))));
    }
    E.menu.replaceChildren(...items);
    const st = E.stage.getBoundingClientRect();
    E.menu.hidden = false;
    const mw = E.menu.offsetWidth, mh = E.menu.offsetHeight;
    E.menu.style.left = Math.min(e.clientX - st.left, st.width - mw - 6) + 'px';
    E.menu.style.top = Math.min(e.clientY - st.top, st.height - mh - 6) + 'px';
  }

  function closeMenus() {
    if (E.menu) { E.menu.hidden = true; E.menu.replaceChildren(); }
    if (E.info) E.info.hidden = true;
    document.querySelectorAll('.lab-pop').forEach((p) => { p.hidden = true; });
  }

  /* ===== Инфо ===== */
  function renderInfo() {
    const p = LAB.presets.find((x) => x.id === S.preset);
    E.info.replaceChildren();
    if (p) {
      const op = p.try && (p.try[2] || 'ping');
      const label = !p.try ? '' : op === 'dhcp' ? 'dhclient на ' + p.try[0] : op === 'http' ? 'открыть ' + p.try[1] + ' с ' + p.try[0] : 'ping ' + p.try[1] + ' с ' + p.try[0];
      E.info.append(h('div', { class: 'lab-info__sec' },
        h('div', { class: 'lab-info__label' }, p.task ? 'Задача' : 'Схема'),
        h('b', null, p.title),
        h('p', null, p.desc),
        p.try ? h('button', { type: 'button', class: 'btn btn--primary', onClick: () => { closeMenus(); tryPreset(p); } }, label) : null));
    }
    if (S.map) E.info.append(h('div', { class: 'lab-info__sec' }, h('div', { class: 'lab-info__label' }, 'Карта'), h('b', null, S.map.name)));
    E.info.append(h('div', { class: 'lab-info__sec' },
      h('div', { class: 'lab-info__label' }, 'Управление'),
      h('ul', { class: 'lab-help' },
        h('li', null, h('b', null, 'Двойной щелчок'), ' по устройству: настройки (веб-интерфейс, диагностика: ping и traceroute, браузер).'),
        h('li', null, h('b', null, 'Кабель'), ': щёлкни правой кнопкой по устройству, кабель пойдёт за курсором, щёлкни по второму устройству. Можно и тянуть с зажатой кнопкой, если в браузере выключены жесты мыши. На телефоне: долгое нажатие на устройство и тянуть.'),
        h('li', null, h('b', null, 'Меню устройства'), ': два щелчка правой кнопкой по нему. По кабелю или пустому месту хватит одного.'),
        h('li', null, h('b', null, 'Удалить'), ': через меню или выдели и нажми Delete. На телефоне: тап по кабелю и ✕, или долгое нажатие.'),
        h('li', null, 'Левой кнопкой двигать устройства и холст, колесо масштабирует, Delete удаляет выделенное.'),
        h('li', null, 'Виды L1, L2, L3: кабели, широковещательные домены, подсети. Полоска у устройства: его уровни OSI, при обработке кадра она подсвечивается.'),
        h('li', null, h('b', null, 'Журнал'), ' внизу справа: ping с любого узла и разбор каждого кадра по уровням.'))),
    h('div', { class: 'lab-info__sec lab-info__stat' }, 'Устройств: ' + S.net.devices.length + ' из ' + MAX_DEVICES + ', кабелей: ' + S.net.links.length));
  }

  function tryPreset(p) {
    const d = S.net.byName(p.try[0]);
    if (!d || !LAB.Win) return;
    const op = p.try[2] || 'ping';
    LAB.Win.runDiag(d.id, op, p.try[1]);
  }

  function fillTry() {
    const p = LAB.presets.find((x) => x.id === S.preset);
    if (!p || !p.try || (p.try[2] && p.try[2] !== 'ping')) return;
    const d = S.net.byName(p.try[0]);
    if (d) { E.src.value = d.id; E.dst.value = p.try[1]; }
  }

  /* ===== Журнал (выезжает снизу) ===== */
  function buildDock() {
    E.src = h('select', { class: 'lab-select', 'aria-label': 'Откуда' });
    E.dst = h('input', { class: 'input lab-dst', placeholder: 'IP, имя или http://адрес', list: 'labIps', spellcheck: 'false', 'aria-label': 'Куда',
      onKeydown: (e) => { if (e.key === 'Enter') startFromDock('ping'); } });
    E.ips = h('datalist', { id: 'labIps' });
    E.speedSel = h('select', { class: 'lab-select', 'aria-label': 'Скорость анимации', title: 'Скорость анимации', onChange: (e) => { S.speed = Number(e.target.value); } },
      [['0.5', '0.5×'], ['1', '1×'], ['2', '2×'], ['4', '4×'], ['8', '8×']].map(([v, l]) => h('option', { value: v, selected: v === '1' }, l)));
    E.log = h('div', { class: 'lab-log', 'aria-live': 'polite' });
    E.pdu = h('div', { class: 'lab-pdu' });
    const grip = h('div', { class: 'lab-dock__grip', title: 'Потяни, чтобы изменить высоту' });
    E.dock = h('div', { class: 'lab-dock', hidden: true },
      grip,
      h('div', { class: 'lab-dock__head' },
        h('b', null, 'Журнал'),
        E.src, E.dst, E.ips,
        h('button', { type: 'button', class: 'lab-btn', onClick: () => startFromDock('ping') }, 'Ping'),
        h('button', { type: 'button', class: 'lab-btn', onClick: () => startFromDock('tracert') }, 'Tracert'),
        h('button', { type: 'button', class: 'lab-btn', onClick: () => startFromDock('http'), title: 'Открыть адрес как сайт: DNS, TCP, HTTP' }, 'HTTP'),
        E.speedSel,
        h('button', { type: 'button', class: 'lab-btn lab-btn--quiet', title: 'Очистить ARP- и MAC-таблицы и аренды DHCP на всех устройствах', onClick: () => { S.sim.reset(); if (LAB.Win) LAB.Win.refresh(); hint('Таблицы ARP, MAC и аренды DHCP очищены', 'ok'); } }, 'Сбросить таблицы'),
        h('button', { type: 'button', class: 'lab-dock__close', title: 'Скрыть журнал', onClick: () => toggleDock(false) }, '✕')),
      h('div', { class: 'lab-dock__body' }, E.log, E.pdu));

    // Высота журнала тянется за верхнюю кромку
    let rs = null;
    grip.addEventListener('pointerdown', (e) => { rs = { y: e.clientY, h: E.dock.offsetHeight }; grip.setPointerCapture(e.pointerId); });
    grip.addEventListener('pointermove', (e) => {
      if (!rs) return;
      const max = E.stage.clientHeight - 60;
      E.dock.style.height = Math.max(140, Math.min(max, rs.h + rs.y - e.clientY)) + 'px';
    });
    grip.addEventListener('pointerup', () => { rs = null; });

    E.chip = h('div', { class: 'lab-chip', hidden: true });
    clearLog();
  }

  function toggleDock(on) {
    const show = on == null ? E.dock.hidden : on;
    E.dock.hidden = !show;
    E.dockBtn.classList.toggle('active', show);
    if (show) E.chip.hidden = true;
  }

  function renderSimControls() {
    const prev = E.src.value;
    const sources = S.net.devices.filter((d) => ['host', 'router'].includes(kindOf(d)));
    E.src.replaceChildren(...sources.slice(0, 500).map((d) => h('option', { value: d.id }, d.name)));
    if (sources.some((d) => d.id === prev)) E.src.value = prev;
    const opts = [];
    S.net.devices.forEach((d) => d.ifaces.forEach((i) => { if (i.ip && opts.length < 500) opts.push([i.ip, d.name]); }));
    S.net.devices.forEach((d) => d.services && d.services.dns.on && d.services.dns.records.forEach((r) => opts.push([r.name, 'DNS: ' + r.ip])));
    E.ips.replaceChildren(...opts.map(([a, n]) => h('option', { value: a }, n)));
  }

  function startFromDock(op) {
    const src = E.src.value;
    const dst = E.dst.value.trim();
    if (!src) { hint('Добавь узел или маршрутизатор, с которого отправлять', 'err'); return; }
    if (!dst) { hint('Укажи, куда: IP-адрес или имя', 'err'); E.dst.focus(); return; }
    const d = S.net.dev(src);
    if (op === 'http' && kindOf(d) !== 'host') { hint('HTTP-запрос можно отправить только с ПК или сервера', 'err'); return; }
    runOp(op, src, dst);
  }

  /* ===== Запуск операций ===== */
  // op: ping | tracert | dns | dhcp | http. Возвращает Promise с результатом после анимации
  function runOp(op, srcId, arg) {
    stopAnim();
    const d = S.net.dev(srcId);
    const sim = S.sim;
    const r = op === 'ping' ? sim.ping(srcId, arg)
      : op === 'tracert' ? sim.tracert(srcId, arg)
        : op === 'dns' ? sim.dnsLookup(srcId, arg)
          : op === 'dhcp' ? sim.dhcp(srcId)
            : sim.http(srcId, arg);
    const title = { ping: 'ping ' + arg, tracert: 'tracert ' + arg, dns: 'nslookup ' + arg, dhcp: 'dhclient', http: 'GET ' + arg }[op];
    clearLog(true);
    E.log.append(h('div', { class: 'lab-log__row is-head' }, title + ' с ' + (d ? d.name : '?')));
    // DHCP уже записал адрес в настройки узла: сохраняем сразу, а показываем после анимации
    if (op === 'dhcp') save();
    chip('run');
    return animate(r).then(() => {
      if (op === 'dhcp') renderGraph();
      const sm = summary(op, r);
      E.log.append(h('div', { class: 'lab-log__row is-sum is-' + sm.cls }, sm.text));
      E.log.scrollTop = E.log.scrollHeight;
      chip('done', sm);
      if (LAB.Win) LAB.Win.refresh();
      return r;
    });
  }

  // Плашка внизу холста: идёт симуляция или итог; журнал открывается по щелчку
  let chipTimer = null;
  function chip(state, sm) {
    clearTimeout(chipTimer);
    if (!E.dock.hidden) { E.chip.hidden = true; return; }
    E.chip.hidden = false;
    if (state === 'run') {
      E.chip.className = 'lab-chip';
      E.chip.replaceChildren(h('span', { class: 'lab-chip__dot' }), 'идёт симуляция',
        h('button', { type: 'button', onClick: () => stopAnim() }, 'пропустить'),
        h('button', { type: 'button', onClick: () => toggleDock(true) }, 'журнал'));
      return;
    }
    E.chip.className = 'lab-chip is-' + sm.cls;
    E.chip.replaceChildren(sm.text.replace(/^Итог: /, ''), h('button', { type: 'button', onClick: () => toggleDock(true) }, 'журнал'));
    chipTimer = setTimeout(() => { E.chip.hidden = true; }, 7000);
  }

  function summary(op, r) {
    const find = (t) => r.results.find((x) => x.type === t);
    let text, cls = 'err';
    if (op === 'tracert') {
      const last = r.hops[r.hops.length - 1];
      text = 'Итог: ' + r.hops.map((x) => x.from || '*').join(' → ');
      cls = last && last.done ? 'ok' : 'err';
    } else if (find('dns-fail')) text = 'Итог: DNS не дал адрес (' + find('dns-fail').reason + ')';
    else if (find('local-err')) text = 'Итог: ' + find('local-err').text;
    else if (op === 'dhcp') { const x = find('dhcp'); text = x ? 'Итог: получен адрес ' + x.ip + '/' + x.prefix : 'Итог: DHCP-сервер не ответил'; cls = x ? 'ok' : 'err'; }
    else if (op === 'dns') { const x = find('dns'); text = x ? 'Итог: ' + x.name + ' = ' + x.ip : 'Итог: DNS-сервер не ответил'; cls = x ? 'ok' : 'err'; }
    else if (op === 'http') {
      const x = find('http');
      if (x) { text = 'Итог: HTTP ' + x.status + ', страница «' + x.title + '»'; cls = x.status === 200 ? 'ok' : 'err'; }
      else text = find('http-refused') ? 'Итог: соединение отклонено, веб-сервер не запущен' : 'Итог: сервер не ответил';
    } else {
      const res = r.results[0];
      if (res && res.type === 'reply') { text = 'Итог: ответ от ' + res.from + ', TTL ' + res.ttl; cls = 'ok'; }
      else if (res && res.type === 'unreach') text = 'Итог: ' + res.from + ' сообщает, что сеть недоступна';
      else if (res && res.type === 'arp-fail') text = 'Итог: на ARP-запрос никто не ответил';
      else text = 'Итог: ответ не пришёл';
    }
    if (r.storm) { text += '. Широковещательный шторм'; cls = 'err'; }
    return { text, cls };
  }

  /* ===== Журнал и разбор кадра ===== */
  function clearLog(keepPdu) {
    if (E.log) E.log.replaceChildren();
    if (E.pdu && !keepPdu) E.pdu.replaceChildren(h('p', { class: 'lab-empty' }, 'Щёлкни строку журнала, чтобы разобрать кадр по уровням.'));
  }

  function logRow(ev) {
    const net = S.net;
    if (ev.type === 'hop') {
      const a = net.dev(ev.from), b = net.dev(ev.to);
      return h('div', { class: 'lab-log__row is-hop', style: { '--kc': KIND_COLOR[ev.kind] }, onClick: (e) => pickRow(e.currentTarget, ev) },
        h('span', { class: 'lab-log__tick' }, ev.tick),
        h('span', { class: 'lab-log__kind' }, KIND_TEXT[ev.kind]),
        h('span', null, h('b', null, (a ? a.name : '?') + ' → ' + (b ? b.name : '?')), ' ' + ev.label));
    }
    const d = ev.dev ? net.dev(ev.dev) : null;
    return h('div', { class: 'lab-log__row is-' + ev.level + (ev.frame ? ' is-click' : ''), onClick: ev.frame ? (e) => pickRow(e.currentTarget, ev) : null },
      h('span', { class: 'lab-log__tick' }, ev.tick),
      h('span', { class: 'lab-log__layer', style: { '--lc': LC[ev.layer] || '#868899' } }, 'L' + ev.layer),
      h('span', null, d ? h('b', null, d.name + ': ') : null, ev.text));
  }

  function pickRow(row, ev) {
    E.log.querySelectorAll('.is-picked').forEach((r) => r.classList.remove('is-picked'));
    row.classList.add('is-picked');
    renderPdu(ev);
    E.gLinks.querySelectorAll('.is-flash').forEach((x) => x.classList.remove('is-flash'));
    if (ev.type === 'hop') {
      const el = E.linkEls.get(ev.link);
      if (el) el.g.classList.add('is-flash');
    }
  }

  function box(n, title, rows, inner, note) {
    return h('div', { class: 'lab-pdu__box', style: { '--lc': LC[n] } },
      h('div', { class: 'lab-pdu__head' }, h('span', null, 'L' + n), title),
      rows && rows.length ? h('table', { class: 'lab-pdu__fields' }, h('tbody', null, rows.map(([k, v]) => h('tr', null, h('th', null, k), h('td', null, v))))) : null,
      inner || null,
      note ? h('p', { class: 'lab-pdu__note' }, note) : null);
  }

  function appBox(app) {
    if (app.type === 'dhcp') {
      return box(7, 'DHCP ' + LAB.DHCP_OP[app.op], [
        ['Клиент (MAC)', app.chaddr],
        app.yiaddr ? ['Адрес клиенту', app.yiaddr + (app.prefix ? '/' + app.prefix : '')] : null,
        app.router ? ['Шлюз', app.router] : null,
        app.dns ? ['DNS', app.dns] : null,
        app.server ? ['Сервер', app.server] : null,
        app.giaddr ? ['Relay (giaddr)', app.giaddr] : null,
        ['ID обмена (xid)', '0x' + (app.xid >>> 0).toString(16)],
      ].filter(Boolean));
    }
    if (app.type === 'dns') {
      return box(7, app.op === 'query' ? 'DNS-запрос' : 'DNS-ответ', [
        ['ID', String(app.id)], ['Имя', app.name], ['Тип', 'A (IPv4-адрес)'],
        app.op === 'response' ? ['Ответ', app.answer || 'NXDOMAIN, имени нет'] : null,
      ].filter(Boolean));
    }
    if (app.type === 'http') {
      return app.op === 'request'
        ? box(7, 'HTTP-запрос', [['Строка', 'GET ' + app.path + ' HTTP/1.1'], ['Host', app.host]])
        : box(7, 'HTTP-ответ', [['Статус', app.status + (app.status === 200 ? ' OK' : ' Not Found')], ['Content-Type', 'text/html'], ['Заголовок страницы', app.title]]);
    }
    return null;
  }

  function renderPdu(ev) {
    const f = ev.frame;
    const net = S.net;
    let inner = null;
    if (f.type === 'arp') {
      const a = f.arp;
      inner = box(2, 'ARP (связывает L3-адрес с L2)', [
        ['Операция', a.op === 'request' ? '1, запрос' : '2, ответ'],
        ['MAC отправителя', a.smac], ['IP отправителя', a.sip],
        ['Искомый MAC', a.op === 'request' ? '00:00:00:00:00:00 (ещё не знаем)' : a.tmac], ['Искомый IP', a.tip],
      ]);
    } else {
      const p = f.ip;
      let l4 = null;
      let proto = '1 (ICMP)';
      if (p.proto === 'udp') {
        proto = '17 (UDP)';
        l4 = box(4, 'UDP-датаграмма', [['Порт отправителя', String(p.udp.sport)], ['Порт получателя', String(p.udp.dport)]], p.app ? appBox(p.app) : null,
          'UDP без соединения и подтверждений: отправил и забыл.');
      } else if (p.proto === 'tcp') {
        proto = '6 (TCP)';
        l4 = box(4, 'TCP-сегмент', [['Порт отправителя', String(p.tcp.sport)], ['Порт получателя', String(p.tcp.dport)], ['Флаги', p.tcp.flags], ['Seq / Ack', p.tcp.seq + ' / ' + p.tcp.ack]],
          p.app ? appBox(p.app) : null,
          p.app ? null : 'Служебный сегмент без данных: ' + ({ SYN: 'просьба открыть соединение', 'SYN, ACK': 'согласие открыть соединение', ACK: 'подтверждение', 'FIN, ACK': 'закрытие соединения' }[p.tcp.flags] || 'управление соединением') + '.');
      } else {
        const icmpNames = { 'echo-request': '8, эхо-запрос', 'echo-reply': '0, эхо-ответ', 'time-exceeded': '11, время жизни истекло', unreachable: '3, назначение недоступно' };
        l4 = box(3, 'ICMP', [['Тип', icmpNames[p.icmp.type] || p.icmp.type]], null, 'Выше L3 у ping ничего нет: ICMP живёт на сетевом уровне, без портов.');
      }
      inner = box(3, 'IPv4-пакет', [
        ['IP отправителя', p.src + (p.src === '0.0.0.0' ? ' (адреса ещё нет)' : '')], ['IP получателя', p.dst + (p.dst === '255.255.255.255' ? ' (всем)' : '')],
        ['TTL', String(p.ttl)], ['Протокол', proto],
      ], l4);
    }
    const frameBox = box(2, 'Ethernet-кадр', [
      ['MAC получателя', f.dst === LAB.BCAST ? f.dst + ' (всем)' : f.dst],
      ['MAC отправителя', f.src],
      ['EtherType', f.type === 'arp' ? '0x0806 (ARP)' : '0x0800 (IPv4)'],
    ], inner);
    let where;
    if (ev.type === 'hop') {
      const a = net.dev(ev.from), b = net.dev(ev.to);
      where = (a ? a.name : '?') + ' → ' + (b ? b.name : '?');
    } else {
      const d = net.dev(ev.dev);
      where = 'на ' + (d ? d.name : '?');
    }
    E.pdu.replaceChildren(
      h('div', { class: 'lab-pdu__where' }, 'Такт ' + ev.tick + ', ' + where),
      box(1, 'биты в кабеле', null, frameBox, 'Преамбула 10101010…, затем кадр побитно, в конце FCS (контрольная сумма).'));
  }

  /* ===== Анимация ===== */
  function flashLayers(devId, layer, level) {
    const g = E.nodeEls.get(devId);
    const dev = S.net.dev(devId);
    if (!g || !dev) return;
    const t = typeOf(dev);
    g.classList.remove('is-ok', 'is-err');
    if (level === 'ok' || level === 'err') g.classList.add('is-' + level);
    g.querySelectorAll('.lab-seg').forEach((seg) => {
      const n = Number(seg.dataset.l);
      if (n >= t.layers[0] && n <= layer) {
        seg.classList.remove('is-on');
        void seg.getBBox();
        seg.classList.add('is-on');
      }
    });
    clearTimeout(g._t);
    g._t = setTimeout(() => {
      g.classList.remove('is-ok', 'is-err');
      g.querySelectorAll('.is-on').forEach((x) => x.classList.remove('is-on'));
    }, 900);
  }

  function animate(r) {
    const events = r.events.slice();
    const total = events.length;
    const maxRows = 400;
    const maxPills = 60;
    const endTick = r.ticks + 1;
    let i = 0, t = 0, last = performance.now();
    let rows = 0;
    const pills = [];

    return new Promise((resolve) => {
      const reveal = (ev, quiet) => {
        if (rows < maxRows) { E.log.append(logRow(ev)); rows++; }
        else if (rows === maxRows) { E.log.append(h('div', { class: 'lab-log__row is-err' }, 'И ещё ' + (total - i) + ' событий...')); rows++; }
        if (quiet) return;
        if (ev.type === 'log' && ev.dev) flashLayers(ev.dev, ev.layer, ev.level);
        if (ev.type === 'hop' && pills.length < maxPills) {
          const el = s('g', { class: 'lab-pill' },
            s('rect', { x: -19, y: -8, width: 38, height: 16, rx: 2, fill: KIND_COLOR[ev.kind] }),
            s('text', { y: 4, 'text-anchor': 'middle' }, KIND_TEXT[ev.kind]));
          E.gPk.append(el);
          pills.push({ ev, el });
          const l = E.linkEls.get(ev.link);
          if (l) l.g.classList.add('is-busy');
        }
      };

      const finish = () => {
        while (i < events.length) reveal(events[i++], true);
        pills.forEach((p) => p.el.remove());
        E.gLinks.querySelectorAll('.is-busy').forEach((x) => x.classList.remove('is-busy'));
        S.anim = null;
        E.log.scrollTop = E.log.scrollHeight;
        resolve();
      };

      const frame = (now) => {
        if (!S.anim) return;
        t += (now - last) * S.speed;
        last = now;
        const tick = t / TICK_MS;
        let added = false;
        while (i < events.length && events[i].tick <= tick) { reveal(events[i++]); added = true; }
        if (added) E.log.scrollTop = E.log.scrollHeight;

        for (let k = pills.length - 1; k >= 0; k--) {
          const p = pills[k];
          const prog = tick - p.ev.tick;
          const a = S.net.dev(p.ev.from), b = S.net.dev(p.ev.to);
          if (prog >= 1 || !a || !b) {
            p.el.remove();
            pills.splice(k, 1);
            if (!pills.some((q) => q.ev.link === p.ev.link)) {
              const l = E.linkEls.get(p.ev.link);
              if (l) l.g.classList.remove('is-busy');
            }
            continue;
          }
          const e2 = prog < 0.5 ? 2 * prog * prog : 1 - Math.pow(-2 * prog + 2, 2) / 2;
          p.el.setAttribute('transform', 'translate(' + (a.x + (b.x - a.x) * e2) + ' ' + (a.y + (b.y - a.y) * e2) + ')');
        }
        if (i >= events.length && tick >= endTick && !pills.length) { finish(); return; }
        requestAnimationFrame(frame);
      };

      S.anim = { finish };
      requestAnimationFrame((now) => { last = now; frame(now); });
    });
  }

  function stopAnim() {
    if (S.anim) S.anim.finish();
  }

  /* ===== Экспорт и импорт ===== */
  function exportFile() {
    const blob = new Blob([JSON.stringify(S.net, null, 2)], { type: 'application/json' });
    const a = h('a', { href: URL.createObjectURL(blob), download: (S.map ? S.map.name : 'network') + '.json' });
    document.body.append(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
  }

  function importFile(e) {
    const f = e.target.files[0];
    e.target.value = '';
    if (!f) return;
    f.text().then((txt) => {
      try {
        const net = LAB.Net.from(JSON.parse(txt));
        if (net.devices.length > MAX_DEVICES) throw new Error('больше ' + MAX_DEVICES + ' устройств');
        setNet(net, null);
        hint('Схема загружена', 'ok');
      } catch (err) {
        hint('Не удалось прочитать файл: ' + err.message, 'err');
      }
    });
  }

  /* ===== Сборка ===== */
  function renderAll() {
    renderGraph();
    renderLegend();
    renderSimControls();
    if (LAB.Win) LAB.Win.refresh();
  }

  // Общий доступ для windows.js и maps.js
  LAB.ui = {
    S, E, h, s, LC, typeOf, kindOf, miniIcon, MAX_DEVICES,
    changed, refreshed, save, hint, runOp, renderAll, setNet, closeMenus,
    select(id) { select(id); },
  };

  function boot() {
    const root = document.getElementById('lab');
    if (!root) return;
    E.root = root;
    E.toolbar = h('div', { class: 'lab-toolbar panel' });
    E.svg = s('svg', { class: 'lab-svg', role: 'img', 'aria-label': 'Схема сети' },
      s('defs', null, s('pattern', { id: 'labGrid', width: 40, height: 40, patternUnits: 'userSpaceOnUse' },
        s('circle', { cx: 1, cy: 1, r: 1, fill: '#2a2a35' }))),
      s('rect', { x: -100000, y: -100000, width: 200000, height: 200000, fill: 'url(#labGrid)' }),
      E.gLinks = s('g'), E.gPreview = s('g'), E.gNodes = s('g'), E.gSel = s('g'), E.gPk = s('g', { class: 'lab-pk' }));
    E.hint = h('div', { class: 'lab-hint', hidden: true });
    E.legend = h('div', { class: 'lab-legend' });
    E.windows = h('div', { class: 'lab-windows' });
    E.menu = h('div', { class: 'lab-menu', hidden: true, role: 'menu' });
    E.info = h('div', { class: 'lab-pop lab-info', hidden: true });
    E.dockBtn = h('button', { type: 'button', class: 'lab-ctl__btn lab-ctl__btn--text', title: 'Журнал пакетов и разбор кадров', onClick: () => toggleDock() }, 'Журнал');
    buildDock();
    E.stage = h('div', { class: 'lab-stage' }, E.svg, E.hint, E.legend,
      h('button', { type: 'button', class: 'lab-ctl__btn lab-ctl__btn--text lab-info-btn', 'data-pop-toggle': '', title: 'Схема и управление',
        onClick: () => { const show = E.info.hidden; closeMenus(); if (show) { renderInfo(); E.info.hidden = false; } } }, 'Инфо'),
      E.info,
      h('div', { class: 'lab-ctl' },
        E.dockBtn,
        h('button', { type: 'button', class: 'lab-ctl__btn', title: 'Приблизить', onClick: () => zoom(1.2) }, '+'),
        h('button', { type: 'button', class: 'lab-ctl__btn', title: 'Отдалить', onClick: () => zoom(1 / 1.2) }, '−'),
        h('button', { type: 'button', class: 'lab-ctl__btn', title: 'Показать всю схему', onClick: fit }, '⤢')),
      E.chip, E.dock, E.windows, E.menu);
    root.append(E.toolbar, E.stage);

    buildToolbar();
    bindStage();

    if (load()) {
      S.sim = new LAB.Sim(S.net);
      renderAll();
      setTimeout(fit);
      fillTry();
    } else {
      S.net = new LAB.Net();
      S.sim = new LAB.Sim(S.net);
      setTimeout(() => loadPreset('services'));
    }
    setView(S.view);
  }

  if (document.readyState === 'complete') setTimeout(boot);
  else document.addEventListener('DOMContentLoaded', boot);
})();
