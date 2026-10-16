// Окна устройств: веб-интерфейс, диагностика (ping, traceroute, nslookup), браузер, службы сервера.
// Окна фиксированы относительно окна браузера, поэтому их можно утащить и выше холста, до шапки.
// Вкладки окна берутся из type.web (types.js). Новая вкладка: добавить запись в TABS и функцию в PAGES.
(function () {
  const ip = LAB.ip;
  const VLAN_COLORS = ['#6a6c80', '#5f9e8a', '#b39a5a', '#6f8fb8', '#a87878', '#8a80b0', '#7fa3a8', '#9c8a6c'];
  const vlanColor = (v) => VLAN_COLORS[(Number(v) - 1) % VLAN_COLORS.length];

  const TABS = {
    net: { label: 'Сеть', sub: 'адрес, шлюз, DNS, DHCP', icon: '⚙' },
    services: { label: 'Службы', sub: 'DNS, DHCP, веб-сервер', icon: '☰' },
    browser: { label: 'Браузер', sub: 'открыть сайт', icon: '◎' },
    router: { label: 'Веб-интерфейс', sub: 'интерфейсы, маршруты', icon: '◫' },
    switch: { label: 'Веб-интерфейс', sub: 'порты и VLAN', icon: '◫' },
    diag: { label: 'Диагностика', sub: 'ping, traceroute', icon: '⌁' },
  };

  const wins = {};
  let z = 10;
  let ui, h;

  function tabsFor(dev) {
    const t = LAB.types[dev.type];
    return (t.web || []).filter((id) => TABS[id]).map((id) => Object.assign({ id }, TABS[id]));
  }

  /* ===== Окно ===== */
  function open(devId, tab) {
    const dev = ui.S.net.dev(devId);
    if (!dev) return null;
    const tabs = tabsFor(dev);
    if (!tabs.length) { ui.hint(dev.name + ': у этого устройства нет настроек', 'err'); return null; }
    let w = wins[devId];
    if (!w) {
      w = wins[devId] = { devId, tab: null, nav: {}, browser: { url: '', state: null }, diag: { target: '', lines: [], busy: false } };
      build(w);
    }
    focus(w);
    const want = tab && tabs.some((t) => t.id === tab) ? tab : (w.tab || tabs[0].id);
    if (want !== w.tab) setTab(w, want);
    return w;
  }

  function build(w) {
    const n = Object.keys(wins).length - 1;
    w.titleEl = h('span', { class: 'lw__title' });
    w.tabsEl = h('div', { class: 'lw__tabs', role: 'tablist' });
    w.body = h('div', { class: 'lw__body' });
    const ctl = (cls, title, path, fn) => h('button', { type: 'button', class: 'lw__ctl lw__ctl--' + cls, title, 'aria-label': title, onClick: fn },
      ui.s('svg', { viewBox: '0 0 12 12', width: 12, height: 12, 'aria-hidden': 'true' }, ui.s('path', { d: path, fill: 'none', stroke: 'currentColor', 'stroke-width': 1.2 })));
    const bar = h('div', { class: 'lw__bar' },
      w.titleEl,
      w.tabsEl,
      h('div', { class: 'lw__ctls' },
        ctl('min', 'Свернуть', 'M1.5 6.5h9', () => w.el.classList.toggle('is-min')),
        ctl('max', 'Развернуть', 'M2 2h8v8H2z', () => { w.el.classList.toggle('is-max'); w.el.classList.remove('is-min'); }),
        ctl('close', 'Закрыть', 'M2 2l8 8M10 2l-8 8', () => close(w.devId))));
    w.el = h('div', { class: 'lw', role: 'dialog', onPointerdown: () => focus(w) }, bar, w.body);
    ui.E.windows.append(w.el);

    const st = ui.E.stage.getBoundingClientRect();
    const width = Math.min(680, st.width - 24, window.innerWidth - 16);
    const height = Math.min(520, Math.max(260, st.height - 24), window.innerHeight - 16);
    w.el.style.width = width + 'px';
    w.el.style.height = height + 'px';
    place(w, st.left + Math.max(12, st.width - width - 16 - n * 26), Math.max(st.top, 0) + 12 + n * 26);

    // Перетаскивание за заголовок
    let drag = null;
    bar.addEventListener('pointerdown', (e) => {
      if (e.target.closest('button') || w.el.classList.contains('is-max')) return;
      drag = { x: e.clientX, y: e.clientY, l: w.el.offsetLeft, t: w.el.offsetTop };
      try { bar.setPointerCapture(e.pointerId); } catch (err) { /* без захвата тоже работает */ }
    });
    bar.addEventListener('pointermove', (e) => {
      if (!drag) return;
      place(w, drag.l + e.clientX - drag.x, drag.t + e.clientY - drag.y);
    });
    bar.addEventListener('pointerup', () => { drag = null; });
    bar.addEventListener('dblclick', (e) => { if (!e.target.closest('button')) w.el.classList.toggle('is-max'); });
  }

  // Держим окно в пределах окна браузера: заголовок всегда виден, чтобы его можно было схватить
  function place(w, x, y) {
    const maxX = window.innerWidth - 120;
    const maxY = window.innerHeight - 40;
    w.el.style.left = Math.round(Math.min(maxX, Math.max(-(w.el.offsetWidth || 300) + 120, x))) + 'px';
    w.el.style.top = Math.round(Math.min(maxY, Math.max(0, y))) + 'px';
  }

  function clamp() { Object.values(wins).forEach((w) => place(w, w.el.offsetLeft, w.el.offsetTop)); }

  function focus(w) {
    w.el.style.zIndex = String(++z);
    Object.values(wins).forEach((x) => x.el.classList.toggle('is-front', x === w));
    if (ui.S.sel !== w.devId) ui.select(w.devId);
  }

  function close(devId) {
    const w = wins[devId];
    if (!w) return;
    w.el.remove();
    delete wins[devId];
  }

  function closeAll() { Object.keys(wins).forEach(close); }

  function setTab(w, tab) {
    w.tab = tab;
    w.el.classList.toggle('lw--browser', tab === 'browser');
    render(w);
  }

  function render(w) {
    const dev = ui.S.net.dev(w.devId);
    if (!dev) { close(w.devId); return; }
    const tabs = tabsFor(dev);
    w.tabsEl.replaceChildren(...tabs.map((t) => h('button', {
      type: 'button', role: 'tab', class: 'lw__tab' + (t.id === w.tab ? ' active' : ''), onClick: () => { if (w.tab !== t.id) setTab(w, t.id); },
    }, t.label)));
    renderTitle(w, dev);
    w.body.replaceChildren();
    // Списки подсказок адресов этого окна: пересоздаются при каждой отрисовке
    if (!w.dl) { w.dl = h('div', { hidden: true }); w.el.append(w.dl); }
    w.dl.replaceChildren();
    dlBox = w.dl;
    const fn = PAGES[w.tab];
    if (fn) fn(w, dev, w.body);
    dlBox = null;
  }

  function renderTitle(w, dev) {
    let title;
    if (w.tab === 'diag') title = dev.name + ': диагностика';
    else if (w.tab === 'browser') title = dev.name + ': браузер';
    else if (w.tab === 'net') title = dev.name + ': параметры сети';
    else if (w.tab === 'services') title = dev.name + ': службы';
    else title = dev.name + ': веб-интерфейс';
    w.titleEl.textContent = title;
  }

  // После изменений перерисовать страницы окон (кроме тех, где сейчас что-то вводят)
  function refresh() {
    Object.values(wins).forEach((w) => {
      const dev = ui.S.net.dev(w.devId);
      if (!dev) { close(w.devId); return; }
      renderTitle(w, dev);
      const a = document.activeElement;
      if (a && w.el.contains(a) && a.matches('textarea, input:not([type=checkbox]):not([type=radio])')) return;
      render(w);
    });
  }

  // Запустить проверку с устройства в его окне (подсказки схем): ping, tracert, dns, dhcp, http
  function runDiag(devId, op, arg) {
    if (op === 'http') {
      const w = open(devId, 'browser');
      if (!w) return;
      w.browser.url = arg;
      w.browser.autogo = true;
      render(w);
      return;
    }
    if (op === 'dhcp') {
      const w = open(devId, 'net');
      if (!w) return;
      ui.runOp('dhcp', devId).then((r) => {
        const ok = r.results.find((x) => x.type === 'dhcp');
        toast(w, ok ? 'Получен ' + ok.ip : 'DHCP-сервер не ответил');
      });
      return;
    }
    const w = open(devId, 'diag');
    if (!w) return;
    w.diag.target = arg;
    diagRun(w, op);
  }

  /* ===== Элементы форм ===== */
  // withDefault: добавить /0 для маршрута по умолчанию
  function maskSelect(prefix, withDefault) {
    const sel = h('select', { class: 'wf__in wf__mask' });
    if (withDefault) sel.append(h('option', { value: 0, selected: prefix === 0 }, '/0 0.0.0.0 (всё)'));
    for (let p = 8; p <= 32; p++) sel.append(h('option', { value: p, selected: p === prefix }, '/' + p + ' ' + ip.maskStr(p)));
    return sel;
  }
  function inp(value, ph, cls) { return h('input', { class: 'wf__in' + (cls ? ' ' + cls : ''), value: value || '', placeholder: ph || '', spellcheck: 'false' }); }

  // Поле адреса с подсказками из схемы. kind: hop (соседние маршрутизаторы), gw (маршрутизаторы),
  // net (сети), cidr (сети, адреса /32 и any), dhcp и dns (серверы с этой службой), all (все адреса)
  let dlSeq = 0, dlBox = null;
  function ipSuggest(dev, kind) {
    const out = [], seen = new Set();
    const add = (v, label) => { if (v && !seen.has(v)) { seen.add(v); out.push([v, label]); } };
    const all = [];
    ui.S.net.devices.forEach((d) => LAB.lifs(d).forEach((l) => { if (l.cfg.ip) all.push({ d, l, kind: LAB.types[d.type].kind }); }));
    const mine = dev ? LAB.lifs(dev).filter((l) => l.cfg.ip) : [];
    const near = (a) => mine.some((l) => ip.same(a, l.cfg.ip, l.cfg.prefix));
    const name = (x) => x.d.name + ' ' + x.l.name;
    if (kind === 'hop' || kind === 'gw') {
      // Сначала те, кто в одной сети с этим устройством: только они годятся как следующий хоп или шлюз
      all.filter((x) => x.d !== dev && x.kind === 'router' && near(x.l.cfg.ip)).forEach((x) => add(x.l.cfg.ip, name(x) + ', в моей сети'));
      if (kind === 'hop') all.filter((x) => x.d !== dev && near(x.l.cfg.ip)).forEach((x) => add(x.l.cfg.ip, name(x)));
      all.filter((x) => x.d !== dev && x.kind === 'router').forEach((x) => add(x.l.cfg.ip, name(x)));
    } else if (kind === 'net' || kind === 'cidr') {
      if (kind === 'cidr') add('any', 'любой адрес');
      all.forEach((x) => { const n = ip.netStr(x.l.cfg.ip, x.l.cfg.prefix); add(kind === 'net' ? n.split('/')[0] : n, 'сеть ' + n + ' (' + x.d.name + ')'); });
      if (kind === 'net') add('0.0.0.0', 'маршрут по умолчанию (маска /0)');
      if (kind === 'cidr') all.forEach((x) => add(x.l.cfg.ip + '/32', 'только ' + name(x)));
    } else if (kind === 'dhcp' || kind === 'dns') {
      all.filter((x) => x.d.services && x.d.services[kind] && x.d.services[kind].on).forEach((x) => add(x.l.cfg.ip, x.d.name + ', ' + kind.toUpperCase() + '-сервер'));
      all.filter((x) => x.kind === 'host' && x.d.type === 'server').forEach((x) => add(x.l.cfg.ip, x.d.name));
    } else {
      all.filter((x) => x.d !== dev).forEach((x) => add(x.l.cfg.ip, name(x)));
    }
    return out.slice(0, 300);
  }
  function ipInp(value, ph, dev, kind) {
    const id = 'labIp' + (++dlSeq);
    if (dlBox) dlBox.append(h('datalist', { id }, ipSuggest(dev, kind).map(([v, l]) => h('option', { value: v }, l))));
    return h('input', { class: 'wf__in', value: value || '', placeholder: ph || '', spellcheck: 'false', autocomplete: 'off', list: id });
  }
  function field(label, control, help) {
    return h('label', { class: 'wf' }, h('span', { class: 'wf__label' }, label), control, help ? h('small', { class: 'wf__help' }, help) : null);
  }
  function toggle(on, onChange, label) {
    const box = h('input', { type: 'checkbox', checked: on, onChange: (e) => onChange(e.target.checked) });
    return h('label', { class: 'wtoggle' }, box, h('span', { class: 'wtoggle__track' }, h('span')), label ? h('span', { class: 'wtoggle__label' }, label) : null);
  }
  function btn(text, onClick, primary) { return h('button', { type: 'button', class: 'wbtn' + (primary ? ' wbtn--primary' : ''), onClick }, text); }
  function errBox() { return h('div', { class: 'wf__err' }); }
  function table(head, rows, empty) {
    if (!rows.length) return h('p', { class: 'wempty' }, empty || 'Пусто');
    return h('table', { class: 'wtable' }, h('thead', null, h('tr', null, head.map((x) => h('th', null, x)))), h('tbody', null, rows));
  }
  function toast(w, text) {
    const t = h('div', { class: 'wtoast' }, text);
    w.el.append(t);
    setTimeout(() => t.remove(), 1800);
  }
  function pill(text, cls) { return h('span', { class: 'wpill wpill--' + cls }, text); }
  function ifState(itf) {
    if (itf.down) return pill('выключен', 'off');
    if (!itf.link) return pill('нет кабеля', 'dim');
    return pill('up', 'ok');
  }
  function peerName(dev, i) {
    const p = ui.S.net.peer(dev.id, i, true);
    return p ? p.dev.name + ' ' + p.dev.ifaces[p.ifIdx].name : 'не подключён';
  }

  // Каркас «веб-админки»: адресная строка, меню слева, страница справа
  function webApp(w, dev, root, brand, pages) {
    const mgmt = dev.ifaces.find((i) => i.ip && LAB.ifUp(i));
    const cur = w.nav[w.tab] && pages[w.nav[w.tab]] ? w.nav[w.tab] : Object.keys(pages)[0];
    const content = h('div', { class: 'wa__page' });
    root.append(
      h('div', { class: 'wurl' }, h('span', { class: 'wurl__lock' }, mgmt ? '⌂' : '⚠'), h('span', null, mgmt ? 'http://' + mgmt.ip + '/' + cur : 'нет IP: доступ только по консольному кабелю')),
      h('div', { class: 'wa' },
        h('nav', { class: 'wa__nav' },
          h('div', { class: 'wa__brand' }, brand, renameInput(dev)),
          Object.entries(pages).map(([id, p]) => h('button', { type: 'button', class: 'wa__link' + (id === cur ? ' active' : ''), onClick: () => { w.nav[w.tab] = id; render(w); } }, p.title))),
        content));
    content.append(h('h3', { class: 'wa__h' }, pages[cur].title));
    pages[cur].render(content);
  }

  // Поле имени устройства: применяется по Enter или при уходе фокуса
  function renameInput(dev) {
    const el = h('input', { class: 'wa__name', value: dev.name, title: 'Имя устройства, можно изменить', 'aria-label': 'Имя устройства', spellcheck: 'false', maxlength: 24 });
    el.addEventListener('keydown', (e) => { if (e.key === 'Enter') el.blur(); });
    el.addEventListener('change', () => { const v = el.value.trim(); if (v && v !== dev.name) { dev.name = v.slice(0, 24); ui.refreshed(); } else el.value = dev.name; });
    return el;
  }

  /* ===== Страницы ===== */
  const PAGES = {};

  /* --- Маршрутизатор --- */
  const lifAddr = (l) => (l.cfg.ip ? l.cfg.ip + '/' + l.cfg.prefix : '—');
  function lifState(l) {
    if (l.cfg.down || l.itf.down) return pill('выключен', 'off');
    if (!l.itf.link) return pill('нет кабеля', 'dim');
    return pill('up', 'ok');
  }
  // Проверить адрес для интерфейса маршрутизатора: формат, не адрес сети, сеть не занята другим интерфейсом
  function lifAddrError(dev, cfg, v, p) {
    if (!v) return '';
    if (!ip.valid(v)) return 'Неверный IP-адрес';
    const bad = ip.hostError(v, p);
    if (bad) return 'Нельзя: ' + bad;
    const clash = LAB.lifs(dev).find((x) => x.cfg !== cfg && x.cfg.ip && (ip.same(x.cfg.ip, v, p) || ip.same(x.cfg.ip, v, x.cfg.prefix)));
    return clash ? 'Эта сеть уже на ' + clash.name + ': у каждого интерфейса своя сеть' : '';
  }

  PAGES.router = (w, dev, root) => {
    const sim = ui.S.sim;
    webApp(w, dev, root, 'RouterOS-lite', {
      overview: { title: 'Обзор', render(c) {
        const ls = LAB.lifs(dev);
        const upN = ls.filter((l) => l.cfg.ip && LAB.lifUp(l)).length;
        const rt = sim.routeTable(dev);
        c.append(
          h('div', { class: 'wstats' },
            h('div', null, h('b', null, upN + ' / ' + ls.length), h('span', null, 'интерфейсов в работе')),
            h('div', null, h('b', null, rt.filter((r) => r.code !== 'C').length), h('span', null, 'маршрутов S и O')),
            h('div', null, h('b', null, Object.keys(sim.tables(dev).arp).length), h('span', null, 'записей ARP'))),
          table(['Интерфейс', 'Адрес', 'Сосед', 'Роль', 'Состояние'], ls.map((l) => h('tr', null,
            h('td', null, h('b', null, l.name)), h('td', null, lifAddr(l)), h('td', null, l.vlan ? 'VLAN ' + l.vlan : peerName(dev, l.i)),
            h('td', null, [l.cfg.nat ? 'NAT ' + l.cfg.nat : '', l.cfg.ospf ? 'OSPF' : '', (l.cfg.aclIn.length || l.cfg.aclOut.length) ? 'ACL' : ''].filter(Boolean).join(', ') || '—'),
            h('td', null, lifState(l))))));
      } },
      ifaces: { title: 'Интерфейсы', render(c) {
        c.append(h('p', { class: 'wnote' }, 'У каждого интерфейса своя сеть. DHCP relay пересылает широковещательные DHCP-запросы из этой сети на сервер в другой сети. Подынтерфейс (G0/0.10) принимает кадры с тегом VLAN: так один кабель обслуживает несколько VLAN.'));
        dev.ifaces.forEach((itf, k) => {
          const ipIn = inp(itf.ip, '192.168.1.1');
          const mask = maskSelect(itf.prefix);
          const helper = ipInp(itf.helper, 'IP DHCP-сервера', dev, 'dhcp');
          const err = errBox();
          const save = () => {
            err.textContent = '';
            const v = ipIn.value.trim(), p = Number(mask.value), hv = helper.value.trim();
            const e = lifAddrError(dev, itf, v, p);
            if (e) { err.textContent = e; return; }
            if (hv && !ip.valid(hv)) { err.textContent = 'Relay: неверный адрес сервера'; return; }
            itf.ip = v ? ip.norm(v) : '';
            itf.prefix = p;
            itf.helper = hv ? ip.norm(hv) : '';
            ui.changed();
            toast(w, itf.name + ': сохранено');
          };
          // Подынтерфейсы этого порта
          const subs = itf.subs.map((sb, j) => {
            const sIp = inp(sb.ip, '192.168.10.1'), sMask = maskSelect(sb.prefix), sHelp = ipInp(sb.helper, 'IP DHCP-сервера', dev, 'dhcp'), sErr = errBox();
            return h('div', { class: 'wsub-if' },
              h('div', { class: 'wsub-if__head' }, h('b', null, itf.name + '.' + sb.vlan), h('span', { class: 'wcard__sub' }, 'тег VLAN ' + sb.vlan),
                h('button', { type: 'button', class: 'wx', title: 'Удалить подынтерфейс', onClick: () => { itf.subs.splice(j, 1); ui.changed(); } }, '×')),
              h('div', { class: 'wgrid' }, field('IP-адрес', sIp), field('Маска', sMask), field('DHCP relay', sHelp)),
              h('div', { class: 'wrow wrow--end' }, sErr, btn('Сохранить', () => {
                sErr.textContent = '';
                const v = sIp.value.trim(), p = Number(sMask.value), hv = sHelp.value.trim();
                const e = lifAddrError(dev, sb, v, p);
                if (e) { sErr.textContent = e; return; }
                if (hv && !ip.valid(hv)) { sErr.textContent = 'Relay: неверный адрес'; return; }
                sb.ip = v ? ip.norm(v) : ''; sb.prefix = p; sb.helper = hv ? ip.norm(hv) : '';
                ui.changed();
                toast(w, itf.name + '.' + sb.vlan + ': сохранено');
              })));
          });
          const nv = h('input', { class: 'wf__in wsub-add__vlan', type: 'number', min: 1, max: 4094, placeholder: 'VLAN' });
          const addErr = errBox();
          const addSub = h('div', { class: 'wrow wsub-add' }, nv, btn('+ подынтерфейс', () => {
            const v = Math.round(Number(nv.value));
            if (!(v >= 1 && v <= 4094)) { addErr.textContent = 'VLAN от 1 до 4094'; return; }
            if (itf.subs.some((x) => x.vlan === v)) { addErr.textContent = 'Такой подынтерфейс уже есть'; return; }
            itf.subs.push(LAB.normSub({ vlan: v }));
            ui.changed();
          }), addErr);
          c.append(h('div', { class: 'wcard' + (LAB.ifUp(itf) ? '' : ' is-off') },
            h('div', { class: 'wcard__head' },
              h('b', null, itf.name), h('span', { class: 'wcard__sub' }, '-> ' + peerName(dev, k)), ifState(itf),
              toggle(!itf.down, (on) => { itf.down = !on; ui.changed(); toast(w, itf.name + (on ? ': включён' : ': выключен')); }, 'вкл.')),
            h('div', { class: 'wgrid' }, field('IP-адрес', ipIn), field('Маска', mask), field('DHCP relay', helper)),
            h('div', { class: 'wrow wrow--end' }, err, btn('Сохранить', save, true)),
            subs, addSub));
        });
      } },
      routes: { title: 'Маршрутизация', render(c) {
        const rows = sim.routeTable(dev);
        const codeTitle = { C: 'подключена', S: 'статический', O: 'OSPF' };
        // Редактируемый маршрут: его строка подсвечена, форма ниже заполнена его значениями
        const edit = w.routeEdit != null && dev.routes[w.routeEdit] ? w.routeEdit : null;
        if (edit == null) w.routeEdit = null;
        c.append(table(['', 'Сеть', 'Куда отдать', ''], rows.map((r) => h('tr', { class: (r.code === 'S' && !r.iface ? 'is-bad' : '') + (r.code === 'S' && r.idx === edit ? ' is-edit' : '') },
          h('td', null, h('span', { class: 'wcode wcode--' + r.code, title: codeTitle[r.code] }, r.code)),
          h('td', null, r.net),
          h('td', null, r.code === 'C' ? 'напрямую, ' + r.iface : r.via + (r.iface ? ' через ' + r.iface : ' (сосед недостижим)') + (r.code === 'O' ? ', метрика ' + r.cost : '')),
          h('td', { class: 'wacl__ops' }, r.code === 'S' ? [
            h('button', { type: 'button', class: 'wx wx--edit', title: 'Изменить', onClick: () => { w.routeEdit = r.idx; render(w); } }, '✎'),
            h('button', { type: 'button', class: 'wx', title: 'Удалить', onClick: () => { dev.routes.splice(r.idx, 1); w.routeEdit = null; ui.changed(); } }, '×'),
          ] : null))),
        'Пусто: задай адреса интерфейсам и подключи кабели.'));
        const cur = edit != null ? dev.routes[edit] : null;
        const net = ipInp(cur ? cur.net : '', '10.3.0.0', dev, 'net');
        const mask = maskSelect(cur ? cur.prefix : 24, true);
        const via = ipInp(cur ? cur.via : '', '10.0.12.2', dev, 'hop');
        // Выбрали известную сеть из подсказок: подставить её маску
        net.addEventListener('change', () => {
          const v = net.value.trim();
          if (v === '0.0.0.0') { mask.value = 0; return; }
          for (const d of ui.S.net.devices) for (const l of LAB.lifs(d)) {
            if (l.cfg.ip && ip.str(ip.net(ip.parse(l.cfg.ip), l.cfg.prefix)) === v) { mask.value = l.cfg.prefix; return; }
          }
        });
        const err = errBox();
        const apply = () => {
          if (!ip.valid(net.value)) { err.textContent = 'Неверная сеть'; return; }
          if (!ip.valid(via.value)) { err.textContent = 'Неверный следующий хоп'; return; }
          const p = Number(mask.value);
          const r = { net: ip.str(ip.net(ip.parse(net.value), p)), prefix: p, via: ip.norm(via.value) };
          if (dev.routes.some((x, k) => k !== edit && x.net === r.net && x.prefix === r.prefix && x.via === r.via)) { err.textContent = 'Такой маршрут уже есть'; return; }
          if (cur) dev.routes[edit] = r; else dev.routes.push(r);
          w.routeEdit = null;
          ui.changed();
          toast(w, cur ? 'Маршрут изменён' : 'Маршрут добавлен');
        };
        c.append(h('div', { class: 'wcard' + (cur ? ' is-edit' : '') },
          h('div', { class: 'wcard__head' }, h('b', null, cur ? 'Изменить маршрут ' + cur.net + '/' + cur.prefix : 'Добавить статический маршрут')),
          h('div', { class: 'wgrid' }, field('Сеть назначения', net, '0.0.0.0 с маской /0: маршрут по умолчанию'), field('Маска', mask), field('Следующий хоп', via, 'IP соседа в подключённой сети')),
          h('div', { class: 'wrow wrow--end' }, err,
            cur ? btn('Отмена', () => { w.routeEdit = null; render(w); }) : null,
            btn(cur ? 'Сохранить' : 'Добавить', apply, true))),
        h('p', { class: 'wnote' }, 'C: сеть подключена к интерфейсу. S: статический, «чтобы попасть в эту сеть, отдай пакет соседу». O: выучен по OSPF. Выбирается самый длинный подходящий префикс, при равенстве C, затем S, затем O.'));
      } },
      ospf: { title: 'OSPF', render(c) {
        const topo = sim.refreshTopo();
        const ls = LAB.lifs(dev).filter((l) => l.cfg.ip);
        c.append(h('p', { class: 'wnote' }, 'Включи OSPF на интерфейсах: маршрутизатор найдёт соседей в этих сетях, расскажет им о своих сетях и выучит чужие. Сеть с узлами тоже включай, иначе о ней никто не узнает.'),
          ls.length ? table(['Интерфейс', 'Сеть', 'OSPF'], ls.map((l) => h('tr', null,
            h('td', null, h('b', null, l.name)), h('td', null, ip.netStr(l.cfg.ip, l.cfg.prefix)),
            h('td', null, toggle(l.cfg.ospf, (on) => { l.cfg.ospf = on; ui.changed(); }))))) : h('p', { class: 'wempty' }, 'Сначала задай адреса интерфейсам.'),
          h('div', { class: 'wrow' }, toggle(!!dev.ospfDef, (on) => { dev.ospfDef = on; ui.changed(); }, 'Распространять маршрут по умолчанию (default-information originate)')),
          h('p', { class: 'wnote' }, 'Работает, если на этом маршрутизаторе есть статический 0.0.0.0/0: соседи получат маршрут по умолчанию в его сторону.'),
          h('div', { class: 'wsub' }, 'Соседи'),
          table(['Сосед', 'Его адрес', 'Через'], (topo.ospf.nbrs[dev.id] || []).map((n) => h('tr', null, h('td', null, n.dev.name), h('td', null, n.ip), h('td', null, n.lif.name))), 'Соседей нет: OSPF должен быть включён на обоих концах канала.'),
          h('div', { class: 'wsub' }, 'Выученные маршруты'),
          table(['Сеть', 'Через', 'Метрика'], (topo.ospf.routes[dev.id] || []).map((r) => h('tr', null, h('td', null, r.net + '/' + r.prefix), h('td', null, r.via + ' (' + r.lif.name + ')'), h('td', null, r.cost))), 'Пока ничего.'));
      } },
      nat: { title: 'NAT', render(c) {
        const ls = LAB.lifs(dev).filter((l) => l.cfg.ip);
        c.append(h('p', { class: 'wnote' }, 'PAT: пакеты из inside-сетей уходят через outside-интерфейс с его адресом, а ответ возвращается по номеру порта. Внутренним сетям обычно нужен ещё маршрут по умолчанию к провайдеру.'),
          ls.length ? table(['Интерфейс', 'Адрес', 'Роль NAT'], ls.map((l) => {
            const sel = h('select', { class: 'wf__in', onChange: (e) => { l.cfg.nat = e.target.value; ui.changed(); } },
              [['', 'нет'], ['inside', 'inside (внутренняя сеть)'], ['outside', 'outside (наружу)']].map(([v, t]) => h('option', { value: v, selected: l.cfg.nat === v }, t)));
            return h('tr', null, h('td', null, h('b', null, l.name)), h('td', null, lifAddr(l)), h('td', null, sel));
          })) : h('p', { class: 'wempty' }, 'Сначала задай адреса интерфейсам.'));
        // Проброс портов
        const proto = h('select', { class: 'wf__in' }, ['tcp', 'udp'].map((p) => h('option', { value: p }, p.toUpperCase())));
        const port = h('input', { class: 'wf__in', type: 'number', min: 1, max: 65535, value: 80 });
        const addr = ipInp('', '192.168.1.80', dev, 'all');
        const lport = h('input', { class: 'wf__in', type: 'number', min: 1, max: 65535, value: 80 });
        const err = errBox();
        c.append(h('div', { class: 'wsub' }, 'Проброс портов (статический NAT)'),
          table(['Снаружи', 'Внутрь', ''], dev.natStatic.map((s, k) => h('tr', null,
            h('td', null, s.proto.toUpperCase() + ' :' + s.port), h('td', null, s.ip + ':' + s.lport),
            h('td', null, h('button', { type: 'button', class: 'wx', onClick: () => { dev.natStatic.splice(k, 1); ui.changed(); } }, '×')))), 'Нет пробросов.'),
          h('div', { class: 'wgrid' }, field('Протокол', proto), field('Внешний порт', port), field('Внутренний адрес', addr), field('Внутренний порт', lport)),
          h('div', { class: 'wrow wrow--end' }, err, btn('Добавить проброс', () => {
            const pp = Math.round(Number(port.value)), lp = Math.round(Number(lport.value));
            if (!(pp >= 1 && pp <= 65535) || !(lp >= 1 && lp <= 65535)) { err.textContent = 'Порт от 1 до 65535'; return; }
            if (!ip.valid(addr.value)) { err.textContent = 'Неверный внутренний адрес'; return; }
            if (dev.natStatic.some((s) => s.proto === proto.value && s.port === pp)) { err.textContent = 'Этот внешний порт уже проброшен'; return; }
            dev.natStatic.push({ proto: proto.value, port: pp, ip: ip.norm(addr.value), lport: lp });
            ui.changed();
            toast(w, 'Проброс добавлен');
          }, true)));
        const tr = Object.values(sim.tables(dev).nat);
        c.append(h('div', { class: 'wsub' }, 'Таблица трансляций'),
          table(['Протокол', 'Внутри', 'Снаружи'], tr.map((m) => h('tr', null, h('td', null, m.proto), h('td', null, m.ip + ':' + m.port), h('td', null, m.pub + ':' + m.ext))), 'Пусто: появится, когда кто-то изнутри выйдет наружу.'));
      } },
      acl: { title: 'ACL', render(c) {
        const ls = LAB.lifs(dev);
        if (!w.acl || !ls.some((l) => l.name === w.acl.lif)) w.acl = { lif: (ls.find((l) => l.cfg.ip) || ls[0]).name, dir: 'in' };
        const cur = ls.find((l) => l.name === w.acl.lif);
        const key = w.acl.dir === 'in' ? 'aclIn' : 'aclOut';
        const list = cur.cfg[key];
        const pick = h('select', { class: 'wf__in', onChange: (e) => { w.acl.lif = e.target.value; render(w); } },
          ls.map((l) => h('option', { value: l.name, selected: l === cur }, l.name + (l.cfg.ip ? ' (' + l.cfg.ip + ')' : '') + ((l.cfg.aclIn.length || l.cfg.aclOut.length) ? ', есть ACL' : ''))));
        const dir = h('div', { class: 'wseg' },
          h('button', { type: 'button', class: w.acl.dir === 'in' ? 'active' : '', onClick: () => { w.acl.dir = 'in'; render(w); } }, 'На входе (in)'),
          h('button', { type: 'button', class: w.acl.dir === 'out' ? 'active' : '', onClick: () => { w.acl.dir = 'out'; render(w); } }, 'На выходе (out)'));
        const move = (k, d) => { const r = list.splice(k, 1)[0]; list.splice(k + d, 0, r); ui.changed(); };
        c.append(h('p', { class: 'wnote' }, 'Правила проверяются сверху вниз до первого совпадения. Если список не пустой, в конце неявный deny any: всё, что не разрешено, запрещено. На входе список видит пакеты, пришедшие в интерфейс, на выходе: пакеты, которые маршрутизатор туда отправляет.'),
          h('div', { class: 'wrow' }, field('Интерфейс', pick), dir),
          list.length ? h('table', { class: 'wtable wacl' }, h('tbody', null, list.map((r, k) => h('tr', null,
            h('td', { class: 'wacl__n' }, String((k + 1) * 10)),
            h('td', null, h('code', { class: r.action === 'deny' ? 'is-deny' : 'is-permit' }, LAB.aclText(r))),
            h('td', { class: 'wacl__ops' },
              h('button', { type: 'button', class: 'wx', title: 'Выше', disabled: k === 0, onClick: () => move(k, -1) }, '↑'),
              h('button', { type: 'button', class: 'wx', title: 'Ниже', disabled: k === list.length - 1, onClick: () => move(k, 1) }, '↓'),
              h('button', { type: 'button', class: 'wx', title: 'Удалить', onClick: () => { list.splice(k, 1); ui.changed(); } }, '×')))),
            h('tr', { class: 'wacl__implicit' }, h('td', null, ''), h('td', null, h('code', null, 'deny ip any any (неявно)')), h('td', null, ''))))
            : h('p', { class: 'wempty' }, 'Списка нет: на ' + cur.name + ' ' + (w.acl.dir === 'in' ? 'на входе' : 'на выходе') + ' пропускается всё.'));
        const act = h('select', { class: 'wf__in' }, h('option', { value: 'deny' }, 'deny (запретить)'), h('option', { value: 'permit' }, 'permit (разрешить)'));
        const proto = h('select', { class: 'wf__in' }, LAB.ACL_PROTOS.map((p) => h('option', { value: p }, p === 'ip' ? 'ip (всё)' : p)));
        const src = ipInp('any', 'any или 192.168.50.0/24', dev, 'cidr');
        const dst = ipInp('any', 'any или 10.0.0.10/32', dev, 'cidr');
        const port = h('input', { class: 'wf__in', type: 'number', min: 0, max: 65535, placeholder: 'любой' });
        const err = errBox();
        const okCidr = (v) => v === 'any' || !!ip.parseCidr(v) || ip.valid(v);
        c.append(h('div', { class: 'wcard' },
          h('div', { class: 'wcard__head' }, h('b', null, 'Добавить правило в конец')),
          h('div', { class: 'wgrid' }, field('Действие', act), field('Протокол', proto), field('Откуда', src, 'any, сеть с маской или адрес'), field('Куда', dst), field('Порт назначения', port, 'для tcp и udp: 80 это HTTP, 53 DNS')),
          h('div', { class: 'wrow wrow--end' }, err, btn('Добавить', () => {
            const s = src.value.trim() || 'any', d = dst.value.trim() || 'any';
            if (!okCidr(s) || !okCidr(d)) { err.textContent = 'Адрес: any, 192.168.1.0/24 или 10.0.0.10'; return; }
            const norm = (v) => (v === 'any' ? 'any' : ip.parseCidr(v) ? (() => { const x = ip.parseCidr(v); return ip.str(ip.net(ip.parse(x.ip), x.prefix)) + '/' + x.prefix; })() : ip.norm(v) + '/32');
            list.push(LAB.normRule({ action: act.value, proto: proto.value, src: norm(s), dst: norm(d), port: Number(port.value) || 0 }));
            ui.changed();
          }, true))));
      } },
      arp: { title: 'ARP', render(c) {
        const arp = Object.entries(sim.tables(dev).arp);
        c.append(table(['IP-адрес', 'MAC-адрес'], arp.map(([a, m]) => h('tr', null, h('td', null, a), h('td', null, h('code', null, m)))), 'Пусто: заполнится после первого обмена.'),
          h('div', { class: 'wrow' }, btn('Очистить ARP', () => { sim.tables(dev).arp = {}; render(w); })));
      } },
    });
  };

  /* --- Коммутатор --- */
  const STP_ROLE = { root: ['корневой', 'ok'], desig: ['назначенный', 'ok'], blocked: ['заблокирован', 'off'] };
  PAGES.switch = (w, dev, root) => {
    const stp = ui.S.net.stpInfo();
    webApp(w, dev, root, 'SwitchOS-lite', {
      ports: { title: 'Порты и VLAN', render(c) {
        const selIdx = w.port != null ? w.port : 0;
        const face = h('div', { class: 'wface' }, dev.ifaces.map((itf, k) => {
          const trunk = itf.mode === 'trunk';
          const blocked = stp.blocked.has(dev.id + ':' + k);
          return h('button', {
            type: 'button', class: 'wport' + (k === selIdx ? ' active' : '') + (itf.down ? ' is-down' : LAB.ifUp(itf) ? ' is-up' : '') + (trunk ? ' is-trunk' : '') + (blocked ? ' is-blk' : ''),
            style: { '--vc': trunk ? '#9aa0b4' : vlanColor(itf.vlan || 1) }, title: itf.name + (trunk ? ', trunk' : ', VLAN ' + (itf.vlan || 1)) + (blocked ? ', заблокирован STP' : ''),
            onClick: () => { w.port = k; render(w); },
          }, h('span', { class: 'wport__led' }), h('span', { class: 'wport__num' }, k + 1), h('span', { class: 'wport__vlan' }, trunk ? 'T' : 'v' + (itf.vlan || 1)));
        }));
        const itf = dev.ifaces[selIdx];
        const mode = h('select', { class: 'wf__in', onChange: (e) => { itf.mode = e.target.value; ui.changed(); } },
          h('option', { value: 'access', selected: itf.mode !== 'trunk' }, 'access (один VLAN)'),
          h('option', { value: 'trunk', selected: itf.mode === 'trunk' }, 'trunk (несколько VLAN, 802.1Q)'));
        const vlan = h('input', { class: 'wf__in', type: 'number', min: 1, max: 4094, value: itf.vlan || 1 });
        const allowed = inp(itf.allowed, 'все');
        const native = h('input', { class: 'wf__in', type: 'number', min: 1, max: 4094, value: itf.native || 1 });
        const desc = inp(itf.desc || '', 'например, бухгалтерия');
        const err = errBox();
        const fields = itf.mode === 'trunk'
          ? [field('Разрешённые VLAN', allowed, 'пусто: все, или список: 10,20,30-35'), field('Native VLAN', native, 'кадры этого VLAN идут без тега')]
          : [field('VLAN', vlan, 'порты с одним VLAN видят друг друга')];
        const role = stp.role[dev.id + ':' + selIdx];
        c.append(
          h('div', { class: 'wface-wrap' }, h('div', { class: 'wface__label' }, dev.name), face),
          h('div', { class: 'wcard' },
            h('div', { class: 'wcard__head' }, h('b', null, itf.name), h('span', { class: 'wcard__sub' }, '-> ' + peerName(dev, selIdx)), ifState(itf),
              role && dev.stp.on ? pill('STP: ' + STP_ROLE[role][0], STP_ROLE[role][1]) : null,
              toggle(!itf.down, (on) => { itf.down = !on; ui.changed(); }, 'вкл.')),
            h('div', { class: 'wgrid' }, field('Режим', mode), fields, field('Описание', desc)),
            h('div', { class: 'wrow wrow--end' }, err, btn('Сохранить', () => {
              const d = desc.value.trim().slice(0, 60);
              if (itf.mode === 'trunk') {
                const n = Math.round(Number(native.value));
                if (!(n >= 1 && n <= 4094)) { err.textContent = 'Native VLAN от 1 до 4094'; return; }
                const a = allowed.value.trim();
                if (a && !/^(all|все)$/i.test(a) && !LAB.parseVlanList(a).size) { err.textContent = 'Список VLAN: 10,20 или 30-35'; return; }
                itf.native = n;
                itf.allowed = a;
              } else {
                const v = Math.round(Number(vlan.value));
                if (!(v >= 1 && v <= 4094)) { err.textContent = 'VLAN от 1 до 4094'; return; }
                itf.vlan = v;
              }
              itf.desc = d;
              ui.changed();
              toast(w, itf.name + ': сохранено');
            }, true))),
          h('p', { class: 'wnote' }, 'Цвет полоски под портом это его VLAN, «T» значит транк. Между коммутаторами и к маршрутизатору с подынтерфейсами нужен транк: по нему кадры идут с тегом VLAN.'));
      } },
      stp: { title: 'STP', render(c) {
        const b = stp.bridge[dev.id];
        const prio = h('select', { class: 'wf__in', onChange: (e) => { dev.stp.priority = Number(e.target.value); ui.changed(); } },
          Array.from({ length: 16 }, (_, k) => k * 4096).map((p) => h('option', { value: p, selected: p === dev.stp.priority }, String(p))));
        c.append(
          h('div', { class: 'wrow' }, toggle(dev.stp.on, (on) => { dev.stp.on = on; ui.changed(); toast(w, on ? 'STP включён' : 'STP выключен'); }, dev.stp.on ? 'STP включён' : 'STP выключен')),
          h('div', { class: 'wgrid' }, field('Приоритет моста', prio, 'меньше значит важнее: корнем станет коммутатор с наименьшим приоритетом'), field('MAC моста', h('code', { class: 'wf__ro' }, dev.ifaces[0].mac))));
        if (!dev.stp.on) {
          c.append(h('p', { class: 'wnote' }, 'Без STP любая петля из кабелей устроит широковещательный шторм: кадры будут ходить по кругу бесконечно.'));
          return;
        }
        if (b) {
          const rootDev = ui.S.net.dev(b.root);
          c.append(h('div', { class: 'wstats' },
            h('div', null, h('b', null, b.isRoot ? 'я' : rootDev ? rootDev.name : '?'), h('span', null, 'корневой мост')),
            h('div', null, h('b', null, b.isRoot ? 0 : b.cost), h('span', null, 'стоимость пути до корня')),
            h('div', null, h('b', null, b.rootPort != null ? dev.ifaces[b.rootPort].name : '—'), h('span', null, 'корневой порт'))));
        }
        c.append(table(['Порт', 'Сосед', 'Роль'], dev.ifaces.map((itf, k) => {
          const r = stp.role[dev.id + ':' + k];
          if (!r) return null;
          return h('tr', null, h('td', null, h('b', null, itf.name)), h('td', null, peerName(dev, k)), h('td', null, pill(STP_ROLE[r][0], STP_ROLE[r][1])));
        }).filter(Boolean), 'Нет подключённых портов.'),
        h('p', { class: 'wnote' }, 'Корневой порт ведёт к корню кратчайшим путём. Назначенный пересылает кадры в свой сегмент. Заблокированный молчит, пока не понадобится: так кольцо становится деревом.'));
      } },
      mac: { title: 'Таблица MAC', render(c) {
        const rows = Object.entries(ui.S.sim.tables(dev).mac);
        c.append(table(['VLAN', 'MAC-адрес', 'Порт'], rows.map(([k, port]) => { const [v, m] = k.split('|'); return h('tr', null, h('td', null, v), h('td', null, h('code', null, m)), h('td', null, dev.ifaces[port].name)); }),
          'Пусто: коммутатор учит адреса, когда через него проходят кадры.'),
        h('div', { class: 'wrow' }, btn('Очистить таблицу', () => { ui.S.sim.tables(dev).mac = {}; render(w); })));
      } },
      overview: { title: 'Обзор', render(c) {
        const by = {};
        const trunks = [];
        dev.ifaces.forEach((i) => { if (i.mode === 'trunk') trunks.push(i.name); else (by[i.vlan || 1] = by[i.vlan || 1] || []).push(i.name); });
        c.append(
          table(['VLAN', 'Порты'], Object.keys(by).sort((a, b) => a - b).map((v) => h('tr', null, h('td', null, h('span', { class: 'wvlan', style: { '--vc': vlanColor(v) } }, v)), h('td', null, by[v].join(', '))))),
          trunks.length ? h('p', { class: 'wnote' }, 'Транки: ' + trunks.join(', ')) : null);
      } },
    });
  };

  /* --- ПК и сервер: параметры сети --- */
  PAGES.net = (w, dev, root) => {
    const itf = dev.ifaces[0];
    const err = errBox();
    const box = h('div', { class: 'wos' });
    root.append(box);
    const state = itf.down ? 'выключен' : itf.link ? 'подключено' : 'кабель не подключён';
    box.append(h('div', { class: 'wos__head' },
      h('div', { class: 'wos__ico' }, '⇄'),
      h('div', null, h('b', null, 'Проводное подключение ' + itf.name), h('small', null, state + ', MAC ' + itf.mac)),
      toggle(!itf.down, (on) => { itf.down = !on; ui.changed(); }, 'вкл.')));

    const nameIn = inp(dev.name);
    nameIn.addEventListener('change', () => { const v = nameIn.value.trim(); if (v) { dev.name = v.slice(0, 24); ui.refreshed(); } });
    nameIn.addEventListener('keydown', (e) => { if (e.key === 'Enter') nameIn.blur(); });
    box.append(field('Имя устройства', nameIn));

    const mode = h('div', { class: 'wseg' },
      h('button', { type: 'button', class: dev.dhcp ? 'active' : '', onClick: () => { if (!dev.dhcp) { dev.dhcp = true; ui.changed(); } } }, 'Автоматически (DHCP)'),
      h('button', { type: 'button', class: dev.dhcp ? '' : 'active', onClick: () => { if (dev.dhcp) { dev.dhcp = false; ui.changed(); } } }, 'Вручную'));
    box.append(mode);

    if (dev.dhcp) {
      const lease = itf.ip
        ? h('div', { class: 'wlease' },
          h('div', null, h('span', null, 'Адрес'), h('b', null, itf.ip + '/' + itf.prefix)),
          h('div', null, h('span', null, 'Шлюз'), h('b', null, dev.gateway || '—')),
          h('div', null, h('span', null, 'DNS'), h('b', null, dev.dns || '—')))
        : h('p', { class: 'wempty' }, 'Адреса пока нет. Нажми «Получить адрес»: узел разошлёт DHCP DISCOVER всем в своей сети.');
      const go = btn('Получить адрес', () => {
        go.disabled = true;
        go.textContent = 'Идёт обмен DHCP…';
        ui.runOp('dhcp', dev.id).then((r) => {
          const ok = r.results.find((x) => x.type === 'dhcp');
          toast(w, ok ? 'Получен ' + ok.ip : 'DHCP-сервер не ответил');
        });
      }, true);
      box.append(lease, h('div', { class: 'wrow' }, go,
        btn('Освободить', () => { itf.ip = ''; dev.gateway = ''; dev.dns = ''; ui.changed(); })));
    } else {
      const ipIn = inp(itf.ip, '192.168.1.10');
      const mask = maskSelect(itf.prefix);
      const gw = ipInp(dev.gateway, '192.168.1.1', dev, 'gw');
      const dns = ipInp(dev.dns, '10.0.0.10', dev, 'dns');
      box.append(h('div', { class: 'wgrid wgrid--2' }, field('IP-адрес', ipIn), field('Маска подсети', mask),
        field('Основной шлюз', gw, 'маршрутизатор в этой же сети'), field('DNS-сервер', dns, 'кто превращает имена в адреса')),
      h('div', { class: 'wrow wrow--end' }, err, btn('Применить', () => {
        err.textContent = '';
        const v = ipIn.value.trim(), p = Number(mask.value), g = gw.value.trim(), d = dns.value.trim();
        if (v) {
          if (!ip.valid(v)) { err.textContent = 'Неверный IP-адрес'; return; }
          const bad = ip.hostError(v, p);
          if (bad) { err.textContent = 'Нельзя: ' + bad; return; }
        }
        if (g && !ip.valid(g)) { err.textContent = 'Неверный адрес шлюза'; return; }
        if (g && v && !ip.same(g, v, p)) { err.textContent = 'Шлюз должен быть в сети ' + ip.netStr(v, p); return; }
        if (d && !ip.valid(d)) { err.textContent = 'Неверный адрес DNS'; return; }
        itf.ip = v ? ip.norm(v) : '';
        itf.prefix = p;
        dev.gateway = g ? ip.norm(g) : '';
        dev.dns = d ? ip.norm(d) : '';
        ui.changed();
        toast(w, 'Настройки применены');
      }, true)));
    }
    const arp = Object.entries(ui.S.sim.tables(dev).arp);
    box.append(h('details', { class: 'wdetails' }, ui.foldSummary('ARP-таблица (' + arp.length + ')'),
      table(['IP-адрес', 'MAC-адрес'], arp.map(([a, m]) => h('tr', null, h('td', null, a), h('td', null, h('code', null, m)))), 'Пусто')));
  };

  /* --- Сервер: службы --- */
  PAGES.services = (w, dev, root) => {
    const sv = dev.services;
    const wrap = h('div', { class: 'wsvc-list' });
    root.append(wrap);
    const card = (key, title, sub, body) => h('section', { class: 'wsvc' + (sv[key].on ? ' is-on' : '') },
      h('div', { class: 'wsvc__head' },
        h('div', null, h('b', null, title), h('small', null, sub)),
        toggle(sv[key].on, (on) => { sv[key].on = on; ui.changed(); toast(w, title + (on ? ' запущен' : ' остановлен')); }, sv[key].on ? 'работает' : 'выключен')),
      body);

    // DNS
    const dName = inp('', 'site.lab'), dIp = ipInp('', '10.0.0.20', dev, 'all'), dErr = errBox();
    wrap.append(card('dns', 'DNS-сервер', 'named, udp/53: имена в адреса', h('div', null,
      table(['Имя', 'Адрес', ''], sv.dns.records.map((r, k) => h('tr', null, h('td', null, h('b', null, r.name)), h('td', null, r.ip),
        h('td', null, h('button', { type: 'button', class: 'wx', onClick: () => { sv.dns.records.splice(k, 1); ui.changed(); } }, '×')))), 'Записей нет'),
      h('div', { class: 'wrow' }, dName, dIp, btn('Добавить', () => {
        const n = dName.value.trim().toLowerCase();
        if (!/^[a-z0-9.-]+$/.test(n)) { dErr.textContent = 'Имя: латиница, цифры, точки'; return; }
        if (!ip.valid(dIp.value)) { dErr.textContent = 'Неверный адрес'; return; }
        sv.dns.records = sv.dns.records.filter((r) => r.name !== n).concat({ name: n, ip: ip.norm(dIp.value) });
        ui.changed();
      })), dErr,
      h('p', { class: 'wnote' }, 'Клиенты узнают адрес этого сервера из поля DNS в своих настройках или из DHCP.'))));

    // DHCP: пулы (по одному на каждую сеть клиентов), резервирования, аренды
    const pErr = errBox();
    const rMac = h('input', { class: 'wf__in', placeholder: 'MAC клиента', list: 'labMacs-' + dev.id, spellcheck: 'false' });
    const rIp = inp('', 'адрес для него');
    const macs = h('datalist', { id: 'labMacs-' + dev.id }, ui.S.net.devices.filter((d) => LAB.types[d.type].kind === 'host' && d.id !== dev.id).map((d) => h('option', { value: d.ifaces[0].mac }, d.name)));
    const leases = Object.entries(ui.S.sim.tables(dev).leases);
    const nameByMac = (m) => { const d = ui.S.net.devices.find((x) => x.ifaces.some((i) => i.mac === m)); return d ? d.name : ''; };
    const poolCard = (pool, k) => {
      const pStart = inp(pool.start, '192.168.10.100'), pEnd = inp(pool.end, '192.168.10.150'), pMask = maskSelect(pool.prefix);
      const pGw = ipInp(pool.gateway, '192.168.10.1', null, 'gw'), pDns = ipInp(pool.dns, '10.0.0.10', null, 'dns'), err = errBox();
      return h('div', { class: 'wsub-if' },
        h('div', { class: 'wsub-if__head' }, h('b', null, 'Пул ' + (k + 1)), h('span', { class: 'wcard__sub' }, pool.start ? ip.netStr(pool.start, pool.prefix) : 'не настроен'),
          sv.dhcp.pools.length > 1 ? h('button', { type: 'button', class: 'wx', title: 'Удалить пул', onClick: () => { sv.dhcp.pools.splice(k, 1); ui.changed(); } }, '×') : null),
        h('div', { class: 'wgrid' }, field('С адреса', pStart), field('По адрес', pEnd), field('Маска', pMask), field('Шлюз для клиентов', pGw), field('DNS для клиентов', pDns)),
        h('div', { class: 'wrow wrow--end' }, err, btn('Сохранить пул', () => {
          err.textContent = '';
          if (!ip.valid(pStart.value) || !ip.valid(pEnd.value)) { err.textContent = 'Укажи начало и конец пула'; return; }
          const p = Number(pMask.value);
          if (!ip.same(pStart.value, pEnd.value, p)) { err.textContent = 'Начало и конец пула в разных сетях'; return; }
          if (ip.parse(pStart.value) > ip.parse(pEnd.value)) { err.textContent = 'Начало пула больше конца'; return; }
          if (pGw.value && !ip.valid(pGw.value)) { err.textContent = 'Неверный шлюз'; return; }
          if (pDns.value && !ip.valid(pDns.value)) { err.textContent = 'Неверный DNS'; return; }
          if (sv.dhcp.pools.some((o, j) => j !== k && o.start && ip.same(o.start, pStart.value, Math.min(p, o.prefix)))) { err.textContent = 'Пул для этой сети уже есть'; return; }
          Object.assign(pool, { start: ip.norm(pStart.value), end: ip.norm(pEnd.value), prefix: p, gateway: ip.norm(pGw.value), dns: ip.norm(pDns.value) });
          ui.changed();
          toast(w, 'Пул сохранён');
        }, true)));
    };
    wrap.append(card('dhcp', 'DHCP-сервер', 'isc-dhcp-server, udp/67: раздаёт адреса', h('div', null,
      sv.dhcp.pools.map(poolCard),
      h('div', { class: 'wrow' }, btn('+ пул для другой сети', () => { sv.dhcp.pools.push(LAB.defaultPool()); ui.changed(); })),
      h('p', { class: 'wnote' }, 'Сервер выбирает пул по сети клиента: в своей сети по своему адресу, из чужих сетей по адресу relay (giaddr) на маршрутизаторе.'),
      h('div', { class: 'wsub' }, 'Резервирование: всегда выдавать этому MAC один и тот же адрес'),
      table(['MAC', 'Адрес', ''], sv.dhcp.reservations.map((r, k) => h('tr', null, h('td', null, h('code', null, r.mac), ' ', h('small', null, nameByMac(r.mac))), h('td', null, r.ip),
        h('td', null, h('button', { type: 'button', class: 'wx', onClick: () => { sv.dhcp.reservations.splice(k, 1); ui.changed(); } }, '×')))), 'Резервирований нет'),
      h('div', { class: 'wrow' }, rMac, macs, rIp, btn('Зарезервировать', () => {
        const m = rMac.value.trim().toLowerCase();
        if (!/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/.test(m)) { pErr.textContent = 'MAC в формате 02:aa:bb:cc:dd:ee'; return; }
        if (!ip.valid(rIp.value)) { pErr.textContent = 'Неверный адрес резервирования'; return; }
        sv.dhcp.reservations = sv.dhcp.reservations.filter((r) => r.mac !== m).concat({ mac: m, ip: ip.norm(rIp.value) });
        ui.changed();
      })), pErr,
      h('div', { class: 'wsub' }, 'Выданные адреса (аренды)'),
      table(['Клиент', 'Адрес'], leases.map(([m, a]) => h('tr', null, h('td', null, nameByMac(m) || h('code', null, m)), h('td', null, a))), 'Пока никому ничего не выдано'))));

    // HTTP
    const tIn = inp(sv.http.title, 'Заголовок страницы');
    const bIn = h('textarea', { class: 'wf__in wf__area', rows: 4, spellcheck: 'false' });
    bIn.value = sv.http.body;
    wrap.append(card('http', 'Веб-сервер', 'nginx, tcp/80: отдаёт статическую страницу /var/www/html/index.html', h('div', null,
      field('Заголовок', tIn), field('Текст страницы', bIn),
      h('div', { class: 'wrow wrow--end' }, btn('Сохранить страницу', () => { sv.http.title = tIn.value.trim() || 'Без названия'; sv.http.body = bIn.value; ui.changed(); toast(w, 'Страница сохранена'); }, true)),
      h('p', { class: 'wnote' }, 'Открой сайт с любого ПК во вкладке «Браузер»: по IP этого сервера или по имени, если добавить его в DNS.'))));
  };

  /* --- Браузер --- */
  PAGES.browser = (w, dev, root) => {
    const b = w.browser;
    if (!b.url) {
      const rec = [];
      ui.S.net.devices.forEach((d) => d.services && d.services.dns.on && d.services.dns.records.forEach((r) => rec.push(r.name)));
      b.url = 'http://' + (rec.find((n) => /^(site|www)\./.test(n)) || rec[0] || '10.0.0.20') + '/';
    }
    const url = h('input', { class: 'wbr__url', value: b.url, spellcheck: 'false', 'aria-label': 'Адрес',
      onKeydown: (e) => { if (e.key === 'Enter') go(); } });
    const view = h('div', { class: 'wbr__view' });
    const go = () => {
      b.url = url.value.trim();
      if (!b.url) return;
      b.state = { loading: true };
      draw();
      ui.runOp('http', dev.id, b.url).then((r) => { b.state = { r }; if (wins[w.devId] === w && w.tab === 'browser') render(w); });
    };
    const draw = () => {
      view.replaceChildren();
      const st = b.state;
      if (!st) { view.append(h('div', { class: 'wbr__blank' }, h('b', null, 'Новая вкладка'), h('p', null, 'Введи адрес сайта и нажми Enter. Внизу будет видно, как браузер спрашивает DNS, открывает TCP-соединение и отправляет HTTP-запрос.'))); return; }
      if (st.loading) { view.append(h('div', { class: 'wbr__blank' }, h('div', { class: 'wbr__spin' }), h('p', null, 'Загрузка… следи за пакетами на схеме'))); return; }
      const res = st.r.results;
      const ok = res.find((x) => x.type === 'http');
      if (ok) {
        view.append(h('article', { class: 'wbr__page' },
          h('h1', null, ok.title),
          String(ok.body).split('\n').map((p) => h('p', null, p)),
          h('footer', null, 'HTTP ' + ok.status + ' от ' + ok.ip + ', nginx')));
        return;
      }
      const dnsFail = res.find((x) => x.type === 'dns-fail');
      const refused = res.find((x) => x.type === 'http-refused');
      const local = res.find((x) => x.type === 'local-err');
      const [title, code, why] = dnsFail ? ['Не удаётся найти сайт', 'DNS_PROBE_FINISHED_NXDOMAIN', dnsFail.reason]
        : refused ? ['Сайт отклонил подключение', 'ERR_CONNECTION_REFUSED', 'на сервере не запущен веб-сервер (порт 80 закрыт)']
          : local ? ['Нет подключения к сети', 'ERR_ADDRESS_UNREACHABLE', local.text]
            : ['Превышено время ожидания', 'ERR_CONNECTION_TIMED_OUT', 'ответ не пришёл: посмотри в журнале, где потерялся пакет'];
      view.append(h('div', { class: 'wbr__error' }, h('div', { class: 'wbr__sad' }, ':('), h('h2', null, title), h('p', null, why), h('code', null, code)));
    };
    root.append(h('div', { class: 'wbr' },
      h('div', { class: 'wbr__bar' },
        h('button', { type: 'button', class: 'wbr__nav', title: 'Обновить', onClick: go }, '↻'),
        url,
        h('button', { type: 'button', class: 'wbtn wbtn--primary', onClick: go }, 'Перейти')),
      view));
    draw();
    if (b.autogo) { b.autogo = false; go(); }
  };

  /* --- Диагностика: ping, traceroute, nslookup --- */
  const DIAG_OPS = {
    ping: { label: 'Ping', cmd: 'ping' },
    tracert: { label: 'Traceroute', cmd: 'traceroute' },
    dns: { label: 'Nslookup', cmd: 'nslookup', host: true },
  };

  function diagLines(op, dst, r, dev) {
    const out = [];
    const add = (t, cls) => out.push({ t, cls: cls || '' });
    const find = (t) => r.results.find((x) => x.type === t);
    const dnsFail = find('dns-fail');
    const local = find('local-err');
    if (op === 'ping') {
      if (dnsFail) { add('Не удалось узнать адрес ' + dst + ': ' + dnsFail.reason, 'err'); return out; }
      if (local) { add('Сеть недоступна: ' + local.text, 'err'); return out; }
      const res = r.results[0];
      if (res && res.type === 'reply') add('Ответ от ' + res.from + ': TTL ' + res.ttl + ', время ' + (0.3 + Math.random() * 2).toFixed(1) + ' мс', 'ok');
      else if (res && res.type === 'unreach') add(res.from + (res.admin ? ' сообщает: доступ запрещён (ACL)' : ' сообщает: сеть назначения недоступна'), 'err');
      else if (res && res.type === 'arp-fail') add('Узел недоступен: на ARP-запрос никто не ответил (узла нет в этой сети или он в другом VLAN)', 'err');
      else add('Превышено время ожидания: ответ не вернулся, в журнале видно, где пропал пакет', 'err');
    } else if (op === 'tracert') {
      if (dnsFail) { add('Не удалось узнать адрес ' + dst + ': ' + dnsFail.reason, 'err'); return out; }
      r.hops.forEach((x) => add(String(x.ttl).padStart(2) + '   ' + (x.from ? x.from + '   ' + (0.5 + x.ttl * 0.7).toFixed(1) + ' мс' + (x.unreach ? (x.admin ? '   запрещено ACL' : '   сеть недоступна') : '') : '*   ответа нет' + (x.error ? ' (' + x.error + ')' : '')),
        x.done ? 'ok' : x.from && !x.unreach ? '' : 'err'));
      if (!r.hops.length) add('Маршрут не найден', 'err');
    } else {
      const ok = find('dns');
      if (ok) add(dst + ' = ' + ok.ip + '   (ответил DNS ' + (dev.dns || '?') + ')', 'ok');
      else if (dnsFail) add(dnsFail.reason === 'DNS-сервер не задан' ? 'DNS-сервер не задан: укажи его во вкладке «Сеть»' : 'Имя ' + dst + ' не найдено: ' + dnsFail.reason, 'err');
      else if (local) add('Сеть недоступна: ' + local.text, 'err');
      else add('DNS-сервер не ответил: он недоступен или служба DNS на нём выключена', 'err');
    }
    return out;
  }

  function diagRun(w, op) {
    const dev = ui.S.net.dev(w.devId);
    const dst = w.diag.target.trim();
    if (!dev || !dst || w.diag.busy) return;
    w.diag.busy = true;
    w.diag.lines.push({ t: DIAG_OPS[op].cmd + ' ' + dst, cls: 'cmd' });
    if (w.diag.lines.length > 300) w.diag.lines.splice(0, w.diag.lines.length - 300);
    if (w.tab === 'diag') render(w);
    ui.runOp(op, dev.id, dst).then((r) => {
      w.diag.busy = false;
      w.diag.lines.push(...diagLines(op, dst, r, dev));
      if (wins[w.devId] === w && w.tab === 'diag') render(w);
    });
  }

  PAGES.diag = (w, dev, root) => {
    const d = w.diag;
    const host = LAB.types[dev.type].kind === 'host';
    const opts = [];
    ui.S.net.devices.forEach((x) => x !== dev && x.ifaces.forEach((i) => { if (i.ip && opts.length < 300) opts.push([i.ip, x.name]); }));
    ui.S.net.devices.forEach((x) => x.services && x.services.dns.on && x.services.dns.records.forEach((r) => opts.push([r.name, 'DNS: ' + r.ip])));
    const listId = 'labDiag-' + dev.id;
    const target = h('input', { class: 'wf__in wdiag__in', value: d.target, placeholder: host ? 'IP-адрес или имя' : 'IP-адрес', list: listId, spellcheck: 'false', 'aria-label': 'Куда',
      onInput: (e) => { d.target = e.target.value; },
      onKeydown: (e) => { if (e.key === 'Enter') diagRun(w, 'ping'); } });
    const ops = Object.entries(DIAG_OPS).filter(([, o]) => host || !o.host);
    const out = h('div', { class: 'wdiag__out', 'aria-live': 'polite' },
      d.lines.length ? d.lines.map((l) => h('div', { class: 'wdiag__line' + (l.cls ? ' is-' + l.cls : '') }, l.t))
        : h('p', { class: 'wempty' }, 'Введи адрес и нажми кнопку. Пакеты пойдут по схеме, разбор каждого кадра есть в журнале.'));
    if (d.busy) out.append(h('div', { class: 'wdiag__line is-dim' }, 'идёт симуляция…'));
    root.append(h('div', { class: 'wdiag' },
      h('div', { class: 'wrow' }, target, h('datalist', { id: listId }, opts.map(([a, n]) => h('option', { value: a }, n))),
        ops.map(([id, o], k) => h('button', { type: 'button', class: 'wbtn' + (k === 0 ? ' wbtn--primary' : ''), disabled: d.busy, onClick: () => { d.target = target.value; diagRun(w, id); } }, o.label)),
        d.lines.length ? h('button', { type: 'button', class: 'wbtn', title: 'Очистить вывод', onClick: () => { d.lines = []; render(w); } }, 'Очистить') : null),
      out));
    requestAnimationFrame(() => { out.scrollTop = out.scrollHeight; });
  };

  /* ===== Подключение ===== */
  function init() {
    ui = LAB.ui;
    h = ui.h;
  }

  LAB.Win = {
    open(devId, tab) { init(); return open(devId, tab); },
    close, closeAll, clamp,
    refresh() { if (ui) refresh(); },
    tabsFor,
    runDiag(devId, op, arg) { init(); runDiag(devId, op, arg); },
  };
})();
