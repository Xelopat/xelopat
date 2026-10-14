// Сетевая лаборатория: холст, инспектор, симуляция с анимацией пакетов.
// Логика сети живёт в net.js, здесь только отображение и действия пользователя.
(function () {
  const ip = LAB.ip;
  const NS = 'http://www.w3.org/2000/svg';
  const STORE = 'cs-lab:v1';
  const TICK_MS = 950;
  const LC = { 7: '#ff8f8f', 6: '#f5a964', 5: '#f9c940', 4: '#61d1ad', 3: '#5aa7ff', 2: '#9d8ff0', 1: '#d68fe0' };
  const LN = { 7: 'Прикладной', 6: 'Представления', 5: 'Сеансовый', 4: 'Транспортный', 3: 'Сетевой', 2: 'Канальный', 1: 'Физический' };
  const DOMAIN_COLORS = ['#61d1ad', '#5aa7ff', '#f9c940', '#ff8f8f', '#9d8ff0', '#f5a964', '#d68fe0', '#8fd3ff', '#c3e88d'];
  const KIND_COLOR = { arp: '#f9c940', req: '#61d1ad', rep: '#5aa7ff', err: '#ff8f8f' };
  const KIND_TEXT = { arp: 'ARP', req: 'ICMP', rep: 'ICMP', err: 'ICMP' };
  const VIEWS = {
    l1: { label: 'L1', title: 'Физический вид: кабели и порты' },
    l2: { label: 'L2', title: 'Канальный вид: широковещательные домены, MAC и VLAN' },
    l3: { label: 'L3', title: 'Сетевой вид: подсети и IP-адреса' },
  };
  const TOOLS = {
    select: { label: 'Выбор', key: 'V', hint: 'Перетаскивай устройства, щёлкни, чтобы настроить. Двойной щелчок открывает консоль. Пустое место двигает холст, колесо масштабирует.' },
    link: { label: 'Кабель', key: 'C', hint: 'Щёлкни первое устройство, потом второе: кабель займёт первые свободные порты.' },
    delete: { label: 'Удалить', key: 'D', hint: 'Щёлкни устройство или кабель, чтобы удалить.' },
  };

  const S = {
    net: null, sim: null,
    tool: 'select', view: 'l2',
    sel: null,          // id устройства или 'link:<id>'
    linkFrom: null,
    cam: { x: 0, y: 0, k: 1 },
    speed: 1,
    anim: null,
    consoles: {},
    preset: null,
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
  const isTyping = (t) => t instanceof Element && !!t.closest('input, textarea, select');

  /* ===== Иконки устройств (центр в 0,0) ===== */
  const ICONS = {
    pc: (c) => s('g', null,
      s('rect', { x: -22, y: -20, width: 44, height: 30, rx: 4, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      s('rect', { x: -17, y: -15, width: 34, height: 20, rx: 2, fill: c, opacity: 0.18 }),
      s('path', { d: 'M-6 10 L-8 17 L8 17 L6 10', fill: '#1e1e25', stroke: c, 'stroke-width': 2, 'stroke-linejoin': 'round' })),
    server: (c) => s('g', null,
      s('rect', { x: -16, y: -24, width: 32, height: 44, rx: 4, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      [-14, -3, 8].map((y) => s('g', null,
        s('line', { x1: -10, y1: y, x2: 4, y2: y, stroke: c, 'stroke-width': 2, opacity: 0.6 }),
        s('circle', { cx: 9, cy: y, r: 2, fill: c })))),
    switch: (c) => s('g', null,
      s('rect', { x: -28, y: -14, width: 56, height: 28, rx: 6, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      s('path', { d: 'M-16 -4 H14 M9 -8 L14 -4 L9 0 M16 5 H-14 M-9 1 L-14 5 L-9 9', fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })),
    router: (c) => s('g', null,
      s('circle', { r: 22, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      s('path', { d: 'M0 -14 V-4 M-4 -10 L0 -14 L4 -10 M0 14 V4 M-4 10 L0 14 L4 10 M-14 0 H-4 M-10 -4 L-14 0 L-10 4 M14 0 H4 M10 -4 L14 0 L10 4', fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })),
    hub: (c) => s('g', null,
      s('rect', { x: -26, y: -11, width: 52, height: 22, rx: 11, fill: '#1e1e25', stroke: c, 'stroke-width': 2 }),
      [-12, -4, 4, 12].map((x) => s('circle', { cx: x, cy: 0, r: 2.5, fill: c }))),
  };
  function iconFor(type) {
    const t = LAB.types[type];
    if (typeof t.icon === 'function') return t.icon(s);
    const fn = ICONS[t.icon];
    if (fn) return fn(t.color);
    return s('g', null,
      s('rect', { x: -22, y: -18, width: 44, height: 36, rx: 8, fill: '#1e1e25', stroke: t.color, 'stroke-width': 2 }),
      s('text', { y: 5, 'text-anchor': 'middle', fill: t.color, 'font-size': 14, 'font-family': 'var(--mono)' }, t.title.slice(0, 2)));
  }

  /* ===== Хранение ===== */
  function save() {
    try { localStorage.setItem(STORE, JSON.stringify({ net: S.net, preset: S.preset, view: S.view })); } catch (e) { /* без хранилища просто не запоминаем */ }
  }
  function load() {
    try {
      const raw = localStorage.getItem(STORE);
      if (!raw) return false;
      const data = JSON.parse(raw);
      S.net = LAB.Net.from(data.net);
      S.preset = data.preset || null;
      if (VIEWS[data.view]) S.view = data.view;
      return true;
    } catch (e) {
      return false;
    }
  }

  function setNet(net, presetId) {
    stopAnim();
    S.net = net;
    S.sim = new LAB.Sim(net);
    S.sel = null;
    S.linkFrom = null;
    S.consoles = {};
    S.preset = presetId || null;
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

  // Изменилась схема или настройки: таблицы ARP и MAC устаревают
  function changed() {
    stopAnim();
    S.sim.reset();
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
    const xs = ds.map((d) => d.x), ys = ds.map((d) => d.y);
    const minX = Math.min(...xs) - 90, maxX = Math.max(...xs) + 90;
    const minY = Math.min(...ys) - 70, maxY = Math.max(...ys) + 90;
    const k = Math.min(r.width / (maxX - minX), r.height / (maxY - minY), 1.3);
    S.cam = { x: (minX + maxX) / 2, y: (minY + maxY) / 2, k: Math.max(0.3, k) };
    applyCam();
  }
  function zoom(f, around) {
    const r = E.svg.getBoundingClientRect();
    const k2 = Math.min(3, Math.max(0.25, S.cam.k * f));
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

  function renderGraph() {
    const net = S.net;
    const dom = S.view === 'l1' ? null : net.domains();
    E.gLinks.replaceChildren();
    E.gNodes.replaceChildren();

    net.links.forEach((l) => {
      const a = net.dev(l.a.dev), b = net.dev(l.b.dev);
      const color = dom ? domainColor(dom.linkDomain[l.id]) : '#5a5a6e';
      const selected = S.sel === 'link:' + l.id;
      const len = Math.hypot(b.x - a.x, b.y - a.y) || 1;
      const g = s('g', { class: 'lab-link' + (selected ? ' is-sel' : ''), 'data-link': l.id },
        s('line', { class: 'lab-link__hit', x1: a.x, y1: a.y, x2: b.x, y2: b.y }),
        s('line', { class: 'lab-link__line', x1: a.x, y1: a.y, x2: b.x, y2: b.y, stroke: color }));
      [[a, l.a.i, b], [b, l.b.i, a]].forEach(([d, i, other]) => {
        const t = Math.min(0.42, 50 / len);
        const x = d.x + (other.x - d.x) * t, y = d.y + (other.y - d.y) * t;
        const itf = d.ifaces[i];
        let text = itf.name;
        if (S.view === 'l2' && kindOf(d) === 'switch' && (itf.vlan || 1) !== 1) text += ' v' + itf.vlan;
        if (S.view === 'l3' && kindOf(d) === 'router') text = itf.ip ? itf.name + ' .' + itf.ip.split('.').pop() : itf.name + ' без IP';
        g.append(s('text', { class: 'lab-port', x, y: y + 3, 'text-anchor': 'middle' }, text));
      });
      E.gLinks.append(g);
    });

    net.devices.forEach((d) => {
      const t = typeOf(d);
      const sel = S.sel === d.id;
      const g = s('g', {
        class: 'lab-node' + (sel ? ' is-sel' : '') + (S.linkFrom === d.id ? ' is-from' : ''),
        'data-node': d.id,
        transform: 'translate(' + d.x + ' ' + d.y + ')',
      });
      g.append(s('title', null, t.title + ': ' + t.hint));
      g.append(s('rect', { class: 'lab-node__halo', x: -38, y: -32, width: 84, height: 96, rx: 12 }));
      g.append(iconFor(d.type));
      // Полоска уровней OSI: снизу L1, сверху L7; подсвечены те, на которых работает устройство
      const bar = s('g', { class: 'lab-bar', transform: 'translate(33 -25)' });
      for (let n = 7; n >= 1; n--) {
        const on = n >= t.layers[0] && n <= t.layers[1];
        bar.append(s('rect', { class: 'lab-seg' + (on ? ' is-in' : ''), 'data-l': n, x: 0, y: (7 - n) * 7, width: 6, height: 5, rx: 1.5, fill: on ? LC[n] : '#2c2c38' }));
      }
      g.append(bar);
      g.append(s('text', { class: 'lab-node__name', y: 40, 'text-anchor': 'middle' }, d.name));
      const sub = nodeSub(d);
      if (sub) g.append(s('text', { class: 'lab-node__sub' + (sub.err ? ' is-err' : ''), y: 54, 'text-anchor': 'middle' }, sub.text));
      E.gNodes.append(g);
    });
  }

  function nodeSub(d) {
    const kind = kindOf(d);
    const i0 = d.ifaces[0];
    if (S.view === 'l1') return { text: typeOf(d).title };
    if (S.view === 'l2') {
      if (kind === 'host') return { text: i0.mac };
      if (kind === 'switch') {
        const vl = [...new Set(d.ifaces.filter((i) => i.link).map((i) => i.vlan || 1))].sort((a, b) => a - b);
        return { text: 'VLAN ' + (vl.length ? vl.join(', ') : '1') };
      }
      if (kind === 'router') return { text: 'граница домена' };
      return { text: 'один домен на все порты' };
    }
    if (kind === 'host') {
      if (!i0.ip) return { text: 'нет IP', err: true };
      return { text: i0.ip + '/' + i0.prefix };
    }
    if (kind === 'router') {
      const n = d.ifaces.filter((i) => i.ip && i.link).length;
      return { text: n + ' сет' + (n === 1 ? 'ь' : n > 1 && n < 5 ? 'и' : 'ей') + ', ' + d.routes.length + ' маршр.' };
    }
    return null;
  }

  function renderLegend() {
    const net = S.net;
    E.legend.replaceChildren();
    E.legend.append(h('div', { class: 'lab-legend__title' }, VIEWS[S.view].title));
    if (S.view === 'l1') {
      E.legend.append(h('div', null, 'Устройств: ' + net.devices.length + ', кабелей: ' + net.links.length));
      E.legend.append(h('div', { class: 'lab-legend__note' }, 'Полоска справа от устройства показывает его уровни OSI.'));
      return;
    }
    const dom = net.domains();
    if (!dom.info.length) { E.legend.append(h('div', null, 'Пока нет кабелей')); return; }
    dom.info.forEach((inf, n) => {
      let text;
      if (S.view === 'l2') {
        const v = [...inf.vlans];
        text = 'Домен ' + (n + 1) + (v.length ? ', VLAN ' + v.join(', ') : '');
      } else {
        const nets = [...inf.nets];
        text = nets.length ? nets.join(', ') : 'нет IP-адресов';
        if (nets.length > 1) text += '  (разные подсети в одном домене!)';
      }
      E.legend.append(h('div', { class: 'lab-legend__row' }, h('i', { style: { background: domainColor(n) } }), text));
    });
    E.legend.append(h('div', { class: 'lab-legend__note' }, S.view === 'l2'
      ? 'Цвет кабеля: широковещательный домен, докуда дойдёт ARP-запрос. Границы: маршрутизаторы и VLAN.'
      : 'Цвет кабеля: сеть. Внутри одной сети узлы общаются напрямую, между сетями только через маршрутизатор.'));
  }

  /* ===== Тулбар ===== */
  function buildToolbar() {
    const palette = h('div', { class: 'lab-group' }, h('span', { class: 'lab-group__label' }, 'Добавить'),
      Object.values(LAB.types).map((t) => h('button', {
        type: 'button', class: 'lab-add', title: t.hint, style: { '--tc': t.color },
        onClick: () => addDevice(t.type),
      }, miniIcon(t.type), t.title)));

    E.toolBtns = {};
    const tools = h('div', { class: 'lab-group lab-seg-ctl' }, Object.entries(TOOLS).map(([id, t]) =>
      (E.toolBtns[id] = h('button', { type: 'button', title: t.hint + ' (клавиша ' + t.key + ')', onClick: () => setTool(id) }, t.label))));

    E.viewBtns = {};
    const views = h('div', { class: 'lab-group lab-seg-ctl' }, h('span', { class: 'lab-group__label' }, 'Вид'),
      Object.entries(VIEWS).map(([id, v]) => (E.viewBtns[id] = h('button', { type: 'button', title: v.title, onClick: () => setView(id) }, v.label))));

    E.presetSel = h('select', { class: 'lab-select', 'aria-label': 'Готовые схемы', onChange: (e) => { if (e.target.value) loadPreset(e.target.value); e.target.value = ''; } },
      h('option', { value: '' }, 'Готовые схемы'),
      LAB.presets.map((p) => h('option', { value: p.id }, (p.task ? 'Задача: ' : '') + p.title.replace(/^Задача: /, ''))));
    const file = h('input', { type: 'file', accept: '.json,application/json', hidden: true, onChange: importFile });
    const files = h('div', { class: 'lab-group' },
      E.presetSel,
      h('button', { type: 'button', class: 'lab-btn', onClick: exportFile, title: 'Скачать схему в JSON' }, 'Экспорт'),
      h('button', { type: 'button', class: 'lab-btn', onClick: () => file.click(), title: 'Загрузить схему из JSON' }, 'Импорт'),
      h('button', { type: 'button', class: 'lab-btn', onClick: () => { if (confirm('Очистить холст?')) setNet(new LAB.Net(), null); } }, 'Очистить'),
      file);

    E.toolbar.append(palette, tools, views, files);
  }

  function miniIcon(type) {
    return s('svg', { viewBox: '-30 -26 60 52', width: 22, height: 19, 'aria-hidden': 'true' }, iconFor(type));
  }

  function setTool(id) {
    S.tool = id;
    S.linkFrom = null;
    Object.entries(E.toolBtns).forEach(([k, b]) => b.classList.toggle('active', k === id));
    E.stage.dataset.tool = id;
    hint(TOOLS[id].hint);
    renderGraph();
  }

  function setView(id) {
    S.view = id;
    Object.entries(E.viewBtns).forEach(([k, b]) => b.classList.toggle('active', k === id));
    save();
    renderGraph();
    renderLegend();
  }

  let hintTimer = null;
  function hint(text, level) {
    E.hint.textContent = text;
    E.hint.className = 'lab-hint' + (level ? ' is-' + level : '');
    clearTimeout(hintTimer);
    if (level) hintTimer = setTimeout(() => hint(TOOLS[S.tool].hint), 3500);
  }

  function addDevice(type) {
    const n = S.net.devices.length;
    const d = S.net.add(type, S.cam.x + ((n * 37) % 120) - 60, S.cam.y + ((n * 53) % 100) - 50);
    S.sel = d.id;
    changed();
    hint(d.name + ' добавлен. ' + (kindOf(d) === 'host' || kindOf(d) === 'router' ? 'Задай ему адрес в панели справа.' : 'Соедини его кабелем.'), 'ok');
  }

  function removeDevice(id) {
    const d = S.net.dev(id);
    if (!d) return;
    S.net.remove(id);
    delete S.consoles[id];
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

  /* ===== Мышь и касания на холсте ===== */
  function bindStage() {
    let drag = null, pan = null;

    E.svg.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) return;
      const nodeEl = e.target.closest('[data-node]');
      const linkEl = e.target.closest('[data-link]');
      const w = toWorld(e);
      try { E.svg.setPointerCapture(e.pointerId); } catch (err) { /* без захвата тоже работает */ }

      if (nodeEl) {
        const id = nodeEl.dataset.node;
        if (S.tool === 'delete') { removeDevice(id); return; }
        if (S.tool === 'link') {
          if (!S.linkFrom) {
            S.linkFrom = id;
            hint('Теперь щёлкни второе устройство');
            renderGraph();
          } else {
            const r = S.net.connect(S.linkFrom, id);
            S.linkFrom = null;
            E.gPreview.replaceChildren();
            if (r.error) { hint(r.error, 'err'); renderGraph(); return; }
            changed();
            const a = S.net.dev(r.link.a.dev), b = S.net.dev(r.link.b.dev);
            hint('Кабель: ' + a.name + ' ' + a.ifaces[r.link.a.i].name + ' ↔ ' + b.name + ' ' + b.ifaces[r.link.b.i].name, 'ok');
          }
          return;
        }
        const d = S.net.dev(id);
        drag = { id, dx: d.x - w.x, dy: d.y - w.y, moved: false };
        if (S.sel !== id) { S.sel = id; renderGraph(); renderInspector(); }
        return;
      }
      if (linkEl) {
        const id = linkEl.dataset.link;
        if (S.tool === 'delete') { removeLink(id); return; }
        S.sel = 'link:' + id;
        renderGraph();
        renderInspector();
        return;
      }
      pan = { x: e.clientX, y: e.clientY, cx: S.cam.x, cy: S.cam.y, moved: false };
    });

    E.svg.addEventListener('pointermove', (e) => {
      if (drag) {
        const w = toWorld(e);
        const d = S.net.dev(drag.id);
        if (!d) return;
        d.x = Math.round((w.x + drag.dx) / 10) * 10;
        d.y = Math.round((w.y + drag.dy) / 10) * 10;
        drag.moved = true;
        renderGraph();
        return;
      }
      if (pan) {
        const dx = e.clientX - pan.x, dy = e.clientY - pan.y;
        if (Math.abs(dx) + Math.abs(dy) > 3) pan.moved = true;
        S.cam.x = pan.cx - dx / S.cam.k;
        S.cam.y = pan.cy - dy / S.cam.k;
        applyCam();
        return;
      }
      if (S.linkFrom) {
        const a = S.net.dev(S.linkFrom);
        const w = toWorld(e);
        E.gPreview.replaceChildren(s('line', { class: 'lab-preview', x1: a.x, y1: a.y, x2: w.x, y2: w.y }));
      }
    });

    const up = () => {
      if (drag && drag.moved) save();
      if (pan && !pan.moved) {
        // Щелчок по пустому месту
        if (S.linkFrom) { S.linkFrom = null; E.gPreview.replaceChildren(); hint(TOOLS[S.tool].hint); }
        if (S.sel) { S.sel = null; renderInspector(); }
        renderGraph();
      }
      drag = null;
      pan = null;
    };
    E.svg.addEventListener('pointerup', up);
    E.svg.addEventListener('pointercancel', up);

    E.svg.addEventListener('dblclick', (e) => {
      const nodeEl = e.target.closest('[data-node]');
      if (!nodeEl || S.tool !== 'select') return;
      S.sel = nodeEl.dataset.node;
      renderInspector();
      const inp = E.side.querySelector('.lab-console input');
      if (inp) { inp.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); inp.focus({ preventScroll: true }); }
    });

    E.svg.addEventListener('wheel', (e) => {
      e.preventDefault();
      zoom(e.deltaY < 0 ? 1.12 : 1 / 1.12, e);
    }, { passive: false });

    if (window.ResizeObserver) new ResizeObserver(applyCam).observe(E.svg);
    else window.addEventListener('resize', applyCam);

    document.addEventListener('keydown', (e) => {
      if (isTyping(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'escape') { setTool('select'); E.gPreview.replaceChildren(); return; }
      if ((k === 'delete' || k === 'backspace') && S.sel) {
        e.preventDefault();
        if (S.sel.startsWith('link:')) removeLink(S.sel.slice(5)); else removeDevice(S.sel);
        return;
      }
      const tool = Object.entries(TOOLS).find(([, t]) => t.key.toLowerCase() === k);
      if (tool) setTool(tool[0]);
    });
  }

  /* ===== Инспектор ===== */
  function section(title, ...body) {
    return h('div', { class: 'lab-sec' }, h('div', { class: 'lab-sec__title' }, title), body);
  }

  function peerText(d, i) {
    const p = S.net.peer(d.id, i);
    return p ? '→ ' + p.dev.name + ' ' + p.dev.ifaces[p.ifIdx].name : 'не подключён';
  }

  // Поле, которое применяется по Enter или при уходе фокуса
  function field(label, value, placeholder, apply, help) {
    const err = h('div', { class: 'lab-field__err' });
    const input = h('input', {
      class: 'input lab-input', value: value || '', placeholder: placeholder || '', spellcheck: 'false',
      onChange: (e) => {
        const msg = apply(e.target.value.trim());
        if (msg) { err.textContent = msg; input.classList.add('is-bad'); return; }
        changed();
      },
      onKeydown: (e) => { if (e.key === 'Enter') e.target.blur(); },
    });
    return h('label', { class: 'lab-field' }, h('span', null, label), input, help ? h('small', null, help) : null, err);
  }

  function cidrApply(itf) {
    return (v) => {
      if (!v) { itf.ip = ''; return ''; }
      const c = ip.parseCidr(v);
      if (!c) return 'формат: 192.168.1.10/24 или 192.168.1.10 255.255.255.0';
      const bad = ip.hostError(c.ip, c.prefix);
      if (bad) return bad;
      itf.ip = c.ip;
      itf.prefix = c.prefix;
      return '';
    };
  }

  function renderInspector() {
    const side = E.side;
    side.replaceChildren();
    if (!S.sel) return renderHelp();

    if (S.sel.startsWith('link:')) {
      const l = S.net.link(S.sel.slice(5));
      if (!l) { S.sel = null; return renderHelp(); }
      const a = S.net.dev(l.a.dev), b = S.net.dev(l.b.dev);
      side.append(
        h('div', { class: 'lab-insp__head' }, h('div', null, h('div', { class: 'lab-insp__type' }, 'L1 / кабель'), h('div', { class: 'lab-insp__name' }, 'Кабель'))),
        section('Концы',
          h('div', { class: 'lab-kv' }, a.name, h('b', null, a.ifaces[l.a.i].name)),
          h('div', { class: 'lab-kv' }, b.name, h('b', null, b.ifaces[l.b.i].name))),
        h('button', { type: 'button', class: 'btn lab-danger', onClick: () => removeLink(l.id) }, 'Удалить кабель'));
      return;
    }

    const d = S.net.dev(S.sel);
    if (!d) { S.sel = null; return renderHelp(); }
    const t = typeOf(d);
    const kind = t.kind;

    const layers = h('div', { class: 'lab-insp__layers' });
    for (let n = 7; n >= 1; n--) {
      const on = n >= t.layers[0] && n <= t.layers[1];
      layers.append(h('span', { class: on ? 'is-in' : '', style: { '--lc': LC[n] }, title: 'L' + n + ' ' + LN[n] }, n));
    }

    side.append(h('div', { class: 'lab-insp__head', style: { '--tc': t.color } },
      h('span', { class: 'lab-insp__icon' }, miniIcon(d.type)),
      h('div', { class: 'lab-insp__title' },
        h('div', { class: 'lab-insp__type' }, t.title + ', уровни L' + t.layers[0] + (t.layers[1] !== t.layers[0] ? '–L' + t.layers[1] : '')),
        h('input', {
          class: 'lab-insp__name lab-insp__name-input', value: d.name, 'aria-label': 'Имя устройства', spellcheck: 'false',
          onChange: (e) => { const v = e.target.value.trim(); if (v) { d.name = v.slice(0, 24); save(); renderGraph(); renderSimControls(); } else e.target.value = d.name; },
          onKeydown: (e) => { if (e.key === 'Enter') e.target.blur(); },
        })),
      layers),
    h('p', { class: 'lab-insp__hint' }, t.hint));

    if (kind === 'host') {
      const i0 = d.ifaces[0];
      side.append(section('Сетевые настройки',
        field('IP-адрес / префикс', i0.ip ? i0.ip + '/' + i0.prefix : '', '192.168.1.10/24', cidrApply(i0),
          i0.ip ? 'маска ' + ip.maskStr(i0.prefix) + ', сеть ' + ip.netStr(i0.ip, i0.prefix) : 'без адреса узел не сможет общаться по IP'),
        field('Шлюз по умолчанию', d.gateway, '192.168.1.1', (v) => {
          if (v && ip.parse(v) == null) return 'неверный IP-адрес';
          if (v && i0.ip && !ip.same(v, i0.ip, i0.prefix)) return 'шлюз должен быть в сети ' + ip.netStr(i0.ip, i0.prefix);
          d.gateway = v ? ip.str(ip.parse(v)) : '';
          return '';
        }, 'адрес маршрутизатора в этой же сети'),
        h('div', { class: 'lab-kv' }, 'MAC', h('code', null, i0.mac)),
        h('div', { class: 'lab-kv' }, i0.name, h('span', null, peerText(d, 0)))));
    }

    if (kind === 'router') {
      side.append(section('Интерфейсы', d.ifaces.map((itf, i) =>
        field(itf.name + '  ' + peerText(d, i), itf.ip ? itf.ip + '/' + itf.prefix : '', 'не задан', cidrApply(itf)))));
      side.append(routesSection(d));
    }

    if (kind === 'switch') {
      side.append(section('Порты и VLAN', h('div', { class: 'lab-ports' }, d.ifaces.map((itf, i) => h('div', { class: 'lab-port-row' + (itf.link ? '' : ' is-off') },
        h('b', null, itf.name),
        h('span', null, peerText(d, i)),
        h('input', {
          class: 'input lab-vlan', type: 'number', min: 1, max: 4094, value: itf.vlan || 1, title: 'VLAN порта', 'aria-label': 'VLAN порта ' + itf.name,
          onChange: (e) => { const v = Math.round(Number(e.target.value)); if (v >= 1 && v <= 4094) { itf.vlan = v; changed(); } else e.target.value = itf.vlan || 1; },
        }))))));
      const mac = Object.entries(S.sim.tables(d).mac);
      side.append(section('Таблица MAC-адресов', mac.length
        ? h('table', { class: 'lab-table' }, h('tbody', null, mac.map(([k, port]) => { const [v, m] = k.split('|'); return h('tr', null, h('td', null, m), h('td', null, d.ifaces[port].name), h('td', null, 'v' + v)); })))
        : h('p', { class: 'lab-empty' }, 'Пусто. Коммутатор выучит адреса, когда через него пройдут кадры.')));
    }

    if (kind === 'hub') {
      side.append(section('Порты', h('div', { class: 'lab-ports' }, d.ifaces.map((itf, i) => h('div', { class: 'lab-port-row' + (itf.link ? '' : ' is-off') },
        h('b', null, itf.name), h('span', null, peerText(d, i)))))));
    }

    if (kind === 'host' || kind === 'router') {
      const arp = Object.entries(S.sim.tables(d).arp);
      side.append(section('ARP-таблица', arp.length
        ? h('table', { class: 'lab-table' }, h('tbody', null, arp.map(([a, m]) => h('tr', null, h('td', null, a), h('td', null, m)))))
        : h('p', { class: 'lab-empty' }, 'Пусто. Заполнится после первого обмена.')));
    }

    side.append(consoleSection(d));
    side.append(h('button', { type: 'button', class: 'btn lab-danger', onClick: () => removeDevice(d.id) }, 'Удалить ' + d.name));
  }

  function routesSection(d) {
    const rows = LAB.routeTable(d);
    const netIn = h('input', { class: 'input lab-input', placeholder: '10.3.0.0/24', spellcheck: 'false' });
    const viaIn = h('input', { class: 'input lab-input', placeholder: 'через 10.0.12.2', spellcheck: 'false' });
    const err = h('div', { class: 'lab-field__err' });
    const add = () => {
      const c = ip.parseCidr(netIn.value);
      const via = ip.parse(viaIn.value);
      if (!c) { err.textContent = 'сеть в формате 10.3.0.0/24'; return; }
      if (via == null) { err.textContent = 'следующий хоп: IP соседнего маршрутизатора'; return; }
      d.routes.push({ net: ip.str(ip.net(ip.parse(c.ip), c.prefix)), prefix: c.prefix, via: ip.str(via) });
      changed();
    };
    return section('Таблица маршрутизации',
      rows.length ? h('table', { class: 'lab-table lab-routes' }, h('tbody', null, rows.map((r) => h('tr', { class: r.code === 'S' && !r.iface ? 'is-bad' : '' },
        h('td', null, h('span', { class: 'lab-code lab-code--' + r.code }, r.code)),
        h('td', null, r.net),
        h('td', null, r.code === 'C' ? r.iface : r.via + (r.iface ? '' : ' ?')),
        h('td', null, r.code === 'S' ? h('button', { type: 'button', class: 'lab-x', title: 'Удалить маршрут', onClick: () => { d.routes.splice(r.idx, 1); changed(); } }, '×') : null)))))
        : h('p', { class: 'lab-empty' }, 'Пусто: задай адреса интерфейсам и подключи кабели.'),
      h('div', { class: 'lab-route-add' }, netIn, viaIn, h('button', { type: 'button', class: 'btn', onClick: add }, 'Добавить')),
      err,
      h('small', { class: 'lab-note' }, 'C: сеть подключена к интерфейсу. S: статический маршрут «чтобы попасть в сеть, отдай пакет соседу». Красный: сосед недостижим ни через один интерфейс.'));
  }

  function drawConsole(out, lines) {
    out.replaceChildren(...lines.slice(-200).map((l) => h('div', { class: l.cls ? 'is-' + l.cls : null }, l.text)));
    out.scrollTop = out.scrollHeight;
  }

  function consoleSection(d) {
    const lines = S.consoles[d.id] || (S.consoles[d.id] = [{ text: 'Консоль ' + d.name + '. Введи help.', cls: 'dim' }]);
    const out = h('div', { class: 'lab-console__out', 'data-dev': d.id });
    const draw = () => drawConsole(out, lines);
    // Пока шла анимация, инспектор мог перерисоваться: пишем в ту консоль, что сейчас на экране
    const print = (text, cls) => {
      lines.push({ text, cls });
      const live = E.side.querySelector('.lab-console__out[data-dev="' + d.id + '"]');
      if (live) drawConsole(live, lines);
    };
    const history = [];
    let hi = 0;
    const input = h('input', {
      class: 'lab-console__in', spellcheck: 'false', autocomplete: 'off', 'aria-label': 'Команда для ' + d.name,
      onKeydown: (e) => {
        if (e.key === 'ArrowUp') { e.preventDefault(); if (hi > 0) input.value = history[--hi]; return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(history.length, hi + 1); input.value = history[hi] || ''; return; }
        if (e.key !== 'Enter') return;
        const line = input.value.trim();
        input.value = '';
        if (!line) return;
        history.push(line);
        hi = history.length;
        print(d.name + '> ' + line, 'cmd');
        const found = LAB.findCommand(line, kindOf(d));
        if (!found) { print('неизвестная команда, введи help', 'err'); return; }
        const ctx = {
          dev: d, net: S.net, sim: S.sim, ip, print,
          clear: () => { lines.length = 0; draw(); },
          ping: (dst) => runSim('ping', d.id, dst),
          tracert: (dst) => runSim('tracert', d.id, dst),
          changed: () => { changed(); },
        };
        const res = found.cmd.run(ctx, found.args);
        if (res && res.then) {
          input.disabled = true;
          res.finally(() => {
            // Инспектор мог перерисоваться: возвращаем фокус в актуальную консоль
            input.disabled = false;
            const live = E.side.querySelector('.lab-console__in');
            if (live && S.sel === d.id) live.focus({ preventScroll: true });
          });
        }
      },
    });
    draw();
    return section('Консоль', h('div', { class: 'lab-console' }, out, h('div', { class: 'lab-console__row' }, h('span', null, d.name + '>'), input)));
  }

  function renderHelp() {
    const p = LAB.presets.find((x) => x.id === S.preset);
    if (p) {
      E.side.append(h('div', { class: 'lab-preset' + (p.task ? ' is-task' : '') },
        h('div', { class: 'lab-sec__title' }, p.task ? 'Задача' : 'Схема'),
        h('div', { class: 'lab-insp__name' }, p.title),
        h('p', null, p.desc),
        p.try ? h('button', { type: 'button', class: 'btn btn--primary', onClick: () => tryPreset(p) }, 'ping ' + p.try[1] + ' с ' + p.try[0]) : null));
    }
    E.side.append(section('Как пользоваться', h('ul', { class: 'lab-help' },
      h('li', null, 'Добавь устройства кнопками сверху и соедини их инструментом ', h('b', null, 'Кабель'), '.'),
      h('li', null, 'Щёлкни устройство, чтобы задать IP, шлюз, маршруты или VLAN. Двойной щелчок открывает консоль (ping, tracert, show ip route).'),
      h('li', null, 'Внизу выбери, откуда и куда пинговать: пакеты побегут по кабелям, а полоска уровней на устройствах покажет, до какого уровня OSI каждое из них разобрало кадр.'),
      h('li', null, 'Щёлкни строку журнала, чтобы увидеть кадр по слоям: Ethernet, ARP, IP, ICMP.'),
      h('li', null, 'Виды L1, L2, L3 показывают ту же сеть глазами разных уровней: кабели, широковещательные домены, подсети.'),
      h('li', null, 'Клавиши: V выбор, C кабель, D удаление, Delete удалить выделенное, Esc отмена.'))));
  }

  function tryPreset(p) {
    const d = S.net.byName(p.try[0]);
    if (!d) return;
    E.src.value = d.id;
    E.dst.value = p.try[1];
    runSim('ping', d.id, p.try[1]);
  }

  function fillTry() {
    const p = LAB.presets.find((x) => x.id === S.preset);
    if (!p || !p.try) return;
    const d = S.net.byName(p.try[0]);
    if (d) { E.src.value = d.id; E.dst.value = p.try[1]; }
  }

  /* ===== Панель симуляции ===== */
  function buildSimBar() {
    E.src = h('select', { class: 'lab-select', 'aria-label': 'Откуда' });
    E.dst = h('input', { class: 'input lab-input lab-dst', placeholder: 'IP получателя', list: 'labIps', spellcheck: 'false', 'aria-label': 'Куда',
      onKeydown: (e) => { if (e.key === 'Enter') startFromBar('ping'); } });
    E.ips = h('datalist', { id: 'labIps' });
    E.speedSel = h('select', { class: 'lab-select', 'aria-label': 'Скорость', onChange: (e) => { S.speed = Number(e.target.value); } },
      [['0.5', '0.5×'], ['1', '1×'], ['2', '2×'], ['4', '4×']].map(([v, l]) => h('option', { value: v, selected: v === '1' }, l)));
    E.skipBtn = h('button', { type: 'button', class: 'btn', hidden: true, onClick: () => stopAnim() }, 'Пропустить анимацию');
    E.status = h('span', { class: 'lab-status' });
    E.simbar.append(
      h('div', { class: 'lab-sim__ctl' },
        h('span', { class: 'lab-group__label' }, 'Откуда'), E.src,
        h('span', { class: 'lab-group__label' }, 'Куда'), E.dst, E.ips,
        h('button', { type: 'button', class: 'btn btn--primary', onClick: () => startFromBar('ping') }, 'Ping'),
        h('button', { type: 'button', class: 'btn', onClick: () => startFromBar('tracert') }, 'Tracert'),
        h('span', { class: 'lab-group__label' }, 'Скорость'), E.speedSel,
        E.skipBtn,
        h('button', { type: 'button', class: 'btn lab-btn--quiet', title: 'Очистить ARP- и MAC-таблицы на всех устройствах', onClick: () => { S.sim.reset(); renderInspector(); hint('ARP- и MAC-таблицы очищены', 'ok'); } }, 'Сбросить таблицы'),
        E.status),
      h('div', { class: 'lab-sim__body' },
        h('div', { class: 'lab-log-wrap' }, h('div', { class: 'lab-sec__title' }, 'Журнал'), E.log = h('div', { class: 'lab-log', 'aria-live': 'polite' })),
        h('div', { class: 'lab-pdu-wrap' }, h('div', { class: 'lab-sec__title' }, 'Кадр по уровням'), E.pdu = h('div', { class: 'lab-pdu' }, h('p', { class: 'lab-empty' }, 'Щёлкни строку журнала со стрелкой, чтобы разобрать кадр.')))));
  }

  function renderSimControls() {
    const prev = E.src.value;
    const sources = S.net.devices.filter((d) => ['host', 'router'].includes(kindOf(d)));
    E.src.replaceChildren(...sources.map((d) => h('option', { value: d.id }, d.name)));
    if (sources.some((d) => d.id === prev)) E.src.value = prev;
    const ips = [];
    S.net.devices.forEach((d) => d.ifaces.forEach((i) => { if (i.ip) ips.push([i.ip, d.name]); }));
    E.ips.replaceChildren(...ips.map(([a, n]) => h('option', { value: a }, n)));
  }

  function startFromBar(kind) {
    const src = E.src.value;
    const dst = E.dst.value.trim();
    if (!src) { hint('Добавь узел или маршрутизатор, с которого пинговать', 'err'); return; }
    if (ip.parse(dst) == null) { hint('Укажи IP-адрес получателя', 'err'); E.dst.focus(); return; }
    const d = S.net.dev(src);
    const lines = S.consoles[src] || (S.consoles[src] = []);
    const print = (text, cls) => {
      lines.push({ text, cls });
      const live = E.side.querySelector('.lab-console__out[data-dev="' + src + '"]');
      if (live) drawConsole(live, lines);
    };
    const cmd = kind === 'ping' ? 'ping' : (kindOf(d) === 'router' ? 'traceroute' : 'tracert');
    print(d.name + '> ' + cmd + ' ' + dst, 'cmd');
    const found = LAB.findCommand(cmd + ' ' + dst, kindOf(d));
    const res = found.cmd.run({
      dev: d, net: S.net, sim: S.sim, ip, print, clear: () => {},
      ping: (x) => runSim('ping', src, x), tracert: (x) => runSim('tracert', src, x), changed,
    }, found.args);
  }

  /* ===== Журнал и разбор кадра ===== */
  function clearLog() {
    if (E.log) E.log.replaceChildren();
    if (E.pdu) E.pdu.replaceChildren(h('p', { class: 'lab-empty' }, 'Щёлкни строку журнала со стрелкой, чтобы разобрать кадр.'));
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
    if (ev.type === 'hop') {
      E.gLinks.querySelectorAll('.is-flash').forEach((x) => x.classList.remove('is-flash'));
      const l = E.gLinks.querySelector('[data-link="' + ev.link + '"]');
      if (l) l.classList.add('is-flash');
    }
  }

  function layerBox(n, title, rows, inner) {
    return h('div', { class: 'lab-pdu__box', style: { '--lc': LC[n] } },
      h('div', { class: 'lab-pdu__head' }, h('span', null, 'L' + n), title),
      rows && rows.length ? h('table', { class: 'lab-pdu__fields' }, h('tbody', null, rows.map(([k, v]) => h('tr', null, h('th', null, k), h('td', null, v))))) : null,
      inner || null);
  }

  function renderPdu(ev) {
    const f = ev.frame;
    const net = S.net;
    let inner = null;
    if (f.type === 'arp') {
      const a = f.arp;
      inner = layerBox(2, 'ARP (связывает L3-адрес с L2)', [
        ['Операция', a.op === 'request' ? '1, запрос' : '2, ответ'],
        ['MAC отправителя', a.smac], ['IP отправителя', a.sip],
        ['Искомый MAC', a.op === 'request' ? '00:00:00:00:00:00 (ещё не знаем)' : a.tmac], ['Искомый IP', a.tip],
      ]);
    } else {
      const p = f.ip;
      const icmpNames = { 'echo-request': '8, эхо-запрос', 'echo-reply': '0, эхо-ответ', 'time-exceeded': '11, время жизни истекло', unreachable: '3, назначение недоступно' };
      const icmp = h('div', { class: 'lab-pdu__box lab-pdu__box--icmp', style: { '--lc': LC[3] } },
        h('div', { class: 'lab-pdu__head' }, h('span', null, 'L3'), 'ICMP'),
        h('table', { class: 'lab-pdu__fields' }, h('tbody', null,
          h('tr', null, h('th', null, 'Тип'), h('td', null, icmpNames[p.icmp.type] || p.icmp.type)),
          p.icmp.seq ? h('tr', null, h('th', null, 'Номер'), h('td', null, p.icmp.seq)) : null)),
        h('p', { class: 'lab-pdu__note' }, 'Выше L3 у ping ничего нет: ICMP живёт на сетевом уровне, без портов и TCP/UDP.'));
      inner = layerBox(3, 'IPv4-пакет', [
        ['IP отправителя', p.src], ['IP получателя', p.dst],
        ['TTL', String(p.ttl)], ['Протокол', '1 (ICMP)'],
      ], icmp);
    }
    const frameBox = layerBox(2, 'Ethernet-кадр', [
      ['MAC получателя', f.dst === LAB.BCAST ? f.dst + ' (всем)' : f.dst],
      ['MAC отправителя', f.src],
      ['EtherType', f.type === 'arp' ? '0x0806 (ARP)' : '0x0800 (IPv4)'],
    ], inner);
    let where = '';
    if (ev.type === 'hop') {
      const a = net.dev(ev.from), b = net.dev(ev.to);
      where = (a ? a.name : '?') + ' → ' + (b ? b.name : '?');
    } else {
      const d = net.dev(ev.dev);
      where = 'на ' + (d ? d.name : '?');
    }
    E.pdu.replaceChildren(
      h('div', { class: 'lab-pdu__where' }, 'Такт ' + ev.tick + ', ' + where),
      h('div', { class: 'lab-pdu__box lab-pdu__box--l1', style: { '--lc': LC[1] } },
        h('div', { class: 'lab-pdu__head' }, h('span', null, 'L1'), 'биты в кабеле'),
        frameBox,
        h('div', { class: 'lab-pdu__bits' }, '10101010 … преамбула, затем кадр побитно, в конце FCS (контрольная сумма)')));
  }

  /* ===== Анимация ===== */
  function flashLayers(devId, layer, level) {
    const g = E.gNodes.querySelector('[data-node="' + devId + '"]');
    if (!g) return;
    const t = typeOf(S.net.dev(devId));
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

  function runSim(kind, srcId, dst) {
    stopAnim();
    const d = S.net.dev(srcId);
    const r = kind === 'ping' ? S.sim.ping(srcId, dst) : S.sim.tracert(srcId, dst);
    clearLog();
    E.log.append(h('div', { class: 'lab-log__row is-head' }, (kind === 'ping' ? 'ping ' : 'tracert ') + dst + ' с ' + (d ? d.name : '?')));
    return animate(r).then(() => {
      let text, cls;
      if (kind === 'ping') {
        const res = r.results[0];
        if (res && res.type === 'reply') { text = 'Итог: ответ от ' + res.from + ', TTL ' + res.ttl; cls = 'ok'; }
        else if (res && res.type === 'local-err') { text = 'Итог: ' + res.text; cls = 'err'; }
        else if (res && res.type === 'unreach') { text = 'Итог: ' + res.from + ' сообщает, что сеть недоступна'; cls = 'err'; }
        else if (res && res.type === 'arp-fail') { text = 'Итог: на ARP-запрос никто не ответил'; cls = 'err'; }
        else { text = 'Итог: ответ не пришёл'; cls = 'err'; }
      } else {
        const last = r.hops[r.hops.length - 1];
        text = 'Итог: ' + r.hops.map((x) => x.from || '*').join(' → ');
        cls = last && last.done ? 'ok' : 'err';
      }
      if (r.storm) { text += '. Широковещательный шторм'; cls = 'err'; }
      E.log.append(h('div', { class: 'lab-log__row is-sum is-' + cls }, text));
      E.log.scrollTop = E.log.scrollHeight;
      E.status.textContent = '';
      if (S.sel) renderInspector();
      return r;
    });
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
    E.skipBtn.hidden = false;
    E.status.textContent = 'идёт симуляция';

    return new Promise((resolve) => {
      const reveal = (ev, quiet) => {
        if (rows < maxRows) { E.log.append(logRow(ev)); rows++; }
        else if (rows === maxRows) { E.log.append(h('div', { class: 'lab-log__row is-err' }, 'И ещё ' + (total - i) + ' событий...')); rows++; }
        if (quiet) return;
        if (ev.type === 'log' && ev.dev) flashLayers(ev.dev, ev.layer, ev.level);
        if (ev.type === 'hop' && pills.length < maxPills) {
          const color = KIND_COLOR[ev.kind];
          const el = s('g', { class: 'lab-pill' },
            s('rect', { x: -19, y: -9, width: 38, height: 18, rx: 9, fill: color }),
            s('text', { y: 4, 'text-anchor': 'middle' }, KIND_TEXT[ev.kind]));
          E.gPk.append(el);
          pills.push({ ev, el });
          const l = E.gLinks.querySelector('[data-link="' + ev.link + '"]');
          if (l) l.classList.add('is-busy');
        }
      };

      const finish = () => {
        while (i < events.length) reveal(events[i++], true);
        pills.forEach((p) => p.el.remove());
        E.gLinks.querySelectorAll('.is-busy').forEach((x) => x.classList.remove('is-busy'));
        E.skipBtn.hidden = true;
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
              const l = E.gLinks.querySelector('[data-link="' + p.ev.link + '"]');
              if (l) l.classList.remove('is-busy');
            }
            continue;
          }
          // Плавный разгон и торможение на кабеле
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
    const a = h('a', { href: URL.createObjectURL(blob), download: 'network.json' });
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
        setNet(LAB.Net.from(JSON.parse(txt)), null);
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
    renderInspector();
    renderSimControls();
  }

  function boot() {
    const root = document.getElementById('lab');
    if (!root) return;
    E.toolbar = h('div', { class: 'lab-toolbar panel' });
    E.svg = s('svg', { class: 'lab-svg', role: 'img', 'aria-label': 'Схема сети' },
      s('defs', null, s('pattern', { id: 'labGrid', width: 40, height: 40, patternUnits: 'userSpaceOnUse' },
        s('circle', { cx: 1, cy: 1, r: 1, fill: '#2e2e3a' }))),
      s('rect', { x: -5000, y: -5000, width: 10000, height: 10000, fill: 'url(#labGrid)' }),
      E.gLinks = s('g'), E.gPreview = s('g'), E.gNodes = s('g'), E.gPk = s('g', { class: 'lab-pk' }));
    E.hint = h('div', { class: 'lab-hint' });
    E.legend = h('div', { class: 'lab-legend' });
    E.stage = h('div', { class: 'lab-stage' }, E.svg, E.hint, E.legend,
      h('div', { class: 'lab-zoom' },
        h('button', { type: 'button', title: 'Приблизить', onClick: () => zoom(1.2) }, '+'),
        h('button', { type: 'button', title: 'Отдалить', onClick: () => zoom(1 / 1.2) }, '−'),
        h('button', { type: 'button', title: 'Показать всю схему', onClick: fit }, '⤢')));
    E.side = h('aside', { class: 'lab-side panel' });
    E.simbar = h('div', { class: 'lab-sim panel' });
    root.append(E.toolbar, h('div', { class: 'lab-main' }, E.stage, E.side), E.simbar);

    buildToolbar();
    buildSimBar();
    bindStage();

    if (load()) {
      S.sim = new LAB.Sim(S.net);
      renderAll();
      requestAnimationFrame(fit);
      fillTry();
    } else {
      requestAnimationFrame(() => loadPreset('routed'));
      S.net = new LAB.Net();
      S.sim = new LAB.Sim(S.net);
    }
    setTool('select');
    setView(S.view);
  }

  window.LAB_UI = { state: S, runSim, loadPreset };

  if (document.readyState === 'complete') setTimeout(boot);
  else document.addEventListener('DOMContentLoaded', boot);
})();
