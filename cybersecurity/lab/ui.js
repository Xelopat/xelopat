// Сетевая лаборатория: холст, карточка устройства, симуляция с анимацией пакетов.
// Логика сети живёт в net.js, окна устройств (веб-интерфейс, терминал, браузер) в windows.js.
(function () {
  const ip = LAB.ip;
  const NS = 'http://www.w3.org/2000/svg';
  const STORE = 'cs-lab:v2';
  const TICK_MS = 950;
  const LC = { 7: '#ff8f8f', 6: '#f5a964', 5: '#f9c940', 4: '#61d1ad', 3: '#5aa7ff', 2: '#9d8ff0', 1: '#d68fe0' };
  const LN = { 7: 'Прикладной', 6: 'Представления', 5: 'Сеансовый', 4: 'Транспортный', 3: 'Сетевой', 2: 'Канальный', 1: 'Физический' };
  // Приглушённые цвета: сеть различима, но не кричит
  const DOMAIN_COLORS = ['#5f9e8a', '#6f8fb8', '#b39a5a', '#a87878', '#8a80b0', '#7fa3a8', '#9c8a6c', '#8e7f9c', '#7f9c78'];
  // Четыре цвета на все пакеты: ARP (L2), ICMP, ошибка, прикладные службы
  const KIND_COLOR = { arp: '#c9a640', req: '#61d1ad', rep: '#61d1ad', err: '#e06c6c', dhcp: '#8fa6c8', dns: '#8fa6c8', tcp: '#8fa6c8', http: '#8fa6c8' };
  const KIND_TEXT = { arp: 'ARP', req: 'ICMP', rep: 'ICMP', err: 'ICMP', dhcp: 'DHCP', dns: 'DNS', tcp: 'TCP', http: 'HTTP' };
  const VIEWS = {
    l1: { label: 'L1', title: 'Физический вид: кабели и порты' },
    l2: { label: 'L2', title: 'Канальный вид: широковещательные домены, MAC и VLAN' },
    l3: { label: 'L3', title: 'Сетевой вид: подсети и IP-адреса' },
  };
  const TOOLS = {
    select: { label: 'Выбор', key: 'V', hint: 'Перетаскивай устройства, щёлкни, чтобы увидеть карточку. Двойной щелчок открывает настройки. Пустое место двигает холст, колесо масштабирует.' },
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
    terms: {},
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

  /* ===== Хранение ===== */
  function save() {
    try { localStorage.setItem(STORE, JSON.stringify({ net: S.net, preset: S.preset, view: S.view })); } catch (e) { /* без хранилища просто не запоминаем */ }
  }
  function load() {
    try {
      const raw = localStorage.getItem(STORE) || localStorage.getItem('cs-lab:v1');
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
    if (LAB.Win) LAB.Win.closeAll();
    S.net = net;
    S.sim = new LAB.Sim(net);
    S.sel = null;
    S.linkFrom = null;
    S.terms = {};
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
    const xs = ds.map((d) => d.x), ys = ds.map((d) => d.y);
    const minX = Math.min(...xs) - 90, maxX = Math.max(...xs) + 90;
    const minY = Math.min(...ys) - 80, maxY = Math.max(...ys) + 100;
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
      const down = net.linkDown(l);
      const color = down ? '#55394a' : dom ? domainColor(dom.linkDomain[l.id]) : '#5a5a6e';
      const selected = S.sel === 'link:' + l.id;
      const len = Math.hypot(b.x - a.x, b.y - a.y) || 1;
      const g = s('g', { class: 'lab-link' + (selected ? ' is-sel' : '') + (down ? ' is-down' : ''), 'data-link': l.id },
        s('line', { class: 'lab-link__hit', x1: a.x, y1: a.y, x2: b.x, y2: b.y }),
        s('line', { class: 'lab-link__line', x1: a.x, y1: a.y, x2: b.x, y2: b.y, stroke: color }));
      [[a, l.a.i, b], [b, l.b.i, a]].forEach(([d, i, other]) => {
        const t = Math.min(0.42, 50 / len);
        const x = d.x + (other.x - d.x) * t, y = d.y + (other.y - d.y) * t;
        const itf = d.ifaces[i];
        let text = itf.name;
        if (S.view === 'l2' && kindOf(d) === 'switch' && (itf.vlan || 1) !== 1) text += ' v' + itf.vlan;
        if (S.view === 'l3' && kindOf(d) === 'router') text = itf.ip ? itf.name + ' .' + itf.ip.split('.').pop() : itf.name + ' без IP';
        g.append(s('text', { class: 'lab-port' + (itf.down ? ' is-down' : ''), x, y: y + 3, 'text-anchor': 'middle' }, itf.down ? text + ' ✕' : text));
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
      g.append(s('rect', { class: 'lab-node__halo', x: -38, y: -32, width: 84, height: 96, rx: 4 }));
      g.append(iconFor(d.type));
      const bar = s('g', { class: 'lab-bar', transform: 'translate(33 -25)' });
      for (let n = 7; n >= 1; n--) {
        const on = n >= t.layers[0] && n <= t.layers[1];
        bar.append(s('rect', { class: 'lab-seg' + (on ? ' is-in' : ''), 'data-l': n, x: 0, y: (7 - n) * 7, width: 5, height: 5, rx: 0.5, fill: on ? LC[n] : '#2c2c38' }));
      }
      g.append(bar);
      const svc = d.services ? ['dns', 'dhcp', 'http'].filter((k) => d.services[k].on) : [];
      if (svc.length && S.view !== 'l1') {
        g.append(s('text', { class: 'lab-node__svc', x: -36, y: -24 }, svc.map((k) => k.toUpperCase()).join(' ')));
      }
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
      if (!i0.ip) return { text: d.dhcp ? 'ждёт DHCP' : 'нет IP', err: !d.dhcp };
      return { text: i0.ip + '/' + i0.prefix + (d.dhcp ? ' dhcp' : '') };
    }
    if (kind === 'router') {
      const n = d.ifaces.filter((i) => i.ip && LAB.ifUp(i)).length;
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
      ? 'Цвет кабеля: широковещательный домен, докуда дойдёт ARP или DHCP DISCOVER. Границы: маршрутизаторы и VLAN.'
      : 'Цвет кабеля: сеть. Внутри одной сети узлы общаются напрямую, между сетями только через маршрутизатор.'));
  }

  /* ===== Тулбар ===== */
  function buildToolbar() {
    const palette = h('div', { class: 'lab-group' },
      Object.values(LAB.types).map((t) => h('button', {
        type: 'button', class: 'lab-add', title: 'Добавить: ' + t.hint, style: { '--tc': t.color },
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
    E.fullBtn = h('button', { type: 'button', class: 'lab-btn lab-btn--full', onClick: toggleFull, title: 'Развернуть лабораторию на весь экран (Esc чтобы выйти)' }, '⛶ На весь экран');
    const files = h('div', { class: 'lab-group lab-group--end' },
      E.presetSel,
      h('button', { type: 'button', class: 'lab-btn', onClick: exportFile, title: 'Скачать схему в JSON' }, 'Экспорт'),
      h('button', { type: 'button', class: 'lab-btn', onClick: () => file.click(), title: 'Загрузить схему из JSON' }, 'Импорт'),
      h('button', { type: 'button', class: 'lab-btn', onClick: () => { if (confirm('Очистить холст?')) setNet(new LAB.Net(), null); } }, 'Очистить'),
      E.fullBtn,
      file);

    E.toolbar.append(palette, tools, views, files);
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

  /* ===== Полный экран ===== */
  function isFull() { return E.root.classList.contains('lab--full'); }
  function setFullClass(on) {
    E.root.classList.toggle('lab--full', on);
    document.documentElement.classList.toggle('lab-noscroll', on);
    E.fullBtn.textContent = on ? '✕ Свернуть' : '⛶ На весь экран';
    requestAnimationFrame(() => { applyCam(); if (LAB.Win) LAB.Win.clamp(); });
  }
  function toggleFull() {
    const on = !isFull();
    if (on) {
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
  function addDevice(type) {
    const n = S.net.devices.length;
    const d = S.net.add(type, S.cam.x + ((n * 37) % 120) - 60, S.cam.y + ((n * 53) % 100) - 50);
    S.sel = d.id;
    changed();
    hint(d.name + ' добавлен. ' + (typeOf(d).web && typeOf(d).web.length ? 'Двойной щелчок по нему откроет настройки.' : 'Соедини его кабелем.'), 'ok');
  }

  function removeDevice(id) {
    const d = S.net.dev(id);
    if (!d) return;
    if (LAB.Win) LAB.Win.close(id);
    S.net.remove(id);
    delete S.terms[id];
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
        if (S.sel !== id) { S.sel = id; renderGraph(); renderSide(); }
        return;
      }
      if (linkEl) {
        const id = linkEl.dataset.link;
        if (S.tool === 'delete') { removeLink(id); return; }
        S.sel = 'link:' + id;
        renderGraph();
        renderSide();
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
        if (S.linkFrom) { S.linkFrom = null; E.gPreview.replaceChildren(); hint(TOOLS[S.tool].hint); }
        if (S.sel) { S.sel = null; renderSide(); }
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
      const d = S.net.dev(nodeEl.dataset.node);
      if (d && LAB.Win) LAB.Win.open(d.id);
    });

    E.svg.addEventListener('wheel', (e) => {
      e.preventDefault();
      zoom(e.deltaY < 0 ? 1.12 : 1 / 1.12, e);
    }, { passive: false });

    if (window.ResizeObserver) new ResizeObserver(() => { applyCam(); if (LAB.Win) LAB.Win.clamp(); }).observe(E.svg);
    else window.addEventListener('resize', applyCam);

    document.addEventListener('keydown', (e) => {
      if (isTyping(e.target) || e.ctrlKey || e.metaKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'escape') {
        if (isFull() && !document.fullscreenElement) { setFullClass(false); return; }
        setTool('select');
        E.gPreview.replaceChildren();
        return;
      }
      if ((k === 'delete' || k === 'backspace') && S.sel) {
        e.preventDefault();
        if (S.sel.startsWith('link:')) removeLink(S.sel.slice(5)); else removeDevice(S.sel);
        return;
      }
      const tool = Object.entries(TOOLS).find(([, t]) => t.key.toLowerCase() === k);
      if (tool) setTool(tool[0]);
    });
  }

  /* ===== Карточка справа ===== */
  function peerText(d, i) {
    const p = S.net.peer(d.id, i, true);
    return p ? p.dev.name + ' ' + p.dev.ifaces[p.ifIdx].name : 'не подключён';
  }

  function fact(k, v, cls) {
    return h('div', { class: 'lab-fact' + (cls ? ' ' + cls : '') }, h('span', null, k), h('b', null, v));
  }

  // Кнопки открытия окон: подписи берёт windows.js, чтобы они совпадали с вкладками окна
  function windowButtons(d) {
    if (!LAB.Win) return null;
    const tabs = LAB.Win.tabsFor(d);
    if (!tabs.length) return null;
    return h('div', { class: 'lab-open' }, tabs.map((t) => h('button', {
      type: 'button', class: 'lab-open__btn', onClick: () => LAB.Win.open(d.id, t.id),
    }, h('span', { class: 'lab-open__ico', 'aria-hidden': 'true' }, t.icon), h('span', null, h('b', null, t.label), h('small', null, t.sub)))));
  }

  function renderSide() {
    const side = E.side;
    side.replaceChildren();
    if (!S.sel) return renderHelp();

    if (S.sel.startsWith('link:')) {
      const l = S.net.link(S.sel.slice(5));
      if (!l) { S.sel = null; return renderHelp(); }
      const a = S.net.dev(l.a.dev), b = S.net.dev(l.b.dev);
      side.append(
        h('div', { class: 'lab-card__head' }, h('div', null, h('div', { class: 'lab-card__type' }, 'L1, кабель'), h('div', { class: 'lab-card__name' }, a.name + ' ↔ ' + b.name))),
        h('div', { class: 'lab-facts' },
          fact(a.name, a.ifaces[l.a.i].name + (a.ifaces[l.a.i].down ? ' (выкл.)' : '')),
          fact(b.name, b.ifaces[l.b.i].name + (b.ifaces[l.b.i].down ? ' (выкл.)' : ''))),
        h('button', { type: 'button', class: 'btn lab-danger', onClick: () => removeLink(l.id) }, 'Удалить кабель'));
      return;
    }

    const d = S.net.dev(S.sel);
    if (!d) { S.sel = null; return renderHelp(); }
    const t = typeOf(d);
    const kind = t.kind;

    const layers = h('div', { class: 'lab-card__layers' });
    for (let n = 7; n >= 1; n--) {
      const on = n >= t.layers[0] && n <= t.layers[1];
      layers.append(h('span', { class: on ? 'is-in' : '', style: { '--lc': LC[n] }, title: 'L' + n + ' ' + LN[n] }));
    }

    side.append(h('div', { class: 'lab-card__head', style: { '--tc': t.color } },
      h('span', { class: 'lab-card__icon' }, miniIcon(d.type, 32)),
      h('div', { class: 'lab-card__title' },
        h('div', { class: 'lab-card__type' }, t.title + ', L' + t.layers[0] + (t.layers[1] !== t.layers[0] ? '–L' + t.layers[1] : '')),
        h('input', {
          class: 'lab-card__name lab-card__name-input', value: d.name, 'aria-label': 'Имя устройства', spellcheck: 'false',
          onChange: (e) => { const v = e.target.value.trim(); if (v) { d.name = v.slice(0, 24); refreshed(); } else e.target.value = d.name; },
          onKeydown: (e) => { if (e.key === 'Enter') e.target.blur(); },
        })),
      layers),
    h('p', { class: 'lab-card__hint' }, t.hint));

    const facts = h('div', { class: 'lab-facts' });
    if (kind === 'host') {
      const i0 = d.ifaces[0];
      facts.append(
        fact('Адрес', i0.ip ? i0.ip + '/' + i0.prefix : (d.dhcp ? 'ждёт DHCP' : 'не задан'), i0.ip ? '' : 'is-warn'),
        fact('Режим', d.dhcp ? 'DHCP' : 'вручную'),
        fact('Шлюз', d.gateway || 'не задан'),
        fact('DNS', d.dns || 'не задан'),
        fact('MAC', i0.mac),
        fact('Порт', i0.name + ' → ' + peerText(d, 0) + (i0.down ? ' (выкл.)' : '')));
      const on = ['dns', 'dhcp', 'http'].filter((k) => d.services[k].on);
      if (on.length) facts.append(fact('Службы', on.map((k) => ({ dns: 'DNS', dhcp: 'DHCP', http: 'веб' })[k]).join(', '), 'is-ok'));
    } else if (kind === 'router') {
      d.ifaces.forEach((itf, i) => facts.append(fact(itf.name, (itf.ip ? itf.ip + '/' + itf.prefix : 'без IP') + (itf.down ? ', выкл.' : itf.link ? '' : ', нет кабеля') + (itf.helper ? ', relay → ' + itf.helper : ''), LAB.ifUp(itf) && itf.ip ? '' : 'is-dim')));
      facts.append(fact('Маршрутов', String(d.routes.length)));
    } else {
      const used = d.ifaces.filter((i) => i.link);
      facts.append(fact('Портов занято', used.length + ' из ' + d.ifaces.length));
      if (kind === 'switch') facts.append(fact('VLAN', [...new Set(d.ifaces.map((i) => i.vlan || 1))].sort((a, b) => a - b).join(', ')));
      used.forEach((itf) => facts.append(fact(itf.name, peerText(d, d.ifaces.indexOf(itf)), 'is-dim')));
    }
    side.append(facts);

    const btns = windowButtons(d);
    if (btns) side.append(h('div', { class: 'lab-sec__title' }, 'Настроить'), btns);
    else side.append(h('p', { class: 'lab-empty' }, 'Неуправляемое устройство: настроек и консоли нет, оно просто повторяет сигнал.'));
    side.append(h('button', { type: 'button', class: 'btn lab-danger', onClick: () => removeDevice(d.id) }, 'Удалить ' + d.name));
  }

  function renderHelp() {
    const p = LAB.presets.find((x) => x.id === S.preset);
    if (p) {
      const op = p.try && (p.try[2] || 'ping');
      const label = !p.try ? '' : op === 'dhcp' ? 'dhclient на ' + p.try[0] : op === 'http' ? 'открыть ' + p.try[1] + ' с ' + p.try[0] : 'ping ' + p.try[1] + ' с ' + p.try[0];
      E.side.append(h('div', { class: 'lab-preset' + (p.task ? ' is-task' : '') },
        h('div', { class: 'lab-sec__title' }, p.task ? 'Задача' : 'Схема'),
        h('div', { class: 'lab-card__name' }, p.title),
        h('p', null, p.desc),
        p.try ? h('button', { type: 'button', class: 'btn btn--primary', onClick: () => tryPreset(p) }, label) : null));
    }
    E.side.append(h('div', { class: 'lab-sec__title' }, 'Как пользоваться'), h('ul', { class: 'lab-help' },
      h('li', null, 'Добавь устройства кнопками сверху и соедини их инструментом ', h('b', null, 'Кабель'), '.'),
      h('li', null, h('b', null, 'Двойной щелчок'), ' по устройству открывает его окно: веб-интерфейс или терминал. У маршрутизатора SSH с командами как в Cisco, у ПК и сервера Linux.'),
      h('li', null, 'На сервере можно запустить DNS, DHCP и веб-сервер, а на ПК открыть сайт во встроенном браузере.'),
      h('li', null, 'Пакеты бегут по кабелям, полоска уровней на устройствах показывает, до какого уровня OSI каждое разобрало кадр. Строка журнала раскрывает кадр по слоям.'),
      h('li', null, 'Виды L1, L2, L3: кабели, широковещательные домены, подсети.'),
      h('li', null, 'Клавиши: V выбор, C кабель, D удаление, Delete удалить выделенное, Esc отмена.')));
  }

  function tryPreset(p) {
    const d = S.net.byName(p.try[0]);
    if (!d) return;
    const op = p.try[2] || 'ping';
    if (op === 'dhcp' || op === 'http') {
      if (LAB.Win) LAB.Win.runInTerm(d.id, op === 'dhcp' ? 'dhclient' : 'curl ' + p.try[1]);
      return;
    }
    E.src.value = d.id;
    E.dst.value = p.try[1];
    startFromBar(op);
  }

  function fillTry() {
    const p = LAB.presets.find((x) => x.id === S.preset);
    if (!p || !p.try || (p.try[2] && p.try[2] !== 'ping')) return;
    const d = S.net.byName(p.try[0]);
    if (d) { E.src.value = d.id; E.dst.value = p.try[1]; }
  }

  /* ===== Панель симуляции ===== */
  function buildSimBar() {
    E.src = h('select', { class: 'lab-select', 'aria-label': 'Откуда' });
    E.dst = h('input', { class: 'input lab-input lab-dst', placeholder: 'IP, имя или http://адрес', list: 'labIps', spellcheck: 'false', 'aria-label': 'Куда',
      onKeydown: (e) => { if (e.key === 'Enter') startFromBar('ping'); } });
    E.ips = h('datalist', { id: 'labIps' });
    E.speedSel = h('select', { class: 'lab-select', 'aria-label': 'Скорость', onChange: (e) => { S.speed = Number(e.target.value); } },
      [['0.5', '0.5×'], ['1', '1×'], ['2', '2×'], ['4', '4×'], ['8', '8×']].map(([v, l]) => h('option', { value: v, selected: v === '1' }, l)));
    E.skipBtn = h('button', { type: 'button', class: 'btn', hidden: true, onClick: () => stopAnim() }, 'Пропустить анимацию');
    E.status = h('span', { class: 'lab-status' });
    E.simToggle = h('button', { type: 'button', class: 'lab-btn lab-sim__toggle', onClick: () => E.simbar.classList.toggle('is-collapsed'), title: 'Свернуть или развернуть журнал' }, 'Журнал');
    E.simbar.append(
      h('div', { class: 'lab-sim__ctl' },
        h('span', { class: 'lab-group__label' }, 'Откуда'), E.src,
        h('span', { class: 'lab-group__label' }, 'Куда'), E.dst, E.ips,
        h('button', { type: 'button', class: 'btn btn--primary', onClick: () => startFromBar('ping') }, 'Ping'),
        h('button', { type: 'button', class: 'btn', onClick: () => startFromBar('tracert') }, 'Tracert'),
        h('button', { type: 'button', class: 'btn', onClick: () => startFromBar('http'), title: 'Открыть адрес как сайт: DNS, TCP, HTTP' }, 'HTTP'),
        h('span', { class: 'lab-group__label' }, 'Скорость'), E.speedSel,
        E.skipBtn,
        h('button', { type: 'button', class: 'btn lab-btn--quiet', title: 'Очистить ARP- и MAC-таблицы и аренды DHCP на всех устройствах', onClick: () => { S.sim.reset(); renderSide(); if (LAB.Win) LAB.Win.refresh(); hint('Таблицы ARP, MAC и аренды DHCP очищены', 'ok'); } }, 'Сбросить таблицы'),
        E.status,
        E.simToggle),
      h('div', { class: 'lab-sim__body' },
        h('div', { class: 'lab-log-wrap' }, h('div', { class: 'lab-sec__title' }, 'Журнал'), E.log = h('div', { class: 'lab-log', 'aria-live': 'polite' })),
        h('div', { class: 'lab-pdu-wrap' }, h('div', { class: 'lab-sec__title' }, 'Кадр по уровням'), E.pdu = h('div', { class: 'lab-pdu' }))));
    clearLog();
  }

  function renderSimControls() {
    const prev = E.src.value;
    const sources = S.net.devices.filter((d) => ['host', 'router'].includes(kindOf(d)));
    E.src.replaceChildren(...sources.map((d) => h('option', { value: d.id }, d.name)));
    if (sources.some((d) => d.id === prev)) E.src.value = prev;
    const opts = [];
    S.net.devices.forEach((d) => d.ifaces.forEach((i) => { if (i.ip) opts.push([i.ip, d.name]); }));
    S.net.devices.forEach((d) => d.services && d.services.dns.on && d.services.dns.records.forEach((r) => opts.push([r.name, 'DNS: ' + r.ip])));
    E.ips.replaceChildren(...opts.map(([a, n]) => h('option', { value: a }, n)));
  }

  // Запуск из нижней панели идёт через терминал устройства, чтобы там остался вывод команды
  function startFromBar(op) {
    const src = E.src.value;
    const dst = E.dst.value.trim();
    if (!src) { hint('Добавь узел или маршрутизатор, с которого отправлять', 'err'); return; }
    if (!dst) { hint('Укажи, куда: IP-адрес или имя', 'err'); E.dst.focus(); return; }
    const d = S.net.dev(src);
    const linux = typeOf(d).shell === 'linux';
    if (op === 'http' && !linux) { hint('HTTP-запрос можно отправить только с ПК или сервера', 'err'); return; }
    const cmd = op === 'ping' ? 'ping ' + dst : op === 'tracert' ? 'traceroute ' + dst : 'curl ' + dst;
    if (LAB.Win) LAB.Win.runInTerm(src, cmd);
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
    return animate(r).then(() => {
      if (op === 'dhcp') renderGraph();
      E.log.append(h('div', { class: 'lab-log__row is-sum is-' + summary(op, r).cls }, summary(op, r).text));
      E.log.scrollTop = E.log.scrollHeight;
      E.status.textContent = '';
      renderSide();
      if (LAB.Win) LAB.Win.refresh();
      return r;
    });
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
    if (E.pdu && !keepPdu) E.pdu.replaceChildren(h('p', { class: 'lab-empty' }, 'Щёлкни строку журнала с цветной меткой, чтобы разобрать кадр по уровням.'));
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
      const l = E.gLinks.querySelector('[data-link="' + ev.link + '"]');
      if (l) l.classList.add('is-flash');
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
        l4 = h('div', { class: 'lab-pdu__box', style: { '--lc': LC[3] } },
          h('div', { class: 'lab-pdu__head' }, h('span', null, 'L3'), 'ICMP'),
          h('table', { class: 'lab-pdu__fields' }, h('tbody', null, h('tr', null, h('th', null, 'Тип'), h('td', null, icmpNames[p.icmp.type] || p.icmp.type)))),
          h('p', { class: 'lab-pdu__note' }, 'Выше L3 у ping ничего нет: ICMP живёт на сетевом уровне, без портов.'));
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
      h('div', { class: 'lab-pdu__box lab-pdu__box--l1', style: { '--lc': LC[1] } },
        h('div', { class: 'lab-pdu__head' }, h('span', null, 'L1'), 'биты в кабеле'),
        frameBox,
        h('div', { class: 'lab-pdu__bits' }, '10101010 … преамбула, затем кадр побитно, в конце FCS (контрольная сумма)')));
  }

  /* ===== Анимация ===== */
  function flashLayers(devId, layer, level) {
    const g = E.gNodes.querySelector('[data-node="' + devId + '"]');
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
            s('rect', { x: -19, y: -8, width: 38, height: 16, rx: 2, fill: color }),
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
    renderSide();
    renderSimControls();
    if (LAB.Win) LAB.Win.refresh();
  }

  // Общий доступ для windows.js
  LAB.ui = {
    S, E, h, s, LC, typeOf, kindOf, miniIcon,
    changed, refreshed, save, hint, runOp, renderAll,
    select(id) { S.sel = id; renderGraph(); renderSide(); },
  };

  function boot() {
    const root = document.getElementById('lab');
    if (!root) return;
    E.root = root;
    E.toolbar = h('div', { class: 'lab-toolbar panel' });
    E.svg = s('svg', { class: 'lab-svg', role: 'img', 'aria-label': 'Схема сети' },
      s('defs', null, s('pattern', { id: 'labGrid', width: 40, height: 40, patternUnits: 'userSpaceOnUse' },
        s('circle', { cx: 1, cy: 1, r: 1, fill: '#2e2e3a' }))),
      s('rect', { x: -5000, y: -5000, width: 10000, height: 10000, fill: 'url(#labGrid)' }),
      E.gLinks = s('g'), E.gPreview = s('g'), E.gNodes = s('g'), E.gPk = s('g', { class: 'lab-pk' }));
    E.hint = h('div', { class: 'lab-hint' });
    E.legend = h('div', { class: 'lab-legend' });
    E.windows = h('div', { class: 'lab-windows' });
    E.stage = h('div', { class: 'lab-stage' }, E.svg, E.hint, E.legend,
      h('div', { class: 'lab-zoom' },
        h('button', { type: 'button', title: 'Приблизить', onClick: () => zoom(1.2) }, '+'),
        h('button', { type: 'button', title: 'Отдалить', onClick: () => zoom(1 / 1.2) }, '−'),
        h('button', { type: 'button', title: 'Показать всю схему', onClick: fit }, '⤢')),
      E.windows);
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
      S.net = new LAB.Net();
      S.sim = new LAB.Sim(S.net);
      requestAnimationFrame(() => loadPreset('services'));
    }
    setTool('select');
    setView(S.view);
  }

  if (document.readyState === 'complete') setTimeout(boot);
  else document.addEventListener('DOMContentLoaded', boot);
})();
