// Окна устройств поверх холста: веб-интерфейс, терминал (SSH или локальный), браузер, службы сервера.
// Вкладки окна берутся из type.web и type.shell (types.js). Новая вкладка: добавить запись в TABS и функцию в PAGES.
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
  };

  const wins = {};
  let z = 10;
  let ui, h;

  function tabsFor(dev) {
    const t = LAB.types[dev.type];
    const list = (t.web || []).map((id) => Object.assign({ id }, TABS[id]));
    if (t.shell && LAB.shells[t.shell]) {
      list.push(t.shell === 'ios'
        ? { id: 'term', label: 'SSH', sub: 'командная строка в стиле Cisco', icon: '>_' }
        : { id: 'term', label: 'Терминал', sub: 'bash: ip, ping, curl', icon: '>_' });
    }
    return list;
  }

  /* ===== Сеанс терминала: живёт дольше окна ===== */
  function session(devId) {
    const S = ui.S;
    if (!S.terms[devId]) {
      const dev = S.net.dev(devId);
      const t = LAB.types[dev.type];
      S.terms[devId] = {
        store: { lines: [], history: [], booted: false },
        shell: LAB.shells[t.shell]({
          dev: () => S.net.dev(devId),
          sim: () => S.sim,
          changed: () => ui.changed(),
          run: (op, arg) => ui.runOp(op, devId, arg),
        }),
      };
    }
    return S.terms[devId];
  }

  /* ===== Окно ===== */
  function open(devId, tab) {
    const dev = ui.S.net.dev(devId);
    if (!dev) return null;
    const tabs = tabsFor(dev);
    if (!tabs.length) { ui.hint(dev.name + ': у этого устройства нет настроек', 'err'); return null; }
    let w = wins[devId];
    if (!w) {
      w = wins[devId] = { devId, tab: null, nav: {}, browser: { url: '', state: null } };
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
    const bar = h('div', { class: 'lw__bar' },
      h('div', { class: 'lw__dots' },
        h('button', { type: 'button', class: 'lw__dot lw__dot--close', title: 'Закрыть', onClick: () => close(w.devId) }),
        h('button', { type: 'button', class: 'lw__dot lw__dot--min', title: 'Свернуть', onClick: () => w.el.classList.toggle('is-min') }),
        h('button', { type: 'button', class: 'lw__dot lw__dot--max', title: 'Развернуть', onClick: () => { w.el.classList.toggle('is-max'); } })),
      w.titleEl,
      w.tabsEl);
    w.el = h('div', { class: 'lw', role: 'dialog', onPointerdown: () => focus(w) }, bar, w.body);
    ui.E.windows.append(w.el);

    const st = ui.E.stage.getBoundingClientRect();
    const width = Math.min(660, st.width - 24);
    const left = Math.max(12, st.width - width - 16 - n * 26);
    w.el.style.left = left + 'px';
    w.el.style.top = (12 + n * 26) + 'px';

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

  function place(w, x, y) {
    const st = ui.E.stage;
    const maxX = Math.max(0, st.clientWidth - 120);
    const maxY = Math.max(0, st.clientHeight - 40);
    w.el.style.left = Math.min(maxX, Math.max(-w.el.offsetWidth + 120, x)) + 'px';
    w.el.style.top = Math.min(maxY, Math.max(0, y)) + 'px';
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
    w.el.classList.toggle('lw--term', tab === 'term');
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
    w.term = null;
    if (w.tab === 'term') {
      const ses = session(w.devId);
      w.term = LAB.Terminal(w.body, ses.shell, ses.store);
      requestAnimationFrame(() => w.term && w.term.focus());
      return;
    }
    const fn = PAGES[w.tab];
    if (fn) fn(w, dev, w.body);
  }

  function renderTitle(w, dev) {
    let title;
    if (w.tab === 'term') title = session(w.devId).shell.title();
    else if (w.tab === 'browser') title = dev.name + ': браузер';
    else if (w.tab === 'net') title = dev.name + ': параметры сети';
    else if (w.tab === 'services') title = dev.name + ': службы';
    else title = dev.name + ': веб-интерфейс';
    w.titleEl.textContent = title;
  }

  // После изменений перерисовать веб-страницы окон (терминал не трогаем, в нём свой вывод)
  function refresh() {
    Object.values(wins).forEach((w) => {
      const dev = ui.S.net.dev(w.devId);
      if (!dev) { close(w.devId); return; }
      renderTitle(w, dev);
      if (w.tab === 'term') { if (w.term) w.term.refreshPrompt(); return; }
      const a = document.activeElement;
      if (a && w.el.contains(a) && a.matches('input, textarea, select')) return;
      render(w);
    });
  }

  // Выполнить команду в терминале устройства (кнопки внизу и подсказки схем)
  function runInTerm(devId, cmd) {
    const w = open(devId, 'term');
    if (w && w.term) w.term.run(cmd);
  }

  /* ===== Элементы форм ===== */
  function maskSelect(prefix) {
    const sel = h('select', { class: 'wf__in wf__mask' });
    for (let p = 8; p <= 32; p++) sel.append(h('option', { value: p, selected: p === prefix }, '/' + p + '  ' + ip.maskStr(p)));
    return sel;
  }
  function inp(value, ph, cls) { return h('input', { class: 'wf__in' + (cls ? ' ' + cls : ''), value: value || '', placeholder: ph || '', spellcheck: 'false' }); }
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
          h('div', { class: 'wa__brand' }, brand, h('small', null, dev.name)),
          Object.entries(pages).map(([id, p]) => h('button', { type: 'button', class: 'wa__link' + (id === cur ? ' active' : ''), onClick: () => { w.nav[w.tab] = id; render(w); } }, p.title))),
        content));
    content.append(h('h3', { class: 'wa__h' }, pages[cur].title));
    pages[cur].render(content);
  }

  /* ===== Страницы ===== */
  const PAGES = {};

  /* --- Маршрутизатор --- */
  PAGES.router = (w, dev, root) => {
    webApp(w, dev, root, 'RouterOS-lite', {
      overview: { title: 'Обзор', render(c) {
        const name = inp(dev.name);
        const upN = dev.ifaces.filter((i) => i.ip && LAB.ifUp(i)).length;
        c.append(
          h('div', { class: 'wstats' },
            h('div', null, h('b', null, upN + ' / ' + dev.ifaces.length), h('span', null, 'интерфейсов в работе')),
            h('div', null, h('b', null, dev.routes.length), h('span', null, 'статических маршрутов')),
            h('div', null, h('b', null, Object.keys(ui.S.sim.tables(dev).arp).length), h('span', null, 'записей ARP'))),
          h('div', { class: 'wrow' }, field('Имя устройства', name), btn('Сохранить', () => { if (name.value.trim()) { dev.name = name.value.trim().slice(0, 24); ui.changed(); toast(w, 'Имя сохранено'); } }, true)),
          table(['Интерфейс', 'Адрес', 'Сосед', 'Состояние'], dev.ifaces.map((i, k) => h('tr', null,
            h('td', null, h('b', null, i.name)), h('td', null, i.ip ? i.ip + '/' + i.prefix : '—'), h('td', null, peerName(dev, k)), h('td', null, ifState(i))))));
      } },
      ifaces: { title: 'Интерфейсы', render(c) {
        c.append(h('p', { class: 'wnote' }, 'У каждого интерфейса маршрутизатора своя сеть. DHCP relay пересылает широковещательные DHCP-запросы из этой сети на сервер в другой сети.'));
        dev.ifaces.forEach((itf, k) => {
          const ipIn = inp(itf.ip, '192.168.1.1');
          const mask = maskSelect(itf.prefix);
          const helper = inp(itf.helper, 'IP DHCP-сервера');
          const err = errBox();
          const save = () => {
            err.textContent = '';
            const v = ipIn.value.trim();
            const p = Number(mask.value);
            if (v) {
              if (!ip.valid(v)) { err.textContent = 'Неверный IP-адрес'; return; }
              const bad = ip.hostError(v, p);
              if (bad) { err.textContent = 'Нельзя: ' + bad; return; }
              const clash = dev.ifaces.find((x, j) => j !== k && x.ip && (ip.same(x.ip, v, p) || ip.same(x.ip, v, x.prefix)));
              if (clash) { err.textContent = 'Эта сеть уже на ' + clash.name + ': у каждого интерфейса должна быть своя сеть'; return; }
            }
            const hv = helper.value.trim();
            if (hv && !ip.valid(hv)) { err.textContent = 'Relay: неверный адрес сервера'; return; }
            itf.ip = v ? ip.norm(v) : '';
            itf.prefix = p;
            itf.helper = hv ? ip.norm(hv) : '';
            ui.changed();
            toast(w, itf.name + ': сохранено');
          };
          c.append(h('div', { class: 'wcard' + (LAB.ifUp(itf) ? '' : ' is-off') },
            h('div', { class: 'wcard__head' },
              h('b', null, itf.name), h('span', { class: 'wcard__sub' }, '→ ' + peerName(dev, k)), ifState(itf),
              toggle(!itf.down, (on) => { itf.down = !on; ui.changed(); toast(w, itf.name + (on ? ': включён' : ': выключен')); }, 'вкл.')),
            h('div', { class: 'wgrid' }, field('IP-адрес', ipIn), field('Маска', mask), field('DHCP relay', helper)),
            h('div', { class: 'wrow wrow--end' }, err, btn('Сохранить', save, true))));
        });
      } },
      routes: { title: 'Маршрутизация', render(c) {
        const rows = LAB.routeTable(dev);
        c.append(table(['', 'Сеть', 'Куда отдать', ''], rows.map((r) => h('tr', { class: r.code === 'S' && !r.iface ? 'is-bad' : '' },
          h('td', null, h('span', { class: 'wcode wcode--' + r.code, title: r.code === 'C' ? 'подключена' : 'статический' }, r.code)),
          h('td', null, r.net),
          h('td', null, r.code === 'C' ? 'напрямую, ' + r.iface : r.via + (r.iface ? ' через ' + r.iface : ' (сосед недостижим)')),
          h('td', null, r.code === 'S' ? h('button', { type: 'button', class: 'wx', title: 'Удалить', onClick: () => { dev.routes.splice(r.idx, 1); ui.changed(); } }, '×') : null))),
        'Пусто: задай адреса интерфейсам и подключи кабели.'));
        const net = inp('', '10.3.0.0');
        const mask = maskSelect(24);
        const via = inp('', '10.0.12.2');
        const err = errBox();
        c.append(h('div', { class: 'wcard' },
          h('div', { class: 'wcard__head' }, h('b', null, 'Добавить статический маршрут')),
          h('div', { class: 'wgrid' }, field('Сеть назначения', net), field('Маска', mask), field('Следующий хоп', via, 'IP соседа в подключённой сети')),
          h('div', { class: 'wrow wrow--end' }, err, btn('Добавить', () => {
            if (!ip.valid(net.value)) { err.textContent = 'Неверная сеть'; return; }
            if (!ip.valid(via.value)) { err.textContent = 'Неверный следующий хоп'; return; }
            const p = Number(mask.value);
            dev.routes.push({ net: ip.str(ip.net(ip.parse(net.value), p)), prefix: p, via: ip.norm(via.value) });
            ui.changed();
            toast(w, 'Маршрут добавлен');
          }, true))),
        h('p', { class: 'wnote' }, 'C: сеть подключена к интерфейсу. S: «чтобы попасть в эту сеть, отдай пакет соседу». Выбирается самый длинный подходящий префикс; 0.0.0.0/0 это маршрут по умолчанию.'));
      } },
      arp: { title: 'ARP', render(c) {
        const arp = Object.entries(ui.S.sim.tables(dev).arp);
        c.append(table(['IP-адрес', 'MAC-адрес'], arp.map(([a, m]) => h('tr', null, h('td', null, a), h('td', null, h('code', null, m)))), 'Пусто: заполнится после первого обмена.'),
          h('div', { class: 'wrow' }, btn('Очистить ARP', () => { ui.S.sim.tables(dev).arp = {}; render(w); })));
      } },
    });
  };

  /* --- Коммутатор --- */
  PAGES.switch = (w, dev, root) => {
    webApp(w, dev, root, 'SwitchOS-lite', {
      ports: { title: 'Порты и VLAN', render(c) {
        const selIdx = w.port != null ? w.port : 0;
        const face = h('div', { class: 'wface' }, dev.ifaces.map((itf, k) => h('button', {
          type: 'button', class: 'wport' + (k === selIdx ? ' active' : '') + (itf.down ? ' is-down' : LAB.ifUp(itf) ? ' is-up' : ''),
          style: { '--vc': vlanColor(itf.vlan || 1) }, title: itf.name + ', VLAN ' + (itf.vlan || 1),
          onClick: () => { w.port = k; render(w); },
        }, h('span', { class: 'wport__led' }), h('span', { class: 'wport__num' }, k + 1), h('span', { class: 'wport__vlan' }, 'v' + (itf.vlan || 1)))));
        const itf = dev.ifaces[selIdx];
        const vlan = h('input', { class: 'wf__in', type: 'number', min: 1, max: 4094, value: itf.vlan || 1 });
        const desc = inp(itf.desc || '', 'например, бухгалтерия');
        const err = errBox();
        c.append(
          h('div', { class: 'wface-wrap' }, h('div', { class: 'wface__label' }, dev.name), face),
          h('div', { class: 'wcard' },
            h('div', { class: 'wcard__head' }, h('b', null, itf.name), h('span', { class: 'wcard__sub' }, '→ ' + peerName(dev, selIdx)), ifState(itf),
              toggle(!itf.down, (on) => { itf.down = !on; ui.changed(); }, 'вкл.')),
            h('div', { class: 'wgrid' }, field('VLAN (access)', vlan, 'порты с одним VLAN видят друг друга'), field('Описание', desc)),
            h('div', { class: 'wrow wrow--end' }, err, btn('Сохранить', () => {
              const v = Math.round(Number(vlan.value));
              if (!(v >= 1 && v <= 4094)) { err.textContent = 'VLAN от 1 до 4094'; return; }
              itf.vlan = v;
              itf.desc = desc.value.trim().slice(0, 60);
              ui.changed();
              toast(w, itf.name + ': VLAN ' + v);
            }, true))),
          h('p', { class: 'wnote' }, 'Цвет полоски под портом это его VLAN. Кадры не переходят между VLAN внутри коммутатора: для этого нужен маршрутизатор.'));
      } },
      mac: { title: 'Таблица MAC', render(c) {
        const rows = Object.entries(ui.S.sim.tables(dev).mac);
        c.append(table(['VLAN', 'MAC-адрес', 'Порт'], rows.map(([k, port]) => { const [v, m] = k.split('|'); return h('tr', null, h('td', null, v), h('td', null, h('code', null, m)), h('td', null, dev.ifaces[port].name)); }),
          'Пусто: коммутатор учит адреса, когда через него проходят кадры.'),
        h('div', { class: 'wrow' }, btn('Очистить таблицу', () => { ui.S.sim.tables(dev).mac = {}; render(w); })));
      } },
      overview: { title: 'Обзор', render(c) {
        const name = inp(dev.name);
        const by = {};
        dev.ifaces.forEach((i) => { (by[i.vlan || 1] = by[i.vlan || 1] || []).push(i.name); });
        c.append(
          h('div', { class: 'wrow' }, field('Имя устройства', name), btn('Сохранить', () => { if (name.value.trim()) { dev.name = name.value.trim().slice(0, 24); ui.changed(); toast(w, 'Имя сохранено'); } }, true)),
          table(['VLAN', 'Порты'], Object.keys(by).sort((a, b) => a - b).map((v) => h('tr', null, h('td', null, h('span', { class: 'wvlan', style: { '--vc': vlanColor(v) } }, v)), h('td', null, by[v].join(', '))))));
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
      const gw = inp(dev.gateway, '192.168.1.1');
      const dns = inp(dev.dns, '10.0.0.10');
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
    box.append(h('details', { class: 'wdetails' }, h('summary', null, 'ARP-таблица (' + arp.length + ')'),
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
    const dName = inp('', 'site.lab'), dIp = inp('', '10.0.0.20'), dErr = errBox();
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

    // DHCP
    const pool = sv.dhcp.pool;
    const pStart = inp(pool.start, '192.168.10.100'), pEnd = inp(pool.end, '192.168.10.150'), pMask = maskSelect(pool.prefix);
    const pGw = inp(pool.gateway, '192.168.10.1'), pDns = inp(pool.dns, '10.0.0.10'), pErr = errBox();
    const rMac = h('input', { class: 'wf__in', placeholder: 'MAC клиента', list: 'labMacs-' + dev.id, spellcheck: 'false' });
    const rIp = inp('', 'адрес для него');
    const macs = h('datalist', { id: 'labMacs-' + dev.id }, ui.S.net.devices.filter((d) => LAB.types[d.type].kind === 'host' && d.id !== dev.id).map((d) => h('option', { value: d.ifaces[0].mac }, d.name)));
    const leases = Object.entries(ui.S.sim.tables(dev).leases);
    const nameByMac = (m) => { const d = ui.S.net.devices.find((x) => x.ifaces.some((i) => i.mac === m)); return d ? d.name : ''; };
    wrap.append(card('dhcp', 'DHCP-сервер', 'isc-dhcp-server, udp/67: раздаёт адреса', h('div', null,
      h('div', { class: 'wgrid' }, field('Пул: с', pStart), field('по', pEnd), field('Маска', pMask), field('Шлюз для клиентов', pGw), field('DNS для клиентов', pDns)),
      h('div', { class: 'wrow wrow--end' }, pErr, btn('Сохранить пул', () => {
        pErr.textContent = '';
        if (!ip.valid(pStart.value) || !ip.valid(pEnd.value)) { pErr.textContent = 'Укажи начало и конец пула'; return; }
        const p = Number(pMask.value);
        if (!ip.same(pStart.value, pEnd.value, p)) { pErr.textContent = 'Начало и конец пула в разных сетях'; return; }
        if (ip.parse(pStart.value) > ip.parse(pEnd.value)) { pErr.textContent = 'Начало пула больше конца'; return; }
        if (pGw.value && !ip.valid(pGw.value)) { pErr.textContent = 'Неверный шлюз'; return; }
        if (pDns.value && !ip.valid(pDns.value)) { pErr.textContent = 'Неверный DNS'; return; }
        Object.assign(pool, { start: ip.norm(pStart.value), end: ip.norm(pEnd.value), prefix: p, gateway: ip.norm(pGw.value), dns: ip.norm(pDns.value) });
        ui.changed();
        toast(w, 'Пул сохранён');
      }, true)),
      h('div', { class: 'wsub' }, 'Резервирование: всегда выдавать этому MAC один и тот же адрес'),
      table(['MAC', 'Адрес', ''], sv.dhcp.reservations.map((r, k) => h('tr', null, h('td', null, h('code', null, r.mac), ' ', h('small', null, nameByMac(r.mac))), h('td', null, r.ip),
        h('td', null, h('button', { type: 'button', class: 'wx', onClick: () => { sv.dhcp.reservations.splice(k, 1); ui.changed(); } }, '×')))), 'Резервирований нет'),
      h('div', { class: 'wrow' }, rMac, macs, rIp, btn('Зарезервировать', () => {
        const m = rMac.value.trim().toLowerCase();
        if (!/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/.test(m)) { pErr.textContent = 'MAC в формате 02:aa:bb:cc:dd:ee'; return; }
        if (!ip.valid(rIp.value)) { pErr.textContent = 'Неверный адрес резервирования'; return; }
        sv.dhcp.reservations = sv.dhcp.reservations.filter((r) => r.mac !== m).concat({ mac: m, ip: ip.norm(rIp.value) });
        ui.changed();
      })),
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
    runInTerm(devId, cmd) { init(); runInTerm(devId, cmd); },
  };
})();
